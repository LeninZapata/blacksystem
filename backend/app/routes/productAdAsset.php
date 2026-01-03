<?php
// Las rutas CRUD se auto-registran desde product-ad-asset.json en ogApi.php
// Este archivo es para rutas personalizadas adicionales

// Ejemplo de ruta personalizada (comentado):
/*
$router->group('/api/product-ad-asset', function($router) {
  
  // Obtener todos los activos de un producto específico
  $router->get('/by-product/{product_id}', function($productId) {
    $assets = ogDb::table('product_ad_assets')
      ->where('product_id', $productId)
      ->where('is_active', 1)
      ->get();
    
    ogResponse::success($assets);
  })->middleware(['auth']);

});
*/