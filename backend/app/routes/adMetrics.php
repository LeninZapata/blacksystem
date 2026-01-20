<?php
// routes/adMetrics.php - Rutas para métricas publicitarias

$router->group('/api/adMetrics', function($router) {

  // Obtener métricas por product_id
  // GET /api/adMetrics/product/{product_id}?date_from=2024-01-01&date_to=2024-01-31
  $router->get('/product/{product_id}', function($productId) {
    ogResponse::json(
      ogApp()->handler('AdMetrics')::getByProduct(['product_id' => $productId])
    );
  })->middleware(['auth', 'throttle:100,1']);

  // Obtener métricas por assets específicos
  // GET /api/adMetrics/assets?assets=[...]&date_from=2024-01-01&date_to=2024-01-31
  $router->get('/assets', function() {
    ogResponse::json(
      ogApp()->handler('AdMetrics')::getByAssets([])
    );
  })->middleware(['auth', 'throttle:100,1']);

  // Endpoint de prueba (usando datos del script)
  // GET /api/adMetrics/test?date_from=2024-01-01&date_to=2024-01-31
  $router->get('/test', function() {
    ogResponse::json(
      ogApp()->handler('AdMetrics')::getTestMetrics([])
    );
  })->middleware(['throttle:100,1']);

  // Guardar métricas HOURLY de todos los productos (CRON cada hora)
  // GET /api/adMetrics/save-all
  $router->get('/save-all', function() {
    ogResponse::json(
      ogApp()->handler('AdMetrics')::saveAllMetrics([])
    );
  })->middleware(['throttle:10,1']);

  // Guardar métricas DIARIAS del día anterior (CRON 1am, 3am)
  // GET /api/adMetrics/save-daily
  $router->get('/save-daily', function() {
    ogResponse::json(
      ogApp()->handler('AdMetrics')::saveDailyMetrics([])
    );
  })->middleware(['throttle:10,1']);

  // Gastos publicitarios por día - GET /api/adMetrics/spend-by-day?range=last_7_days
  $router->get('/spend-by-day', function() {
    $range = ogRequest::query('range', 'last_7_days');
    ogResponse::json(
      ogApp()->handler('AdMetrics')::getAdSpendByDay(['range' => $range])
    );
  })->middleware(['throttle:100,1','auth']);

  // Gastos publicitarios por producto - GET /api/adMetrics/spend-by-product?range=last_7_days
  $router->get('/spend-by-product', function() {
    $range = ogRequest::query('range', 'last_7_days');
    ogResponse::json(
      ogApp()->handler('AdMetrics')::getAdSpendByProduct(['range' => $range])
    );
  })->middleware(['auth', 'throttle:100,1']);

  // Obtener presupuesto gastado y actual de un activo
  // GET /api/adMetrics/budget-status?ad_asset_id=120232490086400388&date_from=2026-01-18&date_to=2026-01-18&real_time=false
  $router->get('/budget-status', function() {
    ogResponse::json(
      ogApp()->handler('adMetrics')::getBudgetStatus([])
    );
  })->middleware(['throttle:100,1']);

});