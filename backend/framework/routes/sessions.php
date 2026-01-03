<?php
// routes/sessions.php - Endpoints de sesiones mejorados
// desde CROM curl http://yourdomain.com/api/sessions/cleanup para eliminar session caducadas
$router->group('/api/sessions', function($router) {

  $middleware = OG_IS_DEV ? [] : ['auth'];
  $logMeta = ['module' => 'session', 'layer' => 'app'];

  // Invalidar todas las sesiones de un usuario
  $router->delete('/user/{user_id}', function($userId) use ($logMeta) {
    ogApp()->loadController('UserController');
    $cleaned = UserController::invalidateSessions($userId);

    ogResponse::success([
      'user_id' => (int)$userId,
      'cleaned' => $cleaned
    ], __('api.session.invalidated', ['count' => $cleaned]));
  }); //->middleware($middleware);

});