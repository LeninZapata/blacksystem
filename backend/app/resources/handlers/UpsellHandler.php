<?php

class UpsellHandler {
  private static $logMeta = ['module' => 'UpsellHandler', 'layer' => 'app/handlers'];
  // Procesar upsell después de venta confirmada
  static function processAfterSale($saleData, $botTimezone) {
    $saleId = $saleData['sale_id'];
    $productId = $saleData['product_id'];
    $clientId = $saleData['client_id'];
    $botId = $saleData['bot_id'];
    $number = $saleData['number'];
    $origin = $saleData['origin'] ?? 'organic';

    ogLog::info("processAfterSale - Iniciando", [ 'sale_id' => $saleId, 'product_id' => $productId, 'origin' => $origin ], self::$logMeta);

    // PASO 1: Cancelar followups pendientes de esta venta
    ogApp()->handler('followup')::cancelBySale($saleId);

    // PASO 2: Determinar el producto base (root) de la cadena
    $rootProductId = self::getRootProductId($saleId, $productId, $origin);

    ogLog::info("processAfterSale - Producto root determinado", [
      'root_product_id' => $rootProductId,
      'current_product_id' => $productId
    ], ['module' => 'upsell']);

    // PASO 3: Buscar upsells disponibles del PRODUCTO BASE (no del que acaba de comprar)
    ogApp()->loadHandler('product');
    $upsells = ProductHandler::getUpsellFile($rootProductId);
    if (empty($upsells)) {
      ogLog::info("processAfterSale - No hay upsells configurados para el producto", [ 'root_product_id' => $rootProductId ], self::$logMeta);
      return ['success' => true, 'upsell_registered' => false, 'reason' => 'no_upsells_configured'];
    }

    ogLog::info("processAfterSale - Upsells encontrados", [ 'root_product_id' => $rootProductId, 'total_upsells' => count($upsells) ], self::$logMeta);

    // PASO 4: Obtener el parent_sale_id correcto (siempre el root)
    $rootSaleId = self::getRootSaleId($saleId, $origin);

    ogLog::info("processAfterSale - Sale root determinado", [ 'root_sale_id' => $rootSaleId, 'current_sale_id' => $saleId ], self::$logMeta);

    // PASO 5: Buscar primer upsell no ejecutado del producto base
    $upsellToExecute = null;

    foreach ($upsells as $upsell) {
      $upsellProductId = $upsell['product_id'] ?? null;
      if (!$upsellProductId) continue;

      // Verificar si ya se ejecutó este upsell en la CADENA del root
      // Buscar ventas que tengan parent_sale_id = rootSaleId
      $existingSaleByParent = ogDb::table(DB_TABLES['sales'])
        ->where('product_id', $upsellProductId)
        ->where('client_id', $clientId)
        ->where('bot_id', $botId)
        ->where('parent_sale_id', $rootSaleId)
        ->first();

      // O buscar si el rootSaleId mismo es de este producto
      $existingSaleByRoot = ogDb::table(DB_TABLES['sales'])
        ->where('product_id', $upsellProductId)
        ->where('client_id', $clientId)
        ->where('bot_id', $botId)
        ->where('id', $rootSaleId)
        ->first();

      if (!$existingSaleByParent && !$existingSaleByRoot) {
        $upsellToExecute = $upsell;
        break;
      }
    }

    if (!$upsellToExecute) {
      ogLog::info("processAfterSale - Todos los upsells ya fueron ejecutados", [ 'root_product_id' => $rootProductId, 'root_sale_id' => $rootSaleId ], self::$logMeta);
      return ['success' => true, 'upsell_registered' => false, 'reason' => 'all_upsells_already_executed'];
    }

    ogLog::info("processAfterSale - Upsell disponible encontrado", [ 'upsell_product_id' => $upsellToExecute['product_id'], 'time_type' => $upsellToExecute['time_type'] ?? 'minuto', 'time_value' => $upsellToExecute['time_value'] ?? 5 ], self::$logMeta);

    // PASO 6: Calcular fecha futura del followup especial
    $futureDate = self::calculateUpsellFutureDate($upsellToExecute, $botTimezone);

    // PASO 7: Registrar followup especial con special='upsell'
    // Guardar metadata en instruction para tracking
    $followupData = [
      'user_id' => ogApp()->handler('chat')::getUserId(),
      'sale_id' => $rootSaleId, // Siempre apuntar al root
      'product_id' => $upsellToExecute['product_id'],
      'client_id' => $clientId,
      'bot_id' => $botId,
      'context' => 'whatsapp',
      'number' => $number,
      'name' => 'upsell-trigger',
      'instruction' => json_encode([
        'root_sale_id' => $rootSaleId,
        'root_product_id' => $rootProductId,
        'last_sale_id' => $saleId,
        'price_upsell' => $upsellToExecute['price'] ?? null
      ]),
      'text' => 'Trigger de upsell',
      'source_url' => null,
      'future_date' => $futureDate,
      'processed' => 0,
      'status' => 1,
      'special' => 'upsell',
      'dc' => date('Y-m-d H:i:s'),
      'tc' => time()
    ];

    ogDb::table(DB_TABLES['followups'])->insert($followupData);

    ogLog::info("processAfterSale - Followup especial de upsell registrado", [ 'root_sale_id' => $rootSaleId, 'root_product_id' => $rootProductId, 'upsell_product_id' => $upsellToExecute['product_id'], 'future_date' => $futureDate ], self::$logMeta);

    return [
      'success' => true,
      'upsell_registered' => true,
      'upsell_product_id' => $upsellToExecute['product_id'],
      'root_product_id' => $rootProductId,
      'root_sale_id' => $rootSaleId,
      'future_date' => $futureDate
    ];
  }

  // Obtener el producto raíz de la cadena
  private static function getRootProductId($saleId, $currentProductId, $origin) {
    // Si es venta orgánica/ad/offer, este ES el producto base
    if ($origin !== 'upsell') {
      return $currentProductId;
    }

    // Si es upsell, buscar la venta root
    $rootSaleId = self::getRootSaleId($saleId, $origin);
    $rootSale = ogDb::table(DB_TABLES['sales'])->find($rootSaleId);

    return $rootSale['product_id'] ?? $currentProductId;
  }

  // Obtener el sale_id raíz de la cadena
  private static function getRootSaleId($saleId, $origin) {
    // Si es venta orgánica/ad/offer, este ES el sale_id base
    if ($origin !== 'upsell') {
      return $saleId;
    }

    // Si es upsell, buscar recursivamente el parent hasta llegar al root
    $currentSale = ogDb::table(DB_TABLES['sales'])->find($saleId);
    $parentSaleId = $currentSale['parent_sale_id'] ?? null;

    // Si no tiene parent, este es el root
    if (!$parentSaleId) {
      return $saleId;
    }

    // Recursivamente buscar el root
    $maxDepth = 10; // Protección anti-loop
    $depth = 0;

    while ($parentSaleId && $depth < $maxDepth) {
      $parentSale = ogDb::table(DB_TABLES['sales'])->find($parentSaleId);

      if (!$parentSale || !$parentSale['parent_sale_id']) {
        return $parentSaleId;
      }

      $parentSaleId = $parentSale['parent_sale_id'];
      $depth++;
    }

    return $parentSaleId ?? $saleId;
  }

  // Calcular fecha futura para followup de upsell
  private static function calculateUpsellFutureDate($upsell, $botTimezone) {
    $timeType = $upsell['time_type'] ?? 'minuto';
    $timeValue = (int)($upsell['time_value'] ?? 5);

    $botTz = new DateTimeZone($botTimezone);
    $baseDate = new DateTime('now', $botTz);

    switch ($timeType) {
      case 'minuto':
      case 'minutes':
        $baseDate->modify("+{$timeValue} minutes");
        break;
      case 'hora':
      case 'hours':
        $baseDate->modify("+{$timeValue} hours");
        break;
      case 'dia':
      case 'days':
        $baseDate->modify("+{$timeValue} days");
        break;
    }

    return $baseDate->format('Y-m-d H:i:s');
  }

  // Ejecutar upsell (cuando el CRON detecta followup con special='upsell')
  static function executeUpsell($followup, $botData) {

    $upsellProductId = $followup['product_id'];
    $clientId = $followup['client_id'];
    $botId = $followup['bot_id'];
    $number = $followup['number'];

    ogLog::info("executeUpsell - Iniciando", [ 'followup_id' => $followup['id'], 'upsell_product_id' => $upsellProductId, 'number' => $number ],  self::$logMeta);

    // Obtener metadata del followup
    $metadata = json_decode($followup['instruction'], true) ?? [];
    $rootSaleId = $metadata['root_sale_id'] ?? $followup['sale_id'];

    // Obtener datos del producto upsell
    ogApp()->loadHandler('product');
    $productData = ProductHandler::getProductFile($upsellProductId);
    if (!$productData) {
      ogLog::error("executeUpsell - Producto upsell no encontrado", ['upsell_product_id' => $upsellProductId], self::$logMeta);
      return ['success' => false, 'error' => 'upsell_product_not_found'];
    }

    ogLog::info("executeUpsell - Producto upsell cargado", [ 'product_id' => $upsellProductId, 'product_name' => $productData['name'] ?? 'N/A' ], self::$logMeta);

    // ===========================================
    // PASO 1: ENVIAR MENSAJES DE WELCOME PRIMERO
    // ===========================================
    $welcomeMessages = ProductHandler::getMessagesFile('welcome_upsell', $upsellProductId);
    $chatapi = ogApp()->service('chatApi');
    $messageSentSuccessfully = false;

    if (!empty($welcomeMessages)) {
      ogLog::info("executeUpsell - Enviando welcome upsell", [ 'product_id' => $upsellProductId, 'total_messages' => count($welcomeMessages) ], self::$logMeta);

      foreach ($welcomeMessages as $index => $msg) {
        $delay = isset($msg['delay']) ? (int)$msg['delay'] : 3;
        $text = $msg['message'] ?? '';
        $url = !empty($msg['url']) && $msg['type'] != 'text' ? $msg['url'] : '';

        if ($index > 0 && $delay > 0) {
          sleep($delay);
        }

        // Enviar mensaje y verificar resultado
        $sendResult = $chatapi::send($number, $text, $url);
        
        if (!$sendResult['success']) {
          ogLog::error("executeUpsell - Error enviando mensaje welcome upsell", [
            'followup_id' => $followup['id'],
            'message_index' => $index,
            'error' => $sendResult['error'] ?? 'unknown'
          ], self::$logMeta);
          
          // Si falla el envío, retornar error SIN crear venta
          return ['success' => false, 'error' => 'failed_to_send_welcome_message'];
        }
        
        $messageSentSuccessfully = true;
      }
    } else {
      ogLog::warning("executeUpsell - No hay mensajes de welcome upsell para enviar", [ 'product_id' => $upsellProductId ], self::$logMeta);
      // Si no hay mensajes, consideramos que no se puede proceder
      return ['success' => false, 'error' => 'no_welcome_messages_configured'];
    }

    // ===========================================
    // PASO 2: SI EL ENVÍO FUE EXITOSO, CREAR VENTA
    // ===========================================
    if (!$messageSentSuccessfully) {
      ogLog::error("executeUpsell - No se envió ningún mensaje exitosamente", ['followup_id' => $followup['id']], self::$logMeta);
      return ['success' => false, 'error' => 'no_message_sent'];
    }

    require_once ogApp()->getPath() . '/workflows/infoproduct/actions/CreateSaleAction.php';

    // Crear nueva venta (origen='upsell', parent_sale_id = root)
    $newSaleData = [
      'bot' => $botData,
      'person' => ['number' => $number, 'name' => ''],
      'product' => $productData,
      'product_id' => $upsellProductId,
      'context' => [
        'type' => 'upsell',
        'price' => $metadata['price_upsell'] ?? $productData['price'] ?? 0,
        'source' => 'system',
        'is_fb_ads' => false
      ],
      'parent_sale_id' => $rootSaleId
    ];

    $saleResult = CreateSaleAction::create($newSaleData);

    if (!$saleResult['success']) {
      ogLog::error("executeUpsell - Error creando venta upsell (mensaje ya fue enviado)", [ 'product_id' => $upsellProductId, 'error' => $saleResult['error'] ?? 'unknown' ], self::$logMeta);
      return ['success' => false, 'error' => 'failed_to_create_upsell_sale'];
    }

    $newSaleId = $saleResult['sale_id'];
    $newClientId = $saleResult['client_id'];
    ogLog::info("executeUpsell - Venta upsell creada exitosamente", [ 'new_sale_id' => $newSaleId, 'parent_sale_id' => $rootSaleId, 'product_id' => $upsellProductId ], self::$logMeta);

    // ===========================================
    // PASO 3: REGISTRAR EN CHAT
    // ===========================================
    ogApp()->loadHandler('chat');
    ChatHandler::register(
      $botId,
      $botData['number'],
      $newClientId,
      $number,
      "Nueva venta iniciada: {$productData['name']}",
      'S',
      'text',
      [
        'action' => 'start_sale',
        'sale_id' => $newSaleId,
        'product_id' => $upsellProductId,
        'product_name' => $productData['name'],
        'price' => $metadata['price_upsell'] ?? $productData['price'] ?? 0,
        'description' => $productData['description'] ?? '',
        'instructions' => $productData['config']['prompt'] ?? '',
        'origin' => 'upsell',
        'parent_sale_id' => $rootSaleId
      ],
      $newSaleId
    );

    ChatHandler::addMessage([
      'number' => $number,
      'bot_id' => $botId,
      'client_id' => $newClientId,
      'sale_id' => $newSaleId,
      'message' => "Nueva venta iniciada: {$productData['name']}",
      'format' => 'text',
      'metadata' => [
        'action' => 'start_sale',
        'sale_id' => $newSaleId,
        'product_id' => $upsellProductId,
        'product_name' => $productData['name'],
        'price' => $metadata['price_upsell'] ?? $productData['price'] ?? 0,
        'description' => $productData['description'] ?? '',
        'instructions' => $productData['config']['prompt'] ?? '',
        'origin' => 'upsell',
        'parent_sale_id' => $rootSaleId
      ]
    ], 'S');

    // RECONSTRUIR CHAT: Regenerar archivo JSON con nuevo current_sale
    ChatHandler::getChat($number, $botId, true, true);
    ogLog::info("executeUpsell - Chat reconstruido con nueva venta upsell", [ 'number' => $number, 'new_sale_id' => $newSaleId ], self::$logMeta);

    // ===========================================
    // PASO 4: REGISTRAR FOLLOWUPS DEL UPSELL
    // ===========================================
    $followupMessages = ProductHandler::getMessagesFile('follow_upsell', $upsellProductId);

    if (!empty($followupMessages)) {
      ogLog::info("executeUpsell - Registrando followups upsell", [ 'product_id' => $upsellProductId, 'total_followups' => count($followupMessages)], self::$logMeta);
      $botTimezone = $botData['config']['timezone'] ?? 'America/Guayaquil';

      ogApp()->loadHandler('followup');
      ogApp()->handler('followup')::registerFromSale(
        [
          'sale_id' => $newSaleId,
          'product_id' => $upsellProductId,
          'client_id' => $newClientId,
          'bot_id' => $botId,
          'number' => $number
        ],
        $followupMessages,
        $botTimezone
      );
    }

    ogLog::info("executeUpsell - Upsell ejecutado completamente", [ 'new_sale_id' => $newSaleId, 'upsell_product_id' => $upsellProductId, 'root_sale_id' => $rootSaleId ], self::$logMeta);

    return [
      'success' => true,
      'new_sale_id' => $newSaleId,
      'upsell_product_id' => $upsellProductId,
      'root_sale_id' => $rootSaleId
    ];
  }
}