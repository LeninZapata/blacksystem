<?php
/**
 * Rutas del flujo de checkout (Hotmart)
 *
 * GET /api/checkout/context
 *   Consultado por el quiz HTML en metodoefectivo.cc.
 *   Recibe el número de WhatsApp y el slug del producto,
 *   retorna bot_id, product_id, checkout_url y type para
 *   construir el SCK al redirigir al checkout de Hotmart.
 *
 *   Params: number (requerido), slug (opcional)
 *   Response: { success, found, number, bot_id, product_id, checkout_url, type }
 */
$router->get('/api/checkout/context', function() {
  $number = ogRequest::query('number');
  $slug   = ogRequest::query('slug');

  if (!$number) {
    ogResponse::json(['success' => false, 'error' => 'number es requerido'], 400);
  }

  ogApp()->loadHandler('checkout');

  $result = CheckoutHandler::getContext($number, $slug);
  ogResponse::json($result);
})->middleware(['throttle:30,1']);
