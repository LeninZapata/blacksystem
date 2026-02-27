<?php
// Verificación de webhook de Meta (WhatsApp Cloud API)
// Meta hace un GET con hub_mode=subscribe, hub_verify_token y hub_challenge para confirmar la URL
$router->get('/api/webhook/whatsapp', function() {
  $mode  = $_GET['hub_mode'] ?? null;
  $token = $_GET['hub_verify_token'] ?? null;
  $challenge = $_GET['hub_challenge'] ?? null;

  if ($mode === 'subscribe' && $token === WEBHOOK_META_VERIFY_TOKEN) {
    http_response_code(200);
    header('Content-Type: text/plain');
    echo $challenge;
    exit;
  }

  http_response_code(403);
  echo 'Forbidden';
  exit;
});

// Webhook de WhatsApp
$router->post('/api/webhook/whatsapp', 'webhook@whatsapp')->middleware(['json']);

// Webhooks futuros (Telegram, etc)
// $router->post('/api/webhook/telegram', 'webhookController@telegram')->middleware(['json']);

// Webhook de E-commerce
$router->post('/api/webhook/ecom-sale', function() {
  $data = ogRequest::data();
  
  if (!isset($data['customer']['phone']) || !isset($data['product']['slug'])) {
    ogResponse::json([
      'success' => false,
      'error' => 'customer.phone y product.slug son obligatorios'
    ], 400);
  }

  try {
    $result = ogApp()->handler('ecom')::processSale($data);
    ogResponse::json($result);
  } catch (Exception $e) {
    ogLog::error('webhook/ecom-sale - Error al procesar', [
      'error' => $e->getMessage(),
      'data' => $data
    ], ['module' => 'webhook']);
    
    ogResponse::serverError('Error al procesar venta', OG_IS_DEV ? $e->getMessage() : null);
  }
})->middleware(['json', 'throttle:100,1']);