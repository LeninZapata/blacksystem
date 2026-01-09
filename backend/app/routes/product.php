<?php
// Las rutas CRUD se auto-registran desde product.json

$router->group('/api/product', function($router) {
  // Clonar producto: GET /api/product/{id}/clone?user_id=X&bot_id=Y
  $router->get('/{id}/clone', function($id) {
    $userId = ogRequest::query('user_id');
    $botId = ogRequest::query('bot_id');

    if (!$userId || !$botId) {
      ogResponse::json(['success' => false, 'error' => 'user_id y bot_id son obligatorios'], 400);
    }

    ogApp()->controller('product')->clone($id);
  });
});