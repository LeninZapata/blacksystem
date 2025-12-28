<?php

class webhookController {
  private static $logMeta = ['module' => 'webhook', 'layer' => 'app'];

  function whatsapp() {
    try {
      $rawData = ogRequest::data();

      // Cargar servicio ogChatApi bajo demanda
      $ogChatApi = ogApp()->service('chatApi');
      $result = $ogChatApi->detectAndNormalize($rawData);

      if (!$result) {
        ogResponse::json(['success' => false, 'error' => 'Provider no detectado'], 400);
      }

      $detectedProvider = $result['provider'];
      $normalized = $result['normalized'];
      $standard = $result['standard'];

      $sender = $standard['sender'];
      $person = $standard['person'];
      $message = $standard['message'];
      $context = $standard['context'];
      $webhookData = $standard['webhook'];

      if (!$sender['number']) {
        ogResponse::json(['success' => false, 'error' => 'Sender no encontrado'], 400);
      }

      if (!$person['number']) {
        ogResponse::json(['success' => false, 'error' => 'Person no encontrado'], 400);
      }

      // Cargar BotHandlers bajo demanda
      ogApp()->loadHandler('BotHandlers');
      $bot = BotHandlers::getDataFile($sender['number']);
      
      if (!$bot) {
        ogLog::error('webhookController::whatsapp - Bot no encontrado', [
          'bot_number' => $sender['number']
        ], ['module' => 'webhook']);
        ogResponse::json(['success' => false, 'error' => "Bot no encontrado: {$sender['number']}"], 404);
      }

      $ogChatApi->setConfig($bot, $detectedProvider);

      $workflowData = BotHandlers::getWorkflowFile($sender['number']);
      $workflowFile = $workflowData['file_path'] ?? null;

      if (!$workflowFile) {
        ogLog::error('webhookController::whatsapp - Workflow no configurado', [
          'bot_number' => $sender['number']
        ], ['module' => 'webhook']);
        ogResponse::json(['success' => false, 'error' => 'Workflow no configurado'], 400);
      }

      // Resolver handler dinámicamente
      $handler = $this->resolveHandler($workflowFile);

      // Ejecutar handler con webhook completo
      $handler->handle([
        'provider' => $detectedProvider,
        'normalized' => $normalized,
        'standard' => $standard,
        'raw' => $rawData
      ]);

      ogResponse::success([
        'message' => 'Webhook procesado',
        'provider' => $detectedProvider,
        'workflow' => $workflowFile
      ]);

    } catch (Exception $e) {
      ogLog::error('webhookController::whatsapp - Error crítico', [
        'error' => $e->getMessage()
      ], ['module' => 'webhook']);
      ogResponse::serverError('Error procesando webhook', OG_IS_DEV ? $e->getMessage() : null);
    }
  }

  // Resolver handler desde versions/
  private function resolveHandler($workflowFile) {
    $workflowPath = APP_PATH . "/workflows/versions/{$workflowFile}";

    if (!file_exists($workflowPath)) {
      ogLog::error('webhookController::resolveHandler - Archivo no existe', [
        'file' => $workflowFile
      ], self::$logMeta);
      ogLog::throwError("Workflow no encontrado: {$workflowFile}", [], self::$logMeta);
    }

    require_once $workflowPath;

    $className = $this->getClassNameFromFile($workflowFile);

    if (!class_exists($className)) {
      ogLog::throwError("Clase no encontrada: {$className} en {$workflowFile}", [], self::$logMeta);
    }

    return new $className();
  }

  // Convertir nombre de archivo a nombre de clase
  private function getClassNameFromFile($filename) {
    // infoproduct-v2.php → InfoproductV2Handler
    // ecommerce-test.php → EcommerceTestHandler

    $name = str_replace('.php', '', $filename);
    $parts = explode('-', $name);

    $className = '';
    foreach ($parts as $part) {
      $className .= ucfirst($part);
    }

    return $className . 'Handler';
  }

  function telegram() {
    try {
      $rawData = ogRequest::data();
      
      // Cargar servicio ogChatApi bajo demanda
      $ogChatApi = ogApp()->ogService('ogChatApi');
      $result = $ogChatApi->detectAndNormalize($rawData);

      if (!$result) {
        ogResponse::json(['success' => false, 'error' => 'Provider no detectado'], 400);
      }

      $standard = $result['standard'];
      $sender = $standard['sender'];
      $message = $standard['message'];
      $context = $standard['context'];

      // TODO: Implementar lógica similar a whatsapp

    } catch (Exception $e) {
      ogLog::error('webhookController::telegram - Error crítico', [
        'error' => $e->getMessage()
      ], ['module' => 'webhook']);
      ogResponse::serverError('Error procesando webhook', OG_IS_DEV ? $e->getMessage() : null);
    }
  }

  function email() {
    try {
      $rawData = ogRequest::data();

      ogResponse::json(['success' => false, 'error' => 'Email webhook no implementado'], 501);

    } catch (Exception $e) {
      ogLog::error('webhookController::email - Error crítico', [
        'error' => $e->getMessage()
      ], ['module' => 'webhook']);
      ogResponse::serverError('Error procesando webhook', OG_IS_DEV ? $e->getMessage() : null);
    }
  }
}