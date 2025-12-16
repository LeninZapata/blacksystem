<?php
class webhookController extends controller {

  function __construct() {
    parent::__construct('webhook');
  }

  function whatsapp() {
    try {
      $data = request::data();
      
      if (empty($data)) {
        response::json(['success' => false, 'error' => 'No se recibieron datos en el webhook'], 400);
      }

      $remoteJid = $data['key']['remoteJid'] ?? null;
      if (!$remoteJid) {
        response::json(['success' => false, 'error' => 'remoteJid no encontrado en webhook'], 400);
      }

      $botNumber = str_replace('@s.whatsapp.net', '', $remoteJid);
      
      $bot = botHandlers::getDataFile($botNumber);
      if (!$bot) {
        log::error('webhookController::whatsapp - Bot no encontrado', ['bot_number' => $botNumber], ['module' => 'webhook']);
        response::json(['success' => false, 'error' => "Bot {$botNumber} no encontrado"], 404);
      }

      chatApiService::setConfig($bot);

      $workflowData = botHandlers::getWorkflowFile($botNumber);
      $workflowFile = $workflowData['file_path'] ?? null;

      if (!$workflowFile) {
        log::error('webhookController::whatsapp - Workflow no configurado', ['bot_number' => $botNumber], ['module' => 'webhook']);
        response::json(['success' => false, 'error' => "Bot {$botNumber} no tiene workflow configurado"], 400);
      }

      $workflowPath = APP_PATH . "/workflows/{$workflowFile}";
      if (!file_exists($workflowPath)) {
        log::error('webhookController::whatsapp - Archivo workflow no existe', ['file' => $workflowFile], ['module' => 'webhook']);
        response::json(['success' => false, 'error' => "Workflow file '{$workflowFile}' no encontrado"], 404);
      }

      require $workflowPath;

      response::success(['message' => 'Webhook procesado']);

    } catch (Exception $e) {
      log::error('webhookController::whatsapp - Error crítico', ['error' => $e->getMessage()], ['module' => 'webhook']);
      response::serverError('Error al procesar webhook', IS_DEV ? $e->getMessage() : null);
    }
  }
}