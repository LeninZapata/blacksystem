# Plantilla CRUD Rápido - Sistema Híbrido Backend + Frontend

## Instrucciones para la AI

Esta plantilla permite crear un CRUD completo (backend PHP + frontend JS) de forma rápida siguiendo las convenciones del sistema.

### Convenciones Generales
- **Tabulación**: 2 espacios
- **Nombres**: Usar camelCase sin guiones → `miRecurso` (NO `mi-recurso`)
- **URLs API**: Sin guiones, usar camelCase → `/api/miRecurso` (NO `/api/mi-recurso`)
- **Tabla BD**: Plural con guión bajo → `mi_recursos` (snake_case)
- **Archivos**: Seguir el nombre del recurso → `miRecurso.json`, `miRecurso.php`, `miRecurso.js`
- **user_id**: Siempre incluir en tablas que necesitan auditoría de usuario creador
- **Comentarios**: Máximo 1 línea, solo si es importante
- **Logs**: Solo en errores, formato `'ext:xxx'`

### ✅ Sincronización Resource JSON ↔️ Formulario
**CRÍTICO**: Los límites deben coincidir entre backend y frontend:

| Resource JSON (bot.json) | Formulario (bot-form.json) | Resultado |
|-------------------------|---------------------------|-----------|
| `"maxLength": 50` | `"validation": "max:50"` | ✅ Correcto |
| `"required": true` | `"required": true` o `"validation": "required"` | ✅ Correcto |
| `"type": "string"` | `"type": "text"` o `"type": "textarea"` | ✅ Correcto |
| `"type": "int"` | `"type": "number"` o `"validation": "numeric"` | ✅ Correcto |

**Regla de oro**: Si `bot.json` tiene `maxLength:50`, el form DEBE tener `max:50` en validation.


### ⚠️ IMPORTANTE - Reglas de Nombrado
1. **Nombre del recurso** debe coincidir en:
   - Archivo JSON: `miRecurso.json`
   - URL de API: `/api/miRecurso`
   - Módulo extraído de URL: `miRecurso`
2. **NO usar guiones** en URLs de API (causa error "Método no existe")
3. **Tabla BD** siempre plural: `mi_recursos`
4. **user_id** debe ir ANTES de los campos de auditoría (dc, da, ta, tu)

### 🚨 CRÍTICO - Atributo `name` OBLIGATORIO
**TODOS los campos y componentes con condiciones DEBEN tener el atributo `name`**

❌ **INCORRECTO** (causará error):
```json
{
  "type": "grouper",
  "condition": [
    {"field": "type", "operator": "==", "value": "chat"}
  ],
  "groups": [...]
}
```

✅ **CORRECTO**:
```json
{
  "name": "chat_config_group",
  "type": "grouper",
  "condition": [
    {"field": "type", "operator": "==", "value": "chat"}
  ],
  "groups": [...]
}
```

**Regla de oro**: Si un campo/componente tiene `condition`, DEBE tener `name`.

**Aplica a**:
- ✅ Campos normales: `text`, `select`, `checkbox`, `textarea`
- ✅ Groupers con condiciones
- ✅ Repetables con condiciones
- ✅ Cualquier elemento con `condition`

**Sin `name`**: El sistema de condiciones no podrá identificar el elemento para mostrar/ocultar y causará un error crítico (`Cannot read properties of undefined (reading 'split')`).

**Debug**: Si ves error `"undefined" debe mostrarse`, verifica que todos los campos con `condition` tengan `name`.

---

## BACKEND

### 1. Tabla SQL
```sql
CREATE TABLE IF NOT EXISTS `{tabla_plural}` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL COMMENT 'ID del usuario creador',
  {campos_personalizados}
  `dc` datetime NOT NULL COMMENT 'Fecha de creación',
  `da` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'Fecha de actualización',
  `ta` int NOT NULL COMMENT 'Timestamp de creación',
  `tu` int DEFAULT NULL COMMENT 'Timestamp de actualización',
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_dc` (`dc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='{Descripción}';
```

### 2. Resource JSON: `/app/resources/{miRecurso}.json`
```json
{
  "resource": "{miRecurso}",
  "table": "{tabla_plural}",
  "timestamps": true,
  "middleware": ["throttle:100,1"],
  "routes": {
    "list": {"method": "GET", "path": "/api/{miRecurso}", "middleware": ["auth"]},
    "show": {"method": "GET", "path": "/api/{miRecurso}/{id}", "middleware": ["auth"]},
    "create": {"method": "POST", "path": "/api/{miRecurso}", "middleware": ["auth", "json"]},
    "update": {"method": "PUT", "path": "/api/{miRecurso}/{id}", "middleware": ["auth", "json"]},
    "delete": {"method": "DELETE", "path": "/api/{miRecurso}/{id}", "middleware": ["auth"]}
  },
  "fields": [
    {"name": "user_id", "type": "int", "required": true},
    {"name": "name", "type": "string", "required": true, "maxLength": 50}
  ]
}
```
**⚠️ Orden obligatorio**: `user_id` → campos custom → `dc, da, ta, tu` (timestamps auto)
**⚠️ No incluir** `dc, da, ta, tu` en fields (se agregan automáticamente si `timestamps: true`)

### 3. Controller: `/app/resources/controllers/{miRecurso}Controller.php`
```php
<?php
class {miRecurso}Controller extends controller {

  function __construct() {
    parent::__construct('{miRecurso}');
  }

  function create() {
    $data = request::data();
    
    if (!isset($data['{campo_requerido}']) || empty($data['{campo_requerido}'])) {
      response::json(['success' => false, 'error' => __('{miRecurso}.{campo_requerido}_required')], 200);
    }

    if (!isset($data['user_id']) || empty($data['user_id'])) {
      response::json(['success' => false, 'error' => __('{miRecurso}.user_id_required')], 200);
    }

    if (isset($data['config']) && is_array($data['config'])) {
      $data['config'] = json_encode($data['config'], JSON_UNESCAPED_UNICODE);
    }

    $data['dc'] = date('Y-m-d H:i:s');
    $data['ta'] = time();

    try {
      $id = db::table('{tabla_plural}')->insert($data);
      response::success(['id' => $id], __('{miRecurso}.create.success'), 201);
    } catch (Exception $e) {
      response::serverError(__('{miRecurso}.create.error'), IS_DEV ? $e->getMessage() : null);
    }
  }

  function update($id) {
    $exists = db::table('{tabla_plural}')->find($id);
    if (!$exists) response::notFound(__('{miRecurso}.not_found'));

    $data = request::data();
    
    if (isset($data['config']) && is_array($data['config'])) {
      $data['config'] = json_encode($data['config'], JSON_UNESCAPED_UNICODE);
    }

    $data['da'] = date('Y-m-d H:i:s');
    $data['tu'] = time();

    try {
      $affected = db::table('{tabla_plural}')->where('id', $id)->update($data);
      response::success(['affected' => $affected], __('{miRecurso}.update.success'));
    } catch (Exception $e) {
      response::serverError(__('{miRecurso}.update.error'), IS_DEV ? $e->getMessage() : null);
    }
  }

  function show($id) {
    $data = db::table('{tabla_plural}')->find($id);
    if (!$data) response::notFound(__('{miRecurso}.not_found'));
    
    if (isset($data['config']) && is_string($data['config'])) {
      $data['config'] = json_decode($data['config'], true);
    }

    response::success($data);
  }

  function list() {
    $query = db::table('{tabla_plural}');
    
    foreach ($_GET as $key => $value) {
      if (in_array($key, ['page', 'per_page', 'sort', 'order'])) continue;
      $query = $query->where($key, $value);
    }

    $sort = request::query('sort', 'id');
    $order = request::query('order', 'DESC');
    $query = $query->orderBy($sort, $order);

    $page = request::query('page', 1);
    $perPage = request::query('per_page', 50);
    $data = $query->paginate($page, $perPage)->get();

    if (!is_array($data)) $data = [];
    
    foreach ($data as &$item) {
      if (isset($item['config']) && is_string($item['config'])) {
        $item['config'] = json_decode($item['config'], true);
      }
    }

    response::success($data);
  }

  function delete($id) {
    $item = db::table('{tabla_plural}')->find($id);
    if (!$item) response::notFound(__('{miRecurso}.not_found'));

    try {
      $affected = db::table('{tabla_plural}')->where('id', $id)->delete();
      response::success(['affected' => $affected], __('{miRecurso}.delete.success'));
    } catch (Exception $e) {
      response::serverError(__('{miRecurso}.delete.error'), IS_DEV ? $e->getMessage() : null);
    }
  }
}
```

### 4. Routes: `/app/routes/apis/{miRecurso}.php`
```php
<?php
// Las rutas CRUD se auto-registran desde {miRecurso}.json

$router->group('/api/{miRecurso}', function($router) {
  // Rutas personalizadas adicionales aquí
});
```

### 5. Traducciones: `/app/lang/es/{miRecurso}.php`
```php
<?php
return [
  '{campo_requerido}_required' => 'El campo {campo_requerido} es requerido',
  'user_id_required' => 'El campo user_id es requerido',
  'not_found' => '{MiRecurso} no encontrado',
  'create.success' => '{MiRecurso} creado correctamente',
  'create.error' => 'Error al crear {miRecurso}',
  'update.success' => '{MiRecurso} actualizado correctamente',
  'update.error' => 'Error al actualizar {miRecurso}',
  'delete.success' => '{MiRecurso} eliminado correctamente',
  'delete.error' => 'Error al eliminar {miRecurso}'
];
```

### 6. Registrar traducciones: `/app/lang/es.php`
```php
return [
  // ... otras traducciones
  '{miRecurso}' => require $langPath . '{miRecurso}.php',
];
```

---

## FRONTEND

### 1. Index extensión: `/public/extensions/{miRecurso}/index.json`
```json
{
  "name": "{miRecurso}",
  "version": "1.0.0",
  "enabled": true,
  "hasViews": true,
  "hasMenu": true,
  "hasHooks": false,
  "description": "{Descripción de la extensión}",
  "menu": {
    "title": "{Título Menú}",
    "icon": "{emoji}",
    "order": 10,
    "items": [
      {
        "id": "{miRecurso}-listado",
        "title": "Listado",
        "view": "sections/{miRecurso}-listado",
        "order": 1
      }
    ]
  }
}
```

### 2. Listado: `/public/extensions/{miRecurso}/views/sections/{miRecurso}-listado.json`
```json
{
  "id": "{miRecurso}Listado",
  "title": "{i18n:{miRecurso}.listado.title}",
  "type": "content",
  "scripts": ["{miRecurso}/assets/js/{miRecurso}.js"],
  "content": [
    {
      "type": "html",
      "content": "<div class='view-header'><h2>{i18n:{miRecurso}.listado.header.title}</h2><p>{i18n:{miRecurso}.listado.header.description}</p></div>",
      "order": 0
    },
    {
      "type": "html",
      "content": "<div class='view-toolbar'><button class='btn btn-primary' onclick=\"modal.open('{miRecurso}|forms/{miRecurso}-form', {title: '{i18n:{miRecurso}.modal.new.title}', width: '90%', maxWidth: '700px', showFooter: false, afterRender: function(formId){{miRecurso}.openNew(formId);}})\">➕ {i18n:core.add}</button><button class='btn btn-secondary' onclick='{miRecurso}.refresh();toast.info(\"{i18n:{miRecurso}.refresh.success}\")'>🔄 {i18n:core.refresh}</button></div>",
      "order": 1
    },
    {
      "type": "component",
      "component": "datatable",
      "order": 2,
      "config": {
        "source": "api/{miRecurso}",
        "columns": [
          {"id": {"name": "i18n:{miRecurso}.column.id", "width": "80px", "align": "center", "sortable": true}},
          {"name": {"name": "i18n:{miRecurso}.column.name", "sortable": true}},
          {"dc": {"name": "i18n:{miRecurso}.column.created", "format": "datetime", "width": "180px", "align": "center"}}
        ],
        "actions": {
          "edit": {
            "name": "i18n:core.edit",
            "onclick": "modal.open('{miRecurso}|forms/{miRecurso}-form', {title: '{i18n:{miRecurso}.modal.edit.title}', width: '90%', maxWidth: '700px', showFooter: false, afterRender: function(formId){{miRecurso}.openEdit(formId, {id});}})"
          },
          "delete": {
            "name": "i18n:core.delete",
            "onclick": "if(confirm('{i18n:{miRecurso}.confirm.delete}')) {miRecurso}.delete({id}).then(r => r && {miRecurso}.refresh())"
          }
        }
      }
    }
  ]
}
```

### 3. Formulario: `/public/extensions/{miRecurso}/views/forms/{miRecurso}-form.json`
```json
{
  "id": "{miRecurso}-form",
  "title": "i18n:{miRecurso}.form.title",
  "description": "i18n:{miRecurso}.form.description",
  "fields": [
    {
      "name": "name",
      "label": "i18n:{miRecurso}.field.name",
      "type": "text",
      "validation": "required|max:50",
      "placeholder": "i18n:{miRecurso}.field.name.placeholder"
    }
  ],
  "statusbar": [
    {"name": "cancel", "type": "button", "label": "i18n:core.cancel", "action": "call:modal.closeAll", "style": "secondary"},
    {"name": "submit", "type": "button", "label": "i18n:core.save", "action": "submit:{miRecurso}.save"}
  ]
}
```
**✅ Validation patterns**:
- String: `"validation": "required|max:50"` (coincide con resource `maxLength:50`)
- Numeric: `"validation": "required|numeric|min:8|max:20"` (auto-aplica transform + HTML attributes)
- Select: `"required": true` + `"validation": "required"` + first option `value: ""`
- Select Async: `"source": "/api/endpoint"` + `"sourceValue": "id"` + `"sourceLabel": "name"`
- Textarea: `"validation": "max:200"` + `"rows": 4`

**⚠️ Asterisco requerido**: Se muestra si `required: true` O `validation` contiene `"required"`

**✨ Select con carga asíncrona (API)**:
```json
{
  "name": "country_code",
  "label": "i18n:bot.field.country",
  "type": "select",
  "required": true,
  "source": "/api/country?region=america",
  "sourceValue": "code",
  "sourceLabel": "name"
}
```
- `source`: URL de la API que devuelve array de objetos
- `sourceValue`: Campo del objeto que se usará como `value` del option
- `sourceLabel`: Campo del objeto que se mostrará como texto del option
- Se carga automáticamente después de 50ms del render
- Agrega option vacío inicial si el campo es `required`

### 4. JavaScript: `/public/extensions/{miRecurso}/assets/js/{miRecurso}.js`
```javascript
class {miRecurso} {
  static apis = {
    {miRecurso}: '/api/{miRecurso}'
  };

  static currentId = null;

  static openNew(formId) {
    this.currentId = null;
    const formEl = document.getElementById(formId);
    const realId = formEl?.getAttribute('data-real-id') || formId;
    form.clearAllErrors(realId);
  }

  static async openEdit(formId, id) {
    this.currentId = id;
    const formEl = document.getElementById(formId);
    const realId = formEl?.getAttribute('data-real-id') || formId;
    
    form.clearAllErrors(realId);
    const data = await this.get(id);
    if (!data) return;
    
    this.fillForm(formId, data);
  }

  static fillForm(formId, data) {
    form.fill(formId, {
      name: data.name || '',
      description: data.description || ''
    });
  }

  static async save(formId) {
    const validation = form.validate(formId);
    if (!validation.success) return toast.error(validation.message);

    const body = this.buildBody(validation.data);
    if (!body) return;

    const result = this.currentId 
      ? await this.update(this.currentId, body) 
      : await this.create(body);

    if (result) {
      toast.success(this.currentId 
        ? __('{miRecurso}.success.updated') 
        : __('{miRecurso}.success.created')
      );
      setTimeout(() => {
        modal.closeAll();
        this.refresh();
      }, 100);
    }
  }

  static buildBody(formData) {
    const userId = auth.user?.id;
    
    if (!userId) {
      logger.error('ext:{miRecurso}', 'No se pudo obtener el user_id');
      toast.error(__('{miRecurso}.error.user_not_found'));
      return null;
    }

    return {
      user_id: userId,
      name: formData.name,
      description: formData.description
    };
  }

  static async create(data) {
    if (!data) return null;

    try {
      const res = await api.post(this.apis.{miRecurso}, data);
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      logger.error('ext:{miRecurso}', error);
      toast.error(__('{miRecurso}.error.create_failed'));
      return null;
    }
  }

  static async get(id) {
    try {
      const res = await api.get(`${this.apis.{miRecurso}}/${id}`);
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      logger.error('ext:{miRecurso}', error);
      toast.error(__('{miRecurso}.error.load_failed'));
      return null;
    }
  }

  static async update(id, data) {
    if (!data) return null;

    try {
      const res = await api.put(`${this.apis.{miRecurso}}/${id}`, {...data, id});
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      logger.error('ext:{miRecurso}', error);
      toast.error(__('{miRecurso}.error.update_failed'));
      return null;
    }
  }

  static async delete(id) {
    try {
      const res = await api.delete(`${this.apis.{miRecurso}}/${id}`);
      if (res.success === false) {
        toast.error(__('{miRecurso}.error.delete_failed'));
        return null;
      }
      toast.success(__('{miRecurso}.success.deleted'));
      this.refresh();
      return res.data || res;
    } catch (error) {
      logger.error('ext:{miRecurso}', error);
      toast.error(__('{miRecurso}.error.delete_failed'));
      return null;
    }
  }

  static async list() {
    try {
      const res = await api.get(this.apis.{miRecurso});
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      logger.error('ext:{miRecurso}', error);
      return [];
    }
  }

  static refresh() {
    if (window.datatable) datatable.refreshFirst();
  }
}

window.{miRecurso} = {miRecurso};
```

### 5. Traducciones: `/public/extensions/{miRecurso}/lang/es.json`
```json
{
  "{miRecurso}.listado.title": "{Emoji} Listado de {Título}",
  "{miRecurso}.listado.header.title": "{Emoji} {Título Completo}",
  "{miRecurso}.listado.header.description": "{Descripción breve}",
  "{miRecurso}.column.id": "ID",
  "{miRecurso}.column.name": "Nombre",
  "{miRecurso}.column.created": "Fecha de Creación",
  "{miRecurso}.form.title": "Formulario de {Título}",
  "{miRecurso}.form.description": "{Descripción formulario}",
  "{miRecurso}.field.name": "Nombre",
  "{miRecurso}.field.name.placeholder": "Ingrese el nombre",
  "{miRecurso}.modal.new.title": "{Emoji} Nuevo {Título}",
  "{miRecurso}.modal.edit.title": "✏️ Editar {Título}",
  "{miRecurso}.confirm.delete": "¿Eliminar {título}?",
  "{miRecurso}.success.created": "{Título} creado correctamente",
  "{miRecurso}.success.updated": "{Título} actualizado correctamente",
  "{miRecurso}.success.deleted": "{Título} eliminado correctamente",
  "{miRecurso}.error.create_failed": "Error al crear {título}",
  "{miRecurso}.error.update_failed": "Error al actualizar {título}",
  "{miRecurso}.error.delete_failed": "Error al eliminar {título}",
  "{miRecurso}.error.load_failed": "Error al cargar {título}",
  "{miRecurso}.error.user_not_found": "Usuario no identificado",
  "{miRecurso}.refresh.success": "Lista actualizada"
}
```
**⚠️ Formato**: Flat JSON (no anidado), claves con punto (.)
**⚠️ Uso**: `i18n:{miRecurso}.field.name` en JSON, `__('{miRecurso}.error.user_not_found')` en JS

### 6. Registrar extensión: `/public/extensions/index.json`
```json
{
  "extensions": [
    {"name": "admin", "description": "Administración del sistema"},
    {"name": "{miRecurso}", "description": "{Descripción}"}
  ]
}
```

---

## EJEMPLO COMPLETO

### Ejemplo: Crear CRUD "workFlow"
```
✅ Nombre recurso: workFlow
✅ URL API: /api/workFlow
✅ Tabla BD: work_flows
✅ Archivos: workFlow.json, workFlowController.php, workFlow.js
❌ NO usar: work-flow, work_flow en URLs
```

---


## VALORES POR DEFECTO (defaultValue)

Todos los campos pueden tener valores iniciales usando `defaultValue`:

```json
{
  "name": "nombre",
  "type": "text",
  "defaultValue": "Juan Pérez"
}
```

### Tokens Especiales

Genera valores únicos automáticamente:

| Token | Resultado | Ejemplo |
|-------|-----------|---------|
| `{hash:n}` | Hash aleatorio de n caracteres | `{hash:8}` → `a7f3k9m2` |
| `{uuid}` | UUID v4 completo | `550e8400-e29b-41d4-a716-446655440000` |
| `{timestamp}` | Milisegundos desde epoch | `1671321600000` |
| `{date}` | Fecha actual (YYYY-MM-DD) | `2024-12-15` |
| `{time}` | Hora actual (HH:MM:SS) | `14:30:45` |
| `{random:min:max}` | Número aleatorio | `{random:1:100}` → `42` |

**Combinar tokens**:
```json
{
  "name": "codigo",
  "defaultValue": "REF-{date}-{hash:8}",
  "readonly": true
}
// Resultado: REF-2024-12-15-a7f3k9m2
```

**En repeatables**: Los tokens generan valores únicos por cada item nuevo.

**Tipos soportados**:
- ✅ `text`, `number`, `textarea` → String o número
- ✅ `select` → Valor del option
- ✅ `checkbox` → `true` o `false`
- ✅ `repeatable` → Aplica a cada campo hijo

## TIPOS DE CAMPOS SOPORTADOS

### Backend (Resource JSON) ↔️ Frontend (Formulario)
| Backend Type | Frontend Type | Validation | Ejemplo Resource | Ejemplo Form |
|--------------|---------------|------------|------------------|--------------|
| `string` (maxLength) | `text` | `max:N` | `{"name":"title","type":"string","maxLength":50}` | `{"name":"title","type":"text","validation":"max:50"}` |
| `string` (large) | `textarea` | `max:N` | `{"name":"desc","type":"string","maxLength":200}` | `{"name":"desc","type":"textarea","validation":"max:200","rows":4}` |
| `int` | `number` o `text` | `numeric` | `{"name":"age","type":"int"}` | `{"name":"age","type":"text","validation":"numeric"}` |
| `boolean` | `checkbox` | - | `{"name":"active","type":"boolean"}` | `{"name":"active","type":"checkbox"}` |
| `json` | `hidden` + buildBody | - | `{"name":"config","type":"json"}` | Hidden input + construir en JS |

### Validaciones Comunes
**Frontend** (validation string):
- `required` - Campo obligatorio
- `min:N` - Mínimo N caracteres
- `max:N` - Máximo N caracteres (debe coincidir con resource maxLength)
- `email` - Email válido
- `numeric` - Solo números
- `alpha` - Solo letras
- `alphanumeric` - Letras y números

**⚠️ TINYTEXT**: Si el campo BD es TINYTEXT, NO agregar maxLength en resource ni max: en validation