<?php

/**
 * DeliveredProductActionHandler
 *
 * Se dispara cuando la IA usa action: "delivered_product" — ocurre cuando el bot
 * entrega el producto sin pasar por el flujo normal de validación de recibo
 * (ej. excepción de Banco del Pacífico que no muestra el destinatario).
 *
 * Si la venta aún no tiene sale_confirmed en el historial → la confirma en la DB,
 * registra el evento en el chat y cancela followups pendientes.
 * Si ya estaba confirmada → no hace nada (idempotente).
 */
class DeliveredProductActionHandler implements ActionHandler {
  private $logMeta = ['module' => 'action/DeliveredProductActionHandler', 'layer' => 'app/workflows'];

  public function handle($context): array {
    $bot      = $context['bot'];
    $person   = $context['person'];
    $chatData = $context['chat_data'];
    $metadata = $context['metadata'] ?? [];

    $saleId    = $metadata['sale_id']    ?? $chatData['current_sale']['sale_id']    ?? null;
    $productId = $metadata['product_id'] ?? $chatData['current_sale']['product_id'] ?? null;

    if (!$saleId) {
      ogLog::warning("handle - No hay sale_id", ['number' => $person['number']], $this->logMeta);
      return ['success' => false, 'error' => 'No sale_id found'];
    }

    // Idempotencia: si ya existe sale_confirmed para este sale_id, no hacer nada
    if ($this->isSaleAlreadyConfirmed($chatData['messages'] ?? [], $saleId)) {
      ogLog::info("handle - Venta ya confirmada, omitiendo", ['sale_id' => $saleId], $this->logMeta);
      return ['success' => true, 'skipped' => true, 'reason' => 'already_confirmed'];
    }

    // 1. Actualizar estado de la venta a sale_confirmed en la DB
    $this->updateSaleStatus($saleId);

    // 2. Registrar sale_confirmed en el chat (mensaje de sistema)
    $this->registerSaleConfirmed($bot, $person, $chatData, $saleId, $productId);

    // 3. Cancelar followups de esta venta
    $this->cancelFollowups($saleId);

    // 4. Reconstruir JSON del chat para reflejar el nuevo estado
    ogApp()->loadHandler('chat');
    ChatHandler::rebuildFromDB($person['number'], $bot['id']);

    ogLog::info("handle - Venta confirmada vía delivered_product", [
      'sale_id'    => $saleId,
      'product_id' => $productId,
      'number'     => $person['number']
    ], $this->logMeta);

    return ['success' => true, 'sale_id' => $saleId, 'product_id' => $productId];
  }

  public function getActionName(): string {
    return 'delivered_product';
  }

  /**
   * Verifica si ya existe un mensaje con action: sale_confirmed
   * para este sale_id en el historial del chat.
   */
  private function isSaleAlreadyConfirmed(array $messages, $saleId): bool {
    foreach ($messages as $msg) {
      $action    = $msg['metadata']['action']   ?? null;
      $msgSaleId = $msg['metadata']['sale_id']  ?? null;
      if ($action === 'sale_confirmed' && (string)$msgSaleId === (string)$saleId) {
        return true;
      }
    }
    return false;
  }

  private function updateSaleStatus($saleId): void {
    try {
      ogApp()->loadHandler('sale');
      SaleHandler::updateStatus($saleId, 'sale_confirmed');
      SaleHandler::registerPayment(
        $saleId,
        'BOT_DELIVERY_' . time(),
        'Entregado por el bot (sin validación de recibo)',
        gmdate('Y-m-d H:i:s')
      );
    } catch (Exception $e) {
      ogLog::error("updateSaleStatus - Error", [
        'sale_id' => $saleId, 'error' => $e->getMessage()
      ], $this->logMeta);
    }
  }

  private function registerSaleConfirmed($bot, $person, $chatData, $saleId, $productId): void {
    $metadata = [
      'action'     => 'sale_confirmed',
      'sale_id'    => $saleId,
      'product_id' => $productId,
      'origin'     => 'bot_delivered',
    ];

    ogApp()->loadHandler('chat');
    ChatHandler::register(
      $bot['id'], $bot['number'],
      $chatData['client_id'], $person['number'],
      'Venta confirmada - Producto entregado por el bot',
      'S', 'text', $metadata, $saleId, true
    );

    ChatHandler::addMessage([
      'number'    => $person['number'],
      'bot_id'    => $bot['id'],
      'client_id' => $chatData['client_id'],
      'sale_id'   => $saleId,
      'message'   => 'Venta confirmada - Producto entregado por el bot',
      'format'    => 'text',
      'metadata'  => $metadata
    ], 'S');
  }

  private function cancelFollowups($saleId): void {
    try {
      ogApp()->loadHandler('followup');
      FollowupHandler::cancelBySale($saleId);
    } catch (Exception $e) {
      ogLog::warning("cancelFollowups - Error", [
        'sale_id' => $saleId, 'error' => $e->getMessage()
      ], $this->logMeta);
    }
  }
}
