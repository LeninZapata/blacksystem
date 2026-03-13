<?php
/**
 * Herramientas de corrección de datos
 * 1. Fix query_hour LOCAL→UTC en ad_metrics_hourly
 * 2. UNDO S-fix: revertir +5h en chats S
 */

require_once __DIR__ . '/../wp.php';
require_once __DIR__ . '/bootstrap.php';

$today  = gmdate('Y-m-d');
$action = $_POST['action'] ?? null;
$results = [];

$pdo = ogDb::table('chats')->getConnection();

function dbq($pdo, $sql, $params = []) {
  $s = $pdo->prepare($sql);
  $s->execute($params);
  return $s;
}
function dba($pdo, $sql, $params = []) {
  return dbq($pdo, $sql, $params)->fetchAll(PDO::FETCH_ASSOC);
}

// ─── ACCION 1: Corregir query_hour LOCAL → UTC en ad_metrics_hourly ──────────
if ($action === 'fix_query_hour_utc') {
  $fixDate = $_POST['fix_date'] ?? $today;
  try {
    $stmt = dbq($pdo,
      "UPDATE `ad_metrics_hourly`
       SET `query_hour` = HOUR(`dc`)
       WHERE `query_date` = ?
         AND `query_hour` != HOUR(`dc`)",
      [$fixDate]
    );
    $results['fix_qh'] = [
      'type' => 'fix_qh',
      'ok'   => true,
      'rows' => $stmt->rowCount(),
      'date' => $fixDate,
    ];
  } catch (Exception $e) {
    $results['fix_qh'] = ['type' => 'fix_qh', 'ok' => false, 'err' => $e->getMessage()];
  }
}

// ─── ACCION 2: UNDO S-fix ─────────────────────────────────────────────────────
if ($action === 'undo_s_fix_client') {
  $clientId = (int)($_POST['client_id'] ?? 0);
  $botId    = (int)($_POST['bot_id']    ?? 0);
  if ($clientId && $botId) {
    try {
      $s1 = dbq($pdo,
        "UPDATE `chats`
         SET `dc` = DATE_SUB(`dc`, INTERVAL 5 HOUR)
         WHERE `type` = 'S' AND `client_id` = ? AND `bot_id` = ?
           AND DATE(`dc`) = ?",
        [$clientId, $botId, $today]
      );
      $s2 = dbq($pdo,
        "UPDATE `client_bot_meta`
         SET `meta_value` = DATE_SUB(`meta_value`, INTERVAL 5 HOUR)
         WHERE `meta_key` = 'last_message_at'
           AND `client_id` = ? AND `bot_id` = ?",
        [$clientId, $botId]
      );
      $results['undo_s'] = [
        'type'      => 'undo_s',
        'ok'        => true,
        'rows_chat' => $s1->rowCount(),
        'rows_meta' => $s2->rowCount(),
        'label'     => "UNDO — client_id={$clientId}, bot_id={$botId}",
      ];
    } catch (Exception $e) {
      $results['undo_s'] = ['type' => 'undo_s', 'ok' => false, 'err' => $e->getMessage()];
    }
  }
}

// ─── Preview: registros con query_hour LOCAL (mal salvados) ───────────────────
$fixDate     = $_POST['fix_date'] ?? $today;
$mixedRows   = dba($pdo,
  "SELECT id, ad_asset_id, query_date, query_hour,
     HOUR(dc) AS hora_utc_dc, spend, dc,
     IF(query_hour = HOUR(dc), 'UTC OK', 'LOCAL MAL') AS tipo
   FROM ad_metrics_hourly
   WHERE query_date = ?
     AND query_hour != HOUR(dc)
   ORDER BY dc ASC",
  [$fixDate]
);
$mixedAssets = dba($pdo,
  "SELECT ad_asset_id, COUNT(*) AS registros_malos
   FROM ad_metrics_hourly
   WHERE query_date = ?
     AND query_hour != HOUR(dc)
   GROUP BY ad_asset_id",
  [$fixDate]
);

// ─── Preview: chats S del cliente ─────────────────────────────────────────────
$previewClientId = (int)($_POST['client_id'] ?? 1545);
$previewBotId    = (int)($_POST['bot_id']    ?? 11);

$sampS = dba($pdo,
  "SELECT id, type, LEFT(message,60) msg, dc,
     CONVERT_TZ(dc,'UTC','America/Guayaquil') as dc_ecu
   FROM `chats`
   WHERE `type` = 'S' AND `client_id` = ? AND `bot_id` = ?
     AND DATE(`dc`) = ?
   ORDER BY id DESC LIMIT 10",
  [$previewClientId, $previewBotId, $today]
);

$sampMeta = dba($pdo,
  "SELECT meta_key, meta_value,
     CONVERT_TZ(meta_value,'UTC','America/Guayaquil') as meta_ecu
   FROM `client_bot_meta`
   WHERE `meta_key` = 'last_message_at' AND `client_id` = ? AND `bot_id` = ?",
  [$previewClientId, $previewBotId]
);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Herramientas de corrección</title>
<style>
  *{margin:0;padding:0;box-sizing:border-box}
  body{font-family:'Segoe UI',sans-serif;background:#f0f2f5;padding:2rem;color:#2c3e50}
  .wrap{max-width:900px;margin:0 auto}
  h1{font-size:1.35rem;margin-bottom:.3rem}
  .subtitle{color:#6c757d;font-size:.85rem;margin-bottom:.5rem}
  .badge{display:inline-block;background:#fff3cd;color:#7d5a00;border-radius:20px;font-size:.78rem;font-weight:700;padding:.2rem .8rem;margin-bottom:1.5rem}
  .alert{padding:.85rem 1.2rem;border-radius:6px;margin-bottom:.75rem;font-size:.88rem}
  .alert-ok{background:#d4edda;color:#155724;border:1px solid #c3e6cb}
  .alert-err{background:#f8d7da;color:#721c24;border:1px solid #f5c6cb}
  .card{background:#fff;border-radius:8px;box-shadow:0 1px 6px rgba(0,0,0,.08);margin-bottom:1.25rem;overflow:hidden}
  .card.red{border:2px solid #e74c3c}
  .card.blue{border:2px solid #2980b9}
  .card-head{padding:.85rem 1.2rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;border-bottom:1px solid #f0f0f0;flex-wrap:wrap}
  .card-title.red{font-size:.95rem;font-weight:700;color:#e74c3c}
  .card-title.blue{font-size:.95rem;font-weight:700;color:#2980b9}
  .card-sub{font-size:.78rem;color:#888;margin-top:.2rem}
  .card-body{padding:1rem 1.2rem}
  .btn{padding:.55rem 1.3rem;font-size:.85rem;font-weight:700;border:none;border-radius:6px;cursor:pointer;white-space:nowrap}
  .btn-red{background:#e74c3c;color:#fff}.btn-red:hover{background:#c0392b}
  .btn-blue{background:#2980b9;color:#fff}.btn-blue:hover{background:#1a5276}
  input[type=number],input[type=date],input[type=text]{padding:.4rem .5rem;border:1px solid #ccc;border-radius:4px;font-size:.85rem}
  table{width:100%;border-collapse:collapse;font-size:.8rem;margin-top:.75rem}
  th{background:#f8f9fa;padding:.4rem .55rem;text-align:left;border-bottom:2px solid #dee2e6;color:#555}
  td{padding:.38rem .55rem;border-bottom:1px solid #f4f4f4}
  tr:last-child td{border-bottom:none}
  td.bad{font-family:monospace;color:#c0392b;font-weight:600}
  td.ok{font-family:monospace;color:#27ae60;font-weight:600}
  td.mono{font-family:monospace}
  .info{font-size:.82rem;color:#555;margin-bottom:.6rem;line-height:1.5}
  .tag-bad{background:#f8d7da;color:#721c24;border-radius:3px;padding:1px 5px;font-size:.75rem;font-weight:700}
  .tag-ok{background:#d4edda;color:#155724;border-radius:3px;padding:1px 5px;font-size:.75rem;font-weight:700}
</style>
</head>
<body>
<div class="wrap">
  <h1>🔧 Herramientas de corrección de datos</h1>
  <div class="badge">📅 Hoy UTC: <?= $today ?></div>

  <?php foreach ($results as $r): ?>
    <?php if ($r['ok']): ?>
      <?php if ($r['type'] === 'fix_qh'): ?>
        <div class="alert alert-ok">
          ✅ <strong>query_hour corregido — fecha: <?= htmlspecialchars($r['date']) ?></strong><br>
          &nbsp;&nbsp;• Registros actualizados: <strong><?= $r['rows'] ?></strong>
        </div>
      <?php else: ?>
        <div class="alert alert-ok">
          ✅ <strong><?= htmlspecialchars($r['label']) ?></strong><br>
          &nbsp;&nbsp;• chats S corregidos: <strong><?= $r['rows_chat'] ?></strong><br>
          &nbsp;&nbsp;• client_bot_meta corregidos: <strong><?= $r['rows_meta'] ?></strong>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <div class="alert alert-err">❌ <?= htmlspecialchars($r['err'] ?? 'Error desconocido') ?></div>
    <?php endif; ?>
  <?php endforeach; ?>

  <!-- === CARD 1: Fix query_hour LOCAL→UTC === -->
  <div class="card blue">
    <div class="card-head">
      <div>
        <div class="card-title blue">🕐 Fix query_hour LOCAL → UTC en ad_metrics_hourly</div>
        <div class="card-sub">
          Corrige registros donde query_hour se guardó en hora local (ECU) en vez de UTC.
          La columna <code>dc</code> siempre es UTC del servidor → se usa como referencia.
        </div>
      </div>
      <form method="POST" onsubmit="return confirm('¿Corregir query_hour para la fecha seleccionada?')">
        <input type="hidden" name="action" value="fix_query_hour_utc">
        <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
          <input type="date" name="fix_date" value="<?= htmlspecialchars($fixDate) ?>">
          <button class="btn btn-blue">▶ Corregir query_hour</button>
        </div>
      </form>
    </div>
    <div class="card-body">
      <p class="info">
        <strong>¿Qué hace?</strong> Ejecuta:<br>
        <code>UPDATE ad_metrics_hourly SET query_hour = HOUR(dc) WHERE query_date = '{fecha}' AND query_hour != HOUR(dc)</code><br>
        Solo afecta registros guardados con hora local (los de hoy guardados antes del fix UTC).
      </p>

      <?php if (!empty($mixedAssets)): ?>
        <strong style="font-size:.82rem;color:#c0392b">
          ⚠️ Registros con query_hour LOCAL (mal) en fecha <?= htmlspecialchars($fixDate) ?>:
        </strong>
        <table>
          <thead><tr><th>ad_asset_id</th><th>Registros malos</th></tr></thead>
          <tbody>
            <?php foreach ($mixedAssets as $a): ?>
            <tr>
              <td class="mono"><?= htmlspecialchars($a['ad_asset_id']) ?></td>
              <td class="bad"><?= $a['registros_malos'] ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <table style="margin-top:1rem">
          <thead>
            <tr>
              <th>ID</th><th>ad_asset_id</th>
              <th>query_hour<br>(guardado)</th>
              <th>HOUR(dc)<br>(UTC real)</th>
              <th>spend</th><th>dc</th><th>Tipo</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($mixedRows as $r): ?>
            <tr>
              <td><?= $r['id'] ?></td>
              <td class="mono"><?= htmlspecialchars($r['ad_asset_id']) ?></td>
              <td class="bad"><?= $r['query_hour'] ?></td>
              <td class="ok"><?= $r['hora_utc_dc'] ?></td>
              <td>$<?= $r['spend'] ?></td>
              <td class="mono"><?= $r['dc'] ?></td>
              <td><span class="tag-bad"><?= $r['tipo'] ?></span></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <p style="color:#27ae60;font-size:.85rem;margin-top:.5rem">
          ✅ Sin registros con query_hour LOCAL para la fecha <?= htmlspecialchars($fixDate) ?>.
          Todos los datos están en UTC.
        </p>
      <?php endif; ?>
    </div>
  </div>

  <!-- === CARD 2: UNDO S-fix === -->
  <div class="card red">
    <div class="card-head">
      <div>
        <div class="card-title red">⚠️ UNDO — Revertir S-fix en cliente específico</div>
        <div class="card-sub">Resta 5h a <code>chats.dc</code> (tipo S) y <code>client_bot_meta.meta_value</code> (last_message_at)</div>
      </div>
      <form method="POST" onsubmit="return confirm('¿Restar 5h a chats S y meta del cliente?')">
        <input type="hidden" name="action" value="undo_s_fix_client">
        <div style="display:flex;gap:.5rem;align-items:center">
          <input type="number" name="client_id" placeholder="client_id" value="<?= $previewClientId ?>" style="width:90px">
          <input type="number" name="bot_id"    placeholder="bot_id"    value="<?= $previewBotId ?>"    style="width:70px">
          <button class="btn btn-red">▶ Revertir -5h</button>
        </div>
      </form>
    </div>
    <div class="card-body">
      <p class="info">
        <strong>¿Por qué?</strong> El S-fix anterior sumó +5h a <em>todos</em> los chats S de hoy,
        incluyendo los que ya estaban en UTC correcto (mensajes de checkout/pago).
        Este botón deshace ese cambio para un cliente específico.<br>
        <strong>Resultado esperado:</strong> <code>18:55 UTC → 13:55 UTC</code> → sidebar mostrará <code>08:55 ECU</code>
      </p>

      <?php if (!empty($sampS)): ?>
        <strong style="font-size:.82rem">Chats S hoy (client_id=<?= $previewClientId ?>, bot=<?= $previewBotId ?>):</strong>
        <table>
          <thead><tr><th>ID</th><th>mensaje</th><th>dc (actual)</th><th>dc ECU</th></tr></thead>
          <tbody>
            <?php foreach ($sampS as $r): ?>
            <tr>
              <td><?= $r['id'] ?></td>
              <td><?= htmlspecialchars($r['msg']) ?></td>
              <td class="bad"><?= $r['dc'] ?></td>
              <td class="ok"><?= $r['dc_ecu'] ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <p style="color:#27ae60;font-size:.85rem;margin-top:.5rem">✅ Sin chats S hoy para este cliente.</p>
      <?php endif; ?>

      <?php if (!empty($sampMeta)): ?>
        <strong style="font-size:.82rem;display:block;margin-top:.9rem">client_bot_meta last_message_at:</strong>
        <table>
          <thead><tr><th>meta_key</th><th>meta_value (actual)</th><th>meta_value ECU</th></tr></thead>
          <tbody>
            <?php foreach ($sampMeta as $r): ?>
            <tr>
              <td><?= $r['meta_key'] ?></td>
              <td class="bad"><?= $r['meta_value'] ?></td>
              <td class="ok"><?= $r['meta_ecu'] ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>

</div>
</body>
</html>
