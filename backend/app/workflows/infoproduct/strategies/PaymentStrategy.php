<?php

class PaymentStrategy implements ConversationStrategyInterface {

  public function execute(array $context): array {
    $bot = $context['bot'];
    $person = $context['person'];
    $imageAnalysis = $context['image_analysis'];
    $chatData = $context['chat_data'];

    require_once APP_PATH . '/workflows/infoproduct/validators/PaymentProofValidator.php';
    
    $validation = PaymentProofValidator::validate($imageAnalysis);

    $this->saveImageMessage($imageAnalysis, $context);

    if (!$validation['is_valid']) {
      $errorMessage = "Lo siento, no pude validar el comprobante de pago. Por favor, envía una foto clara del recibo.";
      
      chatapi::send($person['number'], $errorMessage);

      ChatHandlers::register(
        $bot['id'],
        $bot['number'],
        $chatData['client_id'],
        $person['number'],
        $errorMessage,
        'B',
        'text',
        ['action' => 'invalid_receipt_format', 'errors' => $validation['errors']],
        $chatData['sale_id']
      );

      return [
        'success' => false,
        'error' => 'Invalid payment proof',
        'validation' => $validation
      ];
    }

    $paymentData = $validation['data'];
    $saleId = $chatData['sale_id'];

    // Actualizar monto y nombre
    $this->updateSaleAndClient($paymentData, $saleId, $chatData['client_id'], $person['number']);
    
    // Procesar pago
    $this->processPayment($paymentData, $saleId);
    
    // Entregar producto
    $this->deliverProduct($chatData, $context);

    return [
      'success' => true,
      'payment_data' => $paymentData,
      'sale_id' => $saleId
    ];
  }

  private function saveImageMessage($imageAnalysis, $context) {
    $bot = $context['bot'];
    $person = $context['person'];
    $chatData = $context['chat_data'];

    $resume = $imageAnalysis['metadata']['description']['resume'] ?? 'Imagen de pago';

    ChatHandlers::register(
      $bot['id'],
      $bot['number'],
      $chatData['client_id'],
      $person['number'],
      $resume,
      'P',
      'image',
      $imageAnalysis['metadata'],
      $chatData['sale_id']
    );

    ChatHandlers::addMessage([
      'number' => $person['number'],
      'bot_id' => $bot['id'],
      'client_id' => $chatData['client_id'],
      'sale_id' => $chatData['sale_id'],
      'message' => $resume,
      'format' => 'image',
      'metadata' => $imageAnalysis['metadata']
    ], 'P');
  }

  private function updateSaleAndClient($paymentData, $saleId, $clientId, $clientNumber) {
    $billedAmount = $paymentData['amount'] ?? null;
    $receiptName = $paymentData['name'] ?? null;

    // Actualizar billed_amount en la venta
    if ($billedAmount && $billedAmount > 0) {
      db::table('sales')
        ->where('id', $saleId)
        ->update([
          'billed_amount' => $billedAmount,
          'du' => date('Y-m-d H:i:s'),
          'tu' => time()
        ]);

      log::info("PaymentStrategy - Monto actualizado desde recibo", [
        'sale_id' => $saleId,
        'billed_amount' => $billedAmount
      ], ['module' => 'payment_strategy']);
    }

    // Actualizar nombre del cliente si viene en el recibo y el cliente no tiene nombre
    if ($receiptName && !empty($receiptName)) {
      $client = db::table('clients')->find($clientId);

      if ($client && empty($client['name'])) {
        db::table('clients')
          ->where('id', $clientId)
          ->update([
            'name' => $receiptName,
            'du' => date('Y-m-d H:i:s'),
            'tu' => time()
          ]);

        log::info("PaymentStrategy - Nombre actualizado desde recibo", [
          'client_id' => $clientId,
          'name' => $receiptName
        ], ['module' => 'payment_strategy']);
      }
    }
  }

  private function processPayment($paymentData, $saleId) {
    SaleHandlers::updateStatus($saleId, 'completed');
    
    SaleHandlers::registerPayment(
      $saleId,
      'RECEIPT_' . time(),
      'Recibo de pago',
      date('Y-m-d H:i:s')
    );
  }

  private function deliverProduct($chatData, $context) {
    require_once APP_PATH . '/workflows/infoproduct/actions/DeliverProductAction.php';
    
    $productId = $chatData['full_chat']['current_sale']['product_id'] ?? null;

    if ($productId) {
      DeliverProductAction::send($productId, $context);
    }
  }
}