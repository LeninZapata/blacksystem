<?php
// routes/apis/system.php - Rutas de sistema y limpieza

$router->group('/api/system', function($router) {

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

    // Filtrar por metodo
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

  $router->get('/logs-test', function() {
    ogLog::debug('This is a debug log test from /api/system/logs-test',['data' => 1], ['module' => 'apis', 'layer' => 'app', 'tags' => ['test-tag', '098989899']] );
  });

  // Limpiar cache expirado
  $router->delete('/cache/cleanup', function() {
    // Limpiar cache normal
    $cleanedCache = ogApp()->helper('cache')::cleanup('default');

    // Limpiar sesiones (si existe la config)
    $cleanedSessions = ogApp()->helper('cache')::cleanup('session');

    ogResponse::success([
      'cache' => $cleanedCache,
      'sessions' => $cleanedSessions,
      'total' => $cleanedCache + $cleanedSessions
    ], __('api.system.cache_cleanup_success', ['count' => $cleanedCache + $cleanedSessions]));
  });

});