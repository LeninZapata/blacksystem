<?php
/**
 * hotmartSaleRegistrar
 *
 * Responsabilidad: registrar/actualizar ventas en la BD a partir
 * de un webhook de Hotmart confirmado.
 *
 * - Main purchase → actualiza venta pendiente existente a sale_confirmed
 * - Order bump / upsell → crea una nueva venta vinculada a la venta padre
 * - Sin venta padre → crea venta principal directa (venta directa sin bot previo)
 */
class hotmartSaleRegistrar {

  private static $logMeta = ['module' => 'hotmartSaleRegistrar', 'layer' => 'middle/integration/payment'];

  // ─────────────────────────────────────────────────────────────────────────
  // ENTRADA PRINCIPAL
  // ─────────────────────────────────────────────────────────────────────────

  /**
   * @param array       $eventInfo   Resultado de hotmartEventParser::extractEventInfo()
   * @param int|null    $botId
   * @param int|null    $productId   ID interno del sistema (no hotmart_product_id)
   * @param string|null $from        Número de WhatsApp
   * @param string      $module      'whatsapp' | 'direct'
   * @param array|null  $parentSale  Venta padre pre-detectada (puede venir del parser)
   */
  static function register($eventInfo, $botId, $productId, $from, $module, $parentSale = null, $saleId = null) {
    if ($eventInfo['is_main_purchase'] ?? true) {
      return self::updateMainPurchase($eventInfo, $botId, $productId, $from, $module, $saleId);
    }

    return self::createAdditionalSale($eventInfo, $botId, $from, $module, $parentSale);
  }

  // ─────────────────────────────────────────────────────────────────────────
  // VENTA PRINCIPAL
  // ─────────────────────────────────────────────────────────────────────────

  private static function updateMainPurchase($eventInfo, $botId, $productId, $from, $module, $saleId = null) {
    // Si viene sale_id desde el SCK, buscamos directamente — más preciso que buscar por teléfono
    $pendingSale = $saleId
      ? self::findPendingSaleById($saleId)
      : self::findPendingSaleByPhone($from, $productId, $botId);

    if (!$pendingSale) {
      ogLog::warning('hotmartSaleRegistrar - Venta pendiente no encontrada, creando venta principal directa', [
        'from' => $from, 'bot_id' => $botId, 'product_id' => $productId
      ], self::$logMeta);

      return self::createAdditionalSale($eventInfo, $botId, $from, $module, null, 'main');
    }

    $commission    = hotmartEventParser::getProducerCommission($eventInfo['raw_data'] ?? []);
    $originalPrice = hotmartEventParser::getOriginalPurchasePrice($eventInfo['raw_data'] ?? []);

    $updateData = [
      'transaction_id' => $eventInfo['transaction_id'],
      'payment_method' => 'hotmart',
      'payment_date'   => date('Y-m-d H:i:s'),
      'process_status' => 'sale_confirmed',
      'module'         => $module,
      'du'             => date('Y-m-d H:i:s'),
      'tu'             => time(),
    ];

    if ($commission    !== null) $updateData['amount']        = $commission;
    if ($originalPrice !== null) $updateData['billed_amount'] = $originalPrice;

    ogDb::table('sales')->where('id', (int)$pendingSale['id'])->update($updateData);

    ogLog::info('hotmartSaleRegistrar - Venta principal confirmada', [
      'sale_id'        => $pendingSale['id'],
      'transaction_id' => $eventInfo['transaction_id'],
      'amount'         => $updateData['amount'] ?? $pendingSale['amount'],
    ], self::$logMeta);

    $saleId   = (int)$pendingSale['id'];
    $clientId = $pendingSale['client_id'] ?? null;

    // Actualizar email del cliente para futura detección de upsell
    if ($clientId && !empty($eventInfo['buyer_email'])) {
      self::updateClientEmail($clientId, $eventInfo['buyer_email']);
    }

    self::postConfirmationActions($saleId, $botId, $from, $clientId, $eventInfo, 'main', (int)$pendingSale['product_id']);

    return ['success' => true, 'sale_id' => $saleId, 'client_id' => $clientId, 'action' => 'updated'];
  }

  // ─────────────────────────────────────────────────────────────────────────
  // VENTA ADICIONAL (ORDER BUMP / UPSELL / MAIN DIRECTA)
  // ─────────────────────────────────────────────────────────────────────────

  private static function createAdditionalSale($eventInfo, $botId, $from, $module, $parentSale = null, $forceSaleType = null) {
    $saleType = $forceSaleType ?? ($eventInfo['purchase_type'] ?? 'main');

    // Obtener producto actual por hotmart_product_id
    $product = hotmartEventParser::findProductByHotmartId($eventInfo['hotmart_product_id'] ?? null);
    if (!$product) {
      return ['success' => false, 'error' => 'Producto no encontrado (hotmart_product_id: ' . ($eventInfo['hotmart_product_id'] ?? 'null') . ')'];
    }

    // Obtener venta padre si no viene
    if (!$parentSale) {
      $parentSale = self::resolveParentSale($eventInfo, $saleType);
    }

    // Sin venta padre → crear datos mínimos con cliente nuevo/existente
    if (!$parentSale) {
      $clientId = self::getOrCreateClient($eventInfo, $from);
      if (!$clientId) return ['success' => false, 'error' => 'No se pudo obtener/crear cliente'];

      $parentSale = [
        'id'          => 0,
        'number'      => $from,
        'country_code' => $eventInfo['country_code'] ?? null,
        'bot_id'      => $botId,
        'bot_type'    => 'I',
        'bot_mode'    => 'C',
        'client_id'   => $clientId,
        'module'      => $module,
        'source_url'  => '',
        'device'      => '',
      ];
    }

    // Obtener user_id desde el bot
    $bot    = ogDb::table('bots')->where('id', (int)($parentSale['bot_id'] ?? $botId))->first();
    $userId = $bot['user_id'] ?? null;

    $commission    = hotmartEventParser::getProducerCommission($eventInfo['raw_data'] ?? []);
    $originalPrice = hotmartEventParser::getOriginalPurchasePrice($eventInfo['raw_data'] ?? []);
    $amount        = $commission ?? (float)($product['price'] ?? 0);

    $saleData = [
      'user_id'              => $userId,
      'sale_type'            => $saleType,
      'number'               => $parentSale['number'] ?? $from,
      'country_code'         => $parentSale['country_code'] ?? ($eventInfo['country_code'] ?? null),
      'product_name'         => $product['name'],
      'product_id'           => (int)$product['id'],
      'bot_id'               => (int)($parentSale['bot_id'] ?? $botId),
      'bot_type'             => $parentSale['bot_type'] ?? 'I',
      'bot_mode'             => $parentSale['bot_mode'] ?? 'C',
      'client_id'            => $parentSale['client_id'] ?? null,
      'module'               => $parentSale['module'] ?? $module,
      'amount'               => $amount,
      'billed_amount'        => $originalPrice ?? $amount,
      'currency_code'        => $parentSale['currency_code'] ?? 'USD',
      'process_status'       => 'sale_confirmed',
      'transaction_id'       => $eventInfo['transaction_id'],
      'parent_transaction_id' => $eventInfo['parent_transaction_id'] ?? null,
      'payment_date'         => date('Y-m-d H:i:s'),
      'payment_method'       => 'hotmart',
      'source_app'           => 'hotmart',
      'source_url'           => $parentSale['source_url'] ?? '',
      'device'               => $parentSale['device']     ?? '',
      'force_welcome'        => 0,
      'parent_sale_id'       => (int)($parentSale['id'] ?? 0),
      'is_downsell'          => !empty($eventInfo['custom_params']['is_downsell']) ? 1 : 0,
      'status'               => 1,
      'dc'                   => date('Y-m-d H:i:s'),
      'tc'                   => time(),
    ];

    $saleId = ogDb::table('sales')->insert($saleData);

    if (!$saleId) {
      return ['success' => false, 'error' => 'Error al insertar venta en BD'];
    }

    ogLog::info('hotmartSaleRegistrar - Venta adicional creada', [
      'sale_id'        => $saleId,
      'sale_type'      => $saleType,
      'product_id'     => $product['id'],
      'transaction_id' => $eventInfo['transaction_id'],
    ], self::$logMeta);

    $actualBotId = (int)($parentSale['bot_id'] ?? $botId);
    $actualFrom  = $parentSale['number'] ?? $from;
    $clientId    = $parentSale['client_id'] ?? null;

    if ($clientId && !empty($eventInfo['buyer_email'])) {
      self::updateClientEmail($clientId, $eventInfo['buyer_email']);
    }

    self::postConfirmationActions($saleId, $actualBotId, $actualFrom, $clientId, $eventInfo, $saleType, (int)$product['id']);

    return ['success' => true, 'sale_id' => $saleId, 'client_id' => $clientId, 'action' => 'created'];
  }

  // ─────────────────────────────────────────────────────────────────────────
  // RESOLUCIÓN DE VENTA PADRE
  // ─────────────────────────────────────────────────────────────────────────

  private static function resolveParentSale($eventInfo, $saleType) {
    // Order bump: buscar por parent_transaction_id con reintentos
    if ($saleType === 'order_bump') {
      $parentTx = $eventInfo['parent_transaction_id'] ?? null;
      if (!$parentTx) return null;

      for ($attempt = 1; $attempt <= 3; $attempt++) {
        $parentSale = ogDb::table('sales')
          ->where('transaction_id', $parentTx)
          ->orderBy('id', 'DESC')
          ->first();

        if ($parentSale) {
          ogLog::info('hotmartSaleRegistrar - Venta padre (order_bump) encontrada', [
            'parent_sale_id' => $parentSale['id'], 'attempt' => $attempt
          ], self::$logMeta);
          return $parentSale;
        }

        if ($attempt < 3) sleep(2);
      }

      ogLog::error('hotmartSaleRegistrar - Venta padre no encontrada para order_bump', [
        'parent_transaction_id' => $parentTx
      ], self::$logMeta);
      return null;
    }

    // Upsell: usar parent_sale del parser (detectado via email) si existe
    if ($saleType === 'upsell') {
      return $eventInfo['parent_sale'] ?? null;
    }

    return null;
  }

  // ─────────────────────────────────────────────────────────────────────────
  // ACCIONES POST-CONFIRMACIÓN
  // ─────────────────────────────────────────────────────────────────────────

  /**
   * Acciones que se ejecutan después de confirmar/crear la venta:
   * 1. Mensaje de sistema en el chat (sale_confirmed)
   * 2. Registro de followups
   * 3. Rebuild del JSON del chat
   */
  private static function postConfirmationActions($saleId, $botId, $from, $clientId, $eventInfo, $saleType, $productId) {
    self::addConfirmedChatMessage($saleId, $botId, $from, $clientId, $eventInfo, $saleType, $productId);
    self::registerFollowups($saleId, $botId, $productId, $clientId, $from);
    self::rebuildChat($from, $botId);
  }

  /**
   * Agrega mensaje de sistema 'sale_confirmed' al chat JSON.
   * El bot lee este metadata en la siguiente interacción del cliente
   * para saber que el pago ya fue confirmado.
   */
  private static function addConfirmedChatMessage($saleId, $botId, $from, $clientId, $eventInfo, $saleType, $productId) {
    if (!$botId || !$from) return;

    try {
      ogApp()->loadHandler('chat');

      // Obtener número del bot para ChatHandler::register
      $bot       = ogDb::table('bots')->where('id', (int)$botId)->first();
      $botNumber = $bot['number'] ?? '';

      $amount = hotmartEventParser::getOriginalPurchasePrice($eventInfo['raw_data'] ?? [])
             ?? $eventInfo['amount']
             ?? 0;

      // Para upsell/order_bump: registrar start_sale en DB con contexto completo del producto.
      // ChatHandler::register escribe a la tabla chats (no al JSON), así rebuildFromDB
      // lo recoge correctamente sin que sea sobreescrito.
      if ($saleType !== 'main') {
        $product = ogDb::table('products')
          ->where('id', (int)$productId)
          ->where('status', 1)
          ->first();

        if ($product) {
          $config = is_string($product['config']) ? json_decode($product['config'], true) : ($product['config'] ?? []);

          ChatHandler::register(
            $botId,
            $botNumber,
            $clientId,
            $from,
            'Nueva venta vía Hotmart: ' . $product['name'],
            'S',
            'text',
            [
              'action'          => 'start_sale',
              'sale_id'         => $saleId,
              'product_id'      => $productId,
              'product_name'    => $product['name'],
              'price'           => $product['price'],
              'description'     => $product['description'] ?? '',
              'instructions'    => $config['prompt'] ?? '',
              'origin'          => $saleType,
              'msgs_total'      => 0,
              'msgs_sent'       => 0,
              'msgs_failed'     => 0,
              'msgs_failed_idx' => [],
              'duration_s'      => 0,
            ],
            $saleId,
            true // skipUnreadCount — es mensaje de sistema
          );
        }
      }

      ChatHandler::register(
        $botId,
        $botNumber,
        $clientId,
        $from,
        'Pago confirmado via Hotmart',
        'S',
        'text',
        [
          'action'         => 'sale_confirmed',
          'sale_id'        => $saleId,
          'product_id'     => $productId,
          'amount'         => $amount,
          'payment_method' => 'hotmart',
          'transaction_id' => $eventInfo['transaction_id'],
          'sale_type'      => $saleType,
          'buyer_name'     => $eventInfo['buyer_name'] ?? null,
        ],
        $saleId,
        true // skipUnreadCount — es mensaje de sistema
      );

    } catch (Exception $e) {
      ogLog::warning('hotmartSaleRegistrar - No se pudo guardar mensaje de sistema', [
        'sale_id' => $saleId, 'error' => $e->getMessage()
      ], self::$logMeta);
    }
  }

  /**
   * Registra los followups del producto para esta venta.
   * Carga el archivo follow_{productId}.json del bot.
   */
  private static function registerFollowups($saleId, $botId, $productId, $clientId, $from) {
    if (!$botId || !$productId) return;

    try {
      $followPath = ogApp()->getPath('storage/json/bots/infoproduct/' . $botId . '/messages/follow_' . $productId . '.json');

      if (!file_exists($followPath)) {
        ogLog::debug('hotmartSaleRegistrar - Sin archivo de followups', ['path' => $followPath], self::$logMeta);
        return;
      }

      $followConfig = json_decode(file_get_contents($followPath), true);
      $followups    = $followConfig['followups'] ?? [];
      if (empty($followups)) return;

      ogApp()->loadHandler('followup');

      $bot      = ogDb::table('bots')->where('id', (int)$botId)->first();
      $timezone = $bot['timezone'] ?? 'America/Guayaquil';
      $sale     = ogDb::table('sales')->where('id', (int)$saleId)->first();

      FollowupHandler::registerFromSale([
        'sale_id'    => $saleId,
        'product_id' => $productId,
        'client_id'  => $clientId,
        'bot_id'     => $botId,
        'number'     => $from,
        'bsuid'      => $sale['bsuid'] ?? null,
      ], $followups, $timezone, $bot ?? null);

      ogLog::info('hotmartSaleRegistrar - Followups registrados', [
        'sale_id' => $saleId, 'count' => count($followups)
      ], self::$logMeta);

    } catch (Exception $e) {
      ogLog::warning('hotmartSaleRegistrar - Error registrando followups', [
        'sale_id' => $saleId, 'error' => $e->getMessage()
      ], self::$logMeta);
    }
  }

  private static function rebuildChat($from, $botId) {
    if (!$from || !$botId) return;
    try {
      ogApp()->loadHandler('chat');
      ChatHandler::rebuildFromDB($from, $botId);
    } catch (Exception $e) {
      ogLog::warning('hotmartSaleRegistrar - Error rebuilding chat', [
        'from' => $from, 'error' => $e->getMessage()
      ], self::$logMeta);
    }
  }

  // ─────────────────────────────────────────────────────────────────────────
  // HELPERS DE BD
  // ─────────────────────────────────────────────────────────────────────────

  /**
   * Busca venta pendiente (process_status = initiated) por número.
   * Filtra opcionalmente por product_id y bot_id para mayor precisión.
   */
  static function findPendingSaleById($saleId) {
    return ogDb::table('sales')
      ->where('id', (int)$saleId)
      ->where('process_status', 'initiated')
      ->where('status', 1)
      ->first();
  }

  static function findPendingSaleByPhone($number, $productId = null, $botId = null) {
    if (empty($number)) return null;

    $query = ogDb::table('sales')
      ->where('number', $number)
      ->where('process_status', 'initiated')
      ->where('status', 1)
      ->orderBy('dc', 'DESC');

    if ($productId) $query->where('product_id', (int)$productId);
    if ($botId)     $query->where('bot_id',     (int)$botId);

    return $query->first();
  }

  /**
   * Obtiene o crea un cliente a partir de los datos del comprador.
   * Búsqueda por email primero, luego por número de teléfono.
   */
  static function getOrCreateClient($eventInfo, $from) {
    $email = $eventInfo['buyer_email'] ?? null;
    $name  = trim(($eventInfo['buyer_first_name'] ?? '') . ' ' . ($eventInfo['buyer_last_name'] ?? ''))
           ?: ($eventInfo['buyer_name'] ?? 'Cliente Hotmart');

    if ($email) {
      $existing = ogDb::table('clients')->where('email', $email)->first();
      if ($existing) return (int)$existing['id'];
    }

    if ($from) {
      $existing = ogDb::table('clients')->where('phone', $from)->first();
      if ($existing) {
        if ($email && empty($existing['email'])) {
          ogDb::table('clients')->where('id', (int)$existing['id'])->update([
            'email' => $email, 'du' => date('Y-m-d H:i:s'), 'tu' => time()
          ]);
        }
        return (int)$existing['id'];
      }
    }

    $clientId = ogDb::table('clients')->insert([
      'name'         => $name,
      'email'        => $email,
      'phone'        => $from,
      'country_code' => $eventInfo['country_code'] ?? null,
      'status'       => 1,
      'dc'           => date('Y-m-d H:i:s'),
      'tc'           => time(),
    ]);

    if ($clientId) {
      ogLog::info('hotmartSaleRegistrar - Cliente creado', [
        'client_id' => $clientId, 'email' => $email
      ], self::$logMeta);
      return (int)$clientId;
    }

    return null;
  }

  private static function updateClientEmail($clientId, $email) {
    try {
      ogDb::table('clients')->where('id', (int)$clientId)->update([
        'email' => $email, 'du' => date('Y-m-d H:i:s'), 'tu' => time()
      ]);
    } catch (Exception $e) {
      ogLog::warning('hotmartSaleRegistrar - No se pudo actualizar email del cliente', [
        'client_id' => $clientId, 'error' => $e->getMessage()
      ], self::$logMeta);
    }
  }
}
