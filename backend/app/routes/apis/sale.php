<?php
// routes/apis/sale.php - Rutas custom de sale

$router->group('/api/sale', function($router) {

  // Obtener ventas por cliente - GET /api/sale/client/{client_id}
  $router->get('/client/{client_id}', function($client_id) {
    $result = SaleHandlers::getByClient(['client_id' => $client_id]);
    response::json($result);
  })->middleware(['auth', 'throttle:100,1']);

  // Obtener ventas por bot - GET /api/sale/bot/{bot_id}
  $router->get('/bot/{bot_id}', function($bot_id) {
    $result = SaleHandlers::getByBot(['bot_id' => $bot_id]);
    response::json($result);
  })->middleware(['auth', 'throttle:100,1']);

  // Obtener ventas por producto - GET /api/sale/product/{product_id}
  $router->get('/product/{product_id}', function($product_id) {
    $result = SaleHandlers::getByProduct(['product_id' => $product_id]);
    response::json($result);
  })->middleware(['auth', 'throttle:100,1']);

  // Obtener ventas por estado - GET /api/sale/status/{status}
  $router->get('/status/{status}', function($status) {
    $result = SaleHandlers::getByStatus(['status' => $status]);
    response::json($result);
  })->middleware(['auth', 'throttle:100,1']);

  // Actualizar estado de venta - PUT /api/sale/{id}/status
  $router->put('/{id}/status', function($id) {
    $data = request::data();
    $status = $data['status'] ?? null;
    
    if (!$status) {
      response::json(['success' => false, 'error' => __('sale.status_required')], 400);
    }
    
    $result = SaleHandlers::updateStatus($id, $status);
    response::json($result);
  })->middleware(['auth', 'json', 'throttle:100,1']);

  // Registrar pago - POST /api/sale/{id}/payment
  $router->post('/{id}/payment', function($id) {
    $data = request::data();
    $transactionId = $data['transaction_id'] ?? null;
    $paymentMethod = $data['payment_method'] ?? null;
    $paymentDate = $data['payment_date'] ?? null;
    
    if (!$transactionId || !$paymentMethod) {
      response::json(['success' => false, 'error' => __('sale.payment_data_required')], 400);
    }
    
    $result = SaleHandlers::registerPayment($id, $transactionId, $paymentMethod, $paymentDate);
    response::json($result);
  })->middleware(['auth', 'json', 'throttle:100,1']);

  // Estadísticas de ventas - GET /api/sale/stats
  $router->get('/stats', function() {
    $botId = request::query('bot_id', null);
    $result = SaleHandlers::getStats(['bot_id' => $botId]);
    response::json($result);
  })->middleware(['auth', 'throttle:100,1']);

  // Obtener venta con relaciones (upsells/OB) - GET /api/sale/{id}/related
  $router->get('/{id}/related', function($id) {
    $result = SaleHandlers::getWithRelated(['sale_id' => $id]);
    response::json($result);
  })->middleware(['auth', 'throttle:100,1']);

  // Buscar por transaction_id - GET /api/sale/transaction/{transaction_id}
  $router->get('/transaction/{transaction_id}', function($transaction_id) {
    $result = SaleHandlers::getByTransactionId(['transaction_id' => $transaction_id]);
    response::json($result);
  })->middleware(['auth', 'throttle:100,1']);

  // Eliminar ventas por cliente - DELETE /api/sale/client/{client_id}
  $router->delete('/client/{client_id}', function($client_id) {
    $result = SaleHandlers::deleteByClient(['client_id' => $client_id]);
    response::json($result);
  })->middleware(['auth', 'throttle:100,1']);
});