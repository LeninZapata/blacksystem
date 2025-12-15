<?php
// botHandlers - Handlers personalizados para bot
class botHandlers {

  /**
   * Guarda un archivo JSON con información del bot en una carpeta específica
   * 
   * @param array $botData - Datos del bot (debe contener id, number, config)
   * @param string $context - Carpeta donde guardar (ej: 'workflow', 'sessions', etc)
   * @return bool - True si se guardó correctamente, false en caso de error
   */
  static function saveContextFile($botData, $context = 'workflow') {
    if (!isset($botData['id']) || !isset($botData['number'])) {
      log::error('botHandlers - Datos insuficientes para guardar archivo', $botData, ['module' => 'bot']);
      return false;
    }

    $botId = $botData['id'];
    $number = $botData['number'];
    
    // Parsear config si es string JSON
    $config = isset($botData['config']) ? $botData['config'] : null;
    if (is_string($config)) {
      $config = json_decode($config, true);
    }

    // Obtener file_path desde work_flows usando workflow_id
    $filePath = null;
    $workflowId = $config['workflow_id'] ?? null;
    
    if ($workflowId) {
      $workflow = db::table('work_flows')->find($workflowId);
      if ($workflow) {
        $filePath = $workflow['file_path'] ?? null;
      }
    }

    // Directorio donde se guardará el archivo
    $dir = STORAGE_PATH . '/bots/' . $context;
    
    // Crear directorio si no existe
    if (!is_dir($dir)) {
      if (!mkdir($dir, 0755, true)) {
        log::error('botHandlers - No se pudo crear directorio', ['dir' => $dir], ['module' => 'bot']);
        return false;
      }
    }

    // Nombre del archivo: {numero}_{bot_id}.json
    $filename = $number . '_' . $botId . '.json';
    $fullPath = $dir . '/' . $filename;

    // Contenido del archivo JSON
    $jsonContent = [
      'file_path' => $filePath
    ];

    // Guardar archivo
    $result = file_put_contents($fullPath, json_encode($jsonContent, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    if ($result === false) {
      log::error('botHandlers - Error al guardar archivo', ['path' => $fullPath], ['module' => 'bot']);
      return false;
    }

    log::info('botHandlers - Archivo de contexto guardado', [
      'bot_id' => $botId,
      'context' => $context,
      'file' => $filename
    ], ['module' => 'bot']);

    return true;
  }
}
