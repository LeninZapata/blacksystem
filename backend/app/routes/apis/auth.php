<?php
// routes/apis/auth.php - Solo rutas especiales de autenticación
$router->group('/api/auth', function($router) {

  // RUTAS ESPECIALES DE AUTENTICACIÓN

  // Autenticar usuario en el sistema
  $router->post('/login', function() {
    $result = AuthHandler::login([]);
    response::json($result);
  })->middleware(['json', 'throttle:10,1']);

  // Cerrar sesión del usuario
  $router->post('/logout', function() {
    $result = AuthHandler::logout([]);
    response::json($result);
  })->middleware('auth');

});