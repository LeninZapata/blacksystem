<?php

class WebhookController {
  private $logMeta = ['module' => 'webhook/webhookController', 'layer' => 'app/controller'];

  function whatsapp() {
    try {
      $rawData = ogRequest::data();
      ogLog::info('whatsapp - Webhook recibido', [], $this->logMeta);
      // ogLog::info('whatsapp - Webhook recibido RAW', $rawData, $this->logMeta);

      // Cargar servicio ogChatApi bajo demanda
      $chatapi = ogApp()->service('chatApi');
      $result = $chatapi->detectAndNormalize($rawData);

      if (!$result) {
        ogResponse::json(['success' => false, 'error' => 'Provider no detectado'], 400);
      } ogLog::info('whatsapp - Provider detectado', ['provider' => $result['provider']], $this->logMeta);

      $detectedProvider = $result['provider'];
      $normalized = $result['normalized'];
      $standard = $result['standard'];

      $sender = $standard['sender'];
      $person = $standard['person'];
      $message = $standard['message'];
      $context = $standard['context'];
      $webhookData = $standard['webhook'];


      // FILTRO: Ignorar eventos de status (sent, delivered, read)
      if ($message['type'] === 'STATUS') {
        $statusType = $standard['status']['type'] ?? 'unknown';
        ogLog::info('whatsapp - Evento de status detectado, ignorando', [
          'status_type' => $statusType,
          'message_id' => $message['id']
        ], $this->logMeta);
        ogResponse::success([
          'message' => 'Status event received',
          'status' => $statusType,
          'ignored' => true
        ]);
      }
      if (!$sender['number']) {
        ogResponse::json(['success' => false, 'error' => 'Sender no encontrado'], 400);
      }

      if (!$person['number']) {
        ogResponse::json(['success' => false, 'error' => 'Person no encontrado'], 400);
      }

      // Cargar BotHandler bajo demanda
      ogApp()->loadHandler('bot');
      $bot = BotHandler::getDataFile($sender['number']);

      if (!$bot) {
        ogResponse::json(['success' => false, 'error' => "Bot no encontrado: {$sender['number']}"], 404);
      } ogLog::info('whatsapp - Bot encontrado', ['bot_number' => $bot['number'], 'bot_name' => $bot['name'] ?? null ], $this->logMeta);

      // Enriquecer el standard con datos del bot
      $standard['sender']['user_id'] = $bot['user_id'] ?? null;
      $standard['sender']['bot_id'] = $bot['id'] ?? null;
      $standard['sender']['bot_name'] = $bot['name'] ?? null;
      $standard['bot'] = [
        'id' => $bot['id'] ?? null,
        'user_id' => $bot['user_id'] ?? null,
        'name' => $bot['name'] ?? null,
        'number' => $bot['number'] ?? null,
        'type' => $bot['type'] ?? null,
        'mode' => $bot['mode'] ?? null,
        'country_code' => $bot['country_code'] ?? null
      ];

      $chatapi->setConfig($bot, $detectedProvider);

      $workflowData = BotHandler::getWorkflowFile($sender['number']);
      $workflowFile = $workflowData['file_path'] ?? null;

      if (!$workflowFile) {
        ogLog::error('whatsapp - Workflow no configurado', [ 'bot_number' => $sender['number'] ], $this->logMeta);
        ogResponse::json(['success' => false, 'error' => 'Workflow no configurado'], 400);
      }

      // Resolver handler dinámicamente
      $handler = $this->resolveHandler($workflowFile);
      // Ejecutar handler con webhook completo
      //ogLog::debug("whatsapp - Handler resuelto, se va ejecutar el metodo <code>handle</code> con los siguientes datos", [ 'provider' => $detectedProvider, 'normalized' => $normalized, 'standard' => $standard], $this->logMeta);
      $handler->handle([ 'provider' => $detectedProvider, 'normalized' => $normalized, 'standard' => $standard, 'raw' => $rawData ]);
      ogResponse::success([ 'message' => 'Webhook procesado', 'provider' => $detectedProvider, 'workflow' => $workflowFile ]);

    } catch (Exception $e) {
      ogLog::error('whatsapp - Error crítico', [ 'error' => $e->getMessage() ], $this->logMeta);
      ogResponse::serverError('whatsapp - Error procesando webhook', $e->getMessage() ?? null);
    }
  }

  // Resolver handler desde versions/
  private function resolveHandler($workflowFile) {
    $workflowPath = ogApp()->getPath() . "/workflows/versions/{$workflowFile}";

    if (!file_exists($workflowPath)) {
      ogLog::throwError("resolveHandler - Archivo workflow del bot no existe: {$workflowFile}", [], $this->logMeta);
    }

    require_once $workflowPath;

    $className = $this->getClassNameFromFile($workflowFile);

    if (!class_exists($className)) {
      ogLog::throwError("Clase de ejecución no encontrada: {$className} en {$workflowFile}", [], $this->logMeta);
    } ogLog::info("resolveHandler - Clase de ejecución encontrada: {$className}", [], $this->logMeta);

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
      // $chatapi = ogApp()->ogService('ogChatApi');
      // $result = $chatapi->detectAndNormalize($rawData);

      /*if (!$result) {
        ogResponse::json(['success' => false, 'error' => 'Provider no detectado'], 400);
      }*/

      /*$standard = $result['standard'];
      $sender = $standard['sender'];
      $message = $standard['message'];
      $context = $standard['context'];*/

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