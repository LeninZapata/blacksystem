<?php
// routes/apis/system.php - Rutas de sistema y limpieza

$router->group('/api/system', function($router) {

  // Información general del sistema
  $router->get('/info', function() {
    $sessionsDir = STORAGE_PATH . '/sessions/';
    $activeCount = 0;

    if (is_dir($sessionsDir)) {
      $now = time();
      foreach (scandir($sessionsDir) as $file) {
        if ($file === '.' || $file === '..') continue;
        $parts = explode('_', $file);
        if (count($parts) >= 3 && (int)$parts[0] >= $now) {
          $activeCount++;
        }
      }
    }

    response::success([
      'php_version' => PHP_VERSION,
      'environment' => IS_DEV ? 'development' : 'production',
      'storage_path' => STORAGE_PATH,
      'sessions_active' => $activeCount
    ]);
  })->middleware('auth');

  // Verificar estado del sistema
  $router->get('/health', function() {
    response::success([
      'status' => 'ok',
      'timestamp' => date('Y-m-d H:i:s')
    ]);
  });

  // Listar todos los endpoints del sistema
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

  $router->get('/logs-test', function() {
    log::debug('This is a debug log test from /api/system/logs-test',['data' => 1], ['module' => 'apis', 'layer' => 'app', 'tags' => ['test-tag', '098989899']] );
  });

});