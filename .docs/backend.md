# Backend PHP - Documentación

## api.php

**Propósito:** Entry point del API REST con manejo de CORS y errores.

### Configuración inicial

**CORS headers:**
- `Access-Control-Allow-Origin: *`
- Métodos: GET, POST, PUT, DELETE, OPTIONS
- Headers: Content-Type, Authorization

**Manejo de preflight:**
- OPTIONS request → 200 y exit

### Flujo de ejecución

1. **Output buffering:** Inicia `ob_start()` para capturar output no deseado
2. **Carga configuración:** `config/consts.php` (define IS_DEV)
3. **Error handling:**
   - IS_DEV: muestra todos los errores
   - Producción: oculta errores
4. **Carga core:**
   - `core/autoload.php` - Autoloader de clases
   - `core/router.php` - Router principal
5. **Carga rutas:** `routes/api.php`
6. **Dispatch:** `$router->dispatch()`
7. **Manejo de excepciones:**
   - Captura errores globales
   - IS_DEV: retorna detalles (mensaje, archivo, línea, trace)
   - Producción: retorna mensaje genérico
8. **Validación de respuesta:**
   - Valida que sea JSON válido
   - Si inválido: retorna error con debug

### Respuestas

**Success:**
```json
{
  "success": true,
  "data": {}
}
```

**Error (dev):**
```json
{
  "success": false,
  "error": "mensaje",
  "file": "ruta/archivo.php",
  "line": 123,
  "trace": ["...", "..."]
}
```

**Error (prod):**
```json
{
  "success": false,
  "error": "Internal Server Error"
}
```

### Output buffering

**Primer buffer:**
- Captura warnings/notices antes de dispatch
- Log en desarrollo si hay output

**Segundo buffer:**
- Captura respuesta del controller
- Valida JSON antes de enviar
- Limpia si hay error

### Características clave

- **Zero output antes de JSON:** Previene headers corrupted
- **Validación de JSON:** No envía respuestas malformadas
- **Debug condicional:** Info detallada solo en desarrollo
- **CORS automático:** Sin configuración adicional

---

## config/consts.php

**Propósito:** Constantes globales del sistema.

**Constantes clave:**
- `IS_DEV` - Modo desarrollo (true/false)
- `TIMEZONE` - America/Guayaquil
- `TIME_*` - Constantes de tiempo (SECOND, MINUTE, HOUR, DAY, etc.)
- `SESSION_TTL` - 1 día en segundos
- `SESSION_TTL_MS` - En milisegundos para frontend
- `DB_*` - Configuración de base de datos
- `*_PATH` - Rutas del sistema (BASE, BACKEND, FRONTEND, STORAGE, LOG)
- `API_BASE_URL` - /api

---

## core/

### autoload.php

**Propósito:** SPL autoloader con orden de prioridad.

**Orden de búsqueda:**
1. `helpers/{class}.php` - Helpers (primera prioridad)
2. `core/{class}.php` - Core
3. `resources/controllers/{class}.php` - Controllers personalizados
4. `resources/handlers/{class}.php` - Handlers personalizados
5. `middleware/{class}.php` - Middleware
6. `services/{class}.php` - Services principales
7. `services/integrations/{category}/{class}/{class}.php` - Integraciones (ai, email, etc.)

**Nota:** Busca por categorías en integrations: ai/deepseek, email/plusemail, etc.

### controller.php

**Propósito:** Controller basado en schemas JSON con CRUD automático.

**Constructor:** Recibe `resourceName`, carga `resources/{name}.json`

**Métodos CRUD:**
- `list()` - GET all con filtros, ordenamiento y paginación
- `show($id)` - GET one por ID
- `create()` - POST con validación de required y unique
- `update($id)` - PUT con validación y timestamps
- `delete($id)` - DELETE por ID

**Características:**
- Validación automática de campos `required` y `unique` desde schema
- Timestamps automáticos (created_at, updated_at) si está habilitado
- Filtros dinámicos desde query params
- Paginación: `?page=1&per_page=50`
- Ordenamiento: `?sort=name&order=ASC`

**handleCustomAction(actionName, params):**
Ejecuta acciones custom definidas en `routes.custom` del schema, llama handlers personalizados.

### pluginLoader.php

**Propósito:** Carga dinámica de plugins bajo demanda.

**Métodos:**
- `load($pluginName, $router)` - Carga UN plugin específico
- `loadAll($router)` - Carga TODOS (solo para admin)
- `isLoaded($pluginName)` - Verifica si está cargado
- `getAvailable()` - Lista plugins sin cargarlos
- `getConfig($pluginName)` - Lee config sin cargar

**Validaciones:**
- Plugin existe (`plugin.json`)
- Plugin habilitado (`enabled: true`)
- Plugin tiene backend (`backend: true`)

**Autoload de plugin:**
Registra autoloader para `controllers/`, `services/`, `models/` del plugin.

**Carga de rutas:**
Carga `routes/routes.php` con prefix `routes_prefix` del config.

### resource.php

**Propósito:** Helper fluido para trabajar con recursos (alternativa a controller).

**Métodos:**
- `get($idOrConditions)` - Obtener por ID o condiciones
- `first($conditions)` - Primer registro
- `where($field, $op, $val)` - Query builder fluido
- `insert($data)` - Crear con timestamps auto
- `update($idOrConditions, $data)` - Actualizar
- `delete($idOrConditions)` - Eliminar
- `count($conditions)` - Contar
- `exists($conditions)` - Verificar existencia

**Helper global:**
```php
$user = resource('user')->where('email', $email)->first();
```

### router.php

**Propósito:** Router minimalista con middleware y rutas dinámicas.

**Métodos de registro:**
- `get($path, $handler)`, `post()`, `put()`, `delete()`
- `group($prefix, $callback)` - Agrupación con prefix
- `middleware($mw)` - Middleware de grupo

**Handler formats:**
```php
$router->get('/user', function() {...});
$router->get('/user', [UserController::class, 'list']);
$router->get('/user', 'UserController@list');
```

**Rutas dinámicas:**
```php
$router->get('/user/{id}', function($id) {...});
```

**Middleware:**
```php
$router->middleware('auth')->get('/admin', ...);
$router->get('/login', ...)->middleware(['json', 'throttle:5,1']);
```

**dispatch():**
Ejecuta ruta según método HTTP y path, normaliza URL (sin trailing slash, sin slashes duplicados).

---

## helpers/

### db.php

**Propósito:** Query builder fluido estilo Laravel.

**Uso básico:**
```php
db::table('users')->where('email', $email)->first();
db::table('users')->insert(['name' => 'Juan']);
db::table('users')->where('id', 1)->update(['status' => 'active']);
db::table('users')->where('id', 1)->delete();
```

**Métodos principales:**
- `where($col, $op, $val)` - Condición WHERE
- `orWhere()`, `whereIn()`, `whereNull()`, `whereBetween()`
- `whereFilters($filters)` - Filtros dinámicos avanzados
- `join()`, `leftJoin()`, `rightJoin()`
- `orderBy()`, `groupBy()`, `having()`
- `limit()`, `offset()`, `paginate($page, $perPage)`
- `get()`, `first()`, `find($id)`, `count()`, `exists()`
- `pluck($col)`, `value($col)`, `chunk($size, $callback)`
- `insert()`, `update()`, `delete()`
- `transaction($callback)`

**Debug:**
```php
$query->toSql(); // SQL con placeholders
$query->getSql(); // SQL con valores interpolados
```

### log.php

**Propósito:** Sistema de logging con niveles.

**Métodos:**
- `debug($msg, $ctx)` - Solo en desarrollo
- `info($msg, $ctx)` - Info general
- `warning($msg, $ctx)` - Advertencias
- `error($msg, $ctx)` - Errores
- `sql($sql, $bindings)` - Log de queries SQL (solo dev)

**Archivo:** `storage/logs/api_{fecha}.log`

### request.php

**Propósito:** Helpers para datos de request.

**Métodos:**
- `data()` - Body JSON o form
- `method()` - HTTP method
- `isAjax()` - Verifica si es AJAX
- `ip()` - IP real del cliente (maneja proxies)
- `userAgent()` - User agent
- `query($key, $default)` - Query param
- `post($key, $default)` - POST data
- `path()` - Path actual

### response.php

**Propósito:** Helpers para respuestas JSON.

**Métodos:**
- `json($data, $code)` - Respuesta JSON raw
- `success($data, $msg, $code)` - Respuesta exitosa
- `error($error, $code, $details)` - Respuesta de error
- `validation($errors, $code)` - Errores de validación
- `notFound($msg)` - 404
- `unauthorized($msg)` - 401
- `forbidden($msg)` - 403
- `serverError($msg, $debug)` - 500

**Auto-exit:** Todos terminan la ejecución.

### sessionCleanup.php

**Propósito:** Limpieza de sesiones expiradas.

**Métodos:**
- `clean()` - Elimina sesiones expiradas
- `stats()` - Estadísticas (total, active, expired)
- `cleanByUserId($userId)` - Limpia todas las sesiones de un usuario

**Storage:** `storage/sessions/{token}.json`

### utils.php

**Propósito:** Utilidades generales.

**Métodos:**
- `get($arr, $key, $default)` - Array access seguro
- `uuid()` - UUID v4
- `token($length)` - Token random hex
- `money($amount, $currency)` - Formatear moneda
- `timeAgo($datetime)` - "hace X minutos"
- `slug($text)` - URL-friendly slug
- `truncate($text, $len, $suffix)` - Truncar texto
- `bytes($bytes)` - Formatear bytes (KB, MB, etc.)

### validation.php

**Propósito:** Validación de datos.

**Métodos:**
- `email($email)` - Email válido
- `phone($phone)` - Teléfono válido
- `url($url)` - URL válida
- `numeric($val)` - Es numérico
- `range($val, $min, $max)` - En rango
- `sanitizeText($text)` - Sanitizar HTML
- `sanitizeEmail($email)` - Sanitizar email
- `required($data, $required)` - Validar campos requeridos

---

## middleware/

### authMiddleware.php

**Propósito:** Verificar autenticación usando sesiones en archivos.

**Flujo:**
1. Extrae token del header `Authorization: Bearer {token}`
2. Busca archivo `storage/sessions/{token}.json`
3. Verifica que no esté expirado
4. Guarda user_id y user en `$GLOBALS` para controllers

**Respuestas:**
- Sin token → 401 "Token no proporcionado"
- Token inválido → 401 "Token inválido"
- Token expirado → 401 "Token expirado" (elimina archivo)

### jsonMiddleware.php

**Propósito:** Validar que Content-Type sea JSON y el body sea JSON válido.

**Validaciones:**
- Content-Type debe incluir `application/json`
- Body debe ser JSON válido

**Respuestas:**
- Content-Type inválido → 400
- JSON malformado → 400 con error específico

### throttleMiddleware.php

**Propósito:** Rate limiting por IP.

**Parámetros:** `throttle:maxRequests,minutes`
- Ejemplo: `throttle:60,1` = 60 requests por minuto

**Headers de respuesta:**
- `X-RateLimit-Limit` - Límite total
- `X-RateLimit-Remaining` - Requests restantes
- `X-RateLimit-Reset` - Timestamp de reset
- `Retry-After` - Segundos para retry (si bloqueado)

**Storage:** `sys_get_temp_dir()/throttle_data.json`

**Respuesta al bloquear:** 429 "Demasiadas peticiones"

---

## resources/

### Estructura de resource JSON

**Ejemplo: user.json**
```json
{
  "resource": "user",
  "table": "user",
  "timestamps": true,
  "middleware": ["throttle:100,1"],
  "routes": {
    "list": {
      "method": "GET",
      "path": "/api/user",
      "middleware": ["auth"]
    },
    "show": {...},
    "create": {...},
    "update": {...},
    "delete": {...},
    "custom": [
      {
        "name": "actionName",
        "method": "POST",
        "path": "/api/user/custom",
        "handler": "methodName",
        "middleware": []
      }
    ]
  },
  "fields": [
    {
      "name": "user",
      "type": "string",
      "required": true,
      "unique": true,
      "maxLength": 50
    }
  ]
}
```

**Campos del schema:**
- `resource` - Nombre del recurso
- `table` - Tabla de BD
- `timestamps` - Auto-manejo de created_at/updated_at
- `middleware` - Middleware global del recurso
- `routes` - Configuración de rutas CRUD y custom
- `fields` - Schema de validación

### controllers/userController.php

**Propósito:** Controller personalizado que extiende controller base.

**Características:**
- Extiende `controller` base
- Override de métodos CRUD para lógica específica
- `create()` - Hashea password con bcrypt, valida unique
- `update()` - Hashea password solo si se provee, invalida sesiones si cambia config
- `show()` y `list()` - Ocultan password, parsean config JSON

**Invalidación de sesiones:**
Si se actualiza `config` (permisos), elimina sesiones del usuario excepto la del admin que está editando.

### handlers/userHandlers.php

**Propósito:** Handlers de autenticación y sesiones basadas en archivos.

**Métodos:**
- `login($params)` - Valida credenciales, genera token, guarda sesión
- `logout($params)` - Elimina archivo de sesión
- `profile($params)` - Retorna user desde sesión (SIN consultar BD)
- `updateConfig($params)` - Actualiza config y sincroniza con sesión activa

**Sesión en archivo:**
```json
{
  "user_id": 1,
  "user": {...},
  "token": "...",
  "expires_at": "2025-01-01 12:00:00",
  "ip_address": "...",
  "user_agent": "...",
  "created_at": "..."
}
```

**Login response:**
```json
{
  "success": true,
  "data": {
    "user": {...},
    "token": "...",
    "expires_at": "...",
    "ttl": 86400,
    "ttl_ms": 86400000
  }
}
```

### handlers/clientHandlers.php

**Propósito:** Handlers custom para resource client.

**Métodos:**
- `deleteAllData($params)` - Elimina cliente y datos relacionados en cascada
- `getByNumber($params)` - Busca cliente por número
- `topClients($params)` - Top clientes por amount_spent

---

## routes/

### api.php

**Propósito:** Router híbrido - Rutas manuales + Auto-registro CRUD.

**Flujo:**
1. Extrae módulo del path (`/api/user` → `user`)
2. Carga rutas manuales de `routes/apis/{module}.php` si existe
3. Auto-registra rutas CRUD desde `resources/{module}.json`
4. Auto-registra rutas custom desde `routes.custom` del JSON

**Auto-registro CRUD:**
- `GET /api/{resource}` → list()
- `GET /api/{resource}/{id}` → show()
- `POST /api/{resource}` → create()
- `PUT /api/{resource}/{id}` → update()
- `DELETE /api/{resource}/{id}` → delete()

**Auto-registro custom:**
Lee `routes.custom` del JSON y registra handlers personalizados.

**Ventaja:** No duplica rutas si ya están definidas manualmente.

### apis/user.php

**Propósito:** Rutas manuales de autenticación (no CRUD).

**Rutas:**
- `POST /api/user/login` - Login con throttle (10 requests/min)
- `POST /api/user/logout` - Logout (requiere auth)
- `GET /api/user/profile` - Perfil (requiere auth)
- `PUT /api/user/{id}/config` - Actualizar config (requiere auth + json)
- `GET /api/user/generatepass/{key}` - Generar hash de password (solo dev)

**Nota:** Las rutas CRUD se auto-registran desde `user.json`

### apis/system.php

**Propósito:** Rutas de administración del sistema.

**Rutas:**
- `GET /api/system/cleanup-sessions` - Limpia sesiones expiradas (auth)
- `GET /api/system/sessions-stats` - Estadísticas de sesiones (auth)
- `GET /api/system/info` - Info del sistema (auth)
- `GET /api/system/health` - Health check (sin auth)

---

## Flujo completo de un request

**Ejemplo: POST /api/user/login**

1. **api.php** - Entry point, inicia output buffering
2. **router.php** - Match ruta con middleware
3. **throttleMiddleware** - Verifica rate limit
4. **jsonMiddleware** - Valida JSON body
5. **routes/apis/user.php** - Ejecuta handler
6. **userHandlers::login()** - Lógica de login
7. **db.php** - Consulta usuario
8. **password_verify()** - Valida password
9. **utils::token()** - Genera token
10. **Guarda sesión** en `storage/sessions/{token}.json`
11. **response::json()** - Retorna respuesta
12. **api.php** - Valida JSON output y envía

**Ejemplo: GET /api/user (CRUD auto)**

1. **api.php** - Entry point
2. **routes/api.php** - Auto-registra desde `user.json`
3. **authMiddleware** - Valida token
4. **userController::list()** - Ejecuta query
5. **db.php** - Query con filtros y paginación
6. **response::success()** - Retorna datos

---

## Características clave del backend

**1. CRUD automático desde JSON:**
Define schema una vez, obtén API REST completa con validación.

**2. Controllers personalizados:**
Override métodos CRUD para lógica específica (ej: hash passwords).

**3. Handlers para custom actions:**
Rutas personalizadas sin modificar controller base.

**4. Sesiones basadas en archivos:**
- Sin consultas a BD en cada request
- Token como nombre de archivo
- TTL automático con limpieza programada

**5. Middleware composable:**
- Global (resource-level)
- Por ruta (route-level)
- Con parámetros: `throttle:60,1`

**6. Query builder fluido:**
Chainable, prepared statements, soporte completo de WHERE/JOIN/ORDER.

**7. Auto-carga inteligente:**
Autoloader con 7 niveles de prioridad.

**8. Validación automática:**
Required, unique, timestamps desde schema JSON.