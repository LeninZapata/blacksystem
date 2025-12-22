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
      ->whereNotIn('process_status', ['completed', 'cancelled', 'refunded'])
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

    $saleData = [
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
}