<?php
// routes/apis/user.php
// Las rutas CRUD (create, update, delete, etc.) se auto-registran desde user.json
$router->group('/api/user', function($router) {

  // Perfil del usuario autenticado
  $router->get('/profile', function() {
    ogApp()->loadHandler('UserHandler');
    $result = UserHandler::profile([]);
    ogResponse::json($result);
  })->middleware('auth');

  // Actualizar configuración del usuario
  $router->put('/{id}/config', function($id) {
    ogApp()->loadHandler('UserHandler');
    $result = UserHandler::updateConfig(['id' => $id]);
    ogResponse::json($result);
  })->middleware(['auth', 'json']);


  if (OG_IS_DEV) {

    // Generar hash de contraseña
    $router->get('/generatepass/{key}', function($key) {
      if (empty($key)) {
        ogResponse::json([
          'success' => false,
          'error' => __('user.password.required')
        ]);
      }

      $hash = password_hash($key, PASSWORD_BCRYPT);

      ogResponse::success([
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