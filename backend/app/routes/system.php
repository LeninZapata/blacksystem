<?php
// routes/apis/system.php - Rutas de sistema y limpieza

$router->group('/api/system', function($router) {

  // Informacion general del sistema
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
 
  // Estadisticas de cache
  $router->get('/cache/stats', function() {
    $cacheDir = STORAGE_PATH . '/cache/';

    if (!is_dir($cacheDir)) {
      ogResponse::success([
        'total' => 0,
        'expired' => 0,
        'active' => 0,
        'total_size_kb' => 0
      ]);
      return;
    }

    $total = 0;
    $expired = 0;
    $active = 0;
    $totalSize = 0;
    $now = time();

    $files = scandir($cacheDir);

    foreach ($files as $file) {
      if ($file === '.' || $file === '..' || !str_ends_with($file, '.cache')) continue;

      $filePath = $cacheDir . $file;
      $total++;
      $totalSize += filesize($filePath);

      $parts = explode('_', $file);
      if (count($parts) >= 2) {
        $expiresAt = (int)$parts[0];

        if ($expiresAt < $now) {
          $expired++;
        } else {
          $active++;
        }
      }
    }

    ogResponse::success([
      'total' => $total,
      'active' => $active,
      'expired' => $expired,
      'total_size_kb' => round($totalSize / 1024, 2),
      'avg_size_kb' => $total > 0 ? round(($totalSize / $total) / 1024, 2) : 0
    ]);
  });

});