<?php
// workflowHandlers - Handlers personalizados para work_flows
class workflowHandlers {

  /**
   * Actualiza los archivos JSON de contexto de todos los bots que usan un workflow específico
   *
   * @param int $workflowId - ID del workflow actualizado
   * @return int - Cantidad de bots actualizados
   */
  static function updateBotsContext($workflowId) {
    if (!$workflowId) {
      log::warning('workflowHandlers - workflow_id no proporcionado', [], ['module' => 'workflow']);
      return 0;
    }

    // Buscar todos los bots que tienen este workflow_id en su config
    $bots = db::table('bots')->get();

    $updated = 0;

    foreach ($bots as $bot) {
      // Parsear config
      $config = isset($bot['config']) ? $bot['config'] : null;
      if (is_string($config)) {
        $config = json_decode($config, true);
      }

      // Verificar si el bot usa este workflow
      $botWorkflowId = $config['workflow_id'] ?? null;

      if ($botWorkflowId == $workflowId) {
        // Actualizar archivo de contexto del bot
        $success = botHandlers::saveContextFile($bot, 'workflow');
        if ($success) {
          $updated++;
        }
      }
    }

    log::info('workflowHandlers - Archivos de contexto actualizados', [
      'workflow_id' => $workflowId,
      'bots_updated' => $updated
    ], ['module' => 'workflow']);

    return $updated;
  }
}
