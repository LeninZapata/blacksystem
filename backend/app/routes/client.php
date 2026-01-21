<?php
// routes/apis/client.php - Rutas custom de client

$router->group('/api/client', function($router) {

  // Eliminar todos los datos del cliente por ID - DELETE /api/client/{id}/all-data
  $router->delete('/{id}/all-data', function($id) {
    ogResponse::json( ogApp()->handler('client')::deleteAllData(['id' => $id]) );
  })->middleware(['auth', 'throttle:100,1']);

  // Eliminar todos los datos del cliente por número - DELETE /api/client/number/{number}/all-data
  $router->delete('/number/{number}/all-data', function($number) {
    ogResponse::json( ogApp()->handler('client')::deleteAllDataByNumber(['number' => $number]) );
  })->middleware(['throttle:100,1']);

  // Obtener todos los datos del cliente por número - GET /api/client/number/{number}/all-data
  $router->get('/number/{number}/all-data', function($number) {
    ogResponse::json( ogApp()->handler('client')::getAllDataByNumber(['number' => $number]) );
  })->middleware(['throttle:100,1']);

  // Buscar cliente por número - GET /api/client/number/{number}
  $router->get('/number/{number}', function($number) {
    ogResponse::json( ogApp()->handler('client')::getByNumber(['number' => $number]) );
  })->middleware(['auth', 'throttle:100,1']);

  // Buscar cliente por email - GET /api/client/email/{email}
  $router->get('/email/{email}', function($email) {
    ogResponse::json( ogApp()->handler('client')::getByEmail(['email' => $email]) );
  })->middleware(['auth', 'throttle:100,1']);

  // Top clientes por monto gastado - GET /api/client/top
  $router->get('/top', function() {
    ogResponse::json( ogApp()->handler('client')::topClients([]) );
  })->middleware(['auth', 'throttle:100,1']);

  // Estadísticas de clientes - GET /api/client/stats
  $router->get('/stats', function() {
    ogResponse::json( ogApp()->handler('client')::getStats([]) );
  })->middleware(['auth', 'throttle:100,1']);

  // Incrementar compra de cliente - POST /api/client/{id}/purchase
  $router->post('/{id}/purchase', function($id) {
    $data = ogRequest::data();
    $amount = $data['amount'] ?? 0;
    ogResponse::json( ogApp()->handler('client')::incrementPurchase($id, $amount) );
  })->middleware(['auth', 'json', 'throttle:100,1']);

  // Estadísticas: Clientes nuevos por día - GET /api/client/stats/new-by-day?range=last_7_days
  $router->get('/stats/new-by-day', function() {
    $range = ogRequest::query('range', 'last_7_days');
    ogResponse::json( ogApp()->handler('clientStats')::getNewClientsByDay(['range' => $range]) );
  })->middleware(['auth', 'throttle:100,1']);

});