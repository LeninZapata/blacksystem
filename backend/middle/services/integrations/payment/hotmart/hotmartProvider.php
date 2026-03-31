<?php
/**
 * hotmartProvider
 *
 * Orquestador principal del webhook de Hotmart.
 * Carga las dependencias del subdirectorio e integra:
 *   hotmartEventParser  → parseo y detección de tipo de compra
 *   hotmartSaleRegistrar → registro/actualización de ventas en BD
 *   hotmartPaymentRecorder → guardado del payload completo en tabla payment
 */
class hotmartProvider {

  private $logMeta = ['module' => 'hotmartProvider', 'layer' => 'middle/integration/payment'];

  function __construct() {
    $basePath = ogCache::memoryGet('path_middle') . '/services/integrations/payment/hotmart';

    if (!class_exists('hotmartEventParser')) {
      require_once "{$basePath}/hotmartEventParser.php";
    }
    if (!class_exists('hotmartSaleRegistrar')) {
      require_once "{$basePath}/hotmartSaleRegistrar.php";
    }
    if (!class_exists('hotmartPaymentRecorder')) {
      require_once "{$basePath}/hotmartPaymentRecorder.php";
    }
  }

  /**
   * Procesa el payload completo de un webhook de Hotmart.
   *
   * Flujo:
   *   1. Validar estructura mínima
   *   2. Filtrar solo eventos de compra aprobada
   *   3. Parsear info del evento (tipo de compra, buyer, SCK, etc.)
   *   4. Registrar / actualizar la venta en BD
   *   5. Guardar registro completo en tabla payment
   *
   * @param array $rawData Payload decodificado del webhook
   * @return array {success, reason?, sale_id?, action?, event?, error?}
   */
  function processWebhook($rawData) {
    // 1. Validar estructura mínima
    if (!hotmartEventParser::validateStructure($rawData)) {
      ogLog::warning('hotmartProvider - Estructura de webhook inválida', [
        'keys' => array_keys($rawData ?? [])
      ], $this->logMeta);
      return ['success' => false, 'reason' => 'invalid_structure'];
    }

    $eventType = $rawData['event'] ?? 'unknown';

    // 2. Solo procesar eventos de compra aprobada
    if (!hotmartEventParser::isApprovedPurchase($eventType)) {
      ogLog::info('hotmartProvider - Evento ignorado (no es compra aprobada)', [
        'event' => $eventType
      ], $this->logMeta);
      return ['success' => true, 'reason' => 'event_ignored', 'event' => $eventType];
    }

    // 3. Deduplicar: si esta transaction_id ya fue procesada, ignorar
    $transactionId = $rawData['data']['purchase']['transaction'] ?? null;
    if ($transactionId) {
      try {
        $existing = ogDb::table('payment')->where('purchase_transaction', $transactionId)->first();
        if ($existing) {
          ogLog::info('hotmartProvider - Transaction ya procesada, ignorando duplicado', [
            'transaction_id' => $transactionId, 'event' => $eventType, 'payment_id' => $existing['id']
          ], $this->logMeta);
          return ['success' => true, 'reason' => 'already_processed', 'transaction_id' => $transactionId];
        }
      } catch (Exception $e) {
        // Si la tabla no existe aún, continuar sin deduplicar
      }
    }

    // 4. Parsear información del evento
    $eventInfo             = hotmartEventParser::extractEventInfo($rawData);
    $eventInfo['raw_data'] = $rawData; // necesario para calcular comisiones en hotmartSaleRegistrar

    $customParams = $eventInfo['custom_params'] ?? [];

    // Determinar contexto: número WhatsApp, bot_id y product_id vienen del SCK;
    // si no hay SCK se usa el teléfono del comprador y module pasa a 'direct'.
    $from      = $customParams['whatsapp_number'] ?? $eventInfo['buyer_phone'] ?? null;
    $botId     = $customParams['bot_id_sck']      ?? null;
    $productId = $customParams['product_id_sck']  ?? null;
    $saleId    = $customParams['sale_id_sck']      ?? null;
    $module    = !empty($customParams['whatsapp_number']) ? 'whatsapp' : 'direct';

    ogLog::info('hotmartProvider - Procesando webhook', [
      'event'          => $eventType,
      'transaction'    => $eventInfo['transaction_id'],
      'purchase_type'  => $eventInfo['purchase_type'],
      'detection'      => $eventInfo['detection_method'],
      'from'           => $from,
      'bot_id'         => $botId,
      'product_id'     => $productId,
      'module'         => $module,
    ], $this->logMeta);

    // 5. Registrar / actualizar venta
    $parentSale = $eventInfo['parent_sale'] ?? null;
    $saleResult = hotmartSaleRegistrar::register($eventInfo, $botId, $productId, $from, $module, $parentSale, $saleId);

    if (!($saleResult['success'] ?? false)) {
      ogLog::error('hotmartProvider - Error registrando venta', [
        'event'       => $eventType,
        'transaction' => $eventInfo['transaction_id'],
        'error'       => $saleResult['error'] ?? 'unknown',
      ], $this->logMeta);
      return ['success' => false, 'error' => $saleResult['error'] ?? 'Error registrando venta'];
    }

    $saleId = $saleResult['sale_id'];

    // 6. Guardar registro completo del pago
    hotmartPaymentRecorder::save($saleId, $rawData);

    ogLog::info('hotmartProvider - Webhook procesado exitosamente', [
      'sale_id' => $saleId,
      'action'  => $saleResult['action'],
      'event'   => $eventType,
    ], $this->logMeta);

    return [
      'success' => true,
      'sale_id' => $saleId,
      'action'  => $saleResult['action'],
      'event'   => $eventType,
    ];
  }
}
