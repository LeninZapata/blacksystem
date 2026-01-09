<?php

class CreateSaleAction {
  private static $logMeta = ['module' => 'CreateSaleAction', 'layer' => 'app/workflows'];

  static function create($dataSale) {
    $bot = $dataSale['bot'];
    $product = $dataSale['product'];
    $person = $dataSale['person'];
    $context = $dataSale['context'];

    $from = $person['number'];
    $name = $person['name'];
    $device = $person['platform'] ?? null;

    // OBTENER USER_ID DEL BOT
    $userId = $bot['user_id'] ?? null;

    if (!$userId) {
      ogLog::error("CreateSaleAction - user_id no encontrado en bot", [ 'bot_id' => $bot['id'] ?? null ], self::$logMeta);
    }

    // Detectar si es upsell
    $isUpsell = ($context['type'] ?? null) === 'upsell';
    $parentSaleId = $dataSale['parent_sale_id'] ?? null;

    // Solo verificar duplicados si NO es upsell
    if (!$isUpsell) {
      $existingSale = ogDb::table('sales')
        ->where('number', $from)
        ->where('bot_id', $bot['id'])
        ->where('product_id', $product['id'])
        ->whereNotIn('process_status', ['sale_confirmed', 'cancelled', 'refunded'])
        ->orderBy('id', 'DESC')
        ->first();

      if ($existingSale) {
        ogLog::warn("CreateSaleAction - Venta duplicada detectada", [ 'number' => $from, 'bot_id' => $bot['id'], 'product_id' => $product['id'], 'existing_sale_id' => $existingSale['id'], 'process_status' => $existingSale['process_status'], 'sale_id' => $existingSale['id'], 'error' => 'duplicate_sale' ], self::$logMeta);
        return [
          'success' => false,
          'error' => 'duplicate_sale',
          'sale_id' => $existingSale['id'],
          'status' => $existingSale['process_status']
        ];
      }
    }

    $countryCode = $bot['country_code'] ?? 'EC';
    ogApp()->loadHandler('client');
    $clientResult = ClientHandler::registerOrUpdate($from, $name, $countryCode, $device, $userId);

    if (!$clientResult['success']) {
      ogLog::error("CreateSaleAction - Error al crear o actualizar cliente", [ 'number' => $from, 'error' => $clientResult['error'] ?? null, 'details' => $clientResult['details'] ?? null ], self::$logMeta);
      return [
        'success' => false,
        'error' => 'client_creation_failed',
        'details' => $clientResult['error'] ?? null
      ];
    }

    $clientId = $clientResult['client_id'];
    $botType = $bot['type'];
    $botMode = $bot['mode'];

    // Detectar origen
    $origin = self::detectOrigin($context, $isUpsell, $parentSaleId);
    ogLog::info("CreateSaleAction - Origen de la venta detectado: {$origin}", [], self::$logMeta);

    $saleData = [
      'user_id' => $userId, // AGREGAR USER_ID
      'sale_type' => 'main',
      'context' => 'whatsapp',
      'number' => $from,
      'country_code' => $countryCode,
      'product_name' => $product['name'],
      'product_id' => $dataSale['product_id'],
      'bot_id' => $bot['id'],
      'bot_type' => $botType,
      'bot_mode' => $botMode,
      'client_id' => $clientId,
      'amount' => $context['price'] ?? $product['price'] ?? 0,
      'process_status' => 'initiated',
      'source_app' => $context['source_app'] ?? null,
      'source_url' => $context['ad_data']['source_url'] ?? null,
      'device' => $device,
      'force_welcome' => 0,
      'origin' => $origin,
      'parent_sale_id' => $parentSaleId
    ];

    ogApp()->loadHandler('sale');
    $saleResult = SaleHandler::create($saleData);

    if (!$saleResult['success']) {
      ogLog::error("CreateSaleAction - Error al crear la venta", [ 'number' => $from, 'bot_id' => $bot['id'], 'product_id' => $product['id'], 'error' => $saleResult['error'] ?? null, 'details' => $saleResult['details'] ?? null ], self::$logMeta);
      return [
        'success' => false,
        'error' => 'sale_creation_failed',
        'details' => $saleResult['error'] ?? null
      ];
    }

    return [
      'success' => true,
      'sale_id' => $saleResult['sale_id'],
      'client_id' => $clientId,
      'action' => 'created'
    ];
  }

  // Detectar origen de la venta
  private static function detectOrigin($context, $isUpsell, $parentSaleId) {
    // Prioridad 1: Upsell
    if ($isUpsell || $parentSaleId) {
      return 'upsell';
    }

    // Prioridad 2: Facebook Ads
    if (($context['is_fb_ads'] ?? false) === true) {
      return 'ad';
    }

    if (!empty($context['source_app'])) {
      return 'ad';
    }

    if (($context['source'] ?? null) === 'FB_Ads') {
      return 'ad';
    }

    if (($context['type'] ?? null) === 'conversion') {
      return 'ad';
    }

    // Prioridad 3: Offer
    if (($context['is_offer'] ?? false) === true) {
      return 'offer';
    }

    // Default: Organic
    return 'organic';
  }
}