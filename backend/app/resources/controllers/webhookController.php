<?php
class webhookController {

  function whatsapp() {
    try {
      $rawData = request::data();
      
      // Semántico: Detectar provider del webhook
      $webhook = service::integration('chatapi', 'detect', $rawData);
      
      // Extraer datos normalizados
      $sender = $webhook->extractSender();
      $message = $webhook->extractMessage();
      $detectedProvider = $webhook->getProvider(); // evolution, wazapi, etc
      
      if (!$sender['number']) {
        response::json(['success' => false, 'error' => 'Número de remitente no encontrado'], 400);
      }
      
      $bot = botHandlers::getDataFile($sender['number']);
      if (!$bot) {
        log::error('webhookController::whatsapp - Bot no encontrado', ['bot_number' => $sender['number']], ['module' => 'webhook']);
        response::json(['success' => false, 'error' => "Bot {$sender['number']} no encontrado"], 404);
      }

      // Configurar chatapi con el provider detectado (semántico)
      chatapi::setConfig($bot, $detectedProvider);

      $workflowData = botHandlers::getWorkflowFile($sender['number']);
      $workflowFile = $workflowData['file_path'] ?? null;

      if (!$workflowFile) {
        log::error('webhookController::whatsapp - Workflow no configurado', ['bot_number' => $sender['number']], ['module' => 'webhook']);
        response::json(['success' => false, 'error' => "Bot {$sender['number']} no tiene workflow configurado"], 400);
      }

      $workflowPath = APP_PATH . "/workflows/{$workflowFile}";
      if (!file_exists($workflowPath)) {
        log::error('webhookController::whatsapp - Archivo workflow no existe', ['file' => $workflowFile], ['module' => 'webhook']);
        response::json(['success' => false, 'error' => "Workflow file '{$workflowFile}' no encontrado"], 404);
      }

      // Ejecutar workflow con variables disponibles
      require $workflowPath;

      response::success(['message' => 'Webhook procesado', 'provider' => $detectedProvider]);

    } catch (Exception $e) {
      log::error('webhookController::whatsapp - Error crítico', ['error' => $e->getMessage()], ['module' => 'webhook']);
      response::serverError('Error al procesar webhook', IS_DEV ? $e->getMessage() : null);
    }
  }

  // Futuro: webhook de Telegram
  function telegram() {
    try {
      $rawData = request::data();
      
      // Semántico: Detectar provider
      $webhook = service::integration('chatapi', 'detect', $rawData);
      
      $sender = $webhook->extractSender();
      $message = $webhook->extractMessage();
      $provider = $webhook->getProvider();
      
      // ... lógica similar a whatsapp
      
    } catch (Exception $e) {
      log::error('webhookController::telegram - Error crítico', ['error' => $e->getMessage()], ['module' => 'webhook']);
      response::serverError('Error al procesar webhook', IS_DEV ? $e->getMessage() : null);
    }
  }

  // Futuro: webhook de Email
  function email() {
    try {
      $rawData = request::data();
      
      // Semántico: Detectar provider de email
      $webhook = service::integration('email', 'detect', $rawData);
      
      // ... lógica email
      
    } catch (Exception $e) {
      log::error('webhookController::email - Error crítico', ['error' => $e->getMessage()], ['module' => 'webhook']);
      response::serverError('Error al procesar webhook', IS_DEV ? $e->getMessage() : null);
    }
  }
}