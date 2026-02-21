<?php
// Las rutas CRUD se auto-registran desde product.json

$router->group('/api/product', function($router) {
  // Clonar producto: POST /api/product/clone
  // Body: { product_id: X, target_user_id: Y }
  $router->post('/clone', function() {
    ogApp()->controller('product')->clone();
  })->middleware(['auth', 'json']);
});