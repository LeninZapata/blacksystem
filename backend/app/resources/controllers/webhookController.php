<?php

class WebhookController {
  private $logMeta = ['module' => 'webhook/webhookController', 'layer' => 'app/controller'];

  function whatsapp() {
    try {
      $rawData = ogRequest::data();
      ogLog::info('whatsapp - Webhook recibido', [], $this->logMeta);
      // ogLog::info('whatsapp - Webhook recibido RAW', $rawData, $this->logMeta);

      // Cargar servicio chatApi bajo demanda
      $chatapi = ogApp()->service('chatApi');

      // PASO 1: Detectar provider desde la estructura del webhook
      $detectedProvider = $chatapi->detect($rawData);
      if (!$detectedProvider) {
        ogResponse::json(['success' => false, 'error' => 'Provider no detectado'], 400);
      }
      ogLog::info('whatsapp - Provider detectado', ['provider' => $detectedProvider], $this->logMeta);

      // PASO 2: Extraer número del bot desde el payload crudo (sin normalizar)
      $botNumber = $chatapi->extractSenderFromRaw($rawData, $detectedProvider);
      if (!$botNumber) {
        ogResponse::json(['success' => false, 'error' => 'Sender no encontrado en el payload'], 400);
      }

      // PASO 3: Cargar el bot
      ogApp()->loadHandler('bot');
      $bot = BotHandler::getDataFile($botNumber);
      if (!$bot) {
        ogResponse::json(['success' => false, 'error' => "Bot no encontrado: {$botNumber}"], 404);
      }
      ogLog::info('whatsapp - Bot encontrado', ['bot_number' => $bot['number'], 'bot_name' => $bot['name'] ?? null], $this->logMeta);

      // PASO 4: Configurar el servicio con los datos del bot
      // (debe ocurrir ANTES de normalizar para que la descarga de media tenga acceso al access_token)
      $chatapi->setConfig($bot, $detectedProvider);

      // PASO 5: Normalizar + estandarizar ahora que setConfig ya fue llamado
      $result = $chatapi->normalizeRaw($rawData, $detectedProvider);
      if (!$result) {
        ogResponse::json(['success' => false, 'error' => 'Error al normalizar webhook'], 400);
      }

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

      if (!$person['number']) {
        ogResponse::json(['success' => false, 'error' => 'Person no encontrado'], 400);
      }

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

      $workflowData = BotHandler::getWorkflowFile($botNumber);
      $workflowFile = $workflowData['file_path'] ?? null;

      if (!$workflowFile) {
        ogLog::error('whatsapp - Workflow no configurado', [ 'bot_number' => $botNumber ], $this->logMeta);
        ogResponse::json(['success' => false, 'error' => 'Workflow no configurado'], 400);
      }

      // Resolver handler dinámicamente
      $handler = $this->resolveHandler($workflowFile);
      // Ejecutar handler con webhook completo
      //ogLog::debug("whatsapp - Handler resuelto, se va ejecutar el metodo <code>handle</code> con los siguientes datos", [ 'provider' => $detectedProvider, 'normalized' => $normalized, 'standard' => $standard], $this->logMeta);
      // Responder 200 a Meta inmediatamente para evitar retransmisión del webhook
      ogResponse::flushAndContinue([ 'message' => 'Webhook procesado', 'provider' => $detectedProvider, 'workflow' => $workflowFile ]);

      $handler->handle([ 'provider' => $detectedProvider, 'normalized' => $normalized, 'standard' => $standard, 'raw' => $rawData ]);

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