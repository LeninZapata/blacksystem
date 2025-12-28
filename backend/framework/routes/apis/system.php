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

    ogResponse::success([
      'php_version' => PHP_VERSION,
      'environment' => OG_IS_DEV ? 'development' : 'production',
      'storage_path' => STORAGE_PATH,
      'sessions_active' => $activeCount
    ]);
  })->middleware('auth');

  // Verificar estado del sistema
  $router->get('/health', function() {
    ogResponse::success([
      'status' => 'ok',
      'timestamp' => date('Y-m-d H:i:s')
    ]);
  });

  // Listar todos los endpoints del sistema
  $router->get('/routes', function() {
    $routes = ogApp()->helper('routeDiscovery')->getAllRoutes();
    $stats = ogApp()->helper('routeDiscovery')->getStats($routes);

    // Filtrar por método
    $method = ogRequest::query('method');
    if ($method) {
      $routes = array_filter($routes, fn($r) => strtoupper($r['method']) === strtoupper($method));
      $routes = array_values($routes);
    }

    // Filtrar por source
    $source = ogRequest::query('source');
    if ($source) {
      $routes = array_filter($routes, fn($r) => str_contains($r['source'], $source));
      $routes = array_values($routes);
    }

    // Agrupar por recurso
    $grouped = ogRequest::query('grouped');
    if ($grouped === 'true') {
      $routes = ogApp()->helper('routeDiscovery')->groupByResource($routes);
    }

    ogResponse::success([
      'routes' => $routes,
      'stats' => $stats
    ]);
  });

  // Test zona horaria (deshabilitado en producción)
  /*$router->get('/timezone-test', function() {
    ogResponse::success([
        'timezone' => date_default_timezone_get(),
        'current_time' => date('Y-m-d H:i:s'),
        'timestamp' => time(),
        'timezone_constant' => OG_TIMEZONE
    ]);
  });*/

  $router->get('/logs-test', function() {
    ogLog::debug('This is a debug log test from /api/system/logs-test',['data' => 1], ['module' => 'apis', 'layer' => 'app', 'tags' => ['test-tag', '098989899']] );
  });

});