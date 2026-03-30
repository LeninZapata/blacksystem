<?php
/**
 * hotmartEventParser
 *
 * Responsabilidad única: parsear y extraer datos del webhook de Hotmart.
 * No hace operaciones de negocio, solo extracción y detección.
 *
 * Incluye la detección del tipo de compra (main_purchase / order_bump / upsell)
 * que sí requiere algunas consultas a BD para validar contra el catálogo.
 */
class hotmartEventParser {

  private static $logMeta = ['module' => 'hotmartEventParser', 'layer' => 'middle/integration/payment'];

  // ─────────────────────────────────────────────────────────────────────────
  // VALIDACIÓN
  // ─────────────────────────────────────────────────────────────────────────

  static function validateStructure($data) {
    return !empty($data) && is_array($data) && isset($data['event'], $data['data']);
  }

  static function isApprovedPurchase($eventType) {
    return in_array($eventType, ['PURCHASE_COMPLETE', 'PURCHASE_APPROVED', 'UPSELL_APPROVED']);
  }

  // ─────────────────────────────────────────────────────────────────────────
  // PARSEO DE SCK
  // Formato: {numero}|b-{bot_id}|p-{product_id}|t-{type}|ds-{flag}
  // Tipos (t): p=principal, o=order bump, u=upsell
  // ─────────────────────────────────────────────────────────────────────────

  static function parseSck($sck) {
    if (empty($sck)) return [];

    $result = ['number' => null, 'bot_id' => null, 'product_id' => null, 'type' => null, 'is_downsell' => false];

    foreach (explode('|', $sck) as $part) {
      $part = trim($part);
      if (preg_match('/^b-(\d+)$/i', $part, $m)) { $result['bot_id']    = (int)$m[1];               continue; }
      if (preg_match('/^p-(\d+)$/i', $part, $m)) { $result['product_id'] = (int)$m[1];              continue; }
      if (preg_match('/^t-(p|o|u)$/i', $part, $m)) { $result['type']    = strtolower($m[1]);         continue; }
      if (preg_match('/^ds-(\d+)$/i', $part, $m)) { $result['is_downsell'] = (bool)(int)$m[1];       continue; }
      if (preg_match('/^\d+$/', $part))            { $result['number']   = $part;                     continue; }
    }

    return $result;
  }

  static function extractCustomParams($data) {
    $origin = $data['data']['purchase']['origin'] ?? [];
    $sckRaw = $origin['sck'] ?? null;
    $parsed = self::parseSck($sckRaw);

    $params = [
      'sck_raw'        => $sckRaw,
      'whatsapp_number' => $parsed['number']     ?? null,
      'bot_id_sck'     => $parsed['bot_id']      ?? null,
      'product_id_sck' => $parsed['product_id']  ?? null,
      'type'           => $parsed['type']         ?? null,
      'is_downsell'    => $parsed['is_downsell']  ?? false,
      'src'            => $origin['src']          ?? null,
    ];

    return array_filter($params, fn($v) => $v !== null && $v !== false);
  }

  // ─────────────────────────────────────────────────────────────────────────
  // EXTRACCIÓN DE EVENTO
  // ─────────────────────────────────────────────────────────────────────────

  static function extractEventInfo($data) {
    $ed            = $data['data'] ?? [];
    $buyer         = $ed['buyer'] ?? [];
    $buyerAddress  = $buyer['address'] ?? [];
    $customParams  = self::extractCustomParams($data);
    $purchaseType  = self::identifyPurchaseType($data);

    return [
      'event_type'           => $data['event'] ?? 'unknown',
      'transaction_id'       => $ed['purchase']['transaction'] ?? null,
      'hotmart_product_id'   => $ed['product']['id'] ?? null,
      'product_name'         => $ed['product']['name'] ?? null,
      'buyer_name'           => trim(($buyer['first_name'] ?? '') . ' ' . ($buyer['last_name'] ?? '')) ?: ($buyer['name'] ?? null),
      'buyer_first_name'     => $buyer['first_name'] ?? null,
      'buyer_last_name'      => $buyer['last_name']  ?? null,
      'buyer_email'          => $buyer['email']       ?? null,
      'buyer_phone'          => self::normalizePhone($buyer['checkout_phone'] ?? null),
      'country_code'         => $buyerAddress['country_iso'] ?? null,
      'amount'               => $ed['purchase']['price']['value'] ?? null,
      'currency'             => $ed['purchase']['price']['currency_value'] ?? 'USD',
      'status'               => $ed['purchase']['status'] ?? null,
      'purchase_type'        => $purchaseType['type'],
      'detection_method'     => $purchaseType['detection_method'],
      'is_order_bump'        => $purchaseType['is_order_bump'],
      'is_upsell'            => $purchaseType['is_upsell'],
      'is_main_purchase'     => $purchaseType['is_main_purchase'],
      'parent_transaction_id' => $purchaseType['parent_transaction_id'],
      'parent_sale'          => $purchaseType['parent_sale'] ?? null,
      'custom_params'        => $customParams,
      // raw_data se asigna en hotmartProvider después de llamar extractEventInfo
    ];
  }

  // ─────────────────────────────────────────────────────────────────────────
  // DETECCIÓN DE TIPO DE COMPRA
  //
  // Orden de prioridad:
  //   1. Order Bump: flag nativo de Hotmart (is_order_bump + transacciones diferentes)
  //   2. Principal: sck t-p
  //   3. Upsell: evento UPSELL_APPROVED
  //   4. Upsell: sck t-u
  //   5. Por tipo en BD (columna hotmart_type en products)
  //   6. Upsell por email: tiene pago reciente en tabla payment (ventana 15 min)
  //   7. Upsell: is_funnel=true + email con pago reciente (ventana 30 min)
  //   8. Default: main_purchase
  // ─────────────────────────────────────────────────────────────────────────

  static function identifyPurchaseType($data) {
    $ed           = $data['data'] ?? [];
    $event        = $data['event'] ?? '';
    $purchase     = $ed['purchase'] ?? [];
    $orderBump    = $purchase['order_bump'] ?? null;
    $buyerEmail   = $ed['buyer']['email'] ?? null;
    $customParams = self::extractCustomParams($data);
    $typeSck      = $customParams['type'] ?? null;
    $productIdSck = $customParams['product_id_sck'] ?? null;

    $result = [
      'type'                 => 'main_purchase',
      'is_order_bump'        => false,
      'is_upsell'            => false,
      'is_main_purchase'     => true,
      'parent_transaction_id' => null,
      'parent_sale'          => null,
      'detection_method'     => 'default',
    ];

    // 1. ORDER BUMP: flag nativo de Hotmart
    if (isset($orderBump['is_order_bump']) && $orderBump['is_order_bump'] === true) {
      $currentTx = $purchase['transaction'] ?? null;
      $parentTx  = $orderBump['parent_purchase_transaction'] ?? null;

      if ($parentTx && $currentTx && $parentTx !== $currentTx) {
        return array_merge($result, [
          'type' => 'order_bump', 'is_order_bump' => true,
          'is_main_purchase' => false, 'parent_transaction_id' => $parentTx,
          'detection_method' => 'hotmart_order_bump_flag',
        ]);
      }
    }

    // 2. PRINCIPAL: sck dice t-p
    if ($typeSck === 'p') {
      return array_merge($result, ['detection_method' => 'sck_type_p']);
    }

    // 3. UPSELL: evento UPSELL_APPROVED
    if ($event === 'UPSELL_APPROVED') {
      return array_merge($result, [
        'type' => 'upsell', 'is_upsell' => true,
        'is_main_purchase' => false, 'detection_method' => 'upsell_approved_event',
      ]);
    }

    // 4. UPSELL: sck dice t-u
    if ($typeSck === 'u') {
      return array_merge($result, [
        'type' => 'upsell', 'is_upsell' => true,
        'is_main_purchase' => false, 'detection_method' => 'sck_type_u',
      ]);
    }

    // 5. Por tipo en BD (columna hotmart_type del producto)
    if ($productIdSck) {
      $typeDb = self::getProductHotmartType($productIdSck);

      if ($typeDb === 'o') {
        $parentTx = $orderBump['parent_purchase_transaction'] ?? null;
        return array_merge($result, [
          'type' => 'order_bump', 'is_order_bump' => true,
          'is_main_purchase' => false, 'parent_transaction_id' => $parentTx,
          'detection_method' => 'db_hotmart_type_o',
        ]);
      }
      if ($typeDb === 'u') {
        return array_merge($result, [
          'type' => 'upsell', 'is_upsell' => true,
          'is_main_purchase' => false, 'detection_method' => 'db_hotmart_type_u',
        ]);
      }
      if ($typeDb === 'p') {
        return array_merge($result, ['detection_method' => 'db_hotmart_type_p']);
      }
    }

    // 6. UPSELL por email: ¿tiene pago reciente en tabla payment?
    if ($buyerEmail) {
      $recentPayments = self::getRecentPaymentsByEmail($buyerEmail, 15);

      if (!empty($recentPayments)) {
        $parentPayment = $recentPayments[0];
        $parentSale    = ogDb::table('sales')->where('id', (int)$parentPayment['sale_id'])->first();

        return array_merge($result, [
          'type' => 'upsell', 'is_upsell' => true,
          'is_main_purchase' => false,
          'parent_transaction_id' => $parentPayment['purchase_transaction'] ?? null,
          'parent_sale' => $parentSale ?: null,
          'detection_method' => 'email_recent_payment_15min',
        ]);
      }
    }

    // 7. UPSELL: is_funnel=true + email con pago reciente (ventana 30 min)
    if (!empty($purchase['is_funnel']) && $buyerEmail) {
      $recentPayments = self::getRecentPaymentsByEmail($buyerEmail, 30);

      if (!empty($recentPayments)) {
        $parentPayment = $recentPayments[0];
        $parentSale    = ogDb::table('sales')->where('id', (int)$parentPayment['sale_id'])->first();

        return array_merge($result, [
          'type' => 'upsell', 'is_upsell' => true,
          'is_main_purchase' => false,
          'parent_transaction_id' => $parentPayment['purchase_transaction'] ?? null,
          'parent_sale' => $parentSale ?: null,
          'detection_method' => 'is_funnel_email_30min',
        ]);
      }
    }

    // 8. Default: main_purchase
    return array_merge($result, ['detection_method' => 'default_main_purchase']);
  }

  // ─────────────────────────────────────────────────────────────────────────
  // CONSULTAS A BD
  // ─────────────────────────────────────────────────────────────────────────

  static function findProductByHotmartId($hotmartProductId) {
    if (!$hotmartProductId) return null;

    try {
      // hotmart_product_id se almacena dentro del campo config (JSON)
      return ogDb::table('products')
        ->where('config', 'LIKE', '%"hotmart_product_id":' . (int)$hotmartProductId . '%')
        ->where('status', 1)
        ->first();
    } catch (Exception $e) {
      ogLog::warning('hotmartEventParser - Error buscando producto', [
        'hotmart_product_id' => $hotmartProductId, 'error' => $e->getMessage()
      ], self::$logMeta);
      return null;
    }
  }

  /**
   * Obtiene el hotmart_type del producto (p/o/u) por ID interno del sistema.
   * Se lee desde el campo config (JSON): {"hotmart_type": "p|o|u", ...}
   */
  private static function getProductHotmartType($productId) {
    try {
      $product = ogDb::table('products')->where('id', (int)$productId)->first();
      if (!$product) return null;
      $config = is_string($product['config']) ? json_decode($product['config'], true) : ($product['config'] ?? []);
      return !empty($config['hotmart_type']) ? strtolower(trim($config['hotmart_type'])) : null;
    } catch (Exception $e) {
      return null;
    }
  }

  /**
   * Busca pagos recientes por email en tabla payment.
   * Clave para detectar upsell: si tiene pago reciente = este webhook es el upsell de ese cliente.
   *
   * @param string $email     Email del comprador
   * @param int    $minutes   Ventana de tiempo en minutos hacia atrás
   */
  static function getRecentPaymentsByEmail($email, $minutes = 15) {
    if (empty($email)) return [];

    try {
      $since = date('Y-m-d H:i:s', time() - ($minutes * 60));

      return ogDb::table('payment')
        ->where('buyer_email', $email)
        ->where('dc', '>=', $since)
        ->orderBy('dc', 'DESC')
        ->get() ?? [];
    } catch (Exception $e) {
      // La tabla payment puede no existir aún (antes de migración)
      ogLog::warning('hotmartEventParser - No se pudo consultar tabla payment', [
        'error' => $e->getMessage()
      ], self::$logMeta);
      return [];
    }
  }

  // ─────────────────────────────────────────────────────────────────────────
  // EXTRACCIÓN DE VALORES FINANCIEROS
  // ─────────────────────────────────────────────────────────────────────────

  /**
   * Comisión del PRODUCER (lo que realmente recibes tú).
   */
  static function getProducerCommission($data) {
    foreach ($data['data']['commissions'] ?? [] as $c) {
      if (($c['source'] ?? '') === 'PRODUCER' && isset($c['value'])) {
        return (float)$c['value'];
      }
    }
    return null;
  }

  /**
   * Precio original que pagó el cliente.
   */
  static function getOriginalPurchasePrice($data) {
    $purchase = $data['data']['purchase'] ?? [];
    $price = $purchase['original_offer_price']['value']
          ?? $purchase['price']['value']
          ?? null;
    return $price !== null ? (float)$price : null;
  }

  // ─────────────────────────────────────────────────────────────────────────
  // UTILIDADES
  // ─────────────────────────────────────────────────────────────────────────

  static function normalizePhone($phone) {
    if (empty($phone)) return null;
    $clean = preg_replace('/[^0-9]/', '', $phone);
    return $clean ?: null;
  }
}
