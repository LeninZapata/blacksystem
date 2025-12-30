<?php
// routes/apis/user.php
// Solo rutas relacionadas con CRUD de usuarios (no autenticacion)
$router->group('/api/user', function($router) {

  // Actualizar configuracion del usuario
  $router->put('/{id}/config', function($id) {
    ogApp()->loadHandler('UserHandler');
    $result = UserHandler::updateConfig(['id' => $id]);
    ogResponse::json($result);
  })->middleware(['auth', 'json']);

  if (OG_IS_DEV) {
    // Generar hash de contraseña (solo desarrollo)
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
// NOTA: Las rutas CRUD se registran automaticamente desde user.json:
// - GET    /api/user           (list)    - Listar usuarios
// - GET    /api/user/{id}      (show)    - Ver un usuario
// - POST   /api/user           (create)  - Crear usuario
// - PUT    /api/user/{id}      (update)  - Actualizar usuario
// - DELETE /api/user/{id}      (delete)  - Eliminar usuario
//
// Las rutas de autenticacion estan en /api/auth:
// - POST /api/auth/login       - Login
// - POST /api/auth/logout      - Logout
// - GET  /api/auth/profile     - Perfil del usuario autenticado
// ============================================