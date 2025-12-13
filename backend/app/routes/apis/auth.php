<?php
// routes/apis/auth.php - Solo rutas especiales de autenticación
$router->group('/api/auth', function($router) {

  // RUTAS ESPECIALES DE AUTENTICACIÓN

  // Login - POST /api/auth/login
  $router->post('/login', function() {
    $result = authHandlers::login([]);
    response::json($result);
  })->middleware(['json', 'throttle:10,1']);

  // Logout - POST /api/auth/logout
  $router->post('/logout', function() {
    $result = authHandlers::logout([]);
    response::json($result);
  })->middleware('auth');

});