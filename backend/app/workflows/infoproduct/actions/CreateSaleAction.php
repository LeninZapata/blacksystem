<?php

class CreateSaleAction {
  private static $logMeta = ['module' => 'CreateSaleAction', 'layer' => 'app/workflows'];

  static function create($dataSale) {
    $bot = $dataSale['bot'];
    $product = $dataSale['product'];
    $person = $dataSale['person'];
    $context = $dataSale['context'];

    $from  = $person['number'];
    $bsuid = $person['bsuid'] ?? null;
    $name  = $person['name'];
    $device = $person['platform'] ?? null;

    // OBTENER USER_ID DEL BOT
    $userId = $bot['user_id'] ?? null;

    if (!$userId) {
      ogLog::error("CreateSaleAction - user_id no encontrado en bot", [ 'bot_id' => $bot['id'] ?? null ], self::$logMeta);
    }

    // Detectar si es upsell
    $isUpsell = ($context['type'] ?? null) === 'upsell';
    $parentSaleId = $dataSale['parent_sale_id'] ?? null;

    // Solo verificar duplicados si NO es upsell y NO es bienvenida forzada
    $isForcedWelcome = ($context['force_welcome'] ?? 0) === 1;
    if (!$isUpsell && !$isForcedWelcome) {
      $dupQuery = ogDb::table('sales');
      if ($from) {
        $dupQuery = $dupQuery->where('number', $from);
      } elseif ($bsuid) {
        $dupQuery = $dupQuery->where('bsuid', $bsuid);
      }
      $existingSale = $dupQuery
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

    $countryCode  = $bot['country_code'] ?? 'EC';
    $countryInfo  = ogApp()->helper('country')::get($countryCode);
    $currencyCode = $countryInfo['currency'] ?? 'USD';

    // Detectar el país real del cliente:
    // - Si hay número → desde el prefijo telefónico
    // - Si solo hay bsuid → desde el prefijo ISO del bsuid (ej: "US.xxx" → "US")
    // - Fallback → país del bot
    if ($from) {
      $clientCountryCode = ogApp()->helper('country')::fromPhone($from) ?? $countryCode;
    } else {
      $clientCountryCode = ogApp()->helper('bsuid')::countryCode($bsuid, $countryCode);
    }

    // Cargar tasas de cambio; si el archivo no existe, generarlo en caliente como fallback
    $exchangeJsonPath = ogApp()->getPath('storage/json/system') . '/exchangerate.json';
    $exchangeData = ogApp()->helper('file')::getJson($exchangeJsonPath, function() use ($exchangeJsonPath) {
      $result = ogApp()->helper('http')::get(
        'https://v6.exchangerate-api.com/v6/8c5b0f3061aeb24c65a6456c/latest/USD'
      );
      if (!$result['success'] || ($result['data']['result'] ?? '') !== 'success') {
        ogLog::warning('CreateSaleAction - Exchange rate API no disponible, usando 1:1', [], self::$logMeta);
        return null;
      }
      $apiRates = $result['data']['conversion_rates'];
      $rates    = [];
      foreach (ogApp()->helper('country')::all() as $country => $info) {
        $currency   = $info['currency'];
        $usdToLocal = $apiRates[$currency] ?? null;
        if (!$usdToLocal) continue;
        $rates[$country] = [
          'currency'   => $currency,
          'usd_rate'   => $currency === 'USD' ? 1.0 : round(1 / $usdToLocal, 8),
          'local_rate' => $currency === 'USD' ? 1.0 : round($usdToLocal, 4),
        ];
      }
      $payload = ['updated_at' => date('Y-m-d H:i:s'), 'base' => 'USD', 'rates' => $rates];
      $dir = dirname($exchangeJsonPath);
      if (!is_dir($dir)) mkdir($dir, 0755, true);
      file_put_contents($exchangeJsonPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
      return $payload;
    });

    $localPrice = (float)($context['price'] ?? $product['price'] ?? 0);
    $usdRate    = (float)($exchangeData['rates'][$countryCode]['usd_rate'] ?? 1.0);
    $usdPrice   = round($localPrice * $usdRate, 2);


    ogApp()->loadHandler('client');
    $clientResult = ClientHandler::registerOrUpdate($from, $name, $clientCountryCode, $device, $userId, $bsuid);

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

    $saleData = [
      'user_id' => $userId,
      'sale_type' => 'main',
      'context' => 'whatsapp',
      'number' => $from,
      'bsuid' => $bsuid,
      'country_code' => $countryCode,
      'client_country_code' => $clientCountryCode,
      'product_name' => $product['name'],
      'product_id' => $dataSale['product_id'],
      'bot_id' => $bot['id'],
      'bot_type' => $botType,
      'bot_mode' => $botMode,
      'client_id' => $clientId,
      'amount'              => $usdPrice,
      'billed_amount'       => $usdPrice,
      'local_amount'        => $localPrice,
      'local_billed_amount' => $localPrice,
      'currency_code'       => $currencyCode,
      'process_status' => 'initiated',
      'source_app' => $context['source_app'] ?? null,
      'source_url' => $context['ad_data']['source_url'] ?? null,
      'device' => $device,
      'force_welcome' => $context['force_welcome'] ?? 0,
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
    // 2a. Flag explícito del normalizador
    if (($context['is_fb_ads'] ?? false) === true) {
      return 'ad';
    }

    // 2b. source_app viene de source_type del referral de Facebook
    if (!empty($context['source_app'])) {
      return 'ad';
    }

    // 2c. Source de Evolution API (conversionSource = 'FB_Ads')
    if (($context['source'] ?? null) === 'FB_Ads') {
      return 'ad';
    }

    // 2d. Contexto de tipo conversión (cualquier provider)
    if (($context['type'] ?? null) === 'conversion') {
      return 'ad';
    }

    // 2e. ctwa_clid presente → exclusivo de Click-to-WhatsApp Ads
    if (!empty($context['ad_data']['ctwa_clid'])) {
      return 'ad';
    }

    // 2f. referral de Facebook presente (source_url o raw con datos) pero sin
    //     confirmación de paid ad → post orgánico compartido, QR code, botón de page.
    //     Se clasifica como 'facebook' (no 'ad' ni 'organic').
    if (!empty($context['source_url']) || !empty($context['ad_data'])) {
      return 'facebook';
    }

    // Prioridad 3: Offer
    if (($context['is_offer'] ?? false) === true) {
      return 'offer';
    }

    // Default: Organic
    return 'organic';
  }
}