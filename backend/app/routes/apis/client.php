<?php
// routes/apis/client.php - Rutas custom de client

$router->group('/api/client', function($router) {

  // Eliminar todos los datos del cliente - DELETE /api/client/{id}/all-data
  $router->delete('/{id}/all-data', function($id) {
    $result = clientHandlers::deleteAllData(['id' => $id]);
    response::json($result);
  })->middleware(['auth', 'throttle:100,1']);

  // Buscar cliente por número - GET /api/client/number/{number}
  $router->get('/number/{number}', function($number) {
    $result = clientHandlers::getByNumber(['number' => $number]);
    response::json($result);
  })->middleware(['auth', 'throttle:100,1']);

  // Top clientes por monto gastado - GET /api/client/top
  $router->get('/top', function() {
    $result = clientHandlers::topClients([]);
    response::json($result);
  })->middleware(['auth', 'throttle:100,1']);
});