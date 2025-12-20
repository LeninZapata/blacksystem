<?php
class botHandlers {

  /**
   * Guardar archivos de contexto del bot
   * Genera: data/{numero}.json y workflow_{numero}.json
   */
  static function saveContextFile($botData, $action = 'create', $oldNumber = null) {
    if (!isset($botData['id']) || !isset($botData['number'])) {
      log::error('botHandlers::saveContextFile - Datos insuficientes', null, ['module' => 'bot']);
      return false;
    }

    $currentNumber = $botData['number'];

    // Si cambió el número de bot (solo en update)
    if ($action === 'update' && $oldNumber && $oldNumber !== $currentNumber) {

      // Eliminar archivos antiguos
      self::deleteContextFiles($oldNumber);

      // Regenerar activators con el nuevo número
      productHandler::generateActivatorsFile($currentNumber, $botData['id'], 'update');
    }

    // Generar archivos con el número actual
    self::generateWorkflowFile($currentNumber, $botData, $action);
    self::generateDataFile($currentNumber, $botData, $action);

    return true;
  }

  // Obtener archivo workflow del bot
  static function getWorkflowFile($botNumber) {
    $path = SHARED_PATH . '/bots/infoproduct/rapid/workflow_' . $botNumber . '.json';
    return file::getJson($path, function() use ($botNumber) {
      return self::generateWorkflowFile($botNumber);
    });
  }

  // Obtener archivo data del bot
  static function getDataFile($botNumber) {
    $path = SHARED_PATH . '/bots/data/' . $botNumber . '.json';
    return file::getJson($path, function() use ($botNumber) {
      return self::generateDataFile($botNumber);
    });
  }

  // Generar archivo workflow_{numero}.json
  static function generateWorkflowFile($botNumber, $botData = null, $action = 'create') {
    if (!$botData) {
      $botData = db::table('bots')->where('number', $botNumber)->first();
      if (!$botData) {
        log::error("botHandlers::generateWorkflowFile - Bot no encontrado: {$botNumber}", null, ['module' => 'bot']);
        return false;
      }
    }

    $config = isset($botData['config']) && is_string($botData['config'])
      ? json_decode($botData['config'], true)
      : ($botData['config'] ?? []);

    $filePath = null;
    $workflowId = $config['workflow_id'] ?? null;

    if ($workflowId) {
      $workflow = db::table('work_flows')->find($workflowId);
      if ($workflow) {
        $filePath = $workflow['file_path'] ?? null;
      }
    }

    $path = SHARED_PATH . '/bots/infoproduct/rapid/workflow_' . $botNumber . '.json';
    return file::saveJson($path, ['file_path' => $filePath], 'bot', $action);
  }

  // Generar archivo data/{numero}.json
  static function generateDataFile($botNumber, $botData = null, $action = 'create') {

    if (!$botData) {
      $botData = db::table('bots')->where('number', $botNumber)->first();
      if (!$botData) {
        log::error("botHandlers::generateDataFile - Bot no encontrado: {$botNumber}", null, ['module' => 'bot']);
        return false;
      }
    }

    $data = $botData;

    if (isset($data['config']) && is_string($data['config'])) {
      $data['config'] = json_decode($data['config'], true);
    }

    // Resolver credenciales de AI (conversation, image, audio)
    if (isset($data['config']['apis']['ai']) && is_array($data['config']['apis']['ai'])) {
      $resolvedAi = [];
      foreach ($data['config']['apis']['ai'] as $task => $credentialIds) {
        $resolvedAi[$task] = [];
        if (is_array($credentialIds)) {
          foreach ($credentialIds as $credId) {
            $credential = db::table('credentials')->find($credId);
            if ($credential) {
              if (isset($credential['config']) && is_string($credential['config'])) {
                $credential['config'] = json_decode($credential['config'], true);
              }
              unset($credential['dc'], $credential['da'], $credential['ta'], $credential['tu']);
              $resolvedAi[$task][] = $credential;
            }
          }
        }
      }
      $data['config']['apis']['ai'] = $resolvedAi;
    }

    // Resolver credenciales de chat
    if (isset($data['config']['apis']['chat']) && is_array($data['config']['apis']['chat'])) {
      $resolvedChat = [];
      foreach ($data['config']['apis']['chat'] as $credId) {
        $credential = db::table('credentials')->find($credId);
        if ($credential) {
          if (isset($credential['config']) && is_string($credential['config'])) {
            $credential['config'] = json_decode($credential['config'], true);
          }
          unset($credential['dc'], $credential['da'], $credential['ta'], $credential['tu']);
          $resolvedChat[] = $credential;
        }
      }
      $data['config']['apis']['chat'] = $resolvedChat;
    }

    $path = SHARED_PATH . '/bots/data/' . $botNumber . '.json';
    return file::saveJson($path, $data, 'bot', $action);
  }

  // Eliminar archivos de contexto antiguos
  private static function deleteContextFiles($botNumber) {
    $files = [
      SHARED_PATH . '/bots/data/' . $botNumber . '.json',
      SHARED_PATH . '/bots/infoproduct/rapid/workflow_' . $botNumber . '.json',
      SHARED_PATH . '/bots/infoproduct/rapid/activators_' . $botNumber . '.json'
    ];

    foreach ($files as $file) {
      if (file_exists($file)) {
        unlink($file);
      }
    }
  }
}