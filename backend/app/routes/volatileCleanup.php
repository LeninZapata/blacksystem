<?php
// routes/volatileCleanup.php - Limpieza de tablas volátiles (registros horarios)
// Ejecutar 1 vez al día vía CRON:
// wget --timeout=120 --tries=1 -q -O - "https://dominio.com/api/volatile-cleanup/run" > /dev/null 2>&1

$router->group('/api/volatile-cleanup', function($router) {

  // ── Dry-run: cuántos registros se borrarían ──────────────────────────────
  // GET /api/volatile-cleanup/preview?days=30
  $router->get('/preview', function() {
    $days    = max(1, (int)ogRequest::query('days', 30));
    $cutoff  = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

    $tables = [
      'ad_metrics_hourly'      => 'dc',
      'ad_auto_scale_history'  => 'dc',
    ];

    $result = [];
    foreach ($tables as $table => $col) {
      $toDelete = ogDb::raw(
        "SELECT COUNT(*) AS cnt FROM `{$table}` WHERE `{$col}` < ?",
        [$cutoff]
      )[0]['cnt'] ?? 0;

      $toKeep = ogDb::raw(
        "SELECT COUNT(*) AS cnt FROM `{$table}` WHERE `{$col}` >= ?",
        [$cutoff]
      )[0]['cnt'] ?? 0;

      $result[$table] = [
        'to_delete' => (int)$toDelete,
        'to_keep'   => (int)$toKeep,
        'total'     => (int)$toDelete + (int)$toKeep,
      ];
    }

    ogResponse::success([
      'days'    => $days,
      'cutoff'  => $cutoff,
      'tables'  => $result,
    ], "Preview: registros que se eliminarían con retención de {$days} días");
  })->middleware(['throttle:20,1']);

  // ── Ejecución real: eliminar registros antiguos ──────────────────────────
  // GET /api/volatile-cleanup/run?days=30
  $router->get('/run', function() {
    $days   = max(1, (int)ogRequest::query('days', 30));
    $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));
    $logMeta = ['module' => 'volatile-cleanup', 'layer' => 'app/routes'];

    $tables = [
      'ad_metrics_hourly'     => 'dc',
      'ad_auto_scale_history' => 'dc',
    ];

    $result = [];
    $totalDeleted = 0;

    ogLog::info("volatile-cleanup - INICIO", ['days' => $days, 'cutoff' => $cutoff], $logMeta);

    foreach ($tables as $table => $col) {
      try {
        $pdo  = ogDb::table($table)->getConnection();
        $stmt = $pdo->prepare("DELETE FROM `{$table}` WHERE `{$col}` < ?");
        $stmt->execute([$cutoff]);
        $rows = $stmt->rowCount();

        $result[$table] = ['deleted' => $rows, 'ok' => true];
        $totalDeleted  += $rows;

        ogLog::info("volatile-cleanup - Tabla limpiada", [
          'table'   => $table,
          'deleted' => $rows,
          'cutoff'  => $cutoff,
        ], $logMeta);

      } catch (Exception $e) {
        $result[$table] = ['deleted' => 0, 'ok' => false, 'error' => $e->getMessage()];
        ogLog::error("volatile-cleanup - Error en tabla {$table}", [
          'error' => $e->getMessage(),
        ], $logMeta);
      }
    }

    ogLog::info("volatile-cleanup - COMPLETADO", [
      'total_deleted' => $totalDeleted,
      'tables'        => array_keys($tables),
    ], $logMeta);

    ogResponse::success([
      'days'          => $days,
      'cutoff'        => $cutoff,
      'total_deleted' => $totalDeleted,
      'tables'        => $result,
    ], "Limpieza completada: {$totalDeleted} registros eliminados");
  })->middleware(['throttle:5,1']);

});

// ============================================
// ENDPOINTS:
// GET /api/volatile-cleanup/preview?days=30  → Simula cuántos se borrarían
// GET /api/volatile-cleanup/run?days=30      → Ejecuta la limpieza real
//
// CRON sugerido (1 vez al día, 03:00 UTC):
// 0 3 * * * wget --timeout=120 --tries=1 -q -O - "https://dominio.com/api/volatile-cleanup/run?days=30" > /dev/null 2>&1
// ============================================
