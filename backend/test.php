<?php
/**
 * Preview: tablas volátiles — registros que se borrarían
 */

require_once __DIR__ . '/../wp.php';
require_once __DIR__ . '/bootstrap.php';

// ── Configuración ──────────────────────────────────────────────────────────
$DAYS_TO_KEEP = (int)($_GET['days'] ?? 30);  // editable aquí o por ?days=N
if ($DAYS_TO_KEEP < 1) $DAYS_TO_KEEP = 1;

$cutoff  = gmdate('Y-m-d H:i:s', strtotime("-{$DAYS_TO_KEEP} days"));
$nowUtc  = gmdate('Y-m-d H:i:s');

// ── Tablas volátiles a limpiar ─────────────────────────────────────────────
$tables = [
  'ad_metrics_hourly' => [
    'col'   => 'dc',
    'label' => 'Métricas horarias publicitarias',
    'color' => '#2980b9',
  ],
  'ad_auto_scale_history' => [
    'col'   => 'dc',
    'label' => 'Historial de auto-escalado',
    'color' => '#8e44ad',
  ],
];

// ── Queries ────────────────────────────────────────────────────────────────
$pdo = ogDb::table('ad_metrics_hourly')->getConnection();

function dbCount($pdo, $table, $col, $op, $cutoff) {
  $s = $pdo->prepare("SELECT COUNT(*) AS cnt FROM `{$table}` WHERE `{$col}` {$op} ?");
  $s->execute([$cutoff]);
  return (int)($s->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
}
function dbOldest($pdo, $table, $col) {
  $s = $pdo->prepare("SELECT MIN(`{$col}`) AS oldest, MAX(`{$col}`) AS newest FROM `{$table}`");
  $s->execute();
  return $s->fetch(PDO::FETCH_ASSOC);
}

$stats = [];
foreach ($tables as $tbl => $cfg) {
  $toDelete = dbCount($pdo, $tbl, $cfg['col'], '<',  $cutoff);
  $toKeep   = dbCount($pdo, $tbl, $cfg['col'], '>=', $cutoff);
  $range    = dbOldest($pdo, $tbl, $cfg['col']);
  $stats[$tbl] = [
    'label'     => $cfg['label'],
    'color'     => $cfg['color'],
    'to_delete' => $toDelete,
    'to_keep'   => $toKeep,
    'total'     => $toDelete + $toKeep,
    'oldest'    => $range['oldest'] ?? '—',
    'newest'    => $range['newest'] ?? '—',
  ];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Preview limpieza volátil</title>
<style>
  *{margin:0;padding:0;box-sizing:border-box}
  body{font-family:'Segoe UI',sans-serif;background:#f0f2f5;padding:2rem;color:#2c3e50}
  .wrap{max-width:820px;margin:0 auto}
  h1{font-size:1.3rem;margin-bottom:.25rem}
  .meta{color:#6c757d;font-size:.82rem;margin-bottom:1.5rem;line-height:1.7}
  .meta code{background:#e9ecef;padding:1px 5px;border-radius:3px;font-size:.8rem}
  .card{background:#fff;border-radius:8px;box-shadow:0 1px 6px rgba(0,0,0,.08);margin-bottom:1.25rem;overflow:hidden}
  .card-head{padding:.8rem 1.2rem;display:flex;align-items:center;gap:.75rem;border-bottom:1px solid #f0f0f0}
  .card-title{font-size:.95rem;font-weight:700}
  .card-sub{font-size:.78rem;color:#888;margin-top:.15rem}
  .card-body{padding:1rem 1.2rem}
  .row-stats{display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:.9rem}
  .stat{flex:1;min-width:120px;padding:.65rem .9rem;border-radius:6px;text-align:center}
  .stat-num{font-size:1.55rem;font-weight:700;line-height:1.1}
  .stat-lbl{font-size:.72rem;color:#666;margin-top:.2rem;text-transform:uppercase;letter-spacing:.03em}
  .stat-delete{background:#fdecea;color:#c0392b}
  .stat-keep{background:#e8f8f0;color:#1e8449}
  .stat-total{background:#eaf3fb;color:#1a5276}
  .range{font-size:.8rem;color:#666;border-top:1px solid #f4f4f4;padding-top:.6rem;margin-top:.2rem;line-height:1.8}
  .range code{font-family:monospace;font-size:.78rem}
  .bar-wrap{height:8px;background:#e9ecef;border-radius:4px;overflow:hidden;margin:.5rem 0 .35rem}
  .bar-fill{height:100%;border-radius:4px;transition:width .4s}
  .bar-lbl{font-size:.72rem;color:#888}
  .endpoint{background:#2c3e50;color:#ecf0f1;border-radius:6px;padding:.75rem 1rem;font-size:.8rem;font-family:monospace;margin-top:1.5rem;line-height:2}
  .endpoint a{color:#5dade2;text-decoration:none}
  .endpoint a:hover{text-decoration:underline}
  .form-days{display:inline-flex;align-items:center;gap:.5rem;background:#fff;padding:.4rem .75rem;border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.08);margin-bottom:1.2rem}
  .form-days label{font-size:.82rem;color:#555}
  .form-days input{width:65px;padding:.3rem .4rem;border:1px solid #ccc;border-radius:4px;font-size:.82rem}
  .form-days button{padding:.3rem .8rem;background:#2980b9;color:#fff;border:none;border-radius:4px;font-size:.82rem;cursor:pointer}
  .form-days button:hover{background:#1a5276}
  .dot{width:12px;height:12px;border-radius:50%;flex-shrink:0}
</style>
</head>
<body>
<div class="wrap">
  <h1>🧹 Preview — limpieza de tablas volátiles</h1>
  <div class="meta">
    Hora UTC actual: <code><?= $nowUtc ?></code> &nbsp;·&nbsp;
    Corte: <code><?= $cutoff ?></code> &nbsp;·&nbsp;
    Retención: <strong><?= $DAYS_TO_KEEP ?> días</strong>
  </div>

  <form class="form-days" method="GET">
    <label>Días a retener:</label>
    <input type="number" name="days" value="<?= $DAYS_TO_KEEP ?>" min="1" max="365">
    <button type="submit">Recalcular</button>
  </form>

  <?php foreach ($stats as $tbl => $s):
    $pctDelete = $s['total'] > 0 ? round($s['to_delete'] / $s['total'] * 100) : 0;
    $pctKeep   = 100 - $pctDelete;
  ?>
  <div class="card">
    <div class="card-head">
      <div class="dot" style="background:<?= $s['color'] ?>"></div>
      <div>
        <div class="card-title" style="color:<?= $s['color'] ?>"><?= $s['label'] ?></div>
        <div class="card-sub"><code><?= $tbl ?></code></div>
      </div>
    </div>
    <div class="card-body">
      <div class="row-stats">
        <div class="stat stat-delete">
          <div class="stat-num"><?= number_format($s['to_delete']) ?></div>
          <div class="stat-lbl">Se borrarían</div>
        </div>
        <div class="stat stat-keep">
          <div class="stat-num"><?= number_format($s['to_keep']) ?></div>
          <div class="stat-lbl">Se conservan</div>
        </div>
        <div class="stat stat-total">
          <div class="stat-num"><?= number_format($s['total']) ?></div>
          <div class="stat-lbl">Total actual</div>
        </div>
      </div>
      <div class="bar-wrap">
        <div class="bar-fill" style="width:<?= $pctDelete ?>%;background:<?= $s['color'] ?>;opacity:.55"></div>
      </div>
      <div class="bar-lbl"><?= $pctDelete ?>% a eliminar &nbsp;·&nbsp; <?= $pctKeep ?>% a conservar</div>
      <div class="range">
        Registro más antiguo: <code><?= htmlspecialchars($s['oldest']) ?></code> &nbsp;·&nbsp;
        Registro más reciente: <code><?= htmlspecialchars($s['newest']) ?></code>
      </div>
    </div>
  </div>
  <?php endforeach; ?>

  <div class="endpoint">
    🔗 Endpoints de la ruta:<br>
    <a href="/api/volatile-cleanup/preview?days=<?= $DAYS_TO_KEEP ?>">/api/volatile-cleanup/preview?days=<?= $DAYS_TO_KEEP ?></a> — Preview JSON<br>
    <a href="/api/volatile-cleanup/run?days=<?= $DAYS_TO_KEEP ?>">/api/volatile-cleanup/run?days=<?= $DAYS_TO_KEEP ?></a> — ⚠️ Ejecutar limpieza real<br><br>
    📅 CRON sugerido (03:00 UTC diario):<br>
    <span style="color:#abebc6">0 3 * * * wget --timeout=120 --tries=1 -q -O - "https://dominio.com/api/volatile-cleanup/run?days=<?= $DAYS_TO_KEEP ?>" > /dev/null 2>&1</span>
  </div>

</div>
</body>
</html>
