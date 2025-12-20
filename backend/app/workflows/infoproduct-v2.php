<?php
/**
 * WORKFLOW: infoproduct-v2.php
 * Variables disponibles: $webhook, $sender, $person, $message, $context, $bot, $standard
 */
// Cambiar la hora al timezone del bot
$timezone = $bot['config']['timezone'] ?? country::get($bot['country_code']) ?? 'America/Guayaquil';
date_default_timezone_set($timezone);

// Extraer variables
$from = $person['number']; $name = $person['name'];
$text = $message['text']; $messageType = $message['type'];
$logMetada = [ 'module' => 'whatsapp_message_received', 'bot_id' => $bot['id'], 'tags' => ['workflow', $from] ];

log::info("Mensaje recibido", [ 'from' => $from, 'name' => $name, 'text' => substr($text, 0, 50), 'type' => $messageType ], $logMetada);

// =================== 1) VALIDAR SI EXISTE CONVERSACIÓN ACTIVA ====================
require_once APP_PATH . '/workflows/helpers/conversation/message.php';
if (workflowMessage::hasActiveConversation($from, $bot['id'], 2)) {
  log::info("Conversación activa detectada", ['from' => $from, 'bot_id' => $bot['id']], $logMetada);

  // Obtener datos del chat
  $chatData = workflowMessage::getChatData($from, $bot['id']);
  $clientId = $chatData['client_id'];
  $saleId = $chatData['sale_id'];

  // Resolver mensaje (guardar y procesar conversación)
  if ($clientId) {
    $args = ['bot' => $bot, 'person' => $person, 'message' => $message, 'client_id' => $clientId, 'sale_id' => $saleId];
    workflowMessage::resolve($args);
  }

  return;
}

// ==================== 2) VALIDAR SI ES BIENVENIDA ====================
require_once APP_PATH . '/workflows/helpers/conversation/welcome.php';
$welcomeCheck = workflowWelcome::detect($bot, $message, $context);
if ($welcomeCheck['is_welcome']) {
  $source = $welcomeCheck['source']; // 'fb_ads' o 'normal'
  $productId = $welcomeCheck['product_id'];
  log::info("Mensaje de bienvenida detectado", [ 'from' => $from, 'product_id' => $productId, 'source' => $source ], $logMetada);
  $dataSale = [ 'person' => $person, 'bot' => $bot, 'product_id' => $productId, 'context' => $context ];
  $result = workflowWelcome::sendMessages($dataSale); // Enviar mensajes de bienvenida

  if (!$result['success']) { log::error("Error enviando mensajes", [ 'error' => $result['error'], 'product_id' => $productId ], $logMetada); return; }
  log::info("Mensajes de bienvenida enviados", $result, $logMetada);

  return;
}else{
  // No es bienvenida, procesar mensaje normal
  $args = ['bot' => $bot, 'person' => $person, 'message' => $message, 'client_id' => $clientId, 'sale_id' => $saleId];
  workflowMessage::resolve($args);
}
/*
// ==================== 2) VALIDAR SI TIENE PROCESO PENDIENTE ====================
$estado = workflowState::get($from);

// Estado: Welcome Confirmation
if ($estado === 'welcome_confirmation') {

  if (workflowValidation::isConfirmation($text)) {

    $stateData = workflowState::getData($from);
    $productId = $stateData['product_id'] ?? null;

    if ($productId) {
      // TODO: Obtener producto completo de DB
      // Por ahora solo confirmamos

      chatapi::send($from, "✅ ¡Perfecto! Procesando tu solicitud...");

      log::info("workflow:infoproduct - Confirmación de bienvenida", [
        'from' => $from,
        'product_id' => $productId
      ], ['module' => 'workflow', 'action' => 'welcome_confirmed']);

      workflowState::set($from, 'menu_principal');
    }

    return;
  }

  if (workflowValidation::isDenial($text)) {
    chatapi::send($from, "No hay problema. Escribe *MENU* si cambias de opinión. 😊");
    workflowState::reset($from);
    return;
  }
}

// ==================== COMANDOS GLOBALES ====================
if (workflowValidation::isValidOption(strtolower($text), ['menu', 'inicio', 'start'])) {
  workflowClient::register($from, $name, $bot['user_id']);
  workflowMessages::sendWelcome($from, $name);
  workflowState::set($from, 'menu_principal');
  return;
}

// ==================== FLUJO PRINCIPAL ====================
if ($estado === 'menu_principal' || $estado === 'inicio') {

  if ($text === '1') {
    $productos = db::table('products')
      ->where('bot_id', $bot['id'])
      ->where('context', 'infoproductws')
      ->get();

    workflowMessages::sendCatalog($from, $productos);
    workflowState::set($from, 'viendo_catalogo');
    return;
  }
}

// ==================== MENSAJE NO RECONOCIDO ====================
workflowMessages::sendHelp($from);
log::warning("workflow:infoproduct - Mensaje no reconocido", [
  'from' => $from,
  'text' => substr($text, 0, 100),
  'estado' => $estado
], ['module' => 'workflow']);*/