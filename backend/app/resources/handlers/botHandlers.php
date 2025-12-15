<?php
class botHandlers {

  static function saveContextFile($botData, $action = 'create') {
    if (!isset($botData['id']) || !isset($botData['number'])) {
      log::error('botHandlers::saveContextFile - Datos insuficientes', null, ['module' => 'bot']);
      return false;
    }

    self::generateWorkflowFile($botData['number'], $botData, $action);
    self::generateDataFile($botData['number'], $botData, $action);
    return true;
  }

  static function getWorkflowFile($botNumber) {
    $path = SHARED_PATH . '/bots/infoproduct/rapid/workflow_' . $botNumber . '.json';
    return file::getJson($path, function() use ($botNumber) {
      return self::generateWorkflowFile($botNumber);
    });
  }

  static function getDataFile($botNumber) {
    $path = SHARED_PATH . '/bots/data/' . $botNumber . '.json';
    return file::getJson($path, function() use ($botNumber) {
      return self::generateDataFile($botNumber);
    });
  }

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

    if (isset($data['config']['apis']) && is_array($data['config']['apis'])) {
      foreach ($data['config']['apis'] as $key => $credentialIds) {
        if (is_array($credentialIds)) {
          $resolvedCredentials = [];
          foreach ($credentialIds as $credId) {
            $credential = db::table('credentials')->find($credId);
            if ($credential) {
              if (isset($credential['config']) && is_string($credential['config'])) {
                $credential['config'] = json_decode($credential['config'], true);
              }
              $resolvedCredentials[] = $credential;
            }
          }
          $data['config']['apis'][$key] = $resolvedCredentials;
        }
      }
    }

    $path = SHARED_PATH . '/bots/data/' . $botNumber . '.json';
    return file::saveJson($path, $data, 'bot', $action);
  }
}
