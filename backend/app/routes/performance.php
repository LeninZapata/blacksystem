<?php
// routes/performance.php - Rutas para métricas de rendimiento publicitario

$router->group('/api/performance', function($router) {

  // Métricas de rendimiento por hora - GET /api/performance/metrics-hourly?date=2026-02-10&bot_id=1&product_id=3
  $router->get('/metrics-hourly', function() {
    $params = [
      'date' => ogRequest::query('date', date('Y-m-d')),
      'bot_id' => ogRequest::query('bot_id', null),
      'product_id' => ogRequest::query('product_id', null)
    ];
    ogResponse::json( ogApp()->handler('performanceStats')::getPerformanceHourly($params) );
  })->middleware(['auth', 'throttle:100,1']);

  // Métricas de rendimiento por día - GET /api/performance/metrics-daily?range=last_7_days&bot_id=1&product_id=3
  $router->get('/metrics-daily', function() {
    $params = [
      'range' => ogRequest::query('range', 'last_7_days'),
      'bot_id' => ogRequest::query('bot_id', null),
      'product_id' => ogRequest::query('product_id', null)
    ];
    ogResponse::json( ogApp()->handler('performanceStats')::getPerformanceDaily($params) );
  })->middleware(['auth', 'throttle:100,1']);

});
