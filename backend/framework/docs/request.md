# request - Manejo de peticiones HTTP

Helper para acceder a datos de la petición HTTP.

## Uso

```php
// Obtener datos (JSON o form)
$data = request::data();

// Query params
$page = request::query('page', 1);

// Bearer token
$token = request::bearerToken();

// Información del request
$method = request::method();      // GET, POST, etc.
$ip = request::ip();             // IP del cliente
$path = request::path();         // /api/user
$isAjax = request::isAjax();     // true/false
```

Ver: `/framework/helpers/request.php`
