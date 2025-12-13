<?php
// routes/apis/user.php
// Las rutas CRUD (create, update, delete, etc.) se auto-registran desde user.json
$router->group('/api/user', function($router) {

  // Profile - GET /api/user/profile
  $router->get('/profile', function() {
    $result = userHandlers::profile([]);
    response::json($result);
  })->middleware('auth');

  // Update Config - PUT /api/user/{id}/config
  $router->put('/{id}/config', function($id) {
    $result = userHandlers::updateConfig(['id' => $id]);
    response::json($result);
  })->middleware(['auth', 'json']);

  // UTILIDADES DE DESARROLLO
  // Generate Password Hash - GET /api/user/generatepass/{key}
  if (IS_DEV) {
    $router->get('/generatepass/{key}', function($key) {
      if (empty($key)) {
        response::json([
          'success' => false,
          'error' => __('user.password.required')
        ]);
      }

      $hash = password_hash($key, PASSWORD_BCRYPT);

      response::success([
        'password' => $key,
        'hash' => $hash,
        'algorithm' => 'bcrypt',
        'cost' => 10,
        'verify_test' => password_verify($key, $hash),
        'length' => strlen($hash),
        'example_sql' => "UPDATE user SET pass = '{$hash}' WHERE id = 1;"
      ]);
    });
  }
});

// ============================================
// NOTA: Las rutas CRUD se registran automáticamente desde user.json:
// - GET    /api/user           (list)
// - GET    /api/user/{id}      (show)
// - POST   /api/user           (create)   ← Auto-registrada
// - PUT    /api/user/{id}      (update)   ← Auto-registrada
// - DELETE /api/user/{id}      (delete)   ← Auto-registrada
// ============================================