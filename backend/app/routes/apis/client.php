<?php
// routes/apis/client.php - Rutas custom de client

$router->group('/api/client', function($router) {

  // Eliminar todos los datos del cliente por ID - DELETE /api/client/{id}/all-data
  $router->delete('/{id}/all-data', function($id) {
    ogApp()->loadHandler('client');
    $result = ClientHandler::deleteAllData(['id' => $id]);
    ogResponse::json($result);
  })->middleware(['auth', 'throttle:100,1']);

  // Eliminar todos los datos del cliente por número - DELETE /api/client/number/{number}/all-data
  $router->delete('/number/{number}/all-data', function($number) {
    ogApp()->loadHandler('client');
    $result = ClientHandler::deleteAllDataByNumber(['number' => $number]);
    ogResponse::json($result);
  })->middleware(['throttle:100,1']);

  // Buscar cliente por número - GET /api/client/number/{number}
  $router->get('/number/{number}', function($number) {
    ogApp()->loadHandler('client');
    $result = ClientHandler::getByNumber(['number' => $number]);
    ogResponse::json($result);
  })->middleware(['auth', 'throttle:100,1']);

  // Buscar cliente por email - GET /api/client/email/{email}
  $router->get('/email/{email}', function($email) {
    ogApp()->loadHandler('client');
    $result = ClientHandler::getByEmail(['email' => $email]);
    ogResponse::json($result);
  })->middleware(['auth', 'throttle:100,1']);

  // Top clientes por monto gastado - GET /api/client/top
  $router->get('/top', function() {
    ogApp()->loadHandler('client');
    $result = ClientHandler::topClients([]);
    ogResponse::json($result);
  })->middleware(['auth', 'throttle:100,1']);

  // Estadísticas de clientes - GET /api/client/stats
  $router->get('/stats', function() {
    ogApp()->loadHandler('client');
    $result = ClientHandler::getStats([]);
    ogResponse::json($result);
  })->middleware(['auth', 'throttle:100,1']);

  // Incrementar compra de cliente - POST /api/client/{id}/purchase
  $router->post('/{id}/purchase', function($id) {
    ogApp()->loadHandler('client');
    $data = ogRequest::data();
    $amount = $data['amount'] ?? 0;
    
    $result = ClientHandler::incrementPurchase($id, $amount);
    ogResponse::json($result);
  })->middleware(['auth', 'json', 'throttle:100,1']);
});