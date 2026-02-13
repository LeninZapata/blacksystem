<?php
// routes/apis/sale.php
$router->group('/api/sale', function($router) {

  // Obtener ventas por cliente
  $router->get('/client/{client_id}', function($client_id) {
    ogResponse::json(ogApp()->handler('sale')::getByClient(['client_id' => $client_id]));
  })->middleware(['auth', 'throttle:100,1']);

  // Obtener ventas por bot
  $router->get('/bot/{bot_id}', function($bot_id) {
    ogResponse::json(ogApp()->handler('sale')::getByBot(['bot_id' => $bot_id]));
  })->middleware(['auth', 'throttle:100,1']);

  // Obtener ventas por producto
  $router->get('/product/{product_id}', function($product_id) {
    ogResponse::json(ogApp()->handler('sale')::getByProduct(['product_id' => $product_id]));
  })->middleware(['auth', 'throttle:100,1']);

  // Obtener ventas por estado
  $router->get('/status/{status}', function($status) {
    ogResponse::json(ogApp()->handler('sale')::getByStatus(['status' => $status]));
  })->middleware(['auth', 'throttle:100,1']);

  // Actualizar estado de venta
  $router->put('/{id}/status', function($id) {
    $data = ogRequest::data();
    $status = $data['status'] ?? null;
    if (!$status) {
      ogResponse::json(['success' => false, 'error' => __('sale.status_required')], 400);
    }
    ogResponse::json(ogApp()->handler('sale')::updateStatus($id, $status));
  })->middleware(['auth', 'json', 'throttle:100,1']);

  // Registrar pago
  $router->post('/{id}/payment', function($id) {
    $data = ogRequest::data();
    $transactionId = $data['transaction_id'] ?? null;
    $paymentMethod = $data['payment_method'] ?? null;
    $paymentDate = $data['payment_date'] ?? null;

    if (!$transactionId || !$paymentMethod) {
      ogResponse::json(['success' => false, 'error' => __('sale.payment_data_required')], 400);
    }

    $result = ogApp()->handler('sale')::registerPayment($id, $transactionId, $paymentMethod, $paymentDate);
    ogResponse::json($result);
  })->middleware(['auth', 'json', 'throttle:100,1']);

  // Estadísticas de ventas
  $router->get('/stats', function() {
    $result = ogApp()->handler('sale')::getStats(['bot_id' => ogRequest::query('bot_id', null)]);
    ogResponse::json($result);
  })->middleware(['auth', 'throttle:100,1']);

  // Obtener venta con relaciones (upsells/OB)
  $router->get('/{id}/related', function($id) {
    ogResponse::json(ogApp()->handler('sale')::getWithRelated(['sale_id' => $id]));
  })->middleware(['auth', 'throttle:100,1']);

  // Buscar por transaction_id
  $router->get('/transaction/{transaction_id}', function($transaction_id) {
    ogResponse::json(ogApp()->handler('sale')::getByTransactionId(['transaction_id' => $transaction_id]));
  })->middleware(['auth', 'throttle:100,1']);

  // Eliminar ventas por cliente
  $router->delete('/client/{client_id}', function($client_id) {
    ogResponse::json(ogApp()->handler('sale')::deleteByClient(['client_id' => $client_id]));
  })->middleware(['auth', 'throttle:100,1']);

  // Estadísticas: Ventas $ y Conversión % - GET /api/sale/stats/revenue-conversion?range=last_7_days&bot_id=1&product_id=3
  $router->get('/stats/revenue-conversion', function() {
    $params = [
      'range' => ogRequest::query('range', 'last_7_days'),
      'bot_id' => ogRequest::query('bot_id', null),
      'product_id' => ogRequest::query('product_id', null)
    ];
    ogResponse::json( ogApp()->handler('saleStats')::getSalesRevenueAndConversion($params) );
  })->middleware(['auth', 'throttle:100,1']);

  // Estadísticas: Ventas Directas vs Remarketing - GET /api/sale/stats/direct-vs-remarketing?range=last_7_days
  $router->get('/stats/direct-vs-remarketing', function() {
    $range = ogRequest::query('range', 'last_7_days');
    ogResponse::json( ogApp()->handler('saleStats')::getSalesDirectVsRemarketing(['range' => $range]) );
  })->middleware(['throttle:100,1','auth']);

  // Estadísticas: Ventas por hora - GET /api/sale/stats/hourly?date=2026-02-10&bot_id=1&product_id=3
  $router->get('/stats/hourly', function() {
    $params = [
      'date' => ogRequest::query('date', date('Y-m-d')),
      'bot_id' => ogRequest::query('bot_id', null),
      'product_id' => ogRequest::query('product_id', null)
    ];
    ogResponse::json( ogApp()->handler('saleStats')::getSalesHourly($params) );
  })->middleware(['auth', 'throttle:100,1']);

});