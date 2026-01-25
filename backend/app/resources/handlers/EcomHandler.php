<?php
class EcomHandler {
  private static $logMeta = ['module' => 'EcomHandler', 'layer' => 'app/handler'];

  static function processSale($data) {
    try {
      // 1. Obtener user_id desde queryParams.user (default: 'admin')
      $username = $data['source']['queryParams']['user'] ?? 'admin';
      $user = ogDb::t('users')->where('user', $username)->first();
      
      if (!$user) {
        ogLog::error('processSale - Usuario no encontrado', ['username' => $username], self::$logMeta);
        return ['success' => false, 'error' => 'Usuario no encontrado: ' . $username];
      }

      $userId = $user['id'];

      // 2. Buscar producto por slug (context='ecom')
      $product = ogDb::t('products')
        ->where('slug', $data['product']['slug'])
        ->where('context', 'ecom')
        ->first();

      if (!$product) {
        ogLog::error('processSale - Producto no encontrado', [
          'slug' => $data['product']['slug']
        ], self::$logMeta);
        return ['success' => false, 'error' => 'Producto no encontrado: ' . $data['product']['slug']];
      }

      // Parsear config del producto
      $productConfig = isset($product['config']) && is_string($product['config'])
        ? json_decode($product['config'], true)
        : ($product['config'] ?? []);

      $giftsInProduct = $productConfig['gifts'] ?? [];

      // 3. Crear o actualizar cliente
      $clientResult = ogApp()->handler('client')::registerOrUpdate(
        $data['customer']['phone'],
        $data['customer']['fullName'] ?? ($data['customer']['firstName'] . ' ' . $data['customer']['lastName']),
        'EC', // country_code fijo
        null, //$data['metadata']['userAgent'] ?? null,
        $userId
      );

      if (!$clientResult['success']) {
        return $clientResult;
      }

      $clientId = $clientResult['client_id'];

      // 4. Crear venta principal
      $saleData = [
        'user_id' => $userId,
        'client_id' => $clientId,
        'product_id' => $product['id'],
        'product_name' => $product['name'],
        'number' => $data['customer']['phone'],
        'country_code' => 'EC',
        'sale_type' => 'main',
        'origin' => 'ecom',
        'context' => 'ecom',
        'amount' => $data['pricing']['salePrice'],
        'billed_amount' => $data['pricing']['salePrice'],
        'process_status' => 'pending',
        'device' => null, // $data['metadata']['userAgent'] ?? null,
        'source_url' => $data['source']['fullUrl'] ?? null,
        'status' => 1,
        'dc' => date('Y-m-d H:i:s'),
        'tc' => time()
      ];

      $saleId = ogDb::t('sales')->insert($saleData);

      if (!$saleId) {
        ogLog::error('processSale - Error al crear venta principal', ['data' => $saleData], self::$logMeta);
        return ['success' => false, 'error' => 'Error al crear venta principal'];
      }

      ogLog::success('processSale - Venta principal creada', [
        'sale_id' => $saleId,
        'client_id' => $clientId,
        'product_id' => $product['id'],
        'user_id' => $userId
      ], self::$logMeta);

      // 5. Procesar regalos
      $giftsCount = $data['pricing']['gifts'] ?? 0;
      $giftSales = [];

      if ($giftsCount > 0 && count($giftsInProduct) > 0) {
        $giftSales = self::processGifts(
          $giftsCount,
          $giftsInProduct,
          $saleId,
          $userId,
          $clientId,
          $data['customer']['phone']
        );
      }

      // 6. Actualizar cliente: incrementar compras
      ogApp()->handler('client')::incrementPurchase($clientId, $data['pricing']['salePrice']);

      return [
        'success' => true,
        'message' => 'Venta registrada exitosamente',
        'data' => [
          'sale_id' => $saleId,
          'client_id' => $clientId,
          'client_action' => $clientResult['action'],
          'user_id' => $userId,
          'username' => $username,
          'product' => [
            'id' => $product['id'],
            'name' => $product['name'],
            'slug' => $product['slug']
          ],
          'gifts_processed' => count($giftSales),
          'gift_sales' => $giftSales
        ]
      ];

    } catch (Exception $e) {
      ogLog::error('processSale - Error general', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
      ], self::$logMeta);
      
      return [
        'success' => false,
        'error' => 'Error al procesar venta',
        'details' => OG_IS_DEV ? $e->getMessage() : null
      ];
    }
  }

  // Procesar regalos según cantidad solicitada vs disponible
  private static function processGifts($giftsCount, $giftsInProduct, $parentSaleId, $userId, $clientId, $clientNumber) {
    $giftSales = [];
    $totalGiftsInProduct = count($giftsInProduct);

    ogLog::info('processGifts - Procesando regalos', [
      'gifts_solicitados' => $giftsCount,
      'gifts_en_producto' => $totalGiftsInProduct,
      'parent_sale_id' => $parentSaleId
    ], self::$logMeta);

    // Caso 1: Hay exactamente la misma cantidad de regalos
    if ($totalGiftsInProduct === $giftsCount) {
      // 1 de cada uno
      foreach ($giftsInProduct as $gift) {
        $giftProductId = $gift['product_id'] ?? null;
        if (!$giftProductId) continue;

        $giftSaleId = self::createGiftSale(
          $giftProductId,
          $parentSaleId,
          $userId,
          $clientId,
          $clientNumber,
          1
        );
        
        if ($giftSaleId) {
          $giftSales[] = [
            'gift_sale_id' => $giftSaleId,
            'product_id' => $giftProductId,
            'quantity' => 1
          ];
        }
      }
    }
    // Caso 2: Hay menos regalos en producto que los solicitados
    // Ejemplo: gifts=2, productos=[A] → 2 ventas de A
    else if ($totalGiftsInProduct < $giftsCount && $totalGiftsInProduct > 0) {
      $quantityPerGift = floor($giftsCount / $totalGiftsInProduct);
      $remainder = $giftsCount % $totalGiftsInProduct;

      foreach ($giftsInProduct as $index => $gift) {
        $giftProductId = $gift['product_id'] ?? null;
        if (!$giftProductId) continue;

        $quantity = $quantityPerGift;
        
        // Distribuir el resto en los primeros regalos
        if ($index < $remainder) {
          $quantity++;
        }

        $giftSaleId = self::createGiftSale(
          $giftProductId,
          $parentSaleId,
          $userId,
          $clientId,
          $clientNumber,
          $quantity
        );
        
        if ($giftSaleId) {
          $giftSales[] = [
            'gift_sale_id' => $giftSaleId,
            'product_id' => $giftProductId,
            'quantity' => $quantity
          ];
        }
      }
    }
    // Caso 3: Hay más regalos en producto que los solicitados
    // Ejemplo: gifts=1, productos=[A,B,C] → 1 venta de A
    else if ($totalGiftsInProduct > $giftsCount) {
      for ($i = 0; $i < $giftsCount; $i++) {
        $gift = $giftsInProduct[$i];
        $giftProductId = $gift['product_id'] ?? null;
        if (!$giftProductId) continue;

        $giftSaleId = self::createGiftSale(
          $giftProductId,
          $parentSaleId,
          $userId,
          $clientId,
          $clientNumber,
          1
        );
        
        if ($giftSaleId) {
          $giftSales[] = [
            'gift_sale_id' => $giftSaleId,
            'product_id' => $giftProductId,
            'quantity' => 1
          ];
        }
      }
    }

    ogLog::info('processGifts - Regalos procesados', [
      'parent_sale_id' => $parentSaleId,
      'total_gift_sales' => count($giftSales)
    ], self::$logMeta);

    return $giftSales;
  }

  // Crear venta de regalo
  private static function createGiftSale($giftProductId, $parentSaleId, $userId, $clientId, $clientNumber, $quantity = 1) {
    $giftProduct = ogDb::t('products')->find($giftProductId);
    
    if (!$giftProduct) {
      ogLog::warning('createGiftSale - Producto regalo no encontrado', [
        'product_id' => $giftProductId
      ], self::$logMeta);
      return null;
    }

    $giftSaleData = [
      'user_id' => $userId,
      'client_id' => $clientId,
      'product_id' => $giftProduct['id'],
      'product_name' => $giftProduct['name'] . ' (Regalo x' . $quantity . ')',
      'number' => $clientNumber,
      'country_code' => 'EC',
      'sale_type' => 'gift',
      'origin' => 'ecom',
      'context' => 'ecom',
      'parent_sale_id' => $parentSaleId,
      'amount' => 0.00,
      'billed_amount' => 0.00,
      'process_status' => 'confirmed',
      'status' => 1,
      'dc' => date('Y-m-d H:i:s'),
      'tc' => time()
    ];

    try {
      $giftSaleId = ogDb::t('sales')->insert($giftSaleData);
      
      ogLog::info('createGiftSale - Regalo registrado', [
        'gift_sale_id' => $giftSaleId,
        'parent_sale_id' => $parentSaleId,
        'product_id' => $giftProduct['id'],
        'quantity' => $quantity
      ], self::$logMeta);

      return $giftSaleId;
    } catch (Exception $e) {
      ogLog::error('createGiftSale - Error al crear venta regalo', [
        'error' => $e->getMessage(),
        'product_id' => $giftProductId
      ], self::$logMeta);
      return null;
    }
  }
}