<?php
class workflowHandlers {
  // Nombre de la tabla de bots asociada a este handler
  protected static $tableBots = DB_TABLES['bots'];

  // Actualiza archivos workflow de todos los bots que usan este workflow
  static function updateBotsContext($workflowId) {
    if (!$workflowId) {
      log::warning('workflowHandlers::updateBotsContext - workflow_id no proporcionado', [], ['module' => 'workflow']);
      return 0;
    }
    
    // Buscar todos los bots que usan este workflow
    $bots = db::table(self::$tableBots)
      ->where('config', 'LIKE', '%"workflow_id":"' . $workflowId . '"%')
      ->get();
    
    if (empty($bots)) {
      log::info('workflowHandlers::updateBotsContext - No hay bots usando workflow', [
        'workflow_id' => $workflowId
      ], ['module' => 'workflow']);
      return 0;
    }
    
    $updated = 0;
    
    foreach ($bots as $bot) {
      $botNumber = $bot['number'] ?? null;
      
      if ($botNumber) {
        // Regenerar archivo workflow del bot
        $result = BotHandlers::generateWorkflowFile($botNumber, $bot, 'update');
        
        if ($result) {
          $updated++;
          log::info('workflowHandlers::updateBotsContext - Bot actualizado', [
            'bot_id' => $bot['id'],
            'bot_number' => $botNumber,
            'workflow_id' => $workflowId
          ], ['module' => 'workflow']);
        }
      }
    }
    
    log::info('workflowHandlers::updateBotsContext - Proceso completado', [
      'workflow_id' => $workflowId,
      'bots_updated' => $updated,
      'bots_total' => count($bots)
    ], ['module' => 'workflow']);
    
    return $updated;
  }
}