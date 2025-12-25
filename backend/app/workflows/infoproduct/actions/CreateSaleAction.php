<?php

class CreateSaleAction {

  static function create($dataSale) {
    $bot = $dataSale['bot'];
    $product = $dataSale['product'];
    $person = $dataSale['person'];
    $context = $dataSale['context'];

    $from = $person['number'];
    $name = $person['name'];
    $device = $person['platform'] ?? null;

    $existingSale = db::table('sales')
      ->where('number', $from)
      ->where('bot_id', $bot['id'])
      ->where('product_id', $product['id'])
      ->whereNotIn('process_status', ['sale_confirmed', 'cancelled', 'refunded'])
      ->orderBy('id', 'DESC')
      ->first();

    if ($existingSale) {
      return [
        'success' => false,
        'error' => 'duplicate_sale',
        'sale_id' => $existingSale['id'],
        'status' => $existingSale['process_status']
      ];
    }

    $countryCode = $bot['country_code'] ?? 'EC';
    $clientResult = ClientHandlers::registerOrUpdate($from, $name, $countryCode, $device);

    if (!$clientResult['success']) {
      return [
        'success' => false,
        'error' => 'client_creation_failed',
        'details' => $clientResult['error'] ?? null
      ];
    }

    $clientId = $clientResult['client_id'];
    $botType = $bot['type'];
    $botMode = $bot['mode'];

    // Detectar origen de la venta
    $origin = self::detectOrigin($context, $dataSale);

    $saleData = [
      'sale_type' => 'main',
      'origin' => $origin,
      'context' => 'whatsapp',
      'number' => $from,
      'country_code' => $countryCode,
      'product_name' => $product['name'],
      'product_id' => $dataSale['product_id'],
      'bot_id' => $bot['id'],
      'bot_type' => $botType,
      'bot_mode' => $botMode,
      'client_id' => $clientId,
      'amount' => $product['price'] ?? 0,
      'process_status' => 'initiated',
      'source_app' => $context['source_app'] ?? null,
      'source_url' => $context['ad_data']['source_url'] ?? null,
      'device' => $device,
      'force_welcome' => 0
    ];

    $saleResult = SaleHandlers::create($saleData);

    if (!$saleResult['success']) {
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
  private static function detectOrigin($context, $dataSale) {
    $origin = 'organic'; // Default

    // Prioridad 1: Si viene de un anuncio de Facebook (verificar is_fb_ads)
    if (isset($context['is_fb_ads']) && $context['is_fb_ads'] === true) {
      $origin = 'ad';
    }
    // Prioridad 2: Si tiene source_app (cualquier anuncio)
    elseif (!empty($context['source_app'])) {
      $origin = 'ad';
    }
    // Prioridad 3: Si tiene source === 'FB_Ads'
    elseif (isset($context['source']) && $context['source'] === 'FB_Ads') {
      $origin = 'ad';
    }
    // Prioridad 4: Si el context type es 'conversion'
    elseif (isset($context['type']) && $context['type'] === 'conversion') {
      $origin = 'ad';
    }
    // Prioridad 5: Si viene de un upsell/downsell
    elseif (isset($dataSale['parent_sale_id']) && $dataSale['parent_sale_id'] > 0) {
      $origin = 'upsell';
    }
    // Prioridad 6: Si es una oferta posterior
    elseif (isset($dataSale['is_offer']) && $dataSale['is_offer'] === true) {
      $origin = 'offer';
    }

    log::info("CreateSaleAction - Origen de venta detectado: {$origin}", [
      'product_id' => $dataSale['product_id'] ?? null,
      'number' => $dataSale['person']['number'] ?? null
    ], ['module' => 'create_sale']);

    return $origin;
  }
}