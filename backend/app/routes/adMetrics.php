<?php
// routes/ad-metrics.php - Rutas para métricas publicitarias

$router->group('/api/ad-metrics', function($router) {

  // Obtener métricas por product_id
  // GET /api/ad-metrics/product/{product_id}?date_from=2024-01-01&date_to=2024-01-31
  $router->get('/product/{product_id}', function($productId) {
    ogResponse::json(
      ogApp()->handler('adMetrics')::getByProduct(['product_id' => $productId])
    );
  })->middleware(['throttle:100,1']);

  // Obtener métricas por assets específicos
  // GET /api/ad-metrics/assets?assets=[...]&date_from=2024-01-01&date_to=2024-01-31
  $router->get('/assets', function() {
    ogResponse::json(
      ogApp()->handler('adMetrics')::getByAssets([])
    );
  })->middleware(['auth', 'throttle:100,1']);

  // Endpoint de prueba (usando datos del script)
  // GET /api/ad-metrics/test?date_from=2024-01-01&date_to=2024-01-31
  $router->get('/test', function() {
    ogResponse::json(
      ogApp()->handler('adMetrics')::getTestMetrics([])
    );
  })->middleware(['throttle:100,1']);

});