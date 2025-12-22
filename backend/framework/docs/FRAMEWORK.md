# FRAMEWORK.md - Documentación del Núcleo

Framework PHP minimalista orientado a alto rendimiento, bajo consumo de tokens y desarrollo rápido de SaaS.

---

## 📁 Estructura del Framework

```
framework/
├── config/           # Configuración del framework
├── core/             # Clases principales
├── helpers/          # Helpers utilitarios (lowercase)
├── middleware/       # Middlewares de autenticación, throttle, etc.
├── traits/           # Traits reutilizables
├── services/         # Servicios de integración (AI, ChatAPI, Email, Storage)
├── lang/             # Traducciones del framework (lazy loading)
└── docs/             # Mini-documentación de componentes
```

---

## 🎯 Filosofía del Framework

1. **Minimalista** - Código mínimo, lógica máxima
2. **Lazy Loading** - Carga solo lo necesario bajo demanda
3. **Auto-registro** - CRUD automático desde JSON schemas
4. **Separation of Concerns** - Framework portable, App específica
5. **AI-Friendly** - Consume mínimos tokens, fácil de mantener con IA

---

## 🔧 Core (Clases principales)

### Application.php
**Qué hace:** Maneja el ciclo de vida completo de cada request  
**Uso:** Se instancia automáticamente en `api.php`

```php
$app = new Application();  // Carga autoload, router, rutas
$app->run();               // Ejecuta request y maneja respuesta
```

**Responsabilidades:**
- Cargar autoloader
- Inicializar router
- Cargar rutas del app
- Ejecutar dispatch
- Capturar excepciones
- Validar JSON de salida
- Manejar errores con detalle en dev, genérico en prod

---

### router (clase)
**Qué hace:** Sistema de rutas con middleware, grupos y auto-registro CRUD  
**Uso:** Disponible en archivos de rutas como `$router`

```php
// Ruta simple
$router->get('/api/hello', function() {
  response::json(['message' => 'Hello']);
});

// Con middleware
$router->post('/api/user', [UserController::class, 'create'])
  ->middleware(['auth', 'json']);

// Grupos
$router->group('/api/admin', function($r) {
  $r->get('/stats', 'AdminController@stats');
})->middleware('auth');
```

**Auto-registro CRUD:**  
Las rutas CRUD se registran automáticamente desde `/app/resources/{resource}.json`

**Ver:** `/framework/docs/router.md`

---

### controller (clase base)
**Qué hace:** Controlador genérico con CRUD completo basado en schemas JSON  
**Uso:** Extender para crear controllers personalizados

```php
class UserController extends controller {
  function __construct() {
    parent::__construct('user'); // Carga user.json
  }
  
  // Override para lógica custom
  function create() {
    $data = request::data();
    // Tu lógica...
    parent::create(); // O llamar al padre
  }
}
```

**Métodos automáticos:**
- `list()` - Listar con paginación y filtros
- `show($id)` - Obtener por ID
- `create()` - Crear nuevo
- `update($id)` - Actualizar
- `delete($id)` - Eliminar

**Ver:** `/framework/docs/controller.md`

---

### autoload.php
**Qué hace:** Autoloader inteligente con mapa estático + lazy loading  
**Carga automáticamente:**

1. **Mapa estático** (core crítico):
   - controller, router, resource, service
   - request, response, db

2. **Lazy loading** (bajo demanda):
   - Helpers → `/framework/helpers/{class}.php`
   - Core → `/framework/core/{class}.php`
   - Middleware → `/framework/middleware/{class}.php`
   - Controllers → `/app/resources/controllers/{class}.php`
   - Handlers → `/app/resources/handlers/{class}.php`
   - Traits → `/framework/traits/{class}.php`
   - Services → Auto-discovery inteligente por categoría

**Ver:** `/framework/core/autoload.php`

---

### service (orquestador)
**Qué hace:** Orquestador de servicios de integración (AI, ChatAPI, Email, Storage)  
**Uso:**

```php
$ai = service::integration('ai');
$response = $ai->getChatCompletion($prompt, $bot);

chatapi::send($number, $message, $media);

$provider = service::detect('chatapi', $webhookData);
```

**Ver:** `/framework/docs/service.md`

---

## 🛠️ Helpers (Utility classes)

Todas las clases helper usan **lowercase** y son estáticas (no se instancian).

### db - Query Builder
**Qué hace:** Query builder fluido para MySQL

```php
// Select
$users = db::table('user')->where('role', 'admin')->get();

// WhereFilters (★ MUY ÚTIL)
$filters = [
  ['status', '=', 'active'],
  ['age', '>=', 18],
  ['role', 'IN', ['admin', 'editor']]
];
$users = db::table('user')->whereFilters($filters)->get();

// Métodos útiles
$user = db::table('user')->find(1);
$count = db::table('user')->count();
$exists = db::table('user')->where('email', 'test@x.com')->exists();
$emails = db::table('user')->pluck('email');

// Debug
$sql = db::table('user')->where('id', 1)->getSql();
```

**Ver:** `/framework/docs/db.md`

---

### request - Peticiones HTTP
**Qué hace:** Acceder a datos de la petición

```php
$data = request::data();              // JSON o form
$page = request::query('page', 1);    // Query params
$token = request::bearerToken();      // Bearer token
$method = request::method();          // GET, POST, etc.
$ip = request::ip();                  // IP del cliente
```

**Ver:** `/framework/docs/request.md`

---

### response - Respuestas JSON
**Qué hace:** Enviar respuestas HTTP/JSON estandarizadas

```php
response::success(['user' => $user], 'Usuario creado', 201);
response::error('No encontrado', 404);
response::validation(['email' => 'Email inválido']);

// Shortcuts
response::notFound();
response::unauthorized();
response::forbidden();
response::serverError($msg, $debug);
```

**Ver:** `/framework/docs/response.md`

---

### log - Sistema de logging
**Qué hace:** Logs estructurados con niveles, módulos, tags y rotación

```php
log::debug('Debug info', $ctx, ['module' => 'auth']);
log::info('Usuario logueado', ['user_id' => 1], ['module' => 'auth']);
log::warning('Sesión expirada', [], ['module' => 'session']);
log::error('Error en DB', ['error' => $e], ['module' => 'database']);

// Con tags
log::info('Mensaje enviado', $data, [
  'module' => 'whatsapp',
  'tags' => ['message', 'sent']
]);
```

**Formato:** `[timestamp] [level] [module] [message] [context_json] [file:line] [user_id] [tags]`

**Ver:** `/framework/docs/log.md`

---

### lang - Internacionalización (lazy loading)
**Qué hace:** Traducciones multi-idioma con carga bajo demanda

```php
lang::load('es');  // Solo guarda locale, NO carga archivos

__('auth.login.success');  // Carga SOLO auth.php
__('core.error');          // Carga SOLO core.php
__('services.ai.no_services_available');  // Carga SOLO services/

// Con variables
__('user.created', ['name' => 'Juan']);
```

**Características:**
- ✅ Lazy loading - Solo carga módulos que usas
- ✅ Cache en memoria - No requiere dos veces
- ✅ Merge automático framework + app

**Ver:** `/framework/docs/lang.md`

---

### validation - Validación de datos
**Qué hace:** Validar datos de entrada

```php
validation::email('user@example.com');  // true/false
validation::phone('+593987654321');
validation::url('https://example.com');
validation::required($data, ['user', 'pass', 'email']);
```

**Ver:** `/framework/docs/validation.md`

---

### http - Cliente HTTP
**Qué hace:** Hacer requests a APIs externas

```php
$response = http::get('https://api.example.com/users');
$response = http::post($url, $data, ['headers' => ['Authorization: Bearer xyz']]);

// Response: ['success' => bool, 'data' => [...], 'httpCode' => 200]
```

**Ver:** `/framework/docs/http.md`

---

### Otros helpers útiles

```php
// logReader - Leer y filtrar logs
$logs = logReader::today(100);
$logs = logReader::filter($logs, ['level' => 'ERROR', 'module' => 'auth']);

// sessionCleanup - Limpieza optimizada de sesiones
sessionCleanup::clean();
sessionCleanup::cleanByUserId($userId);
$stats = sessionCleanup::stats();

// routeDiscovery - Descubrir todos los endpoints
$routes = routeDiscovery::getAllRoutes();
$stats = routeDiscovery::getStats($routes);

// utils - Utilidades generales
utils::uuid();
utils::token(64);
utils::slug('Hello World');
utils::timeAgo($datetime);

// str - Manipulación de strings
str::normalize('Café');  // 'cafe'
str::containsAllWords('hola mundo', 'este es un hola mundo');
str::isJson($string);

// url - Manejo de URLs
url::normalizeUrl($url);
url::addQueryParams($url, ['page' => 1]);
url::isValid($url);

// file - Manejo de archivos
file::saveJson($path, $data, 'module');
file::getJson($path);
file::delete($path);

// country - Información de países
country::get('EC');  // ['name' => 'Ecuador', 'timezone' => 'America/Guayaquil']
country::now('EC');  // Hora actual en Ecuador
country::convert($datetime, 'EC', 'ES');  // Convertir zona horaria
```

---

## 🎭 Traits (Reutilizables)

### ValidatesUnique
**Qué hace:** Validaciones de unicidad para controllers  
**Uso:**

```php
class UserController extends controller {
  use ValidatesUnique;
  
  function create() {
    $data = request::data();
    
    // Validar email (formato + unicidad)
    $this->validateEmail($data['email'], 'user');
    
    // Validar campo único
    $this->validateUnique('user', 'user', $data['user'], 'user.already_exists');
    
    // ...
  }
  
  function update($id) {
    // Validar único excepto ID actual
    $this->validateUniqueExcept('user', 'email', $data['email'], $id);
  }
}
```

**Métodos disponibles:**
- `validateUnique($table, $field, $value, $errorKey)`
- `validateUniqueExcept($table, $field, $value, $excludeId, $errorKey)`
- `validateEmail($email, $table, $excludeId)`

---

## 🔐 Middleware

### authMiddleware
**Qué hace:** Validar token de autenticación  
**Cómo funciona:**
1. Busca token Bearer en headers
2. Busca archivo de sesión optimizado: `{timestamp}_{user_id}_{token_short}.json`
3. Valida expiración
4. Carga datos del usuario en `$GLOBALS['auth_user']`

```php
$router->get('/api/user/profile', 'UserHandler@profile')
  ->middleware('auth');
```

---

### throttleMiddleware
**Qué hace:** Limitar peticiones por IP  
**Uso:**

```php
->middleware('throttle:100,1')  // 100 requests por 1 minuto
->middleware('throttle:10,1')   // 10 requests por 1 minuto
```

---

### jsonMiddleware
**Qué hace:** Validar que el body sea JSON válido  
**Uso:**

```php
$router->post('/api/user', 'UserController@create')
  ->middleware(['json', 'auth']);
```

---

## 🌐 Servicios de Integración

### AI Service
**Providers:** DeepSeek, OpenAI  
**Funciones:**
- `getChatCompletion($prompt, $bot, $options)` - Chat con fallback automático
- `transcribeAudio($audioUrl, $bot)` - Transcripción de audio
- `analyzeImage($imageDataUri, $instruction, $bot)` - Análisis de imágenes

```php
$ai = new ai();
$response = $ai->getChatCompletion($prompt, $bot, ['model' => 'deepseek-chat']);
```

---

### ChatAPI Service
**Providers:** Evolution API  
**Funciones:**
- `send($to, $message, $media)` - Enviar mensaje con fallback
- `sendPresence($to, $type, $delay)` - Enviar "escribiendo..."
- `sendArchive($chatNumber, $lastMessageId, $archive)` - Archivar chat
- `detectAndNormalize($rawData)` - Detectar provider y normalizar webhook

```php
chatapi::setConfig($botData, $provider);
chatapi::send($number, 'Hola mundo', $mediaUrl);
```

---

### Email Service
**Providers:** PlusEmail (extensible)  
**Funciones:**
- `send($to, $subject, $body, $opts)` - Enviar email

```php
email::provider('plusemail')->send($to, $subject, $body);
```

---

## 📝 Convenciones

### Nombres de clases
- **lowercase** → Framework helpers/core: `db`, `log`, `str`, `request`
- **camelCase** → Compound helpers: `logReader`, `sessionCleanup`
- **PascalCase** → App resources: `UserController`, `AuthHandler`

### Nombres de archivos
- **camelCase.php** → Framework: `db.php`, `logReader.php`
- **PascalCase.php** → App: `UserController.php`, `AuthHandler.php`
- **kebab-case.json** → Configs: `user.json`, `client.json`

### Nombres de métodos
- **camelCase** → Todos: `getUserById()`, `sendMessage()`

### Variables
- **camelCase** → Locales: `$userId`, `$totalAmount`
- **SCREAMING_SNAKE_CASE** → Constantes: `DB_HOST`, `SESSION_TTL`

**Ver:** `/framework/docs/naming-conventions.md`

---

### Campos de base de datos
- **dc** → Date Created (Y-m-d H:i:s)
- **du** → Date Updated (Y-m-d H:i:s)
- **tc** → Timestamp Created (unix timestamp)
- **tu** → Timestamp Updated (unix timestamp)

**Ver:** `/framework/docs/schema-conventions.md`

---

## 🚀 Flujo de Ejecución

```
1. api.php
   └─ Carga app/config/init.php
      ├─ Define constantes (BASE_PATH, FRAMEWORK_PATH, APP_PATH)
      └─ Carga framework/config/init.php
         ├─ Carga helpers críticos (system, lang, log)
         ├─ Configura timezone, error_reporting
         └─ lang::load('es')  (solo guarda locale)

2. new Application()
   ├─ Carga autoload.php
   ├─ Carga router.php
   ├─ Hace $router accesible
   └─ Carga app/routes/api.php
      ├─ Auto-registra CRUD desde JSONs
      └─ Carga rutas manuales desde /routes/apis/{module}.php

3. $app->run()
   ├─ Captura output buffer
   ├─ $router->dispatch()
   │  ├─ Ejecuta middlewares
   │  ├─ Carga controller/handler bajo demanda
   │  └─ Ejecuta método
   ├─ Maneja excepciones
   ├─ Valida JSON de salida
   └─ Envía respuesta
```

---

## 💾 Constantes Disponibles

```php
// Rutas
BASE_PATH       // /var/www/proyecto
BACKEND_PATH    // /var/www/proyecto/backend
FRAMEWORK_PATH  // /var/www/proyecto/backend/framework
APP_PATH        // /var/www/proyecto/backend/app
ROUTES_PATH     // /var/www/proyecto/backend/app/routes
STORAGE_PATH    // /var/www/proyecto/backend/app/storage
LOG_PATH        // /var/www/proyecto/backend/app/storage/logs
SERVICES_PATH   // /var/www/proyecto/backend/framework/services

// Tiempo
TIME_SECOND     // 1
TIME_MINUTE     // 60
TIME_HOUR       // 3600
TIME_DAY        // 86400
TIME_WEEK       // 604800
TIME_MONTH      // 2592000
TIME_YEAR       // 31536000

// Base de datos
DB_HOST         // Auto-detecta localhost vs producción
DB_NAME         // Auto-detecta según entorno
DB_USER         // Auto-detecta según entorno
DB_PASS         // Auto-detecta según entorno
DB_CHARSET      // utf8mb4

// Entorno
IS_DEV          // true en localhost, false en producción
DEFAULT_LANG    // 'es'
TIMEZONE        // 'America/Guayaquil'

// Sesiones
SESSION_TTL     // 2592000 (30 días)
SESSION_TTL_MS  // 2592000000 (milisegundos)
```

---

## 📚 Documentación Adicional

Cada componente tiene su mini-doc en `/framework/docs/`:

- `db.md` - Query Builder
- `router.md` - Sistema de rutas
- `controller.md` - Controlador base
- `request.md` - Manejo de peticiones
- `response.md` - Respuestas JSON
- `log.md` - Sistema de logging
- `lang.md` - Internacionalización
- `validation.md` - Validación de datos
- `http.md` - Cliente HTTP
- `service.md` - Orquestador de servicios
- `naming-conventions.md` - Convenciones de nombres
- `schema-conventions.md` - Convenciones de BD

---

## 🎯 Próximos pasos
2. **BUSINESS-LOGIC-MAP.md** - Template para mapear lógica de negocio