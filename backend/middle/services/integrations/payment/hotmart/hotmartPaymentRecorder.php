<?php
/**
 * hotmartPaymentRecorder
 *
 * Responsabilidad única: guardar el payload completo del webhook
 * de Hotmart en la tabla `payment`, vinculado a una venta de `sales`.
 */
class hotmartPaymentRecorder {

  private static $logMeta = ['module' => 'hotmartPaymentRecorder', 'layer' => 'middle/integration/payment'];

  /**
   * Guarda los datos del webhook en la tabla payment.
   *
   * @param int   $saleId      ID de la venta en tabla sales
   * @param array $webhookData Payload completo del webhook de Hotmart
   * @return int|false ID del payment creado o false en caso de error
   */
  static function save($saleId, $webhookData) {
    try {
      $data      = self::prepare($saleId, $webhookData);
      $paymentId = ogDb::table('payment')->insert($data);

      if ($paymentId) {
        // Vincular el payment_id en la venta para acceso directo desde sales
        ogDb::table('sales')->where('id', $saleId)->update(['payment_id' => $paymentId]);

        ogLog::info('hotmartPaymentRecorder - Payment guardado', [
          'payment_id'  => $paymentId,
          'sale_id'     => $saleId,
          'transaction' => $data['purchase_transaction'],
          'event'       => $data['webhook_event'],
        ], self::$logMeta);
        return $paymentId;
      }

      ogLog::warning('hotmartPaymentRecorder - No se pudo guardar payment', [
        'sale_id' => $saleId
      ], self::$logMeta);
      return false;

    } catch (Exception $e) {
      ogLog::error('hotmartPaymentRecorder - Error', [
        'sale_id' => $saleId, 'error' => $e->getMessage()
      ], self::$logMeta);
      return false;
    }
  }

  /**
   * Mapea el payload de Hotmart v2.0 al esquema de la tabla payment.
   */
  private static function prepare($saleId, $webhookData) {
    $data            = $webhookData['data']               ?? [];
    $product         = $data['product']                   ?? [];
    $purchase        = $data['purchase']                  ?? [];
    $buyer           = $data['buyer']                     ?? [];
    $producer        = $data['producer']                  ?? [];
    $commissions     = $data['commissions']               ?? [];
    $orderBump       = $purchase['order_bump']            ?? [];
    $payment         = $purchase['payment']               ?? [];
    $offer           = $purchase['offer']                 ?? [];
    $buyerAddress    = $buyer['address']                  ?? [];
    $checkoutCountry = $purchase['checkout_country']      ?? [];

    // Separar comisiones por source
    $commMarketplace = null;
    $commProducer    = null;
    foreach ($commissions as $c) {
      if (($c['source'] ?? '') === 'MARKETPLACE') $commMarketplace = $c;
      if (($c['source'] ?? '') === 'PRODUCER')    $commProducer    = $c;
    }

    // Timestamps de Hotmart vienen en milisegundos
    $orderDate    = !empty($purchase['order_date'])
      ? date('Y-m-d H:i:s', intval($purchase['order_date'] / 1000))
      : null;
    $approvedDate = !empty($purchase['approved_date'])
      ? date('Y-m-d H:i:s', intval($purchase['approved_date'] / 1000))
      : null;

    return [
      'sale_id'                          => $saleId,

      // Webhook general
      'webhook_id'                       => $webhookData['id']      ?? null,
      'webhook_event'                    => $webhookData['event']   ?? null,
      'webhook_version'                  => $webhookData['version'] ?? null,

      // Producto (IDs de Hotmart, no del sistema interno)
      'product_id'                       => $product['id']               ?? null,
      'product_ucode'                    => $product['ucode']            ?? null,
      'product_name'                     => $product['name']             ?? null,
      'product_has_co_production'        => ($product['has_co_production'] ?? false) ? 1 : 0,

      // Comisiones
      'commission_marketplace_currency'  => $commMarketplace['currency_value'] ?? null,
      'commission_marketplace_value'     => $commMarketplace['value']          ?? null,
      'commission_producer_currency'     => $commProducer['currency_value']    ?? null,
      'commission_producer_value'        => $commProducer['value']             ?? null,

      // Compra
      'purchase_transaction'             => $purchase['transaction']                    ?? null,
      'purchase_status'                  => $purchase['status']                         ?? null,
      'purchase_currency'                => $purchase['price']['currency_value']        ?? null,
      'purchase_price'                   => $purchase['price']['value']                 ?? null,
      'purchase_full_price'              => $purchase['full_price']['value']            ?? null,
      'purchase_original_price'          => $purchase['original_offer_price']['value']  ?? null,
      'purchase_country_code'            => $checkoutCountry['iso']                     ?? null,
      'purchase_country_name'            => $checkoutCountry['name']                    ?? null,
      'purchase_ip'                      => $purchase['buyer_ip']                       ?? null,
      'purchase_order_date'              => $orderDate,
      'purchase_approved_date'           => $approvedDate,
      'purchase_is_funnel'               => ($purchase['is_funnel']               ?? false) ? 1 : 0,
      'purchase_is_order_bump'           => ($orderBump['is_order_bump']          ?? false) ? 1 : 0,

      // Pago
      'payment_type'                     => $payment['type']                ?? null,
      'payment_installments'             => $payment['installments_number'] ?? 1,

      // Oferta
      'offer_code'                       => $offer['code'] ?? null,
      'offer_name'                       => $offer['name'] ?? null,

      // Productor
      'producer_name'                    => $producer['name']          ?? null,
      'producer_document'                => $producer['document']      ?? null,
      'producer_legal_nature'            => $producer['legal_nature']  ?? null,

      // Comprador
      'buyer_name'                       => $buyer['name']                   ?? null,
      'buyer_first_name'                 => $buyer['first_name']             ?? null,
      'buyer_last_name'                  => $buyer['last_name']              ?? null,
      'buyer_email'                      => $buyer['email']                  ?? null,
      'buyer_phone'                      => $buyer['checkout_phone']         ?? null,
      'buyer_document'                   => $buyer['document']               ?? null,
      'buyer_country_code'               => $buyerAddress['country_iso']     ?? null,
      'buyer_country_name'               => $buyerAddress['country']         ?? null,
      'buyer_address'                    => $buyerAddress['address']         ?? null,
      'buyer_city'                       => $buyerAddress['city']            ?? null,
      'buyer_zipcode'                    => $buyerAddress['zipcode']         ?? null,

      // Control
      'status' => 1,
      'dc'     => date('Y-m-d H:i:s'),
      'tc'     => time(),
    ];
  }
}
