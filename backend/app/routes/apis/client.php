<?php
// routes/apis/client.php - Rutas custom de client

$router->group('/api/client', function($router) {

  // Eliminar todos los datos del cliente por ID - DELETE /api/client/{id}/all-data
  $router->delete('/{id}/all-data', function($id) {
    $result = clientHandlers::deleteAllData(['id' => $id]);
    response::json($result);
  })->middleware(['auth', 'throttle:100,1']);

  // Eliminar todos los datos del cliente por número - DELETE /api/client/number/{number}/all-data
  $router->delete('/number/{number}/all-data', function($number) {
    $result = clientHandlers::deleteAllDataByNumber(['number' => $number]);
    response::json($result);
  })->middleware(['throttle:100,1']);

  // Buscar cliente por número - GET /api/client/number/{number}
  $router->get('/number/{number}', function($number) {
    $result = clientHandlers::getByNumber(['number' => $number]);
    response::json($result);
  })->middleware(['auth', 'throttle:100,1']);

  // Buscar cliente por email - GET /api/client/email/{email}
  $router->get('/email/{email}', function($email) {
    $result = clientHandlers::getByEmail(['email' => $email]);
    response::json($result);
  })->middleware(['auth', 'throttle:100,1']);

  // Top clientes por monto gastado - GET /api/client/top
  $router->get('/top', function() {
    $result = clientHandlers::topClients([]);
    response::json($result);
  })->middleware(['auth', 'throttle:100,1']);

  // Estadísticas de clientes - GET /api/client/stats
  $router->get('/stats', function() {
    $result = clientHandlers::getStats([]);
    response::json($result);
  })->middleware(['auth', 'throttle:100,1']);

  // Incrementar compra de cliente - POST /api/client/{id}/purchase
  $router->post('/{id}/purchase', function($id) {
    $data = request::data();
    $amount = $data['amount'] ?? 0;
    
    $result = clientHandlers::incrementPurchase($id, $amount);
    response::json($result);
  })->middleware(['auth', 'json', 'throttle:100,1']);
});