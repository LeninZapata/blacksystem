<?php
// routes/profit.php - Rutas para estadísticas de profit (ganancias)

$router->group('/api/profit', function($router) {

  // Obtener profit por hora - Para hoy/ayer
  // GET /api/profit/hourly?date=2026-02-10&bot_id=1&product_id=5
  $router->get('/hourly', function() {
    ogResponse::json(
      ogApp()->handler('ProfitStats')::getProfitHourly([])
    );
  })->middleware(['auth', 'throttle:100,1']);

  // Obtener profit por día - Para rangos históricos
  // GET /api/profit/daily?range=last_7_days&bot_id=1&product_id=5
  $router->get('/daily', function() {
    ogResponse::json(
      ogApp()->handler('ProfitStats')::getProfitDaily([])
    );
  })->middleware(['auth', 'throttle:100,1']);

  // Resumen de profit por producto - Para tab Resumen en Automation
  // GET /api/profit/summary-by-product?bot_id=1&range=today
  // GET /api/profit/summary-by-product?bot_id=1&range=last_7_days
  // GET /api/profit/summary-by-product?bot_id=1&range=custom_date&date=2026-03-10
  $router->get('/summary-by-product', function() {
    ogResponse::json(
      ogApp()->handler('ProfitStats')::getSummaryByProduct([])
    );
  })->middleware(['auth', 'throttle:100,1']);

});