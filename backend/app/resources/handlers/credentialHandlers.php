<?php
class credentialHandlers {

  // Actualiza archivos JSON de todos los bots que usan esta credencial
  static function updateBotsContext($credentialId) {
    if (!$credentialId) {
      log::warning('credentialHandlers::updateBotsContext - credential_id no proporcionado', [], ['module' => 'credential']);
      return 0;
    }

    // Buscar todos los bots que usan esta credencial en config.apis
    $bots = db::table('bots')->get();

    if (empty($bots)) {
      log::info('credentialHandlers::updateBotsContext - No hay bots en el sistema', [
        'credential_id' => $credentialId
      ], ['module' => 'credential']);
      return 0;
    }

    $updated = 0;

    foreach ($bots as $bot) {
      // Parsear config del bot
      $config = isset($bot['config']) && is_string($bot['config'])
        ? json_decode($bot['config'], true)
        : ($bot['config'] ?? []);

      // Verificar si el bot usa esta credencial
      $usesCredential = self::botUsesCredential($config, $credentialId);
      log::debug('credentialHandlers::botUsesCredential - este bot usa esta credencial: ' . $credentialId, $usesCredential, ['module' => 'credential']);

      if (!$usesCredential) {
        continue;
      }

      $botNumber = $bot['number'] ?? null;

      if ($botNumber) {
        // Regenerar archivo data del bot
        $result = botHandlers::generateDataFile($botNumber, $bot, 'update');

        if ($result) {
          $updated++;
          log::info('credentialHandlers::updateBotsContext - Bot actualizado', [
            'bot_id' => $bot['id'],
            'bot_number' => $botNumber,
            'credential_id' => $credentialId
          ], ['module' => 'credential']);
        }
      }
    }

    log::info('credentialHandlers::updateBotsContext - Proceso completado', [
      'credential_id' => $credentialId,
      'bots_updated' => $updated,
      'bots_checked' => count($bots)
    ], ['module' => 'credential']);

    return $updated;
  }

  // Verifica si un bot usa una credencial específica
  private static function botUsesCredential($config, $credentialId) {
    $apis = $config['apis'] ?? [];

    // Buscar en AI (estructura anidada por tareas)
    if (isset($apis['ai']) && is_array($apis['ai'])) {
      foreach ($apis['ai'] as $task => $credentialIds) {
        if (is_array($credentialIds) && in_array($credentialId, $credentialIds)) {
          return true;
        }
      }
    }

    // Buscar en Chat (array directo)
    if (isset($apis['chat']) && is_array($apis['chat'])) {
      if (in_array($credentialId, $apis['chat'])) {
        return true;
      }
    }

    return false;
  }
}