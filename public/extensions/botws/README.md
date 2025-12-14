# Extensión Botws (Bots de WhatsApp)

## Descripción
Extensión para la gestión de bots de WhatsApp en el sistema.

## Características
- ✅ CRUD completo de bots
- ✅ Listado con datatable
- ✅ Formularios modales
- ✅ Soporte multiidioma (ES/EN)
- ✅ Validaciones de formulario
- ✅ Integración con sistema de permisos

## Instalación

### 1. Copiar archivos
Copiar toda la carpeta `botws` a `public/extensions/`

### 2. Actualizar index.json de extensiones
Editar `public/extensions/index.json` y agregar:
```json
{
  "extensions": [
    {
      "name": "admin",
      "description": "Administración del sistema"
    },
    {
      "name": "botws",
      "description": "Gestión de Bots de WhatsApp"
    }
  ]
}
```

### 3. Crear tabla en base de datos
Ejecutar el script SQL ubicado en `database/bots.sql`

### 4. Crear endpoints en el backend
Crear los siguientes endpoints en tu API:

```
GET    /api/bots          - Listar todos los bots
GET    /api/bots/:id      - Obtener un bot por ID
POST   /api/bots          - Crear un nuevo bot
PUT    /api/bots/:id      - Actualizar un bot
DELETE /api/bots/:id      - Eliminar un bot
```

#### Formato de respuesta esperado:
```json
{
  "success": true,
  "data": {
    "id": 1,
    "name": "Bot de Soporte",
    "personality": "Amigable y servicial",
    "config": {},
    "dc": "2024-12-13 10:30:00",
    "da": null,
    "ta": 1702468200,
    "tu": null
  }
}
```

## Estructura de archivos

```
botws/
├── assets/
│   └── js/
│       └── botws.js          # Lógica principal del CRUD
├── database/
│   └── bots.sql              # Script de creación de tabla
├── lang/
│   ├── en.json               # Traducciones inglés
│   └── es.json               # Traducciones español
├── views/
│   ├── forms/
│   │   └── bot-form.json     # Formulario de bot
│   └── sections/
│       └── botws-listado.json # Vista principal
├── index.json                # Configuración de la extensión
└── README.md                 # Este archivo
```

## Formato correcto para modal.open

**IMPORTANTE:** Para abrir formularios o vistas desde modales, usar el formato:
```javascript
modal.open('extension|tipo/archivo', opciones)
```

### Ejemplos correctos:
```javascript
// Abrir formulario
modal.open('botws|forms/bot-form', {title: 'Nuevo Bot'})

// Abrir sección
modal.open('botws|sections/detalle', {title: 'Detalle'})

// Desde botón en HTML
onclick="modal.open('botws|forms/bot-form', {title: '🤖 Nuevo Bot'})"
```

### ❌ Formato INCORRECTO:
```javascript
modal.open('botws/forms/bot-form')  // ❌ NO usar slash /
```

## Uso

### Acceso
Una vez instalado, la extensión aparecerá en el menú lateral como "Bots WS" con el submenú "Listado".

### Operaciones

#### Crear bot:
1. Click en "➕ Nuevo Bot"
2. Completar formulario
3. Click en "Guardar"

#### Editar bot:
1. Click en "✏️" en la fila del bot
2. Modificar datos
3. Click en "Guardar"

#### Eliminar bot:
1. Click en "🗑️" en la fila del bot
2. Confirmar eliminación

## Campos del formulario

- **Nombre del Bot** (requerido): Nombre identificador del bot (3-50 caracteres)
- **Personalidad** (opcional): Descripción de la personalidad del bot (máx 250 caracteres)

## Personalización

### Agregar campos al formulario
Editar `views/forms/bot-form.json` y agregar nuevos campos en el array `fields`.

### Modificar columnas de la tabla
Editar `views/sections/botws-listado.json` en la sección `config.columns`.

### Agregar traducciones
Editar los archivos en `lang/` agregando nuevas claves.

## Soporte
Para problemas o dudas, revisar el manual de plugins del sistema.
