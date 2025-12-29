<?php
class BotHandlers {
  protected static $table = DB_TABLES['bots'];
  private static $logMeta = ['module' => 'BotHandlers', 'layer' => 'app/resources'];

  /**
   * Guardar archivos de contexto del bot
   * Genera: data/{numero}.json y workflow_{numero}.json
   */
  static function saveContextFile($botData, $action = 'create', $oldNumber = null) {
    if (!isset($botData['id']) || !isset($botData['number'])) {
      ogLog::error('saveContextFile - Datos insuficientes', null, self::$logMeta);
      return false;
    }

    $currentNumber = $botData['number'];

    // Si cambió el número de bot (solo en update)
    if ($action === 'update' && $oldNumber && $oldNumber !== $currentNumber) {

      // Eliminar archivos antiguos
      self::deleteContextFiles($oldNumber);

      // Regenerar activators con el nuevo número
      ogApp()->loadHandler('ProductHandler');
      ProductHandler::generateActivatorsFile($currentNumber, $botData['id'], 'update');
    }

    // Generar archivos con el número actual
    self::generateWorkflowFile($currentNumber, $botData, $action);
    self::generateDataFile($currentNumber, $botData, $action);

    return true;
  }

  // Obtener archivo workflow del bot
  static function getWorkflowFile($botNumber) {
    $path = BOTS_INFOPRODUCT_RAPID_PATH . '/workflow_' . $botNumber . '.json';

    // Cargar ogFile bajo demanda
    $file = ogApp()->helper('file');

    return $file->getJson($path, function() use ($botNumber) {
      return self::generateWorkflowFile($botNumber);
    });
  }

  // Obtener archivo data del bot
  static function getDataFile($botNumber) {
    $path = BOTS_DATA_PATH . '/' . $botNumber . '.json';

    // Cargar ogFile bajo demanda
    $file = ogApp()->helper('file');

    return $file->getJson($path, function() use ($botNumber) {
      return self::generateDataFile($botNumber);
    });
  }

  // Generar archivo workflow_{numero}.json
  static function generateWorkflowFile($botNumber, $botData = null, $action = 'create') {
    if (!$botData) {
      $botData = ogDb::table(self::$table)->where('number', $botNumber)->first();
      if (!$botData) {
        ogLog::error("generateWorkflowFile - Bot no encontrado: {$botNumber}", null,  self::$logMeta);
        return false;
      }
    }

    $config = isset($botData['config']) && is_string($botData['config'])
      ? json_decode($botData['config'], true)
      : ($botData['config'] ?? []);

    $filePath = null;
    $workflowId = $config['workflow_id'] ?? null;

    if ($workflowId) {
      $workflow = ogDb::table('work_flows')->find($workflowId);
      if ($workflow) {
        $filePath = $workflow['file_path'] ?? null;
      }
    }

    $path = BOTS_INFOPRODUCT_RAPID_PATH . '/workflow_' . $botNumber . '.json';

    // Cargar ogFile bajo demanda
    $file = ogApp()->helper('file');

    return $file->saveJson($path, ['file_path' => $filePath], 'bot', $action);
  }

  // Generar archivo data/{numero}.json
  static function generateDataFile($botNumber, $botData = null, $action = 'create') {

    if (!$botData) {
      $botData = ogDb::table(self::$table)->where('number', $botNumber)->first();
      if (!$botData) {
        ogLog::error("generateDataFile - Bot no encontrado: {$botNumber}", null, self::$logMeta);
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
            $credential = ogDb::table('credentials')->find($credId);
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
        $credential = ogDb::table('credentials')->find($credId);
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

    $path = BOTS_DATA_PATH . '/' . $botNumber . '.json';

    // Cargar ogFile bajo demanda
    $file = ogApp()->helper('file');

    return $file->saveJson($path, $data, 'bot', $action);
  }

  // Eliminar archivos de contexto antiguos
  private static function deleteContextFiles($botNumber) {
    $files = [
      BOTS_DATA_PATH . '/' . $botNumber . '.json',
      BOTS_INFOPRODUCT_RAPID_PATH . '/workflow_' . $botNumber . '.json',
      BOTS_INFOPRODUCT_RAPID_PATH . '/activators_' . $botNumber . '.json'
    ];

    foreach ($files as $file) {
      if (file_exists($file)) {
        @unlink($file);
      }
    }
  }
}