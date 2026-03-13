<?php
/**
 * MIGRACIÓN DE FECHAS → UTC
 * Convierte campos datetime de Ecuador (UTC-5) a UTC sumando 5 horas.
 * Los campos tc/tu son Unix timestamps — NO se tocan.
 * ⚠️ Ejecutar cada botón solo una vez. Hacer respaldo antes.
 */

require_once __DIR__ . '/../wp.php';
require_once __DIR__ . '/bootstrap.php';

$action = $_POST['action'] ?? null;
$result = null;

if ($action) {
  try {
    $pdo = ogDb::table('clients')->getConnection();

    // ── ad_auto_scale ─────────────────────────────────────────────────────────
    if ($action === 'ad_auto_scale') {
      $s1 = $pdo->prepare("UPDATE `ad_auto_scale` SET `dc` = DATE_ADD(`dc`, INTERVAL 5 HOUR)");
      $s1->execute(); $r1 = $s1->rowCount();
      $s2 = $pdo->prepare("UPDATE `ad_auto_scale` SET `du` = DATE_ADD(`du`, INTERVAL 5 HOUR) WHERE `du` IS NOT NULL");
      $s2->execute(); $r2 = $s2->rowCount();
      $result = ['success' => true, 'table' => 'ad_auto_scale', 'rows' => ['dc' => $r1, 'du' => $r2]];

    // ── ad_auto_scale_history ─────────────────────────────────────────────────
    } elseif ($action === 'ad_auto_scale_history') {
      $s = $pdo->prepare(
        "UPDATE `ad_auto_scale_history`
         SET `executed_at` = DATE_ADD(`executed_at`, INTERVAL 5 HOUR),
             `dc`          = DATE_ADD(`dc`, INTERVAL 5 HOUR)"
      );
      $s->execute();
      $result = ['success' => true, 'table' => 'ad_auto_scale_history', 'rows' => ['executed_at + dc' => $s->rowCount()]];

    // ── ad_budget_resets ──────────────────────────────────────────────────────
    } elseif ($action === 'ad_budget_resets') {
      // reset_date se recalcula a partir del reset_at original antes de desplazar
      $s = $pdo->prepare(
        "UPDATE `ad_budget_resets`
         SET `reset_date` = DATE(DATE_ADD(`reset_at`, INTERVAL 5 HOUR)),
             `reset_at`   = DATE_ADD(`reset_at`, INTERVAL 5 HOUR)"
      );
      $s->execute();
      $result = ['success' => true, 'table' => 'ad_budget_resets', 'rows' => ['reset_at + reset_date' => $s->rowCount()]];

    // ── ad_metrics_daily ──────────────────────────────────────────────────────
    } elseif ($action === 'ad_metrics_daily') {
      $s = $pdo->prepare(
        "UPDATE `ad_metrics_daily`
         SET `generated_at` = DATE_ADD(`generated_at`, INTERVAL 5 HOUR),
             `dc`           = DATE_ADD(`dc`, INTERVAL 5 HOUR)"
      );
      $s->execute();
      $result = ['success' => true, 'table' => 'ad_metrics_daily', 'rows' => ['generated_at + dc' => $s->rowCount()]];

    // ── ad_metrics_hourly ─────────────────────────────────────────────────────
    } elseif ($action === 'ad_metrics_hourly') {
      // query_date + query_hour forman juntos el datetime de consulta.
      // Se recalculan ambos a partir de los valores originales en una sola sentencia
      // (MySQL usa los valores originales de la fila para todas las expresiones del SET).
      $s = $pdo->prepare(
        "UPDATE `ad_metrics_hourly`
         SET
           `query_date` = DATE(DATE_ADD(
             CONCAT(`query_date`, ' ', LPAD(`query_hour`, 2, '0'), ':00:00'),
             INTERVAL 5 HOUR
           )),
           `query_hour` = HOUR(DATE_ADD(
             CONCAT(`query_date`, ' ', LPAD(`query_hour`, 2, '0'), ':00:00'),
             INTERVAL 5 HOUR
           )),
           `dc` = DATE_ADD(`dc`, INTERVAL 5 HOUR)"
      );
      $s->execute();
      $result = ['success' => true, 'table' => 'ad_metrics_hourly', 'rows' => ['query_date + query_hour + dc' => $s->rowCount()]];
    }

  } catch (Exception $e) {
    $result = ['success' => false, 'error' => $e->getMessage()];
  }
}

// ── Conteos actuales ──────────────────────────────────────────────────────────

$metaKeys   = ['last_message_at', 'last_client_message_at', 'open_chat'];
$metaCounts = [];
foreach ($metaKeys as $k) {
  $metaCounts[$k] = (int) ogDb::raw(
    "SELECT COUNT(*) as t FROM `client_bot_meta`
     WHERE `meta_key` = ? AND `meta_value` REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$'",
    [$k]
  )[0]['t'];
}

$cnt = [];
$cnt['ad_auto_scale_total']   = (int) ogDb::raw("SELECT COUNT(*) as t FROM `ad_auto_scale`", [])[0]['t'];
$cnt['ad_auto_scale_du']      = (int) ogDb::raw("SELECT COUNT(*) as t FROM `ad_auto_scale` WHERE `du` IS NOT NULL", [])[0]['t'];
$cnt['ad_auto_scale_history'] = (int) ogDb::raw("SELECT COUNT(*) as t FROM `ad_auto_scale_history`", [])[0]['t'];
$cnt['ad_budget_resets']      = (int) ogDb::raw("SELECT COUNT(*) as t FROM `ad_budget_resets`", [])[0]['t'];
$cnt['ad_metrics_daily']      = (int) ogDb::raw("SELECT COUNT(*) as t FROM `ad_metrics_daily`", [])[0]['t'];
$cnt['ad_metrics_hourly']     = (int) ogDb::raw("SELECT COUNT(*) as t FROM `ad_metrics_hourly`", [])[0]['t'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Migración UTC</title>
  <style>
    * { margin:0; padding:0; box-sizing:border-box; }
    body { font-family:'Segoe UI',sans-serif; background:#f0f2f5; padding:2rem; }
    .wrap { max-width:860px; margin:0 auto; }
    h1 { color:#1a2332; margin-bottom:.4rem; font-size:1.5rem; }
    .subtitle { color:#6c757d; font-size:.9rem; margin-bottom:2rem; }
    .alert { padding:.9rem 1.25rem; border-radius:6px; margin-bottom:1.5rem; font-size:.9rem; }
    .alert-ok  { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
    .alert-err { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
    .card { background:white; border-radius:8px; box-shadow:0 1px 6px rgba(0,0,0,.08); margin-bottom:1.25rem; overflow:hidden; }
    .card-head { padding:.9rem 1.25rem; display:flex; align-items:center; justify-content:space-between; border-bottom:1px solid #f0f0f0; }
    .card-title { font-size:1rem; font-weight:700; color:#1a2332; }
    .card-body { padding:1rem 1.25rem; display:flex; align-items:flex-end; justify-content:space-between; gap:1.5rem; flex-wrap:wrap; }
    .fields { flex:1; }
    .fields p { font-size:.82rem; color:#555; margin-bottom:.35rem; }
    .fields p strong { color:#2c3e50; }
    .pill { display:inline-block; background:#e8f4fd; color:#1a6fa8; border-radius:20px; font-size:.75rem; font-weight:600; padding:.2rem .65rem; margin:.15rem .1rem; }
    .pill.note { background:#fff8e1; color:#7d5a00; }
    .counts { display:flex; gap:.75rem; align-items:flex-start; flex-wrap:wrap; }
    .count-box { text-align:center; background:#f8f9fa; border-radius:6px; padding:.5rem .85rem; border:1px solid #dee2e6; min-width:80px; }
    .count-num { font-size:1.4rem; font-weight:700; color:#2c3e50; }
    .count-lbl { font-size:.72rem; color:#888; margin-top:.1rem; }
    .btn-run { padding:.6rem 1.4rem; font-size:.88rem; font-weight:700; background:#e74c3c; color:white; border:none; border-radius:6px; cursor:pointer; white-space:nowrap; }
    .btn-run:hover { background:#c0392b; }
    code { background:#f1f1f1; padding:.1rem .35rem; border-radius:3px; font-family:monospace; font-size:.85em; }
    .badge-pending { background:#fff3cd; color:#856404; border:1px solid #ffc107; padding:.2rem .6rem; border-radius:4px; font-size:.75rem; font-weight:600; }
    .badge-done    { background:#d4edda; color:#155724; border:1px solid #28a745; padding:.2rem .6rem; border-radius:4px; font-size:.75rem; font-weight:600; }
    .btn-disabled  { padding:.6rem 1.4rem; font-size:.88rem; font-weight:700; background:#adb5bd; color:white; border:none; border-radius:6px; cursor:not-allowed; white-space:nowrap; }
  </style>
</head>
<body>
<div class="wrap">
  <h1>🕐 Migración Ecuador → UTC</h1>
  <p class="subtitle">Suma 5 horas a todos los campos datetime pendientes. Ejecutar cada botón <strong>una sola vez</strong>.</p>

  <?php if ($result): ?>
    <?php if ($result['success']): ?>
      <div class="alert alert-ok">
        ✅ <strong><?= htmlspecialchars($result['table']) ?></strong> migrado correctamente.<br>
        <?php foreach ($result['rows'] as $field => $count): ?>
          &nbsp;&nbsp;• <code><?= htmlspecialchars($field) ?></code>: <strong><?= number_format($count) ?></strong> registros actualizados<br>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="alert alert-err">❌ Error: <?= htmlspecialchars($result['error']) ?></div>
    <?php endif; ?>
  <?php endif; ?>


  <!-- 1. client_bot_meta (meta_value) — YA MIGRADO -->
  <div class="card" style="opacity:.6">
    <div class="card-head">
      <span class="card-title"><code>client_bot_meta</code> — meta_value (fechas del chat)</span>
      <span class="badge-done">✅ Ya migrado</span>
    </div>
    <div class="card-body">
      <div class="fields">
        <p><strong>Campos afectados:</strong></p>
        <?php foreach ($metaKeys as $k): ?>
          <span class="pill"><?= $k ?></span>
        <?php endforeach; ?>
        <p style="margin-top:.6rem;font-size:.8rem;color:#e74c3c;font-weight:600">
          Ejecutado en sesión anterior — NO ejecutar de nuevo.
        </p>
      </div>
      <div class="counts">
        <?php foreach ($metaCounts as $k => $n): ?>
          <div class="count-box"><div class="count-num"><?= number_format($n) ?></div><div class="count-lbl"><?= $k ?></div></div>
        <?php endforeach; ?>
      </div>
      <button class="btn-disabled" disabled>✅ Ya ejecutado</button>
    </div>
  </div>


  <!-- 2. ad_auto_scale -->
  <div class="card">
    <div class="card-head">
      <span class="card-title"><code>ad_auto_scale</code> — Reglas de escalado automático</span>
      <span class="badge-pending">⏳ Pendiente</span>
    </div>
    <div class="card-body">
      <div class="fields">
        <p><strong>Campos afectados:</strong></p>
        <span class="pill">dc</span>
        <span class="pill">du</span>
        <p style="margin-top:.5rem;font-size:.8rem;color:#888;"><code>du</code> solo donde no es NULL.</p>
      </div>
      <div class="counts">
        <div class="count-box"><div class="count-num"><?= number_format($cnt['ad_auto_scale_total']) ?></div><div class="count-lbl">dc (total)</div></div>
        <div class="count-box"><div class="count-num"><?= number_format($cnt['ad_auto_scale_du']) ?></div><div class="count-lbl">du (non-null)</div></div>
      </div>
      <form method="POST" onsubmit="return confirm('¿Migrar ad_auto_scale?\n\nNo se puede deshacer sin respaldo.')">
        <input type="hidden" name="action" value="ad_auto_scale">
        <button class="btn-run" type="submit">🚀 Migrar</button>
      </form>
    </div>
  </div>


  <!-- 3. ad_auto_scale_history -->
  <div class="card">
    <div class="card-head">
      <span class="card-title"><code>ad_auto_scale_history</code> — Historial de ejecuciones</span>
      <span class="badge-pending">⏳ Pendiente</span>
    </div>
    <div class="card-body">
      <div class="fields">
        <p><strong>Campos afectados:</strong></p>
        <span class="pill">executed_at</span>
        <span class="pill">dc</span>
      </div>
      <div class="counts">
        <div class="count-box"><div class="count-num"><?= number_format($cnt['ad_auto_scale_history']) ?></div><div class="count-lbl">registros</div></div>
      </div>
      <form method="POST" onsubmit="return confirm('¿Migrar ad_auto_scale_history?\n\nNo se puede deshacer sin respaldo.')">
        <input type="hidden" name="action" value="ad_auto_scale_history">
        <button class="btn-run" type="submit">🚀 Migrar</button>
      </form>
    </div>
  </div>


  <!-- 4. ad_budget_resets -->
  <div class="card">
    <div class="card-head">
      <span class="card-title"><code>ad_budget_resets</code> — Historial de resets de presupuesto</span>
      <span class="badge-pending">⏳ Pendiente</span>
    </div>
    <div class="card-body">
      <div class="fields">
        <p><strong>Campos afectados:</strong></p>
        <span class="pill">reset_at</span>
        <span class="pill">reset_date</span>
        <p style="margin-top:.5rem;font-size:.8rem;color:#888;">
          <code>reset_date</code> (DATE) se recalcula como <code>DATE(reset_at + 5h)</code>
          a partir del valor original antes del desplazamiento.
        </p>
      </div>
      <div class="counts">
        <div class="count-box"><div class="count-num"><?= number_format($cnt['ad_budget_resets']) ?></div><div class="count-lbl">registros</div></div>
      </div>
      <form method="POST" onsubmit="return confirm('¿Migrar ad_budget_resets?\n\nNo se puede deshacer sin respaldo.')">
        <input type="hidden" name="action" value="ad_budget_resets">
        <button class="btn-run" type="submit">🚀 Migrar</button>
      </form>
    </div>
  </div>


  <!-- 5. ad_metrics_daily -->
  <div class="card">
    <div class="card-head">
      <span class="card-title"><code>ad_metrics_daily</code> — Métricas diarias</span>
      <span class="badge-pending">⏳ Pendiente</span>
    </div>
    <div class="card-body">
      <div class="fields">
        <p><strong>Campos afectados:</strong></p>
        <span class="pill">generated_at</span>
        <span class="pill">dc</span>
        <span class="pill note">metric_date — NO se toca</span>
        <p style="margin-top:.5rem;font-size:.8rem;color:#888;">
          <code>metric_date</code> es fecha de negocio (el día al que pertenecen las métricas) — independiente de timezone, no se migra.
        </p>
      </div>
      <div class="counts">
        <div class="count-box"><div class="count-num"><?= number_format($cnt['ad_metrics_daily']) ?></div><div class="count-lbl">registros</div></div>
      </div>
      <form method="POST" onsubmit="return confirm('¿Migrar ad_metrics_daily?\n\nNo se puede deshacer sin respaldo.')">
        <input type="hidden" name="action" value="ad_metrics_daily">
        <button class="btn-run" type="submit">🚀 Migrar</button>
      </form>
    </div>
  </div>


  <!-- 6. ad_metrics_hourly -->
  <div class="card">
    <div class="card-head">
      <span class="card-title"><code>ad_metrics_hourly</code> — Métricas por hora</span>
      <span class="badge-pending">⏳ Pendiente</span>
    </div>
    <div class="card-body">
      <div class="fields">
        <p><strong>Campos afectados:</strong></p>
        <span class="pill">query_date</span>
        <span class="pill">query_hour</span>
        <span class="pill">dc</span>
        <p style="margin-top:.5rem;font-size:.8rem;color:#888;">
          <code>query_date</code> + <code>query_hour</code> se combinan, se suman 5h y se re-extraen
          en una sola sentencia (ej: hora 21 → 2 del día siguiente).
        </p>
      </div>
      <div class="counts">
        <div class="count-box"><div class="count-num"><?= number_format($cnt['ad_metrics_hourly']) ?></div><div class="count-lbl">registros</div></div>
      </div>
      <form method="POST" onsubmit="return confirm('¿Migrar ad_metrics_hourly?\n\nNo se puede deshacer sin respaldo.')">
        <input type="hidden" name="action" value="ad_metrics_hourly">
        <button class="btn-run" type="submit">🚀 Migrar</button>
      </form>
    </div>
  </div>

</div>
</body>
</html>

