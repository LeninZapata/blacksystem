# BLUEPRINT.md - Crear un Nuevo Proyecto SaaS

Guía paso a paso para lanzar un nuevo proyecto usando el framework en **menos de 1 hora**.

---

## 🎯 Objetivo

Pasar de **idea a API funcionando** en el menor tiempo posible, sin repensar arquitectura.

---

## 📋 Prerequisitos

- PHP 8.0+
- MySQL/MariaDB
- Servidor web (Apache/Nginx) o Laragon/XAMPP local
- Framework base ya refactorizado

---

## 🚀 Paso 1: Copiar Estructura Base

### 1.1 Crear directorio del proyecto

```bash
mkdir mi-nuevo-saas
cd mi-nuevo-saas
```

### 1.2 Copiar framework (100% portable)

```bash
cp -r /ruta/del/proyecto-base/backend/framework ./backend/framework
```

**El framework se copia completo, sin modificar nada:**
- `/framework/config/`
- `/framework/core/`
- `/framework/helpers/`
- `/framework/middleware/`
- `/framework/traits/`
- `/framework/services/`
- `/framework/lang/`
- `/framework/docs/`

### 1.3 Crear estructura del app

```bash
mkdir -p backend/app/{config,routes/apis,resources/{controllers,handlers,schemas},storage/{logs,sessions},lang/es}
```

**Estructura final:**
```
mi-nuevo-saas/
└── backend/
    ├── api.php
    ├── .htaccess
    ├── framework/          (copiado, sin tocar)
    └── app/
        ├── config/
        │   ├── init.php
        │   ├── consts.php
        │   └── database.php
        ├── routes/
        │   ├── api.php
        │   └── apis/       (rutas manuales por módulo)
        ├── resources/
        │   ├── controllers/
        │   ├── handlers/
        │   └── schemas/    (archivos .json)
        ├── storage/
        │   ├── logs/
        │   └── sessions/
        └── lang/
            └── es/
```

---

## 🗄️ Paso 2: Configurar Base de Datos

### 2.1 Crear archivo `/app/config/database.php`

```php
<?php
return [
  'host' => isLocalhost() ? 'localhost' : 'produccion.com',
  'name' => isLocalhost() ? 'mi-nuevo-saas' : 'db_prod',
  'user' => isLocalhost() ? 'root' : 'user_prod',
  'pass' => isLocalhost() ? '' : 'pass_prod',
  'charset' => 'utf8mb4'
];
```

### 2.2 Crear archivo `/app/config/consts.php`

```php
<?php
$dbConfig = require __DIR__ . '/database.php';

define('DB_HOST', $dbConfig['host']);
define('DB_NAME', $dbConfig['name']);
define('DB_USER', $dbConfig['user']);
define('DB_PASS', $dbConfig['pass']);
define('DB_CHARSET', $dbConfig['charset']);

// Sesiones
define('SESSION_TTL', TIME_MONTH);        // 30 días
define('SESSION_TTL_MS', TIME_MONTH * 1000);
```

### 2.3 Crear archivo `/app/config/init.php`

```php
<?php
// Definir rutas base del proyecto
define('BASE_PATH', dirname(dirname(__DIR__)));
define('BACKEND_PATH', BASE_PATH . '/backend');
define('FRAMEWORK_PATH', BACKEND_PATH . '/framework');
define('APP_PATH', BACKEND_PATH . '/app');
define('ROUTES_PATH', APP_PATH . '/routes');
define('STORAGE_PATH', APP_PATH . '/storage');
define('LOG_PATH', STORAGE_PATH . '/logs');
define('SERVICES_PATH', FRAMEWORK_PATH . '/services');

// Cargar configuración del framework
require_once FRAMEWORK_PATH . '/config/init.php';

// Cargar constantes específicas del app
require_once __DIR__ . '/consts.php';
```

### 2.4 Crear base de datos

```sql
CREATE DATABASE `mi-nuevo-saas` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

---

## 📝 Paso 3: Definir Modelos (Schemas JSON)

Piensa en tu SaaS: **¿Qué entidades necesitas?**

Ejemplo: **Sistema de Gestión de Tareas**
- `user` (usuarios)
- `project` (proyectos)
- `task` (tareas)
- `comment` (comentarios)

### 3.1 Crear schema: `/app/resources/schemas/user.json`

```json
{
  "resource": "user",
  "table": "user",
  "timestamps": true,
  "middleware": ["throttle:100,1"],

  "routes": {
    "list": {
      "method": "GET",
      "path": "/api/user/",
      "middleware": ["auth"]
    },
    "show": {
      "method": "GET",
      "path": "/api/user/{id}",
      "middleware": ["auth"]
    },
    "create": {
      "method": "POST",
      "path": "/api/user",
      "middleware": ["json"]
    },
    "update": {
      "method": "PUT",
      "path": "/api/user/{id}",
      "middleware": ["auth", "json"]
    },
    "delete": {
      "method": "DELETE",
      "path": "/api/user/{id}",
      "middleware": ["auth"]
    }
  },

  "fields": [
    { "name": "user", "type": "string", "required": true, "unique": true, "maxLength": 50 },
    { "name": "pass", "type": "string", "required": true, "maxLength": 255 },
    { "name": "email", "type": "string", "unique": true, "maxLength": 150 },
    { "name": "config", "type": "json" },
    { "name": "role", "type": "string", "maxLength": 50 },
    { "name": "dc", "type": "datetime" },
    { "name": "du", "type": "datetime" },
    { "name": "tc", "type": "int" },
    { "name": "tu", "type": "int" }
  ]
}
```

### 3.2 Crear schema: `/app/resources/schemas/task.json`

```json
{
  "resource": "task",
  "table": "task",
  "timestamps": true,
  "middleware": ["auth", "throttle:100,1"],

  "routes": {
    "list": { "method": "GET", "path": "/api/task", "middleware": [] },
    "show": { "method": "GET", "path": "/api/task/{id}", "middleware": [] },
    "create": { "method": "POST", "path": "/api/task", "middleware": ["json"] },
    "update": { "method": "PUT", "path": "/api/task/{id}", "middleware": ["json"] },
    "delete": { "method": "DELETE", "path": "/api/task/{id}", "middleware": [] }
  },

  "fields": [
    { "name": "title", "type": "string", "required": true, "maxLength": 200 },
    { "name": "description", "type": "text" },
    { "name": "status", "type": "string", "default": "pending" },
    { "name": "priority", "type": "string", "default": "medium" },
    { "name": "user_id", "type": "int", "required": true },
    { "name": "project_id", "type": "int" },
    { "name": "due_date", "type": "datetime" },
    { "name": "dc", "type": "datetime" },
    { "name": "du", "type": "datetime" }
  ]
}
```

### 3.3 Crear tablas en MySQL

```sql
-- Tabla user
CREATE TABLE `user` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user` VARCHAR(50) UNIQUE NOT NULL,
  `pass` VARCHAR(255) NOT NULL,
  `email` VARCHAR(150) UNIQUE,
  `config` JSON,
  `role` VARCHAR(50),
  `dc` DATETIME,
  `du` DATETIME,
  `tc` INT,
  `tu` INT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabla task
CREATE TABLE `task` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(200) NOT NULL,
  `description` TEXT,
  `status` VARCHAR(50) DEFAULT 'pending',
  `priority` VARCHAR(50) DEFAULT 'medium',
  `user_id` INT NOT NULL,
  `project_id` INT,
  `due_date` DATETIME,
  `dc` DATETIME,
  `du` DATETIME,
  FOREIGN KEY (`user_id`) REFERENCES `user`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## 🛣️ Paso 4: Configurar Rutas

### 4.1 Crear archivo `/app/routes/api.php`

```php
<?php
// routes/api.php - Router híbrido: Rutas manuales + Auto-registro CRUD

$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);

// Normalizar path
$path = preg_replace('#/+#', '/', $path);
if (preg_match('#(/api/.*)$#', $path, $matches)) {
  $path = $matches[1];
}
$path = rtrim($path, '/');

// Extraer el módulo: /api/user -> user
$module = null;
if (preg_match('#^/api/([^/]+)#', $path, $matches)) {
  $module = $matches[1];
}

// PASO 1: Auto-registrar rutas CRUD desde JSON
if ($module) {
  $resourceFile = APP_PATH . "/resources/schemas/{$module}.json";

  if (file_exists($resourceFile)) {
    $config = json_decode(file_get_contents($resourceFile), true);

    // Verificar si existe controller personalizado
    $controllerClass = ucfirst($module) . 'Controller';
    $ctrl = class_exists($controllerClass)
      ? new $controllerClass()
      : new controller($module);

    $globalMw = $config['middleware'] ?? [];

    // Rutas CRUD estándar
    $crudRoutes = [
      'list'   => ['get',    "/api/{$module}",      'list'],
      'show'   => ['get',    "/api/{$module}/{id}", 'show'],
      'create' => ['post',   "/api/{$module}",      'create'],
      'update' => ['put',    "/api/{$module}/{id}", 'update'],
      'delete' => ['delete', "/api/{$module}/{id}", 'delete']
    ];

    foreach ($crudRoutes as $key => $routeData) {
      list($method, $routePath, $action) = $routeData;

      $routeConfig = $config['routes'][$key] ?? [];

      if (isset($routeConfig['enabled']) && $routeConfig['enabled'] === false) {
        continue;
      }

      $routeMw = array_merge($globalMw, $routeConfig['middleware'] ?? []);

      $route = $router->$method($routePath, [$ctrl, $action]);

      if (!empty($routeMw)) {
        $route->middleware($routeMw);
      }
    }
  }
}

// PASO 2: Cargar rutas manuales (custom routes)
$manualRoutes = ROUTES_PATH . '/apis/' . $module . '.php';
if ($module && file_exists($manualRoutes)) {
  require_once $manualRoutes;
}
```

### 4.2 Crear rutas manuales de autenticación: `/app/routes/apis/auth.php`

```php
<?php
// routes/apis/auth.php - Rutas de autenticación
$router->group('/api/auth', function($router) {

  // Login - POST /api/auth/login
  $router->post('/login', function() {
    $result = AuthHandler::login([]);
    response::json($result);
  })->middleware(['json', 'throttle:10,1']);

  // Logout - POST /api/auth/logout
  $router->post('/logout', function() {
    $result = AuthHandler::logout([]);
    response::json($result);
  })->middleware('auth');

});
```

---

## 🎨 Paso 5: Crear Handlers Personalizados

### 5.1 Crear `/app/resources/handlers/AuthHandler.php`

```php
<?php
class AuthHandler {

  static function login($params) {
    $data = request::data();

    if (!isset($data['user']) || !isset($data['pass'])) {
      return ['success' => false, 'error' => __('auth.credentials.required')];
    }

    // Buscar usuario
    $user = db::table('user')
      ->where('user', $data['user'])
      ->orWhere('email', $data['user'])
      ->first();

    if (!$user || !password_verify($data['pass'], $user['pass'])) {
      log::warning('Login fallido', ['user' => $data['user']], ['module' => 'auth']);
      return ['success' => false, 'error' => __('auth.credentials.invalid')];
    }

    // Generar token
    $token = utils::token(64);
    $expiresAt = time() + SESSION_TTL;

    // Parsear config si es JSON
    if (isset($user['config']) && is_string($user['config'])) {
      $user['config'] = json_decode($user['config'], true);
    }

    unset($user['pass']);

    // Guardar sesión
    self::saveSession($user, $token, $expiresAt);

    log::info('Login exitoso', ['user_id' => $user['id']], ['module' => 'auth']);

    return [
      'success' => true,
      'message' => __('auth.login.success'),
      'data' => [
        'user' => $user,
        'token' => $token,
        'expires_at' => date('Y-m-d H:i:s', $expiresAt)
      ]
    ];
  }

  static function logout($params) {
    $token = request::bearerToken();
    if (!$token) {
      return ['success' => false, 'error' => __('auth.token.missing')];
    }

    self::deleteSessionByToken($token);
    return ['success' => true, 'message' => __('auth.logout.success')];
  }

  // Guardar sesión con nombre optimizado
  private static function saveSession($user, $token, $expiresAt) {
    $sessionsDir = STORAGE_PATH . '/sessions/';
    if (!is_dir($sessionsDir)) mkdir($sessionsDir, 0755, true);

    $tokenShort = substr($token, 0, 16);
    $filename = "{$expiresAt}_{$user['id']}_{$tokenShort}.json";

    file_put_contents($sessionsDir . $filename, json_encode([
      'user_id' => $user['id'],
      'user' => $user,
      'token' => $token,
      'expires_at' => date('Y-m-d H:i:s', $expiresAt),
      'expires_timestamp' => $expiresAt,
      'created_at' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE));
  }

  private static function deleteSessionByToken($token) {
    $sessionsDir = STORAGE_PATH . '/sessions/';
    $tokenShort = substr($token, 0, 16);
    $files = glob($sessionsDir . "*_*_{$tokenShort}.json");

    foreach ($files as $file) {
      $session = json_decode(file_get_contents($file), true);
      if ($session && $session['token'] === $token) {
        unlink($file);
        return true;
      }
    }
    return false;
  }
}
```

### 5.2 Crear Controller personalizado (opcional)

Si necesitas lógica custom, crear `/app/resources/controllers/UserController.php`:

```php
<?php
class UserController extends controller {
  use ValidatesUnique;

  function __construct() {
    parent::__construct('user');
  }

  // Override create para hashear contraseña
  function create() {
    $data = request::data();

    if (!isset($data['user']) || !isset($data['pass'])) {
      response::error(__('user.fields_required'), 400);
    }

    // Validar unicidad
    $this->validateUnique('user', 'user', $data['user'], 'user.already_exists');

    if (isset($data['email']) && !empty($data['email'])) {
      $this->validateEmail($data['email'], 'user');
    }

    // Hashear contraseña
    $data['pass'] = password_hash($data['pass'], PASSWORD_BCRYPT);

    // Timestamps
    $data['dc'] = date('Y-m-d H:i:s');
    $data['tc'] = time();

    try {
      $id = db::table('user')->insert($data);
      response::success(['id' => $id], __('user.created'), 201);
    } catch (Exception $e) {
      response::serverError(__('user.create_error'), IS_DEV ? $e->getMessage() : null);
    }
  }
}
```

---

## 🌐 Paso 6: Traducciones (Idiomas)

### 6.1 Crear `/app/lang/es/auth.php`

```php
<?php
return [
  'credentials' => [
    'required' => 'Usuario y contraseña son requeridos',
    'invalid' => 'Credenciales inválidas'
  ],
  'login' => [
    'success' => 'Inicio de sesión exitoso'
  ],
  'logout' => [
    'success' => 'Sesión cerrada exitosamente'
  ],
  'token' => [
    'missing' => 'Token no proporcionado',
    'invalid' => 'Token inválido',
    'expired' => 'Token expirado'
  ]
];
```

### 6.2 Crear `/app/lang/es/user.php`

```php
<?php
return [
  'fields_required' => 'Usuario y contraseña son requeridos',
  'already_exists' => 'El usuario ya existe',
  'email_exists' => 'El email ya está registrado',
  'not_found' => 'Usuario no encontrado',
  'created' => 'Usuario creado exitosamente',
  'create_error' => 'Error al crear usuario'
];
```

---

## 🧪 Paso 7: Testing

### 7.1 Crear usuario de prueba

```bash
# POST /api/user
curl -X POST http://localhost/mi-nuevo-saas/backend/api/user \
  -H "Content-Type: application/json" \
  -d '{
    "user": "admin",
    "pass": "12345678",
    "email": "admin@test.com",
    "role": "admin"
  }'
```

### 7.2 Login

```bash
# POST /api/auth/login
curl -X POST http://localhost/mi-nuevo-saas/backend/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "user": "admin",
    "pass": "12345678"
  }'
```

Respuesta:
```json
{
  "success": true,
  "message": "Inicio de sesión exitoso",
  "data": {
    "user": {...},
    "token": "abc123...",
    "expires_at": "2025-01-20 10:30:00"
  }
}
```

### 7.3 Crear tarea (con auth)

```bash
# POST /api/task
curl -X POST http://localhost/mi-nuevo-saas/backend/api/task \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer abc123..." \
  -d '{
    "title": "Mi primera tarea",
    "description": "Descripción de la tarea",
    "status": "pending",
    "user_id": 1
  }'
```

### 7.4 Listar tareas

```bash
# GET /api/task
curl http://localhost/mi-nuevo-saas/backend/api/task \
  -H "Authorization: Bearer abc123..."
```

---

## ⏱️ Resumen: De 0 a API en 1 hora

| Paso | Tiempo | Qué haces |
|------|--------|-----------|
| 1. Copiar estructura | 5 min | `cp -r framework`, crear carpetas |
| 2. Configurar BD | 5 min | database.php, consts.php, init.php |
| 3. Definir modelos | 15 min | Crear schemas JSON + tablas SQL |
| 4. Configurar rutas | 5 min | api.php + auth.php |
| 5. Crear handlers | 20 min | AuthHandler + Controllers custom |
| 6. Traducciones | 5 min | auth.php, user.php |
| 7. Testing | 5 min | Crear usuario, login, CRUD |
| **TOTAL** | **~1 hora** | **API funcionando** ✅ |

---

## 🎯 Checklist de Verificación

- [ ] Framework copiado sin modificar
- [ ] database.php con auto-detección localhost/prod
- [ ] Schemas JSON creados para cada recurso
- [ ] Tablas creadas en MySQL
- [ ] api.php con auto-registro CRUD
- [ ] AuthHandler con login/logout
- [ ] Traducciones básicas en `/app/lang/es/`
- [ ] Usuario de prueba creado
- [ ] Login funcionando y retorna token
- [ ] CRUD de al menos 1 recurso funcionando

---

## 🚀 Siguiente Nivel

Una vez que tengas la base funcionando:

1. **Agregar más recursos** - Solo crear JSON + tabla
2. **Handlers personalizados** - Para lógica específica
3. **Middleware custom** - Para validaciones especiales
4. **Servicios de integración** - AI, WhatsApp, Email
5. **Frontend** - Consumir la API con tu framework JS favorito

---

## 📚 Recursos Adicionales

- **FRAMEWORK.md** - Documentación completa del núcleo
- **BUSINESS-LOGIC-MAP.md** - Template para mapear lógica de negocio
- `/framework/docs/` - Mini-docs de cada componente