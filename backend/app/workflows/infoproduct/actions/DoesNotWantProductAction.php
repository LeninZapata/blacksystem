<?php

class DoesNotWantProductAction implements ActionHandler {

  public function handle($context): array {
    $bot = $context['bot'];
    $person = $context['person'];
    $chatData = $context['chat_data'];
    $metadata = $context['metadata'] ?? [];

    // Obtener sale_id desde metadata o desde current_sale
    $saleId = $metadata['sale_id'] ?? $chatData['current_sale']['sale_id'] ?? null;

    if (!$saleId) {
      ogLog::warning("DoesNotWantProductAction - No sale_id encontrado", [
        'number' => $person['number']
      ], ['module' => 'does_not_want_product']);

      return [
        'success' => false,
        'error' => 'No sale_id found'
      ];
    }

    // Cancelar todos los followups de esta venta
    $cancelResult = $this->cancelFollowups($saleId);

    // Actualizar estado de la venta a 'cancelled'
    $saleUpdateResult = $this->updateSaleStatus($saleId);

    // Registrar acción en el chat
    $this->registerCancellation($bot, $person, $chatData, $saleId);

    // Reconstruir chat JSON
    ChatHandlers::rebuildFromDB($person['number'], $bot['id']);

    ogLog::info("DoesNotWantProductAction - Venta cancelada", [
      'sale_id' => $saleId,
      'followups_cancelled' => $cancelResult,
      'sale_updated' => $saleUpdateResult
    ], ['module' => 'does_not_want_product']);

    return [
      'success' => true,
      'sale_id' => $saleId,
      'followups_cancelled' => $cancelResult,
      'sale_updated' => $saleUpdateResult
    ];
  }

  public function getActionName(): string {
    return 'does_not_want_the_product';
  }

  private function cancelFollowups($saleId) {
    require_once APP_PATH . '/resources/handlers/FollowupHandlers.php';
    return FollowupHandlers::cancelBySale($saleId);
  }

  private function updateSaleStatus($saleId) {
    try {
      $affected = db::table(DB_TABLES['sales'])
        ->where('id', $saleId)
        ->update([
          'process_status' => 'cancelled',
          'du' => date('Y-m-d H:i:s'),
          'tu' => time()
        ]);

      return $affected > 0;
    } catch (Exception $e) {
      ogLog::error("DoesNotWantProductAction::updateSaleStatus - Error", [
        'error' => $e->getMessage()
      ], ['module' => 'does_not_want_product']);

      return false;
    }
  }

  private function registerCancellation($bot, $person, $chatData, $saleId) {
    $message = "Cliente indicó que no quiere el producto - Venta cancelada";
    $metadata = [
      'action' => 'sale_cancelled_by_client',
      'sale_id' => $saleId,
      'cancelled_at' => date('Y-m-d H:i:s')
    ];

    ChatHandlers::register(
      $bot['id'],
      $bot['number'],
      $chatData['client_id'],
      $person['number'],
      $message,
      'S',
      'text',
      $metadata,
      $saleId
    );

    ChatHandlers::addMessage([
      'number' => $person['number'],
      'bot_id' => $bot['id'],
      'client_id' => $chatData['client_id'],
      'sale_id' => $saleId,
      'message' => $message,
      'format' => 'text',
      'metadata' => $metadata
    ], 'S');
  }
}