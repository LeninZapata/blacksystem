<?php
// routes/apis/system.php - Rutas de sistema y limpieza

$router->group('/api/system', function($router) {

  // ✅ Limpiar sesiones expiradas - GET /api/system/cleanup-sessions
  $router->get('/cleanup-sessions', function() {
    $result = sessionCleanup::clean();
    response::success($result, __('session.cleanup.success'));
  })->middleware('auth');

  // ✅ Estadísticas de sesiones - GET /api/system/sessions-stats
  $router->get('/sessions-stats', function() {
    $stats = sessionCleanup::stats();
    response::success($stats);
  })->middleware('auth');

  // ✅ Info del sistema - GET /api/system/info
  $router->get('/info', function() {
    response::success([
      'php_version' => PHP_VERSION,
      'environment' => IS_DEV ? 'development' : 'production',
      'storage_path' => STORAGE_PATH,
      'sessions_active' => sessionCleanup::stats()['active']
    ]);
  })->middleware('auth');

  // ✅ Health check - GET /api/system/health
  $router->get('/health', function() {
    response::success([
      'status' => 'ok',
      'timestamp' => date('Y-m-d H:i:s')
    ]);
  });

  // ✅ Listar todos los endpoints - GET /api/system/routes
  $router->get('/routes', function() {
    $routes = routeDiscovery::getAllRoutes();
    $stats = routeDiscovery::getStats($routes);

    // Filtrar por método
    $method = request::query('method');
    if ($method) {
      $routes = array_filter($routes, fn($r) => strtoupper($r['method']) === strtoupper($method));
      $routes = array_values($routes);
    }

    // Filtrar por source
    $source = request::query('source');
    if ($source) {
      $routes = array_filter($routes, fn($r) => str_contains($r['source'], $source));
      $routes = array_values($routes);
    }

    // Agrupar por recurso
    $grouped = request::query('grouped');
    if ($grouped === 'true') {
      $routes = routeDiscovery::groupByResource($routes);
    }

    response::success([
      'routes' => $routes,
      'stats' => $stats
    ]);
  });

  // Test zona horaria (deshabilitado en producción)
  /*$router->get('/timezone-test', function() {
    response::success([
        'timezone' => date_default_timezone_get(),
        'current_time' => date('Y-m-d H:i:s'),
        'timestamp' => time(),
        'timezone_constant' => TIMEZONE
    ]);
  });*/


  
});