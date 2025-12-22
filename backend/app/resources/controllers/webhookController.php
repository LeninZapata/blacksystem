<?php

class webhookController {

  function whatsapp() {
    try {
      $rawData = request::data();

      $chatapi = service::integration('chatapi');
      $result = $chatapi->detectAndNormalize($rawData);

      if (!$result) {
        response::json(['success' => false, 'error' => 'Provider no detectado'], 400);
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
        response::json(['success' => false, 'error' => 'Sender no encontrado'], 400);
      }

      if (!$person['number']) {
        response::json(['success' => false, 'error' => 'Person no encontrado'], 400);
      }

      $bot = botHandlers::getDataFile($sender['number']);
      
      if (!$bot) {
        log::error('webhookController::whatsapp - Bot no encontrado', [
          'bot_number' => $sender['number']
        ], ['module' => 'webhook']);
        response::json(['success' => false, 'error' => "Bot no encontrado: {$sender['number']}"], 404);
      }

      $chatapi->setConfig($bot, $detectedProvider);

      $workflowData = botHandlers::getWorkflowFile($sender['number']);
      $workflowFile = $workflowData['file_path'] ?? null;

      if (!$workflowFile) {
        log::error('webhookController::whatsapp - Workflow no configurado', [
          'bot_number' => $sender['number']
        ], ['module' => 'webhook']);
        response::json(['success' => false, 'error' => 'Workflow no configurado'], 400);
      }

      // NUEVO: Resolver handler dinámicamente
      $handler = $this->resolveHandler($workflowFile);

      // NUEVO: Ejecutar handler con webhook completo
      $handler->handle([
        'provider' => $detectedProvider,
        'normalized' => $normalized,
        'standard' => $standard,
        'raw' => $rawData
      ]);

      response::success([
        'message' => 'Webhook procesado',
        'provider' => $detectedProvider,
        'workflow' => $workflowFile
      ]);

    } catch (Exception $e) {
      log::error('webhookController::whatsapp - Error crítico', [
        'error' => $e->getMessage()
      ], ['module' => 'webhook']);
      response::serverError('Error procesando webhook', IS_DEV ? $e->getMessage() : null);
    }
  }

  // NUEVO: Resolver handler desde versions/
  private function resolveHandler($workflowFile) {
    $workflowPath = APP_PATH . "/workflows/versions/{$workflowFile}";

    if (!file_exists($workflowPath)) {
      log::error('webhookController::resolveHandler - Archivo no existe', [
        'file' => $workflowFile
      ], ['module' => 'webhook']);
      throw new Exception("Workflow no encontrado: {$workflowFile}");
    }

    require_once $workflowPath;

    $className = $this->getClassNameFromFile($workflowFile);

    if (!class_exists($className)) {
      throw new Exception("Clase no encontrada: {$className} en {$workflowFile}");
    }

    return new $className();
  }

  // NUEVO: Convertir nombre de archivo a nombre de clase
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
      $rawData = request::data();
      $result = chatapi::detectAndNormalize($rawData);

      if (!$result) {
        response::json(['success' => false, 'error' => 'Provider no detectado'], 400);
      }

      $standard = $result['standard'];
      $sender = $standard['sender'];
      $message = $standard['message'];
      $context = $standard['context'];

      // TODO: Implementar lógica similar a whatsapp

    } catch (Exception $e) {
      log::error('webhookController::telegram - Error crítico', [
        'error' => $e->getMessage()
      ], ['module' => 'webhook']);
      response::serverError('Error procesando webhook', IS_DEV ? $e->getMessage() : null);
    }
  }

  function email() {
    try {
      $rawData = request::data();

      response::json(['success' => false, 'error' => 'Email webhook no implementado'], 501);

    } catch (Exception $e) {
      log::error('webhookController::email - Error crítico', [
        'error' => $e->getMessage()
      ], ['module' => 'webhook']);
      response::serverError('Error procesando webhook', IS_DEV ? $e->getMessage() : null);
    }
  }
}