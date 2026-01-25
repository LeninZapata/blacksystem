<?php
// Webhook de WhatsApp (Evolution API)
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