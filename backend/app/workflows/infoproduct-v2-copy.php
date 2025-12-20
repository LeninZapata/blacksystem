<?php
/**
 * ========================================
 * WORKFLOW: infoproduct-v2.php
 * ========================================
 * 
 * Este archivo recibe variables automáticamente desde webhookController.php
 * 
 * VARIABLES DISPONIBLES (pasadas automáticamente):
 * ------------------------------------------------
 * 
 * $webhook   → Objeto serviceHelper completo
 * $sender    → Array con datos del remitente (formato estándar)
 * $message   → Array con datos del mensaje (formato estándar)
 * $context   → Array con contexto FB Ads, etc (formato estándar)
 * $bot       → Array con configuración del bot
 * $standard  → Array completo estandarizado (contiene todo)
 * 
 * ESTRUCTURA DE $sender:
 * ----------------------
 * [
 *   'id' => '593998865723@s.whatsapp.net',
 *   'number' => '593998865723',
 *   'name' => 'Letii',
 *   'is_me' => false,
 *   'platform' => 'android'
 * ]
 * 
 * ESTRUCTURA DE $message:
 * -----------------------
 * [
 *   'id' => 'AC295C30EF0A76CD1C19123945357373',
 *   'type' => 'fb_ads_lead' | 'conversation' | 'image' | 'video',
 *   'text' => 'Deseo el material de *Grafismo Fonético*...',
 *   'timestamp' => 1759873705,
 *   'status' => 'DELIVERY_ACK',
 *   'media_url' => null | 'https://...',
 *   'raw' => [...] // Mensaje crudo original
 * ]
 * 
 * ESTRUCTURA DE $context:
 * -----------------------
 * [
 *   'type' => 'conversion' | 'normal' | 'reply',
 *   'source' => 'FB_Ads' | null,
 *   'source_app' => 'facebook' | null,
 *   'is_fb_ads' => true | false,
 *   'ad_data' => [
 *     'title' => 'Tu pequeñ@ se lo merece...',
 *     'body' => 'Les recomiendo este material...',
 *     'media_type' => 'VIDEO',
 *     'source_id' => '120233198588470388',
 *     'greeting_shown' => true,
 *     'greeting_body' => '¡Hola! ¿Cómo podemos ayudarte?',
 *     ...
 *   ] | null
 * ]
 */

// ==================== CARGAR HELPERS ====================
/*require_once APP_PATH . '/workflows/helpers/client/registration.php';
require_once APP_PATH . '/workflows/helpers/chatapi/messages.php';
require_once APP_PATH . '/workflows/helpers/chatapi/validation.php';
require_once APP_PATH . '/workflows/helpers/conversation/state.php';*/

// ==================== EXTRAER VARIABLES ====================
// Las variables $sender, $message, $context ya vienen desde webhookController
$from = $sender['number'];        // '593998865723'
$name = $sender['name'];          // 'Letii'
$text = $message['text'];         // 'Deseo el material...'
$messageType = $message['type'];  // 'fb_ads_lead'
$platform = $sender['platform'];  // 'android'

// ==================== DETECTAR ORIGEN ====================
$isFromFBAds = $context['is_fb_ads'];
$adData = $context['ad_data'];

// ==================== LOG OPCIONAL ====================
log::info("workflow:infoproduct - Mensaje recibido", [
  'from' => $from,
  'name' => $name,
  'text' => substr($text, 0, 50),
  'type' => $messageType,
  'fb_ads' => $isFromFBAds,
  'platform' => $platform
], ['module' => 'whatsapp_message_received']);

// ==================== FLUJO ESPECIAL: LEAD DE FB ADS ====================
/*if ($isFromFBAds) {
  
  // Registrar lead de Facebook
  workflowClient::register($from, $name, $bot['user_id']);
  
  // Log especial para analytics de FB Ads
  log::info("workflow:infoproduct - Lead de FB Ads", [
    'from' => $from,
    'name' => $name,
    'ad_title' => $adData['title'] ?? null,
    'ad_source_id' => $adData['source_id'] ?? null,
    'greeting_shown' => $adData['greeting_shown'] ?? false
  ], ['module' => 'workflow', 'category' => 'fb-ads']);
  
  // Mensaje de bienvenida especial para leads de FB
  chatapi::sendPresence($from, 'composing', 2000);
  
  $welcomeMsg = "¡Hola {$name}! 👋\n\n";
  $welcomeMsg .= "Gracias por tu interés en el *Kit Grafismo Fonético*.\n\n";
  $welcomeMsg .= "Vi que llegaste desde nuestro anuncio. ";
  $welcomeMsg .= "¿Ya viste que incluye 3 regalos secretos? 🎁\n\n";
  $welcomeMsg .= "💰 Todo por solo \$3\n\n";
  $welcomeMsg .= "¿Deseas proceder con tu pedido?\n";
  $welcomeMsg .= "Responde *SI* para confirmar.";
  
  chatapi::send($from, $welcomeMsg);
  
  // Guardar estado especial para leads
  workflowState::set($from, 'fb_ads_confirmation', [
    'ad_source_id' => $adData['source_id'] ?? null,
    'timestamp' => time()
  ]);
  
  return;
}

// ==================== COMANDOS GLOBALES ====================

// Comando: MENU
if (workflowValidation::isValidOption(strtolower($text), ['menu', 'inicio', 'start'])) {
  workflowClient::register($from, $name, $bot['user_id']);
  workflowMessages::sendWelcome($from, $name);
  workflowState::set($from, 'menu_principal');
  return;
}

// ==================== FLUJO PRINCIPAL ====================

$estado = workflowState::get($from);

// Estado: FB Ads Confirmation
if ($estado === 'fb_ads_confirmation') {
  
  if (workflowValidation::isConfirmation($text)) {
    
    // Crear pedido
    $producto = db::table('products')->where('name', 'Kit Grafismo Fonético')->first();
    
    if ($producto) {
      $pedidoId = db::table('orders')->insert([
        'client_number' => $from,
        'product_id' => $producto['id'],
        'status' => 'pending',
        'source' => 'fb_ads',
        'created_at' => date('Y-m-d H:i:s'),
        'ta' => time()
      ]);
      
      workflowMessages::sendOrderConfirmation($from, $producto);
      
      log::info("workflow:infoproduct - Pedido FB Ads creado", [
        'pedido_id' => $pedidoId,
        'client' => $from,
        'product_id' => $producto['id']
      ], ['module' => 'workflow', 'action' => 'order_created', 'source' => 'fb_ads']);
    }
    
    workflowState::reset($from);
    return;
  }
  
  if (workflowValidation::isDenial($text)) {
    chatapi::send($from, "No hay problema. Escribe *MENU* si cambias de opinión. 😊");
    workflowState::reset($from);
    return;
  }
}

// Estado: Menu Principal
if ($estado === 'menu_principal' || $estado === 'inicio') {
  
  if ($text === '1') {
    $productos = db::table('products')->where('status', 'active')->get();
    workflowMessages::sendCatalog($from, $productos);
    workflowState::set($from, 'viendo_catalogo');
    return;
  }
  
  // ... más opciones
}

// ==================== MENSAJE NO RECONOCIDO ====================
workflowMessages::sendHelp($from); */