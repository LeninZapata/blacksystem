<?php
/**
 * UNDO S-fix â€” Revertir +5h aplicado por error a chats S que ya estaban en UTC
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

// â”€â”€â”€ Ejecutar acciÃ³n â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($action === 'undo_s_fix_client') {
  $clientId = (int)($_POST['client_id'] ?? 0);
  $botId    = (int)($_POST['bot_id'] ?? 0);
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
        'ok' => true,
        'rows_chat' => $s1->rowCount(),
        'rows_meta' => $s2->rowCount(),
        'label' => "UNDO â€” client_id={$clientId}, bot_id={$botId}"
      ];
    } catch (Exception $e) {
      $results['undo_s'] = ['ok' => false, 'err' => $e->getMessage()];
    }
  }
}

// â”€â”€â”€ Preview chats S del cliente â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$previewClientId = (int)($_POST['client_id'] ?? 1545);
$previewBotId    = (int)($_POST['bot_id'] ?? 11);

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
<title>UNDO S-fix UTC</title>
<style>
  *{margin:0;padding:0;box-sizing:border-box}
  body{font-family:'Segoe UI',sans-serif;background:#f0f2f5;padding:2rem;color:#2c3e50}
  .wrap{max-width:860px;margin:0 auto}
  h1{font-size:1.35rem;margin-bottom:.3rem}
  .subtitle{color:#6c757d;font-size:.85rem;margin-bottom:.5rem}
  .badge{display:inline-block;background:#fff3cd;color:#7d5a00;border-radius:20px;font-size:.78rem;font-weight:700;padding:.2rem .8rem;margin-bottom:1.5rem}
  .alert{padding:.85rem 1.2rem;border-radius:6px;margin-bottom:.75rem;font-size:.88rem}
  .alert-ok{background:#d4edda;color:#155724;border:1px solid #c3e6cb}
  .alert-err{background:#f8d7da;color:#721c24;border:1px solid #f5c6cb}
  .card{background:#fff;border-radius:8px;box-shadow:0 1px 6px rgba(0,0,0,.08);margin-bottom:1.25rem;overflow:hidden;border:2px solid #e74c3c}
  .card-head{padding:.85rem 1.2rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;border-bottom:1px solid #f0f0f0}
  .card-title{font-size:.95rem;font-weight:700;color:#e74c3c}
  .card-sub{font-size:.78rem;color:#888;margin-top:.2rem}
  .card-body{padding:1rem 1.2rem}
  .btn{padding:.55rem 1.3rem;font-size:.85rem;font-weight:700;border:none;border-radius:6px;cursor:pointer}
  .btn-red{background:#e74c3c;color:#fff}.btn-red:hover{background:#c0392b}
  input[type=number],input[type=text]{padding:.4rem .5rem;border:1px solid #ccc;border-radius:4px;font-size:.85rem}
  table{width:100%;border-collapse:collapse;font-size:.8rem;margin-top:.75rem}
  th{background:#f8f9fa;padding:.4rem .55rem;text-align:left;border-bottom:2px solid #dee2e6;color:#555}
  td{padding:.38rem .55rem;border-bottom:1px solid #f4f4f4}
  tr:last-child td{border-bottom:none}
  td.dc{font-family:monospace;color:#c0392b;font-weight:600}
  td.ok{font-family:monospace;color:#27ae60;font-weight:600}
  .info{font-size:.82rem;color:#555;margin-bottom:.6rem;line-height:1.5}
</style>
</head>
<body>
<div class="wrap">
  <h1>âª UNDO â€” Revertir S-fix errÃ³neo</h1>
  <p class="subtitle">Resta 5h a chats tipo S y <code>last_message_at</code> de un cliente que ya tenÃ­a UTC correcto y recibiÃ³ +5h de mÃ¡s.</p>
  <div class="badge">ðŸ“… Hoy UTC: <?= $today ?></div>

  <?php foreach ($results as $r): ?>
    <?php if ($r['ok']): ?>
      <div class="alert alert-ok">
        âœ… <strong><?= htmlspecialchars($r['label']) ?></strong><br>
        &nbsp;&nbsp;â€¢ chats S corregidos: <strong><?= $r['rows_chat'] ?></strong><br>
        &nbsp;&nbsp;â€¢ client_bot_meta corregidos: <strong><?= $r['rows_meta'] ?></strong>
      </div>
    <?php else: ?>
      <div class="alert alert-err">âŒ <?= htmlspecialchars($r['err']) ?></div>
    <?php endif; ?>
  <?php endforeach; ?>

  <div class="card">
    <div class="card-head">
      <div>
        <div class="card-title">âš ï¸ Revertir S-fix en cliente especÃ­fico</div>
        <div class="card-sub">Resta 5h a <code>chats.dc</code> (tipo S) y <code>client_bot_meta.meta_value</code> (last_message_at)</div>
      </div>
      <form method="POST" onsubmit="return confirm('Â¿Restar 5h a chats S y meta del cliente?')">
        <input type="hidden" name="action" value="undo_s_fix_client">
        <div style="display:flex;gap:.5rem;align-items:center">
          <input type="number" name="client_id" placeholder="client_id" value="<?= $previewClientId ?>" style="width:90px">
          <input type="number" name="bot_id" placeholder="bot_id" value="<?= $previewBotId ?>" style="width:70px">
          <button class="btn btn-red">â–¶ Revertir -5h</button>
        </div>
      </form>
    </div>
    <div class="card-body">
      <p class="info">
        <strong>Â¿Por quÃ©?</strong> El S-fix anterior sumÃ³ +5h a <em>todos</em> los chats S de hoy,
        incluyendo los que ya estaban en UTC correcto (mensajes de checkout/pago).
        Este botÃ³n deshace ese cambio para un cliente especÃ­fico.<br>
        <strong>Resultado esperado:</strong> <code>18:55 UTC â†’ 13:55 UTC</code> â†’ sidebar mostrarÃ¡ <code>08:55 ECU</code>
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
              <td class="dc"><?= $r['dc'] ?></td>
              <td class="ok"><?= $r['dc_ecu'] ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <p style="color:#27ae60;font-size:.85rem;margin-top:.5rem">âœ… Sin chats S hoy para este cliente.</p>
      <?php endif; ?>

      <?php if (!empty($sampMeta)): ?>
        <strong style="font-size:.82rem;display:block;margin-top:.9rem">client_bot_meta last_message_at:</strong>
        <table>
          <thead><tr><th>meta_key</th><th>meta_value (actual)</th><th>meta_value ECU</th></tr></thead>
          <tbody>
            <?php foreach ($sampMeta as $r): ?>
            <tr>
              <td><?= $r['meta_key'] ?></td>
              <td class="dc"><?= $r['meta_value'] ?></td>
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
