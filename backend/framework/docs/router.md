# router - Sistema de rutas minimalista

Enrutador con soporte de middleware, grupos y auto-registro CRUD desde JSON.

## Uso básico

```php
// Rutas simples
$router->get('/api/hello', function() {
  response::json(['message' => 'Hello']);
});

// Rutas con parámetros
$router->get('/api/user/{id}', function($id) {
  $user = db::table('user')->find($id);
  response::json($user);
});

// Middleware
$router->post('/api/user', [UserController::class, 'create'])
  ->middleware(['auth', 'json']);

// Grupos
$router->group('/api/admin', function($r) {
  $r->get('/stats', 'AdminController@stats');
})->middleware('auth');
```

**Auto-registro CRUD:** Las rutas CRUD se registran automáticamente desde `/app/resources/{resource}.json`

Ver: `/framework/core/router.php`
