<?php
// routes/volatileCleanup.php - Limpieza de tablas volátiles (registros horarios)

// Retención por tabla (días hacia atrás)
$volatileRetention = [
  'ad_metrics_hourly'     => ['col' => 'dc', 'days' => 15],
  'ad_auto_scale_history' => ['col' => 'dc', 'days' => 40],
];

$router->group('/api/volatileCleanup', function($router) use ($volatileRetention) {

  // ── Dry-run: cuántos registros se borrarían ──────────────────────────────
  // GET /api/volatileCleanup/preview
  $router->get('/preview', function() use ($volatileRetention) {
    $result = [];
    foreach ($volatileRetention as $table => $cfg) {
      $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$cfg['days']} days"));
      $col    = $cfg['col'];

      $toDelete = ogDb::raw(
        "SELECT COUNT(*) AS cnt FROM `{$table}` WHERE `{$col}` < ?",
        [$cutoff]
      )[0]['cnt'] ?? 0;

      $toKeep = ogDb::raw(
        "SELECT COUNT(*) AS cnt FROM `{$table}` WHERE `{$col}` >= ?",
        [$cutoff]
      )[0]['cnt'] ?? 0;

      $result[$table] = [
        'retention_days' => $cfg['days'],
        'cutoff'         => $cutoff,
        'to_delete'      => (int)$toDelete,
        'to_keep'        => (int)$toKeep,
        'total'          => (int)$toDelete + (int)$toKeep,
      ];
    }

    ogResponse::success(['tables' => $result], 'Preview: registros que se eliminarían');
  })->middleware(['throttle:20,1']);

  // ── Ejecución real: eliminar registros antiguos ──────────────────────────
  // GET /api/volatileCleanup/run
  $router->get('/run', function() use ($volatileRetention) {
    $logMeta = ['module' => 'volatile-cleanup', 'layer' => 'app/routes'];
    $result       = [];
    $totalDeleted = 0;

    foreach ($volatileRetention as $table => $cfg) {
      $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$cfg['days']} days"));
      $col    = $cfg['col'];

      try {
        $pdo  = ogDb::table($table)->getConnection();
        $stmt = $pdo->prepare("DELETE FROM `{$table}` WHERE `{$col}` < ?");
        $stmt->execute([$cutoff]);
        $rows = $stmt->rowCount();

        $result[$table] = [
          'retention_days' => $cfg['days'],
          'cutoff'         => $cutoff,
          'deleted'        => $rows,
          'ok'             => true,
        ];
        $totalDeleted += $rows;

      } catch (Exception $e) {
        $result[$table] = ['deleted' => 0, 'ok' => false, 'error' => $e->getMessage()];
        ogLog::error("volatile-cleanup - Error en tabla {$table}", [
          'error' => $e->getMessage(),
        ], $logMeta);
      }
    }

    ogResponse::success([
      'total_deleted' => $totalDeleted,
      'tables'        => $result,
    ], "Limpieza completada: {$totalDeleted} registros eliminados");
  })->middleware(['throttle:5,1']);

});

// ============================================
// ENDPOINTS:
// GET /api/volatileCleanup/preview  → Simula cuántos se borrarían
// GET /api/volatileCleanup/run      → Ejecuta la limpieza real
//
// Retención:
//   ad_metrics_hourly     → 15 días
//   ad_auto_scale_history → 40 días
//
// CRON sugerido (1 vez al día, 03:00 UTC):
// 0 3 * * * wget -q -O - https://blacksystem.site/api/volatileCleanup/run /dev/null 2>&1
// ============================================
