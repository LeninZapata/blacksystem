<?php
/**
 * routes/apis/logs.php - Endpoints de logs usando logReader
 *
 * Toda la lógica de lectura y filtrado está en logReader
 * Este archivo solo maneja las rutas y respuestas
 *
 * EJEMPLOS DE USO:
 *
 * 1. Logs de hoy:
 *    GET /api/logs/today
 *    GET /api/logs/today?limit=50
 *    GET /api/logs/today?module=integrations/whatsapp
 *    GET /api/logs/today?tags=whatsapp,error
 *    GET /api/logs/today?number=593987654321&bot_id=10
 *
 * 2. Logs de fecha específica:
 *    GET /api/logs/2025/12/08
 *    GET /api/logs/2025/12/08?module=integrations/whatsapp
 *    GET /api/logs/2025/12/08?tags=whatsapp&level=ERROR
 *    GET /api/logs/2025/12/08?number=593987654321
 *
 * 3. Búsqueda con rango de fechas:
 *    GET /api/logs/search?from=2025-12-01&to=2025-12-08
 *    GET /api/logs/search?module=integrations/whatsapp&from=2025-12-01
 *    GET /api/logs/search?number=593987654321&tags=error
 *    GET /api/logs/search?bot_id=10&level=ERROR
 *
 * 4. Últimos logs:
 *    GET /api/logs/latest?limit=20
 *
 * 5. Estadísticas:
 *    GET /api/logs/stats
 *    GET /api/logs/stats?module=integrations/whatsapp
 *
 * FILTROS DISPONIBLES (query params):
 * - limit: Número máximo de logs (default: varía por endpoint)
 * - level: DEBUG, INFO, WARNING, ERROR
 * - module: Módulo específico (ej: integrations/whatsapp, auth, bot)
 * - tags: Tags separados por coma (ej: whatsapp,error o whatsapp,message)
 * - search: Buscar en mensaje o contexto
 * - number: Filtrar por número de teléfono (custom var)
 * - bot_id: Filtrar por bot_id (custom var)
 * - user_id: Filtrar por user_id (custom var)
 * - client_id: Filtrar por client_id (custom var)
 * - [cualquier custom var]: Se busca en el contexto del log
 *
 * EJEMPLOS COMBINADOS:
 * GET /api/logs/today?module=integrations/whatsapp&number=593987654321&tags=error
 * GET /api/logs/search?bot_id=10&level=ERROR&from=2025-12-01&to=2025-12-08
 * GET /api/logs/2025/12/08?tags=whatsapp,telegram&level=WARNING
 */

$router->group('/api/logs', function($router) {

  $middleware = OG_IS_DEV ? [] : ['auth'];

  // Obtener logs del día actual
  $router->get('/today', function() {
    $limit = (int) ogRequest::query('limit', 100);
    $logs = ogApp()->helper('logReader')->today($limit);

    // Aplicar filtros
    $logs = applyLogFilters($logs);

    ogResponse::success([
      'logs' => $logs,
      'count' => count($logs),
      'date' => date('Y-m-d')
    ]);
  })->middleware($middleware);

  // Obtener los últimos logs registrados
  $router->get(['/latest','','/'], function() {
    $limit = (int) ogRequest::query('limit', 50);
    $logs = ogApp()->helper('logReader')->latest($limit);

    $logs = applyLogFilters($logs);

    ogResponse::success([
      'logs' => $logs,
      'count' => count($logs)
    ]);
  })->middleware($middleware);

  // Obtener logs de una fecha específica
  $router->get('/{year}/{month}/{day}', function($year, $month, $day) {
    $files = ogApp()->helper('logReader')->find([
      'year' => $year,
      'month' => str_pad($month, 2, '0', STR_PAD_LEFT),
      'day' => str_pad($day, 2, '0', STR_PAD_LEFT)
    ]);

    if (empty($files)) {
      ogResponse::success([
        'logs' => [],
        'count' => 0,
        'message' => __('logs.not_found_date')
      ]);
      return;
    }

    $allLogs = [];
    foreach ($files as $file) {
      $logs = ogApp()->helper('logReader')->parse($file);
      $allLogs = array_merge($allLogs, $logs);
    }

    usort($allLogs, function($a, $b) {
      return strtotime($b['timestamp']) - strtotime($a['timestamp']);
    });

    $allLogs = applyLogFilters($allLogs);

    $limit = (int) ogRequest::query('limit', 1000);
    $allLogs = array_slice($allLogs, 0, $limit);

    ogResponse::success([
      'logs' => $allLogs,
      'count' => count($allLogs),
      'date' => "$year-$month-$day",
      'files' => array_map('basename', $files)
    ]);
  })->middleware($middleware);

  // Obtener logs de un mes completo
  $router->get('/{year}/{month}', function($year, $month) {
    $files = ogApp()->helper('logReader')->find([
      'year' => $year,
      'month' => str_pad($month, 2, '0', STR_PAD_LEFT)
    ]);

    if (empty($files)) {
      ogResponse::success([
        'logs' => [],
        'count' => 0,
        'message' => __('logs.not_found_month')
      ]);
      return;
    }

    $allLogs = [];
    foreach ($files as $file) {
      $logs = ogApp()->helper('logReader')->parse($file);
      $allLogs = array_merge($allLogs, $logs);
    }

    usort($allLogs, function($a, $b) {
      return strtotime($b['timestamp']) - strtotime($a['timestamp']);
    });

    $allLogs = applyLogFilters($allLogs);

    $limit = (int) ogRequest::query('limit', 1000);
    $allLogs = array_slice($allLogs, 0, $limit);

    ogResponse::success([
      'logs' => $allLogs,
      'count' => count($allLogs),
      'month' => "$year-$month",
      'files' => count($files)
    ]);
  })->middleware($middleware);

  // Buscar logs con filtros y rango de fechas
  $router->get('/search', function() {
    $from = ogRequest::query('from');
    $to = ogRequest::query('to');
    $limit = (int) ogRequest::query('limit', 500);

    if (!$from) {
      $from = date('Y-m-d', strtotime('-7 days'));
    }
    if (!$to) {
      $to = date('Y-m-d');
    }

    $allLogs = ogApp()->helper('logReader')->range($from, $to, 0);

    $allLogs = applyLogFilters($allLogs);

    $allLogs = array_slice($allLogs, 0, $limit);

    ogResponse::success([
      'logs' => $allLogs,
      'count' => count($allLogs),
      'range' => ['from' => $from, 'to' => $to]
    ]);
  })->middleware($middleware);

  // Obtener estadísticas de logs del sistema
  $router->get('/stats', function() {
    $logs = ogLogReader::today(0);

    $logs = applyLogFilters($logs);

    $stats = ogApp()->helper('logReader')->stats($logs);
    $stats['date'] = date('Y-m-d');

    ogResponse::success($stats);
  })->middleware($middleware);
});

/**
 * Aplicar filtros desde query params
 *
 * Filtros soportados:
 * - level: Nivel del log
 * - module: Módulo específico
 * - tags: Tags (string o array separado por comas)
 * - search: Búsqueda en mensaje o contexto
 * - [custom_var]: number, bot_id, user_id, etc.
 */
function applyLogFilters($logs) {
  $filters = [];

  // Filtro por nivel
  if ($level = ogRequest::query('level')) {
    $filters['level'] = $level;
  }

  // Filtro por módulo
  if ($module = ogRequest::query('module')) {
    $filters['module'] = $module;
  }

  // Filtro por TAGS
  if ($tags = ogRequest::query('tags')) {
    $filters['tags'] = is_array($tags) ? $tags : explode(',', $tags);
  }

  // Búsqueda en mensaje
  if ($search = ogRequest::query('search')) {
    $filters['search'] = $search;
  }

  // Filtros por CUSTOM VARS (number, bot_id, user_id, etc.)
  $standardParams = ['limit', 'level', 'module', 'tags', 'search', 'from', 'to', 'page', 'per_page'];

  foreach ($_GET as $key => $value) {
    if (!in_array($key, $standardParams)) {
      $filters[$key] = $value;
    }
  }

  if (empty($filters)) {
    return $logs;
  }

  return ogApp()->helper('logReader')->filter($logs, $filters);
}