<?php
class workflowHandlers {
  // Nombre de la tabla de bots asociada a este handler
  protected static $tableBots = DB_TABLES['bots'];
  private static $logMeta = ['module' => 'workflowHandlers', 'layer' => 'app/resources'];

  // Actualiza archivos workflow de todos los bots que usan este workflow
  static function updateBotsContext($workflowId) {
    if (!$workflowId) {
      ogLog::warning('updateBotsContext - workflow_id no proporcionado', [], self::$logMeta);
      return 0;
    }

    // Buscar todos los bots que usan este workflow
    $bots = ogDb::table(self::$tableBots)
      ->where('config', 'LIKE', '%"workflow_id":"' . $workflowId . '"%')
      ->get();

    if (empty($bots)) {
      ogLog::info('updateBotsContext - No hay bots usando workflow', [ 'workflow_id' => $workflowId ], self::$logMeta);
      return 0;
    }

    $updated = 0;

    foreach ($bots as $bot) {
      $botNumber = $bot['number'] ?? null;

      if ($botNumber) {
        // Regenerar archivo workflow del bot
        ogApp()->loadHandler('BotHandler');
        $result = BotHandlers::generateWorkflowFile($botNumber, $bot, 'update');

        if ($result) {
          $updated++;
          ogLog::info('updateBotsContext - Bot actualizado', [ 'bot_id' => $bot['id'], 'bot_number' => $botNumber, 'workflow_id' => $workflowId ], self::$logMeta);
        }
      }
    }

    ogLog::info('updateBotsContext - Proceso completado', [ 'workflow_id' => $workflowId, 'bots_updated' => $updated, 'bots_total' => count($bots) ], self::$logMeta);

    return $updated;
  }
}