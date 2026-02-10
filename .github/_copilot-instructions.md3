# Instrucciones para GitHub Copilot

## Contexto del Proyecto
Sistema blacksystem con arquitectura MVC personalizada, sistema de extensiones y generación automática de archivos JSON.

## Convenciones de Código

### Backend (PHP)

#### Logging
- Usar `log::error()`, `log::warning()`, `log::info()`, `log::debug()` del helper log.php
- Los logs se guardan en `/backend/app/storage/logs/`
- SIEMPRE incluir contexto con array `['module' => 'nombre']`

#### Arquitectura
- **Controllers**: Extienden de `controller` base, métodos CRUD estándar
- **Handlers**: Lógica especializada (generación archivos, procesamiento)
- **Resources**: Archivos JSON que definen estructura de modelos
- **Helpers**: Funciones utilitarias estáticas (db, file, log, request, response, validation)
- **Middleware**: authMiddleware, corsMiddleware, jsonMiddleware, etc.

#### Acceso a Base de Datos
- Usar helper `db::table('nombre_tabla')` - NO MySQLQueryBuilder
- Métodos disponibles:
  - `db::table('users')->find($id)` - Buscar por ID
  - `db::table('users')->where('campo', 'valor')->first()` - Primera coincidencia
  - `db::table('users')->where()->get()` - Múltiples resultados
  - `db::table('users')->insert($data)` - Insertar
  - `db::table('users')->where()->update($data)` - Actualizar
  - `db::table('users')->where()->delete()` - Eliminar
  - `db::table('users')->paginate($page, $perPage)->get()` - Paginación
  - dentro de `framework/helpers/db.php` hay más métodos útiles como el `whereFilters()`

#### Respuestas API
```php
// Éxito
response::success($data, $message, $statusCode);

// Error servidor
response::serverError($message, $debug);

// No encontrado
response::notFound($message);

// Error validación
response::json(['success' => false, 'error' => $message], 200);
```

#### Traducciones
- Usar `__('modulo.key')` para traducciones
- Archivos en `/backend/app/lang/{es|en}/modulo.php`
- Retornan arrays asociativos

### Base de Datos

#### Nombres de Tablas
- SIEMPRE en inglés, plural cuando aplica
- Ejemplos: `users`, `bots`, `products`, `sessions`, `credentials`, `work_flows`

#### Nombres de Campos
- Campos técnicos en inglés: `id`, `name`, `type`, `mode`, `status`
- Campos de auditoría estándar:
  - `dc` → date creación (DATETIME)
  - `da` → date actualización (DATETIME, nullable)
  - `ta` / `tc` → timestamp creación (INT)
  - `tu` → timestamp actualización (INT, nullable)
  - `user_id` → ID del usuario propietario

#### Campos JSON en BD
- Usar `json_encode($data, JSON_UNESCAPED_UNICODE)` - SIN JSON_PRETTY_PRINT
- Almacenar comprimido: `{"key":"value","array":[1,2,3]}`
- Al leer, usar `json_decode($json, true)` para arrays asociativos
- Campos comunes: `config`, `metadata`

### Sistema de Archivos JSON

#### Archivos Físicos (en /shared/)
- SIEMPRE usar `JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE`
- Estructura con auditoría:
```json
{
  "created": "2025-12-15 18:30:00",
  "module": "bot",
  "action": "create",
  "data": {...}
}
```

#### Rutas Estándar
- Datos de bots: `/shared/bots/data/{number}.json`
- Workflows: `/shared/bots/infoproduct/rapid/workflow_{number}.json`
- Activadores: `/shared/bots/infoproduct/rapid/activators_{number}.json`
- Mensajes: `/shared/bots/infoproduct/messages/{type}_{product_id}.json`

#### Helpers de Archivos
```php
// Guardar JSON con data
file::saveJson($path, $data, $module, $action);

// Guardar JSON con items
file::saveJsonItems($path, $items, $module, $action);

// Leer JSON con reconstrucción automática
file::getJson($path, $reconstructCallback);
```

### Handlers

#### Patrón de Handlers
- Métodos estáticos para operaciones específicas
- Generación de archivos JSON
- Reconstrucción automática si no existen
- Ejemplo:
```php
class botHandlers {
  static function getDataFile($botNumber) {
    $path = SHARED_PATH . '/bots/data/' . $botNumber . '.json';
    return file::getJson($path, function() use ($botNumber) {
      return self::generateDataFile($botNumber);
    });
  }
  
  static function generateDataFile($botNumber, $botData = null, $action = 'create') {
    // Lógica de generación
  }
}
```

### Controllers

#### Estructura Estándar
```php
class moduloController extends controller {
  function __construct() {
    parent::__construct('nombre_recurso');
  }
  
  function create() {
    $data = request::data();
    
    // Validaciones
    if (!isset($data['campo'])) {
      response::json(['success' => false, 'error' => __('modulo.error')], 200);
    }
    
    // Timestamps
    $data['dc'] = date('Y-m-d H:i:s');
    $data['ta'] = time();
    
    // Insertar
    try {
      $id = db::table('tabla')->insert($data);
      
      // Invocar handlers si aplica
      if ($affected > 0) {
        moduloHandler::handleContext($data, 'create');
      }
      
      response::success(['id' => $id], __('modulo.success'), 201);
    } catch (Exception $e) {
      log::error('Controller - Error', ['message' => $e->getMessage()], ['module' => 'modulo']);
      response::serverError(__('modulo.error'), IS_DEV ? $e->getMessage() : null);
    }
  }
}
```

### Frontend (JavaScript)

#### Estructura de Extensiones
- Cada extensión en `/public/extensions/{nombre}/`
- Archivos JSON para definir vistas, formularios, secciones
- JavaScript en `/assets/js/`
- Traducciones en `/lang/{es|en}.json`

#### Clases de Módulos
```javascript
class moduloEntity {
  static apis = {
    endpoint: '/api/endpoint'
  };
  
  static currentId = null;
  static context = 'nombre_contexto';
  
  static async get(id) {
    const res = await api.get(`${this.apis.endpoint}/${id}`);
    return res.success === false ? null : (res.data || res);
  }
  
  static async save(formId) {
    const validation = form.validate(formId);
    if (!validation.success) return toast.error(validation.message);
    
    const body = this.buildBody(validation.data);
    const result = this.currentId
      ? await this.update(this.currentId, body)
      : await this.create(body);
    
    if (result) {
      toast.success(__('modulo.success'));
      modal.closeAll();
      this.refresh();
    }
  }
}
```

#### Componentes Framework
- `api` - Llamadas HTTP
- `form` - Manejo formularios (fill, validate, clearAllErrors)
- `modal` - Modales
- `toast` - Notificaciones
- `datatable` - Tablas de datos
- `auth` - Autenticación (auth.user)
- `logger` - Logs frontend

### Nombres y Estructura

#### Convenciones de Nombres
- **Archivos**: camelCase → `userController.php`, `botHandlers.php`
- **Clases**: PascalCase → `class userController`
- **Métodos**: camelCase → `function getUserData()`
- **Variables**: camelCase → `$userData`, `$botNumber`
- **Constantes**: UPPER_SNAKE_CASE → `SHARED_PATH`, `BASE_PATH`

#### IMPORTANTE
- Nombres de archivos, clases, métodos, variables: SIEMPRE en inglés
- Comentarios: en español
- Keys JSON para datos de usuario: español
- Keys JSON técnicas/sistema: inglés

#### Rutas API
- `/backend/app/routes/apis/{modulo}.php`
- Registradas en `/backend/app/routes/api.php`

#### Extensiones
- Plugin.json define metadatos
- Rutas propias en `/routes/routes.php`
- Autoload desde `/backend/framework/core/extensionLoader.php`

## Reglas Importantes

1. **NO eliminar archivos** sin verificar dependencias
2. **Usar constantes** de `/backend/app/config/consts.php`:
   - `BASE_PATH`, `BACKEND_PATH`, `SHARED_PATH`, `STORAGE_PATH`
   - `TIME_MINUTE`, `TIME_HOUR`, `TIME_DAY`, etc.
3. **NO usar `require_once`** - autoload en `consts.php` ya lo maneja
4. **Espaciado**: 2 espacios para indentación
5. **NO dejar espacios** en blanco al final de líneas
6. **Comentarios existentes**: NO eliminar, solo editar si necesario
7. **Parsear JSON**: Al leer de BD, verificar si es string y hacer `json_decode()`
8. **Reconstrucción**: Archivos JSON deben poder regenerarse automáticamente
9. **Auditoría**: Siempre incluir `created`, `module`, `action` en JSON físicos
10. **Seguridad**: Obtener `user_id` de `$GLOBALS['auth_user_id']`, NO del cliente

## Patrones de Uso Común

### Crear/Actualizar con Handler
```php
// En controller
$data['dc'] = date('Y-m-d H:i:s');
$data['ta'] = time();

$id = db::table('tabla')->insert($data);

if ($id) {
  $data['id'] = $id;
  moduloHandler::handleByContext($data, 'create');
}
```

### Obtener Archivo con Reconstrucción
```php
// En handler
static function getFile($identifier) {
  $path = SHARED_PATH . '/ruta/archivo_' . $identifier . '.json';
  return file::getJson($path, function() use ($identifier) {
    return self::generateFile($identifier, null, 'rebuild');
  });
}
```

### Resolver Relaciones en Handlers
```php
// Resolver IDs a objetos completos
if (isset($data['config']['apis']) && is_array($data['config']['apis'])) {
  foreach ($data['config']['apis'] as $key => $ids) {
    $resolved = [];
    foreach ($ids as $id) {
      $item = db::table('tabla')->find($id);
      if ($item) {
        if (isset($item['config']) && is_string($item['config'])) {
          $item['config'] = json_decode($item['config'], true);
        }
        $resolved[] = $item;
      }
    }
    $data['config']['apis'][$key] = $resolved;
  }
}
```

## Notas de Desarrollo

- **Logs mínimos**: Solo errores y operaciones críticas
- **Código minimalista**: Sin comentarios PHPDoc excesivos en handlers
- **Versatilidad**: Métodos deben funcionar con o sin datos completos
- **Performance**: Usar archivos JSON para datos frecuentes, evitar consultas repetitivas

## Comentarios en metodos/funciones/clases
- no quiero que hagas esto:
```php
/**
 * Esta función hace X, Y, Z.
*/
```
- quiero que hagas esto:
```php
// Esta función hace X, Y, Z.
```
porque necesito consumir menos lineas y quiero que el código sea más limpio
- Los comentarios largos solo cuando sean realmente necesarios para explicar lógica compleja
- Comentarios en español
- Mantener comentarios existentes a menos que sean incorrectos o irrelevantes
- Si el nombre del metodo/clase/función ya es claro, no es necesario agregar un comentario redundante
- Esto aplica para cualquier lenguaje .php, .js, etc...