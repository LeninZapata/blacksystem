<?php
class webhookController {

  function whatsapp() {
    try {
      $rawData = request::data();

      // 1. Detectar provider y normalizar (usa service::integration para evitar recorrido de autoload)
      $chatapi = service::integration('chatapi');
      $result = $chatapi->detectAndNormalize($rawData);

      if (!$result) {
        response::json(['success' => false, 'error' => 'Provider no detectado'], 400);
      }

      $detectedProvider = $result['provider'];
      $normalized = $result['normalized'];
      $standard = $result['standard'];

      // 2. Extraer datos en variables simples para el workflow
      $sender = $standard['sender'];
      $person = $standard['person'];
      $message = $standard['message'];
      $context = $standard['context'];
      $webhookData = $standard['webhook'];

      if (!$sender['number']) {
        response::json(['success' => false, 'error' => 'Sender no encontrado'], 400);
      }

      if (!$person['number']) {
        response::json(['success' => false, 'error' => 'Person no encontrado'], 400);
      }

      // 3. Buscar bot por número
      $bot = botHandlers::getDataFile($sender['number']);
      if (!$bot) {
        log::error('webhookController::whatsapp - Bot no encontrado', ['bot_number' => $sender['number']], ['module' => 'webhook']);
        response::json(['success' => false, 'error' => "Bot no encontrado: {$sender['number']}"], 404);
      }

      // 4. Configurar chatapi con el provider detectado (usa instancia cacheada)
      $chatapi->setConfig($bot, $detectedProvider);

      // 5. Obtener archivo de workflow
      $workflowData = botHandlers::getWorkflowFile($sender['number']);
      $workflowFile = $workflowData['file_path'] ?? null;

      if (!$workflowFile) {
        log::error('webhookController::whatsapp - Workflow no configurado', ['bot_number' => $sender['number']], ['module' => 'webhook']);
        response::json(['success' => false, 'error' => 'Workflow no configurado'], 400);
      }

      $workflowPath = APP_PATH . "/workflows/{$workflowFile}";
      if (!file_exists($workflowPath)) {
        log::error('webhookController::whatsapp - Archivo workflow no existe', ['file' => $workflowFile], ['module' => 'webhook']);
        response::json(['success' => false, 'error' => "Workflow no encontrado: {$workflowFile}"], 404);
      }

      // 6. Ejecutar workflow con variables disponibles
      require $workflowPath;

      response::success(['message' => 'Webhook procesado', 'provider' => $detectedProvider]);

    } catch (Exception $e) {
      log::error('webhookController::whatsapp - Error crítico', ['error' => $e->getMessage()], ['module' => 'webhook']);
      response::serverError('Error procesando webhook', IS_DEV ? $e->getMessage() : null);
    }
  }

  function telegram() {
    try {
      $rawData = request::data();
      $result = chatapi::detectAndNormalize($rawData);

      if (!$result) {
        response::json(['success' => false, 'error' => 'Provider no detectado'], 400);
      }

      $standard = $result['standard'];
      $sender = $standard['sender'];
      $message = $standard['message'];
      $context = $standard['context'];

      // ... lógica similar a whatsapp

    } catch (Exception $e) {
      log::error('webhookController::telegram - Error crítico', ['error' => $e->getMessage()], ['module' => 'webhook']);
      response::serverError('Error procesando webhook', IS_DEV ? $e->getMessage() : null);
    }
  }

  function email() {
    try {
      $rawData = request::data();

      // TODO: Crear emailService con método detectAndNormalize()
      // $result = email::detectAndNormalize($rawData);

      response::json(['success' => false, 'error' => 'Email webhook no implementado'], 501);

    } catch (Exception $e) {
      log::error('webhookController::email - Error crítico', ['error' => $e->getMessage()], ['module' => 'webhook']);
      response::serverError('Error procesando webhook', IS_DEV ? $e->getMessage() : null);
    }
  }
}