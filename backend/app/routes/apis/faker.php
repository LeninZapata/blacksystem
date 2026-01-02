<?php
// routes/apis/faker.php

$router->group('/api/faker', function($router) {

  // Generar datos fake - GET /api/faker?num=100&start_date=2025-01-01&end_date=2025-01-31
  $router->get('', function() {
    $params = [
      'num' => ogRequest::query('num', 50),
      'start_date' => ogRequest::query('start_date', date('Y-m-01')),
      'end_date' => ogRequest::query('end_date', date('Y-m-t'))
    ];
    ogResponse::json(ogApp()->handler('faker')::generate($params));
  })->middleware(OG_IS_DEV ? [] : ['auth']);

  // Limpiar todos los datos faker - DELETE /api/faker
  $router->delete('', function() {
    ogResponse::json(ogApp()->handler('faker')::clean());
  })->middleware(OG_IS_DEV ? [] : ['auth']);

  // Generar métricas publicitarias - GET /api/faker/ad-metrics?num=10
  $router->get('/ad-metrics', function() {
    $params = [
      'num' => ogRequest::query('num', 10),
      'start_date' => ogRequest::query('start_date', date('Y-m-01')),
      'end_date' => ogRequest::query('end_date', date('Y-m-t'))
    ];
    ogResponse::json(ogApp()->handler('faker')::generateAdMetrics($params));
  })->middleware(OG_IS_DEV ? [] : ['auth']);

  // Limpiar métricas publicitarias - DELETE /api/faker/ad-metrics
  $router->delete('/ad-metrics', function() {
    ogResponse::json(ogApp()->handler('faker')::cleanAdMetrics());
  })->middleware(OG_IS_DEV ? [] : ['auth']);

});

// ============================================
// ENDPOINTS:
//
// VENTAS Y CLIENTES:
// - GET    /api/faker                          -> Genera datos fake (num, start_date, end_date)
// - DELETE /api/faker                          -> Limpia todos los datos
//
// MÉTRICAS PUBLICITARIAS:
// - GET    /api/faker/ad-metrics               -> Genera métricas publicitarias (num, start_date, end_date)
// - DELETE /api/faker/ad-metrics               -> Limpia métricas publicitarias
//
// EJEMPLOS:
// Ventas:
// - /api/faker                                 -> 50 registros del mes actual
// - /api/faker?num=200                         -> 200 registros del mes actual
// - /api/faker?num=100&start_date=2025-01-01&end_date=2025-01-31
//
// Métricas publicitarias:
// - /api/faker/ad-metrics                      -> 10 productos con métricas del mes actual
// - /api/faker/ad-metrics?num=5                -> 5 productos con métricas
// - /api/faker/ad-metrics?num=10&start_date=2025-01-01&end_date=2025-01-31
// ============================================