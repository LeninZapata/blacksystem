<?php
// routes/system.php - Rutas de sistema y limpieza

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

  // Listar estructura de tablas - GET /api/system/tables?names=users,products&format=mini
  $router->get('/tables', function() {
    $requestedTables = ogRequest::query('names');
    $format = ogRequest::query('format', 'mini');

    // Ahora ogDb::raw funciona directamente con array vacío
    $allTablesResult = ogDb::raw("SHOW TABLES", []);
    $allTables = array_column($allTablesResult, array_keys($allTablesResult[0])[0]);

    // Filtrar tablas si se especificaron
    if ($requestedTables) {
      $requestedArray = array_map('trim', explode(',', $requestedTables));
      $tables = array_filter($allTables, fn($t) => in_array($t, $requestedArray));
    } else {
      $tables = $allTables;
    }

    $structure = [];

    foreach ($tables as $table) {
      // Obtener estructura de la tabla
      $columns = ogDb::raw("
        SELECT
          COLUMN_NAME as field,
          COLUMN_TYPE as type,
          IS_NULLABLE as nullable,
          COLUMN_COMMENT as comment
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
        ORDER BY ORDINAL_POSITION
      ", [$table]);

      // Obtener comentario de la tabla
      $tableCommentResult = ogDb::raw("
        SELECT TABLE_COMMENT
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
      ", [$table]);

      $tableComment = !empty($tableCommentResult) ? $tableCommentResult[0]['TABLE_COMMENT'] : '';

      $structure[$table] = [
        'comment' => $tableComment,
        'columns' => $columns
      ];
    }

    // Formato JSON
    if ($format === 'json') {
      ogResponse::json([
        'success' => true,
        'tables' => $structure
      ]);
      return;
    }

    // Formato HTML minimalista
    if ($format === 'html') {
      header('Content-Type: text/html; charset=utf-8');

      echo "<!DOCTYPE html>";
      echo "<html><head>";
      echo "<meta charset='utf-8'>";
      echo "<title>Estructura BD</title>";
      echo "<style>";
      echo "body { font-family: monospace; padding: 20px; max-width: 1200px; margin: 0 auto; }";
      echo "h2 { color: #333; border-bottom: 2px solid #ddd; padding-bottom: 5px; }";
      echo ".desc { color: #666; font-style: italic; margin: 5px 0 15px 0; }";
      echo "table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }";
      echo "th { background: #f5f5f5; text-align: left; padding: 8px; border: 1px solid #ddd; }";
      echo "td { padding: 8px; border: 1px solid #ddd; }";
      echo "hr { border: none; border-top: 2px solid #ddd; margin: 40px 0; }";
      echo "</style>";
      echo "</head><body>";

      foreach ($structure as $tableName => $tableInfo) {
        echo "<h2>{$tableName}</h2>";

        if (!empty($tableInfo['comment'])) {
          echo "<div class='desc'>{$tableInfo['comment']}</div>";
        }

        echo "<table>";
        echo "<tr><th>Campo</th><th>Tipo</th><th>Null</th><th>Descripción</th></tr>";

        foreach ($tableInfo['columns'] as $col) {
          $comment = $col['comment'] ? htmlspecialchars($col['comment']) : '-';
          $nullable = $col['nullable'] === 'YES' ? 'NULL' : 'NOT NULL';
          echo "<tr>";
          echo "<td><strong>{$col['field']}</strong></td>";
          echo "<td>{$col['type']}</td>";
          echo "<td>{$nullable}</td>";
          echo "<td>{$comment}</td>";
          echo "</tr>";
        }

        echo "</table>";
        echo "<hr>";
      }

      echo "</body></html>";
      exit;
    }

    // Formato minimalista (default)
    header('Content-Type: text/plain; charset=utf-8');

    foreach ($structure as $tableName => $tableInfo) {
      echo "{$tableName}";

      if (!empty($tableInfo['comment'])) {
        echo ": {$tableInfo['comment']}";
      }

      echo "\n";

      foreach ($tableInfo['columns'] as $col) {
        $comment = $col['comment'] ?: '-';
        $nullable = $col['nullable'] === 'YES' ? 'NULL' : 'NOT NULL';
        echo "- {$col['field']} | {$col['type']} | {$nullable} | {$comment}\n";
      }

      echo str_repeat('-', 50) . "\n";
    }

    exit;
  })->middleware(['dev']);

});