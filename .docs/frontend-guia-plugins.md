# Guía Práctica: Creación de Extensions Frontend

Esta guía te enseña a crear extensions frontend con ejemplos prácticos. Todo está basado en ejemplos reales del sistema.

---

## Estructura del nucleo del frontend
```
public/
├── extensions/
│   ├── admin/
│   │   ├── assets/
│   │   │   ├── css/
│   │   │   │   └── permissions.css
│   │   │   └── js/
│   │   │       ├── admin-permissions.js
│   │   │       ├── admin.js
│   │   │       └── permissions.js
│   │   ├── lang/
│   │   │   ├── en.json
│   │   │   └── es.json
│   │   ├── views/
│   │   │   └── sections/
│   │   │       └── admin-panel.json
│   │   ├── hooks.js
│   │   └── index.json
│   ├── botmaster/
│   │   ├── assets/
│   │   │   ├── css/
│   │   │   │   └── botmaster.css
│   │   │   └── js/
│   │   │       └── botmaster.js
│   │   ├── lang/
│   │   │   ├── en.json
│   │   │   └── es.json
│   │   ├── mock/
│   │   │   ├── bots.json
│   │   │   ├── families.json
│   │   │   └── tasks.json
│   │   ├── views/
│   │   │   ├── forms/
│   │   │   │   ├── bot-form.json
│   │   │   │   ├── family-form.json
│   │   │   │   └── task-builder.json
│   │   │   └── sections/
│   │   │       ├── bots.json
│   │   │       ├── dashboard.json
│   │   │       ├── families.json
│   │   │       ├── monitor.json
│   │   │       └── tasks.json
│   │   └── index.json
│   ├── ejemplos/
│   │   ├── assets/
│   │   │   ├── css/
│   │   │   │   └── chart.css
│   │   │   └── js/
│   │   │       └── chart.js
│   │   ├── mock/
│   │   │   ├── format-demo.json
│   │   │   └── users-mock.json
│   │   ├── views/
│   │   │   ├── forms/
│   │   │   │   ├── formularios/
│   │   │   │   │   ├── conditions-advanced.json
│   │   │   │   │   ├── conditions-multiple.json
│   │   │   │   │   ├── conditions-repeatable.json
│   │   │   │   │   ├── conditions-simple.json
│   │   │   │   │   ├── form-grouped-fields-column-overflow.json
│   │   │   │   │   ├── form-grouped-fields.json
│   │   │   │   │   ├── form-inputs-normales.json
│   │   │   │   │   ├── form-modal-login.json
│   │   │   │   │   ├── form-modal-register.json
│   │   │   │   │   ├── form-repeatable-grouped-opt1.json
│   │   │   │   │   ├── form-repeatable-grouped-opt2.json
│   │   │   │   │   ├── form-repetibles-anidados.json
│   │   │   │   │   └── form-repetibles-demo.json
│   │   │   │   ├── form-grouper-anidado.json
│   │   │   │   ├── form-grouper-linear.json
│   │   │   │   └── form-grouper-tabs.json
│   │   │   └── sections/
│   │   │       ├── formularios/
│   │   │       │   └── main.json
│   │   │       ├── conditions-demo.json
│   │   │       ├── grouper-demo.json
│   │   │       ├── hooks-caso1.json
│   │   │       ├── hooks-caso2.json
│   │   │       ├── script-bajo-demanda.json
│   │   │       ├── tabs-demo.json
│   │   │       ├── toast-demo.json
│   │   │       ├── users-datatable.json
│   │   │       └── widgets-demo.json
│   │   ├── hooks.js
│   │   └── index.json
├── framework/
│   ├── css/
│   │   ├── components/
│   │   │   ├── dataTable.css
│   │   │   ├── grouper.css
│   │   │   ├── langSelector.css
│   │   │   ├── modal.css
│   │   │   ├── tabs.css
│   │   │   ├── toast.css
│   │   │   └── widget.css
│   │   ├── core/
│   │   │   ├── auth.css
│   │   │   ├── button.css
│   │   │   ├── form.css
│   │   │   ├── layout.css
│   │   │   ├── reset.css
│   │   │   ├── sidebar.css
│   │   │   ├── table.css
│   │   │   ├── vars.css
│   │   │   └── view.css
│   │   └── main.css
│   └── js/
│       ├── components/
│       │   ├── dataTable.js
│       │   ├── grouper.js
│       │   ├── langSelector.js
│       │   ├── modal.js
│       │   ├── tabs.js
│       │   ├── toast.js
│       │   └── widget.js
│       ├── core/
│       │   ├── api.js
│       │   ├── auth.js
│       │   ├── cache.js
│       │   ├── conditions.js
│       │   ├── dataLoader.js
│       │   ├── event.js
│       │   ├── form.js
│       │   ├── hook.js
│       │   ├── i18n.js
│       │   ├── layout.js
│       │   ├── loader.js
│       │   ├── logger.js
│       │   ├── sidebar.js
│       │   ├── validator.js
│       │   └── view.js
│       ├── lang/
│       │   ├── en.json
│       │   └── es.json
│       ├── views/
│       │   ├── auth/
│       │   │   ├── forms/
│       │   │   │   └── login-form.json
│       │   │   └── login.json
│       │   ├── dashboard/
│       │   │   └── dashboard.json
│       │   └── user/
│       │       ├── forms/
│       │       │   └── user-form.json
│       │       ├── mock/
│       │       │   └── mock-users.json
│       │       ├── sections/
│       │       │   └── user-list.json
│       │       └── mock-users.json
│       └── main.js
└── index.html

```
---

## 📁 Estructura de un Extension

```
public/extensions/miExt/
├── index.json              # ⚠️ ARCHIVO PRINCIPAL - Configuración del extension
├── assets/
│   ├── css/
│   │   └── miExtension.css   # Estilos del extension
│   └── js/
│       └── miExntesion.js    # JavaScript del extension
├── lang/
│   ├── es.json            # Traducciones español
│   └── en.json            # Traducciones inglés
└── views/
    ├── sections/          # Vistas principales (dashboard, listados, etc)
    │   ├── dashboard.json
    │   └── listado.json
    └── forms/             # Formularios (crear/editar)
        └── item-form.json
```

---

## 🏗️ Estructura CRUD para Extensions

Para extensions que manejan datos (create, read, update, delete), usa esta estructura normalizada en `assets/js/miExtension.js`:

```javascript
class miExtension {
  // APIs del extension
  static apis = {
    main: '/api/recurso',     // CRUD principal
    options: '/api/opciones'  // APIs auxiliares (selects, etc)
  };

  static currentId = null;

  // FORMULARIOS
  static openNew(formId) { this.currentId = null; }
  static async openEdit(formId, id) { 
    this.currentId = id;
    const data = await this.get(id);
    if (data) this.fillForm(formId, data);
  }
  static fillForm(formId, data) { 
    form.fill(formId, { campo1: data.campo1 }); 
  }

  // GUARDAR
  static async save(formId) {
    const validation = form.validate(formId);
    if (!validation.success) return toast.error(validation.message);
    const body = this.buildBody(validation.data);
    const result = this.currentId 
      ? await this.update(this.currentId, body) 
      : await this.create(body);
    if (result) {
      toast.success(this.currentId ? 'Actualizado' : 'Creado');
      setTimeout(() => { modal.closeAll(); this.refresh(); }, 800);
    }
  }
  static buildBody(formData) { 
    return { campo1: formData.campo1 }; 
  }

  // CRUD (métodos estándar)
  static async create(data) { /* api.post */ }
  static async get(id) { /* api.get */ }
  static async update(id, data) { /* api.put */ }
  static async delete(id) { /* api.delete */ }
  static async list() { /* api.get */ }

  // UTILIDADES
  static refresh() { if (window.datatable) datatable.refreshFirst(); }
}

window.miExtension = miExtension;
```

**Ventajas:**
- ✅ Minimalista y fácil de replicar
- ✅ Validación automática con `form.validate()`
- ✅ Separación clara: formularios, guardar, CRUD, utilidades
- ✅ `buildBody()` centraliza mapeo de datos
- ✅ Actualización automática de datatable

---

## 1️⃣ index.json - Configuración Principal

Este es el archivo más importante. Define el menú, scripts y configuración del extension.

### Ejemplo básico (sin submenús):

```json
{
  "name": "miExtension",           // Nombre único del extension (minúsculas, sin espacios)
  "version": "1.0.0",              // Versión semántica
  "enabled": true,                 // ⚠️ REQUERIDO: true para activar el extension
  "hasMenu": true,                 // Si tiene menú en sidebar
  "hasViews": true,                // Si tiene vistas (archivos JSON en views/)
  "hasHooks": false,               // Si tiene hooks/eventos personalizados
  "description": "Descripción",    // Descripción corta del extension

  "menu": {
    "title": "Mi Extension",          // Título visible en el menú
    "icon": "🔌",                  // Emoji o icono del menú
    "order": 10,                   // Orden de aparición (menor = más arriba)
    "view": "sections/dashboard"   // ⚠️ Ruta DEBE incluir "sections/" si es una vista principal
  }
}
```

**⚠️ Reglas Críticas:**
1. ✅ **`enabled: true` ES REQUERIDO** en index.json del extension para activarlo
2. IDs de menú DEBEN empezar con: `"{nombre-extension}-{id}"` (ej: `"clientes-dashboard"`)
3. ⚠️ **Rutas de vistas DEBEN incluir carpeta:** `"sections/listado"` o `"forms/item"` (NO solo `"listado"`)
4. Keys de traducción en inglés: `field.name` no `field.nombre`
5. En grouper usar `"fields":[]` NO `"content":[]`
6. **Keys de idioma:** usar punto `.` no dos puntos `:` → `"i18n:clientes.field.name"` ✅ no `"i18n:clientes:field.name"` ❌
7. **Keys en lang/*.json:** con prefijo del extension → `"clientes.field.name"` ✅
8. **DataTable:** usar `"source": "ruta"` simple, NO objeto `dataSource` complejo


### Ejemplo con submenús:

```json
{
  "name": "inventario",              // Nombre del extension
  "version": "1.0.0",                // Versión
  "enabled": true,                   // ⚠️ REQUERIDO para activar
  "hasMenu": true,                   // Tiene menú
  "hasViews": true,                  // Tiene vistas
  "hasHooks": true,                  // Tiene hooks personalizados
  "description": "Gestión inventario", // Descripción

  "menu": {
    "title": "Inventario",           // Título del menú principal
    "icon": "📦",                    // Icono
    "order": 10,                     // Orden en sidebar
    "items": [                       // ⚠️ Array de submenús (NO "view" en raíz si hay "items")
      {
        "id": "inventario-listado",  // ⚠️ ID único con prefijo del extension
        "title": "Productos",        // Título visible del submenú
        "view": "sections/listado",  // ⚠️ Ruta completa con "sections/"
        "order": 1                   // Orden dentro del submenú
      },
      {
        "id": "inventario-stock",    // ⚠️ Prefijo consistente
        "title": "Stock",
        "view": "sections/stock",    // ⚠️ Siempre incluir carpeta
        "order": 2
      }
    ]
  }
}
```

### Ejemplo con scripts y estilos cargados al abrir al cargar la pagina (sin importar la vista, y solo es necesario cuando un script se necesita carga a nivel global [muy poco casos]):

```json
{
  "name": "botmaster",                                            // Nombre del extension
  "version": "1.0.0",                                             // Versión
  "enabled": true,                                                // ⚠️ REQUERIDO para activar
  "hasMenu": true,                                                // Tiene menú
  "hasViews": true,                                               // Tiene vistas
  "hasHooks": false,                                              // Hooks personalizados
  "description": "Sistema de automatización de bots",             // Descripción
  "autoload": "extensions/{extension_name}/assets/js/botmaster.js",     // Script principal (carga automática)
  "scripts": ["extensions/{extension_name}/assets/js/helper.js"],       // Scripts adicionales globales
  "styles": ["extensions/{extension_name}/assets/css/botmaster.css"],   // Estilos globales

  "menu": {
    "title": "Botmaster",                                         // Título del menú
    "icon": "🤖",                                                 // Icono
    "order": 10,                                                  // Orden
    "items": [                                                    // Submenús
      {
        "id": "botmaster-dashboard",                              // ⚠️ ID con prefijo
        "title": "Dashboard",
        "view": "sections/dashboard",                             // ⚠️ Incluir "sections/"
        "order": 1
      },
      {
        "id": "botmaster-bots",                                   // ⚠️ ID con prefijo
        "title": "Bots",
        "view": "sections/bots",                                  // ⚠️ Incluir "sections/"
        "order": 2
      }
    ]
  }
}
```

### ⚠️ Importante: Scripts y estilos a nivel de vista

Si quieres cargar scripts/estilos solo cuando se abre una vista específica (mejor performance), ponlos en el JSON de la vista, NO en index.json:

```json
{
  "id": "graficos",
  "title": "Gráficos",
  "scripts": ["extensions/{extension_name}/assets/js/chart.js"],
  "styles": ["extensions/{extension_name}/assets/css/chart.css"],
  "content": [...]
}
```

**Cómo funciona:**
1. Vista renderiza HTML → containers existen en DOM
2. Script se descarga y ejecuta → crea el objeto/clase
3. `view.js` busca automáticamente el método `init()` y lo ejecuta
4. Script renderiza contenido dentro de los containers

**Requisitos del script:**
```javascript
class ejemploChart {
  static init() {  // ← view.js ejecuta esto automáticamente
    const container = document.getElementById('chart1');
    // Crear contenido aquí
  }
}
window.ejemploChart = ejemploChart;  // ← Exportar a window (obligatorio)
```
El nombre del objeto debe coincidir con el archivo: `chart.js` → `ejemploChart` o `chart`.

---

## 2️⃣ Vistas - Estructura y Tipos

### Vista básica con HTML:

```json
{
  "id": "mi-vista",
  "title": "Mi Vista Simple",
  "content": [
    {
      "type": "html",
      "order": 1,
      "content": "<h3>Título</h3><p>Descripción</p>"
    }
  ]
}
```

### Vista con Formulario Dinámico (carga automática):

Puedes cargar formularios directamente dentro de un `type: "html"` usando la clase `dynamic-form`. El sistema lo detecta y lo carga automáticamente.

```json
{
  "id": "login-view",
  "title": "Iniciar Sesión",
  "content": [
    {
      "type": "html",
      "content": "<div class='auth-container'><div class='auth-card'><div class='auth-header'><h1>🔐 Iniciar Sesión</h1><p>Ingresa tus credenciales</p></div><div class='form-container'><div class='dynamic-form' data-form-json='core:auth/forms/login-form'></div></div></div></div>"
    }
  ]
}
```

**Cómo funciona:**
1. El HTML contiene un `<div class='dynamic-form' data-form-json='...'></div>`
2. El atributo `data-form-json` especifica qué formulario cargar
3. El sistema detecta automáticamente estos elementos y carga el formulario en su lugar

**Formatos soportados para `data-form-json`:**
- `'core:auth/forms/login-form'` - Formulario del core
- `'miExtension|forms/item-form'` - Formulario de extension
- `'forms/config'` - Formulario relativo (busca en core o extension según contexto)

**Versión más legible del HTML:**
```html
<div class='auth-container'>
  <div class='auth-card'>
    <div class='auth-header'>
      <h1>🔐 Iniciar Sesión</h1>
      <p>Ingresa tus credenciales</p>
    </div>
    <div class='form-container'>
      <!-- Este div será reemplazado automáticamente con el formulario -->
      <div class='dynamic-form' data-form-json='core:auth/forms/login-form'></div>
    </div>
  </div>
</div>
```

**💡 Ventajas de formularios dinámicos:**
- Permite diseñar layouts personalizados alrededor del formulario
- Combina HTML estático con formularios funcionales
- Útil para páginas de login, registro, o formularios con diseños especiales
- Se integra perfectamente con el sistema de validación

### Vista con Componente Dinámico:

Similar a los formularios, puedes cargar componentes dinámicamente:

```json
{
  "id": "dashboard-custom",
  "title": "Dashboard Personalizado",
  "content": [
    {
      "type": "html",
      "content": "<div class='custom-layout'><h2>Mis Estadísticas</h2><div class='dynamic-component' data-component='widget' data-config='{\"title\":\"Widget\",\"html\":\"<p>Contenido</p>\"}'></div></div>"
    }
  ]
}
```

**Atributos:**
- `class='dynamic-component'` - Marca el elemento como componente dinámico
- `data-component='nombre'` - Nombre del componente a cargar (widget, datatable, etc)
- `data-config='{...}'` - Configuración JSON del componente

### Vista con Widgets (Dashboard):

```json
{
  "id": "botmaster-dashboard",
  "title": "Dashboard",
  "content": [
    {
      "type": "component",
      "component": "widget",
      "order": 1,
      "config": {
        "title": "Total Usuarios",
        "dataSource": {
          "type": "auto",
          "api": {
            "endpoint": "/api/users/count",
            "method": "GET"
          }
        },
        "html": "<div class='widget-stat'><h2>{{total}}</h2><p>Usuarios</p></div>"
      }
    },
    {
      "type": "component",
      "component": "widget",
      "order": 2,
      "config": {
        "title": "Widget con HTML estático",
        "html": "<div class='widget-info'><h3>🎯 Bienvenido</h3><p>Este widget no necesita cargar datos de un endpoint.</p><p>Es solo contenido HTML estático.</p></div>"
      }
    },
    {
      "type": "component",
      "component": "widget",
      "order": 3,
      "config": {
        "title": "Ventas del Mes",
        "dataSource": {
          "type": "auto",
          "api": {
            "endpoint": "/api/sales/monthly",
            "method": "GET"
          }
        },
        "html": "<div class='widget-stat'><h2>${{amount}}</h2><p>Total</p></div>"
      }
    }
  ]
}
```

### Vista con DataTable (Listado):

```json
{
  "id": "productos-listado",
  "title": "Productos",
  "content": [
    {
      "type": "html",
      "order": 1,
      "content": "<div class='view-toolbar'><button class='btn btn-primary' onclick=\"modal.open('inventario|forms/producto', {title: 'Nuevo Producto'})\">➕ Nuevo</button></div>"
    },
    {
      "type": "component",
      "component": "datatable",
      "order": 2,
      "config": {
        "extensionName": "inventario",
        "dataSource": {
          "type": "auto",
          "api": {
            "endpoint": "/api/productos",
            "method": "GET"
          }
        },
        "columns": ["id", "nombre", "categoria", "precio", "cantidad"],
        "actions": {
          "edit": {
            "name": "✏️ Editar",
            "dataLoader": {
              "type": "auto",
              "api": {
                "endpoint": "/api/productos/{id}",
                "method": "GET"
              }
            },
            "onclick": "modal.openWithData('inventario|forms/producto', {id: {id}, title: 'Editar Producto'})"
          },
          "delete": {
            "name": "🗑️ Eliminar",
            "role": "admin", // opcional
            "onclick": "if(confirm('¿Eliminar {nombre}?')) { api.delete('/api/productos/{id}').then(() => { toast.success('Eliminado'); datatable.refreshFirst(); }); }"
          }
        }
      }
    }
  ]
}
```

**⚠️ Actions con validación de role:**
- Agrega `"role": "admin"` en cualquier action para mostrarla solo a usuarios con ese rol
- Sin `role` → visible para todos los usuarios
- Con `role` → visible solo si `window.auth.user.role` coincide exactamente

### Vista con Tabs:

```json
{
  "id": "configuracion",
  "title": "Configuración",
  "tabs": [
    {
      "id": "general",
      "title": "General",
      "content": [
        {
          "type": "form",
          "form_json": "forms/config-general"
        }
      ]
    },
    {
      "id": "avanzado",
      "title": "Avanzado",
      "content": [
        {
          "type": "html",
          "content": "<h4>Configuración Avanzada</h4>"
        },
        {
          "type": "component",
          "component": "datatable",
          "config": {
            "extensionName": "admin",
            "columns": ["key", "value"],
            "dataSource": {
              "type": "auto",
              "api": {"endpoint": "/api/settings"}
            }
          }
        }
      ]
    }
  ]
}
```

---

## 3️⃣ Formularios

### Formulario básico:

```json
{
  "id": "producto-form",
  "title": "Formulario de Producto",
  "fields": [
    {
      "name": "nombre",
      "label": "Nombre",
      "type": "text",
      "required": true,
      "validation": "required|min:3"
    },
    {
      "name": "precio",
      "label": "Precio",
      "type": "number",
      "required": true,
      "validation": "required|numeric"
    },
    {
      "name": "categoria",
      "label": "Categoría",
      "type": "select",
      "required": true,
      "options": [
        {"value": "electronica", "label": "Electrónica"},
        {"value": "ropa", "label": "Ropa"},
        {"value": "alimentos", "label": "Alimentos"}
      ]
    },
    {
      "name": "descripcion",
      "label": "Descripción",
      "type": "textarea",
      "rows": 4
    },
    {
      "name": "activo",
      "label": "Activo",
      "type": "checkbox"
    }
  ]
}
```

### Formulario con campos repetibles (Repeatable):

```json
{
  "id": "blog-form",
  "title": "Formulario de Blog",
  "fields": [
    {
      "name": "nombre",
      "label": "Nombre del Blog",
      "type": "text",
      "required": true
    },
    {
      "name": "sources",
      "label": "Fuentes de Scraping",
      "type": "repeatable",
      "addButtonPosition": "bottom",
      "addButtonText": "➕ Agregar Fuente",
      "fields": [
        {
          "name": "nombre",
          "label": "Nombre de la Fuente",
          "type": "text",
          "required": true
        },
        {
          "name": "url_principal",
          "label": "URL Principal",
          "type": "text",
          "required": true
        },
        {
          "name": "url_listado",
          "label": "URL del Listado",
          "type": "text",
          "required": true
        },
        {
          "name": "selector_titulo",
          "label": "Selector del Título (XPath)",
          "type": "text",
          "placeholder": "//h1"
        }
      ]
    }
  ]
}
```

### Formulario con repetibles anidados (Nested Repeatable):

```json
{
  "id": "proyectos-form",
  "title": "Gestión de Proyectos",
  "fields": [
    {
      "name": "proyectos",
      "label": "Proyectos",
      "type": "repeatable",
      "addButtonText": "➕ Agregar Proyecto",
      "fields": [
        {
          "name": "nombre_proyecto",
          "label": "Nombre del Proyecto",
          "type": "text",
          "required": true
        },
        {
          "name": "tareas",
          "label": "Tareas",
          "type": "repeatable",
          "addButtonText": "➕ Agregar Tarea",
          "fields": [
            {
              "name": "nombre_tarea",
              "label": "Nombre de la Tarea",
              "type": "text"
            },
            {
              "name": "completada",
              "label": "Completada",
              "type": "checkbox"
            }
          ]
        }
      ]
    }
  ]
}
```

**Cargar datos en repetibles anidados:**
```javascript
// Estructura de datos JSON
const mockData = {
  proyectos: [
    {
      nombre_proyecto: "Sistema Web",
      tareas: [
        { nombre_tarea: "Diseño", completada: true },
        { nombre_tarea: "Desarrollo", completada: false }
      ]
    },
    {
      nombre_proyecto: "App Mobile",
      tareas: [
        { nombre_tarea: "Prototipo", completada: true }
      ]
    }
  ]
};

// Cargar datos en el formulario
form.fill('proyectos-form', mockData);
```

**💡 Notas:**
- Soporta **infinitos niveles** de anidación
- Los datos se cargan **secuencialmente** con delays automáticos
- Los paths se construyen automáticamente: `proyectos[0].tareas[1].nombre_tarea`

### Formulario con agrupación (Grouper - Acordeón):

```json
{
  "id": "config-form",
  "title": "Configuración",
  "fields": [
    {
      "name": "nombre",
      "label": "Nombre",
      "type": "text"
    },
    {
      "type": "grouper",
      "mode": "linear",
      "collapsible": true,
      "openFirst": true,
      "groups": [
        {
          "title": "📧 Configuración de Email",
          "fields": [
            {
              "name": "email_host",
              "label": "Host SMTP",
              "type": "text"
            },
            {
              "name": "email_port",
              "label": "Puerto",
              "type": "number"
            }
          ]
        },
        {
          "title": "🔐 Seguridad",
          "fields": [
            {
              "name": "ssl_enabled",
              "label": "Habilitar SSL",
              "type": "checkbox"
            }
          ]
        }
      ]
    }
  ]
}
```

### Formulario con agrupación (Grouper - Tabs):

```json
{
  "id": "perfil-form",
  "title": "Perfil de Usuario",
  "fields": [
    {
      "type": "grouper",
      "mode": "tabs",
      "groups": [
        {
          "title": "Datos Personales",
          "fields": [
            {
              "name": "nombre",
              "label": "Nombre",
              "type": "text"
            },
            {
              "name": "email",
              "label": "Email",
              "type": "email"
            }
          ]
        },
        {
          "title": "Dirección",
          "fields": [
            {
              "name": "calle",
              "label": "Calle",
              "type": "text"
            },
            {
              "name": "ciudad",
              "label": "Ciudad",
              "type": "text"
            }
          ]
        }
      ]
    }
  ]
}
```

### Formulario con columnas (Group):

```json
{
  "id": "producto-form",
  "title": "Producto",
  "fields": [
    {
      "type": "group",
      "columns": 2,
      "gap": "normal",
      "fields": [
        {
          "name": "nombre",
          "label": "Nombre",
          "type": "text"
        },
        {
          "name": "precio",
          "label": "Precio",
          "type": "number"
        }
      ]
    },
    {
      "name": "descripcion",
      "label": "Descripción",
      "type": "textarea"
    }
  ]
}
```

### Formulario con Validaciones:

```json
{
  "id": "user-form",
  "title": "Formulario de Usuario",
  "fields": [
    {
      "name": "nombre",
      "label": "Nombre",
      "type": "text",
      "required": true,
      "validation": "required|min:3|max:50"
    },
    {
      "name": "email",
      "label": "Email",
      "type": "email",
      "required": true,
      "validation": "required|email"
    },
    {
      "name": "edad",
      "label": "Edad",
      "type": "number",
      "validation": "number|minValue:18|maxValue:100"
    },
    {
      "name": "website",
      "label": "Sitio Web",
      "type": "text",
      "validation": "url"
    },
    {
      "name": "username",
      "label": "Usuario",
      "type": "text",
      "validation": "required|alpha_num|min:4"
    }
  ]
}
```

**Validaciones disponibles:**
- `required` - Campo obligatorio
- `email` - Email válido
- `min:n` - Mínimo n caracteres
- `max:n` - Máximo n caracteres
- `minValue:n` - Valor mínimo (números)
- `maxValue:n` - Valor máximo (números)
- `number` - Solo números
- `url` - URL válida
- `alpha_num` - Solo letras y números

**Nota:** También puedes usar `"required": true` como propiedad booleana, pero `validation` permite combinar múltiples reglas.

### Formulario con Condiciones (campos condicionales):

Los campos pueden mostrarse u ocultarse según el valor de otros campos usando el sistema de `conditions`.

```json
{
  "id": "proyecto-form",
  "title": "Formulario de Proyecto",
  "fields": [
    {
      "name": "tipo_proyecto",
      "label": "Tipo de Proyecto",
      "type": "select",
      "required": true,
      "options": [
        {"value": "web", "label": "Web"},
        {"value": "mobile", "label": "Mobile"},
        {"value": "desktop", "label": "Desktop"}
      ]
    },
    {
      "name": "url_proyecto",
      "label": "URL del Proyecto",
      "type": "text",
      "placeholder": "https://...",
      "condition": [
        {"field": "tipo_proyecto", "operator": "==", "value": "web"}
      ],
      "conditionContext": "form",
      "conditionLogic": "AND"
    },
    {
      "name": "plataforma",
      "label": "Plataforma",
      "type": "select",
      "options": [
        {"value": "ios", "label": "iOS"},
        {"value": "android", "label": "Android"}
      ],
      "condition": [
        {"field": "tipo_proyecto", "operator": "==", "value": "mobile"}
      ]
    },
    {
      "name": "requiere_api",
      "label": "¿Requiere API?",
      "type": "checkbox"
    },
    {
      "name": "url_api",
      "label": "URL del API",
      "type": "text",
      "condition": [
        {"field": "requiere_api", "operator": "==", "value": true}
      ]
    }
  ]
}
```

**Operadores de condiciones disponibles:**
- `==` - Igual a
- `!=` - Diferente de
- `>` - Mayor que
- `<` - Menor que
- `>=` - Mayor o igual
- `<=` - Menor o igual
- `any` - Valor está en lista (ej: "admin,manager")
- `not-any` - Valor NO está en lista
- `empty` - Campo vacío
- `not-empty` - Campo NO vacío
- `contains` - Contiene texto
- `not-contains` - NO contiene texto

**Contextos de condiciones:**
- `form` - Busca el campo en todo el formulario (default)
- `repeatable` - Solo dentro del item del repeatable (útil para repetibles)
- `group` - Dentro del grupo más cercano
- `view` - En todo el documento

**Lógica de condiciones:**
- `AND` - Todas las condiciones deben cumplirse (default)
- `OR` - Al menos una condición debe cumplirse

### Condiciones con Repetibles:

```json
{
  "id": "presupuesto-form",
  "fields": [
    {
      "name": "items",
      "label": "Items del Presupuesto",
      "type": "repeatable",
      "addButtonText": "➕ Agregar Item",
      "fields": [
        {
          "name": "tipo",
          "label": "Tipo",
          "type": "select",
          "options": [
            {"value": "producto", "label": "Producto"},
            {"value": "servicio", "label": "Servicio"}
          ]
        },
        {
          "name": "sku",
          "label": "SKU",
          "type": "text",
          "condition": [
            {"field": "tipo", "operator": "==", "value": "producto"}
          ],
          "conditionContext": "repeatable"
        },
        {
          "name": "horas",
          "label": "Horas",
          "type": "number",
          "condition": [
            {"field": "tipo", "operator": "==", "value": "servicio"}
          ],
          "conditionContext": "repeatable"
        }
      ]
    }
  ]
}
```

**⚠️ Importante:** Cuando uses condiciones dentro de repetibles, asegúrate de usar `"conditionContext": "repeatable"` para que cada item evalúe las condiciones de forma independiente.

---

## 4️⃣ Rutas y Referencias

### Cargar vistas del extension actual:

```javascript
// Desde index.json del extension
"view": "dashboard"  // ✅ Relativo al extension

// Desde código JavaScript
modal.open('miExtension|sections/detalle')  // ✅ Especificando extension
```

### Cargar vistas del core:

```javascript
modal.open('core:dashboard/dashboard')
view.loadView('core:user/sections/user-list')
```

### Cargar formularios del extension:

```javascript
modal.open('inventario|forms/producto')
modal.openWithData('botmaster|forms/bot-form', {id: 123})
```

### Cargar formularios del core:

```javascript
modal.open('core:user/forms/user-form')
```

---

## 5️⃣ Anidación y Recursividad

El sistema permite anidar componentes de forma recursiva:

### Vista → Tabs → DataTable:

```json
{
  "id": "panel",
  "title": "Panel de Control",
  "tabs": [
    {
      "id": "usuarios",
      "title": "Usuarios",
      "content": [
        {
          "type": "component",
          "component": "datatable",
          "config": {
            "extensionName": "admin",
            "columns": ["id", "nombre", "email"],
            "dataSource": {
              "type": "auto",
              "api": {"endpoint": "/api/users"}
            }
          }
        }
      ]
    }
  ]
}
```

### Vista → HTML → Modal con Formulario:

```json
{
  "id": "vista-principal",
  "title": "Principal",
  "content": [
    {
      "type": "html",
      "content": "<button onclick=\"modal.open('miExtension|forms/crear', {title: 'Crear Item'})\">Crear</button>"
    }
  ]
}
```

### Vista → Tabs → Form → Grouper → Repeatable:

```json
{
  "id": "configuracion-avanzada",
  "tabs": [
    {
      "id": "config",
      "title": "Configuración",
      "content": [
        {
          "type": "form",
          "form_json": "forms/config"
        }
      ]
    }
  ]
}
```

**Y el formulario (forms/config.json):**
```json
{
  "id": "config-form",
  "fields": [
    {
      "type": "grouper",
      "mode": "tabs",
      "groups": [
        {
          "title": "Fuentes",
          "fields": [
            {
              "name": "fuentes",
              "type": "repeatable",
              "addButtonText": "Agregar",
              "fields": [
                {"name": "url", "type": "text"}
              ]
            }
          ]
        }
      ]
    }
  ]
}
```

---

## 6️⃣ Componentes del Sistema

### Modal - Ventanas Emergentes

El sistema de modales permite abrir formularios, vistas completas y HTML en ventanas emergentes.

**Abrir modal con formulario nuevo:**
```javascript
modal.open('miExtension|forms/item-form', {
  title: 'Nuevo Item',
  width: '80%',
  maxWidth: '900px'
})
```

**Abrir modal con formulario para editar (carga datos automáticamente):**
```javascript
modal.openWithData('miExtension|forms/item-form', {
  id: 123,
  title: 'Editar Item',
  width: '70%'
})
```

**Abrir modal con una vista (section) completa:**
```javascript
// Vista de extension
modal.open('miExtension|sections/detalle', {
  title: 'Detalles del Item',
  width: '90%',
  maxWidth: '1200px'
})

// Vista del core
modal.open('core:sections/dashboard', {
  title: 'Dashboard',
  showFooter: false
})

// Vista simple
modal.open('sections/report', {
  title: 'Reporte'
})
```

**Abrir modal con HTML:**
```javascript
modal.open('<div><h3>Título</h3><p>Contenido HTML</p></div>', {
  html: true,
  title: 'Información',
  showFooter: false
})
```

**Cerrar modal:**
```javascript
modal.closeAll()  // Cierra todos los modales
modal.close(modalId)  // Cierra un modal específico
```

**💡 Tip:** Las vistas (sections) en modales pueden contener DataTables, widgets, tabs, formularios y cualquier otro componente. Es útil para mostrar información detallada sin salir de la vista actual.

### Toast - Notificaciones

Sistema de notificaciones emergentes no intrusivas.

```javascript
// Tipos de notificaciones
toast.success('Operación exitosa');
toast.error('Ha ocurrido un error');
toast.warning('Advertencia importante');
toast.info('Información adicional');

// Con duración personalizada (en milisegundos)
toast.success('Guardado', { duration: 5000 });

// Con posición personalizada
toast.info('Mensaje', {
  position: 'top-right'  // top-right|top-left|bottom-right|bottom-left
});
```

### Grouper - Agrupación de Campos (en formularios)

Ya explicado en la sección de formularios. Permite agrupar campos en tabs o acordeón.

**Carga dinámica en grouper:**
```json
{
  "type": "grouper",
  "mode": "linear",
  "groups": [
    {
      "title": "📝 Formulario",
      "content": "<div class='dynamic-form' data-form-json='extension/forms/form1'></div>"
    },
    {
      "title": "📊 HTML",
      "content": "<div>Contenido HTML estático</div>"
    }
  ]
}
```
Los formularios se cargan al expandir/abrir el grupo (lazy loading).

### Tabs - Sistema de Pestañas (en vistas)

Las vistas pueden tener tabs para organizar contenido:

```json
{
  "id": "mi-vista",
  "title": "Vista con Tabs",
  "tabs": [
    {
      "id": "tab1",
      "title": "Información General",
      "content": [
        {
          "type": "html",
          "content": "<h3>Contenido del Tab 1</h3>"
        }
      ]
    },
    {
      "id": "tab2",
      "title": "Configuración",
      "content": [
        {
          "type": "form",
          "form_json": "forms/config"
        }
      ]
    },
    {
      "id": "tab3",
      "title": "Datos",
      "content": [
        {
          "type": "component",
          "component": "datatable",
          "config": {
            "extensionName": "miExtension",
            "columns": ["id", "nombre"],
            "dataSource": {
              "type": "auto",
              "api": {"endpoint": "/api/datos"}
            }
          }
        }
      ]
    }
  ]
}
```

**Tabs anidados:**
```json
{
  "type": "component",
  "component": "tabs",
  "config": {
    "id": "tabs-nivel-1",
    "tabs": [
      {
        "id": "tab1",
        "title": "Productos",
        "content": [
          {
            "type": "component",
            "component": "tabs",
            "config": {
              "id": "tabs-nivel-2",
              "tabs": [
                {"id": "cat1", "title": "Electrónica", "content": [...]},
                {"id": "cat2", "title": "Ropa", "content": [...]}
              ]
            }
          }
        ]
      }
    ]
  }
}
```

**Precarga total (opcional):**
```json
{
  "id": "mi-vista",
  "type": "tabs",
  "preloadAllTabs": true,
  "tabs": [...]
}
```
Con `preloadAllTabs: true` todas las tabs se cargan al inicio (cambio instantáneo). Sin ella, carga bajo demanda (default).

---

## 7️⃣ Acciones Comunes

### Abrir modal con formulario nuevo:

```javascript
modal.open('miExtension|forms/item-form', {
  title: 'Nuevo Item'
})
```

### Abrir modal con formulario para editar:

```javascript
modal.openWithData('miExtension|forms/item-form', {
  id: 123,
  title: 'Editar Item'
})
```

### Abrir modal con una vista completa:

```javascript
// Útil para mostrar detalles, reportes, o información compleja, aparte de poder combinar form dentro de la vista sections tambien.
modal.open('inventario|sections/detalle-producto', {
  title: 'Detalle del Producto',
  width: '90%',
  showFooter: false
})
```

### Eliminar con confirmación:

```javascript
if(confirm('¿Eliminar?')) {
  api.delete('/api/items/123').then(() => {
    toast.success('Eliminado');
    datatable.refreshFirst();
  }).catch(e => {
    toast.error('Error al eliminar');
  });
}
```

### Llamada API personalizada:

```javascript
api.post('/api/blog/123/scrape').then(() => {
  toast.success('Scraping iniciado');
}).catch(e => {
  toast.error('Error: ' + e.message);
});
```

---

## 8️⃣ DataSource - Carga de Datos

### Solo API:

```json
{
  "type": "api",
  "api": {
    "endpoint": "/api/items",
    "method": "GET"
  }
}
```

### Solo Mock (datos locales):

```json
{
  "type": "mock",
  "mock": {
    "file": "mock/items.json"
  }
}
```

### Auto (API con fallback a Mock):

```json
{
  "type": "auto",
  "api": {
    "endpoint": "/api/items",  // ⚠️ Si el endpoint termina en .json, se cargará como archivo JSON estático
    "method": "GET"
  },
  "mock": {
    "file": "mock/items.json"  // Fallback si falla el API
  }
}
```

**Nota importante:** El componente `datatable` detecta automáticamente si un endpoint termina en `.json` y lo carga como archivo estático en lugar de hacer una llamada API. Ejemplo:
- `/api/items` → Llamada API al backend
- `data/items.json` → Carga de archivo JSON estático
- `/api/items.json` → Carga de archivo JSON estático (no es API)

---

## 9️⃣ Registrar el Extension

Una vez creado el extension, registrarlo en `/public/extensions/index.json`:

```json
{
  "extensions": [
    "admin",
    "botmaster",
    "ejemplos",
    "inventario",
    "miExtension"
  ]
}
```

---

## 🔟 Ejemplo Completo: Extension "TaskManager"

### Estructura:
```
public/extensions/taskmanager/
├── index.json
├── assets/
│   ├── css/
│   │   └── taskmanager.css
│   └── js/
│       └── taskmanager.js
├── lang/
│   ├── es.json
│   └── en.json
└── views/
    ├── sections/
    │   ├── dashboard.json
    │   └── tasks.json
    └── forms/
        └── task-form.json
```

### index.json:
```json
{
  "name": "taskmanager",
  "version": "1.0.0",

  "hasMenu": true,
  "hasViews": true,
  "styles": ["assets/css/taskmanager.css"],
  "scripts": ["assets/js/taskmanager.js"],
  "menu": {
    "title": "Tareas",
    "icon": "✅",
    "order": 15,
    "items": [
      {
        "id": "botmaster-dashboard",
        "title": "Dashboard",
        "view": "dashboard",
        "order": 1
      },
      {
        "id": "tasks",
        "title": "Mis Tareas",
        "view": "tasks",
        "order": 2
      }
    ]
  }
}
```

### views/sections/dashboard.json:
```json
{
  "id": "taskmanager-dashboard",
  "title": "Dashboard de Tareas",
  "content": [
    {
      "type": "component",
      "component": "widget",
      "order": 1,
      "config": {
        "title": "Tareas Pendientes",
        "dataSource": {
          "type": "auto",
          "api": {
            "endpoint": "/api/tasks/count?status=pending",
            "method": "GET"
          }
        },
        "html": "<div class='widget-stat'><h2>{{count}}</h2><p>Pendientes</p></div>"
      }
    },
    {
      "type": "component",
      "component": "widget",
      "order": 2,
      "config": {
        "title": "Completadas Hoy",
        "dataSource": {
          "type": "auto",
          "api": {
            "endpoint": "/api/tasks/count?status=completed&period=today",
            "method": "GET"
          }
        },
        "html": "<div class='widget-stat'><h2>{{count}}</h2><p>Hoy</p></div>"
      }
    }
  ]
}
```

### views/sections/tasks.json:
```json
{
  "id": "taskmanager-tasks",
  "title": "Gestión de Tareas",
  "content": [
    {
      "type": "html",
      "order": 1,
      "content": "<div class='view-toolbar'><button class='btn btn-primary' onclick=\"modal.open('taskmanager|forms/task-form', {title: '➕ Nueva Tarea'})\">➕ Nueva Tarea</button></div>"
    },
    {
      "type": "component",
      "component": "datatable",
      "order": 2,
      "config": {
        "extensionName": "taskmanager",
        "dataSource": {
          "type": "auto",
          "api": {
            "endpoint": "/api/tasks",
            "method": "GET"
          }
        },
        "columns": ["id", "title", "status", "priority", "due_date"],
        "actions": {
          "edit": {
            "name": "✏️ Editar",
            "dataLoader": {
              "type": "auto",
              "api": {
                "endpoint": "/api/tasks/{id}",
                "method": "GET"
              }
            },
            "onclick": "modal.openWithData('taskmanager|forms/task-form', {id: {id}, title: 'Editar Tarea'})"
          },
          "complete": {
            "name": "✓ Completar",
            "onclick": "api.post('/api/tasks/{id}/complete').then(() => { toast.success('Tarea completada'); datatable.refreshFirst(); });"
          },
          "delete": {
            "name": "🗑️ Eliminar",
            "onclick": "if(confirm('¿Eliminar tarea?')) { api.delete('/api/tasks/{id}').then(() => { toast.success('Eliminado'); datatable.refreshFirst(); }); }"
          }
        }
      }
    }
  ]
}
```

### views/forms/task-form.json:
```json
{
  "id": "task-form",
  "title": "Formulario de Tarea",
  "fields": [
    {
      "name": "title",
      "label": "Título",
      "type": "text",
      "required": true,
      "validation": "required|min:3"
    },
    {
      "name": "description",
      "label": "Descripción",
      "type": "textarea",
      "rows": 4
    },
    {
      "type": "group",
      "columns": 2,
      "fields": [
        {
          "name": "status",
          "label": "Estado",
          "type": "select",
          "options": [
            {"value": "pending", "label": "Pendiente"},
            {"value": "in_progress", "label": "En Progreso"},
            {"value": "completed", "label": "Completada"}
          ]
        },
        {
          "name": "priority",
          "label": "Prioridad",
          "type": "select",
          "options": [
            {"value": "low", "label": "Baja"},
            {"value": "medium", "label": "Media"},
            {"value": "high", "label": "Alta"}
          ]
        }
      ]
    },
    {
      "name": "due_date",
      "label": "Fecha de Vencimiento",
      "type": "date"
    },
    {
      "name": "subtasks",
      "label": "Subtareas",
      "type": "repeatable",
      "addButtonText": "➕ Agregar Subtarea",
      "fields": [
        {
          "name": "title",
          "label": "Título de Subtarea",
          "type": "text",
          "required": true
        },
        {
          "name": "completed",
          "label": "Completada",
          "type": "checkbox"
        }
      ]
    }
  ]
}
```

### lang/es.json (Sistema de traducciones semánticas):

**⚠️ IMPORTANTE:** Usa keys semánticas descriptivas en lugar de valores literales. Esto hace el código más mantenible y fácil de interpretar.

```json
{
  "task.column.id": "ID",
  "task.column.title": "Título",
  "task.column.status": "Estado",
  "task.column.priority": "Prioridad",
  "task.column.due_date": "Fecha de Vencimiento",
  "task.action.edit": "Editar Tarea",
  "task.action.delete": "Eliminar Tarea",
  "task.action.complete": "Marcar como Completada",
  "task.status.pending": "Pendiente",
  "task.status.in_progress": "En Progreso",
  "task.status.completed": "Completada",
  "task.priority.low": "Baja",
  "task.priority.medium": "Media",
  "task.priority.high": "Alta",
  "task.title.tasks": "Gestión de Tareas",
  "task.title.dashboard": "Dashboard de Tareas",
  "task.description.tasks": "Administra tus tareas diarias",
  "task.button.new_task": "Nueva Tarea",
  "task.message.task_completed": "Tarea completada exitosamente",
  "task.message.task_deleted": "Tarea eliminada",
  "task.message.confirm_delete": "¿Está seguro de eliminar esta tarea?"
}
```

**Uso en el código:**
```javascript
// En lugar de texto hardcodeado:
toast.success('Tarea completada');

// Usar traducciones:
toast.success(__('taskmanager:message.task_completed'));

// En formularios JSON:
{
  "label": "i18n:taskmanager:task.column.title",
  "placeholder": "i18n:taskmanager:placeholder.enter_title",
  "onclick": "toast.info(\"i18n:task.message.task_deleted\")"
}
```

---

## 🔑 Reglas de Oro

1. **Nombres de componentes en minúsculas:** `"datatable"` no `"dataTable"`
2. **DataTable requiere `extensionName`:** Siempre especificar el extension
3. **Columnas como array simple:** `["id", "nombre"]` no objetos
4. **Acciones con `onclick`:** Código JavaScript ejecutable
5. **Rutas relativas en index.json:** `"sections/dashboard"` no `"miExtension/sections/dashboard"`
6. **Content es array:** Todo va dentro del array `content`
7. **Registrar en extensions/index.json:** No olvides agregarlo a la lista

---

## 📚 Referencia Rápida de Tipos

### Tipos de content:
- `"type": "html"` - HTML directo
- `"type": "component"` - Componente (widget, datatable)
- `"type": "form"` - Formulario

**💡 Formularios y componentes dinámicos:**
También puedes cargar formularios y componentes dentro de HTML usando:
- `<div class='dynamic-form' data-form-json='ruta/formulario'></div>` - Carga formulario automáticamente
- `<div class='dynamic-component' data-component='nombre' data-config='{...}'></div>` - Carga componente automáticamente

### Componentes disponibles:
- `"component": "widget"` - Widgets para dashboard
- `"component": "datatable"` - Tablas de datos
- `modal` - Sistema de ventanas emergentes (ver sección Acciones Comunes)
- `grouper` - Agrupación de campos en formularios (tabs/acordeón)
- `toast` - Notificaciones emergentes
- `tabs` - Sistema de pestañas para vistas

### Tipos de campos de formulario:
- `"type": "text"` - Texto simple
- `"type": "email"` - Email
- `"type": "number"` - Número
- `"type": "textarea"` - Texto largo
- `"type": "select"` - Selector
- `"type": "checkbox"` - Casilla
- `"type": "radio"` - Opciones
- `"type": "date"` - Fecha
- `"type": "repeatable"` - Campos repetibles
- `"type": "grouper"` - Agrupación (tabs/acordeón)
- `"type": "group"` - Columnas

### Validaciones:
- `"required"` - Obligatorio
- `"email"` - Email válido
- `"min:n"` - Mínimo n caracteres
- `"max:n"` - Máximo n caracteres
- `"numeric"` - Solo números


---

## 📊 DataTable - Configuración de Source

### Reglas para `source`:

1. **API Endpoints** (sin `.json`)
   ```json
   {
     "source": "api/productos"
   }
   ```
   → Llama a: `/api/productos`

2. **Archivos JSON** (con `.json` = ruta completa)
   ```json
   {
     "source": "inventario/views/mock/productos.json"
   }
   ```
   → Carga desde ruta exacta (no agrega prefijo)

3. **Archivos JSON de otro extension**
   ```json
   {
     "source": "otro-extension/data/clientes.json"
   }
   ```
   → Permite cargar datos de cualquier extension

### Ejemplos Completos:

**Cargar desde API:**
```json
{
  "type": "component",
  "component": "datatable",
  "config": {
    "source": "api/usuarios",
    "columns": [
      {
        "id": { "name": "ID", "width": "80px" }
      },
      {
        "nombre": { "name": "Nombre" }
      }
    ]
  }
}
```

**Cargar desde mock JSON:**
```json
{
  "type": "component",
  "component": "datatable",
  "config": {
    "source": "clientes/views/mock/clientes.json",
    "columns": [
      {
        "id": { "name": "i18n:clientes.column.id", "width": "80px" }
      },
      {
        "nombre": { "name": "i18n:clientes.column.name" }
      }
    ]
  }
}
```

**⚠️ IMPORTANTE:**
- Si termina en `.json` → usa la ruta completa tal cual
- Si NO termina en `.json` → es un endpoint API
- NO usar `dataSource` complejo, solo `source` simple

## Logger

**Propósito:** Sistema de logging con prefijos por módulo y colores.

### Niveles

- `debug()` - Solo en desarrollo (requiere `isDevelopment: true`)
- `info()` - Información general
- `warn()` - Advertencias
- `success()` - Operaciones exitosas
- `error()` - Errores
- `log()` - Logs genéricos

### Formato

Todos los métodos reciben: `(module, ...args)`

**Convención de prefijos:**
- `core:xxx` - Core (ej: `core:auth`, `core:view`, `core:api`)
- `com:xxx` - Componentes (ej: `com:modal`, `com:datatable`)
- `ext:xxx` - Extensions (ej: `ext:permissions`, `ext:botmaster`)
- `m:xxx` - main.js solamente

**Ejemplos:**
```javascript
logger.debug('core:auth', 'Token válido');
logger.info('core:view', 'Vista cargada:', viewName);
logger.warn('com:modal', 'Modal no encontrado');
logger.success('ext:botmaster', 'Bots cargados');
logger.error('core:api', 'Error en petición:', error);
```
**⚠️ IMPORTANTE:**
- Solo usarlo en lugares como errores tipo try/catch
- Si existe fallas entonces se puede agregarlo dentro de metodos involucrados para el debug

---

## Sistema de Hooks

**Propósito:** Permite a los extensions inyectar contenido dinámicamente en vistas, tabs, content y formularios sin modificar archivos originales.

### Configuración

En `index.json` del extension:
```json
{
  "hasHooks": true
}
```

Crear archivo `hooks.js` en la raíz del extension:
```javascript
class miExtensionHooks {

  static hook_nombreVista() {
    return [
      {
        id: 'hook-unico',
        type: 'html',
        order: 15,
        context: 'content',
        content: 'Contenido inyectado'
      }
    ];
  }

  static hook_inputs_demo() {
    return [
      {
        name: 'nuevo_campo',
        label: 'Campo Inyectado',
        type: 'text',
        order: 17,
        context: 'form'
      }
    ];
  }
}

window.miExtensionHooks = miExtensionHooks;
```

### Contextos Disponibles

| Context | Ubicación | Uso |
|---------|-----------|-----|
| `'view'` | Alrededor de vista completa | Banners, headers. Requiere `position: 'before'` o `'after'` |
| `'tab'` | Dentro de tab específico | Contenido en tabs. Requiere `target: 'tabId'` |
| `'content'` | En array content | Se mezcla con items por `order` |
| `'form'` | Dentro de formulario | Inyecta fields adicionales por `order` |

### Ejemplos Rápidos

**Agregar banner antes de vista:**
```javascript
{
  id: 'banner-promo',
  context: 'view',
  position: 'before',
  type: 'html',
  content: '¡Oferta especial!'
}
```

**Inyectar contenido en tab:**
```javascript
{
  id: 'hook-tab',
  context: 'tab',
  target: 'tab1',
  order: 15,
  type: 'html',
  content: 'Contenido adicional'
}
```

**Agregar field en formulario:**
```javascript
{
  name: 'confirm_email',
  label: 'Confirmar Email',
  type: 'email',
  order: 12,
  context: 'form'
}
```

### Reglas de Order

- Los fields/items originales reciben `order` automático: 5, 10, 15, 20...
- Los hooks usan `order` para posicionarse entre items existentes
- `order: 7` → Entre 5 y 10
- `order: 17` → Entre 15 y 20
- Todo se ordena al final por `order`

**⚠️ IMPORTANTE:**
- El nombre del método debe ser: `hook_{id_de_la_vista}` (guiones convertidos a guiones bajos)
- Exportar la clase a `window`: `window.miExtensionHooks = miExtensionHooks;`
- Los hooks se ejecutan automáticamente al cargar la vista

---