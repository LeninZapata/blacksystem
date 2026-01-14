<?php
// routes/auth.php - Todas las rutas de autenticacion
$router->group('/api/auth', function($router) {

  // @example {"username": "admin", "password": "123456"}
  // Login - autenticar usuario
  $router->post('/login', function() {
    ogApp()->loadHandler('auth');
    $result = AuthHandler::login([]);
    ogResponse::json($result);
  })->middleware(['json', 'throttle:10,1']);

  // Logout - cerrar sesion
  $router->post('/logout', function() {
    ogApp()->loadHandler('auth');
    $result = AuthHandler::logout([]);
    ogResponse::json($result);
  })->middleware('auth');

  // Profile - obtener perfil del usuario autenticado
  $router->get('/profile', function() {
    ogApp()->loadHandler('auth');
    $result = AuthHandler::profile([]);
    ogResponse::json($result);
  })->middleware('auth');

  // Me - alias de profile (para compatibilidad)
  $router->get('/me', function() {
    ogApp()->loadHandler('auth');
    $result = AuthHandler::profile([]);
    ogResponse::json($result);
  })->middleware('auth');

});