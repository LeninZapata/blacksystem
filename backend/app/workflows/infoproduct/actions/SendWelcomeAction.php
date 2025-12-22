<?php

class SendWelcomeAction {

  static function send($dataSale) {
    $person = $dataSale['person'];
    $productId = $dataSale['product_id'];
    $bot = $dataSale['bot'];

    $from = $person['number'];
    $name = $person['name'];

    $product = ProductHandler::getProductFile($productId);

    if (!$product) {
      return [
        'success' => false,
        'total_messages' => 0,
        'messages_sent' => 0,
        'client_id' => null,
        'sale_id' => null,
        'error' => 'Producto no encontrado'
      ];
    }

    $dataSale['product'] = $product;

    $messages = ProductHandler::getMessagesFile('welcome', $productId);

    if (!$messages || empty($messages)) {
      return [
        'success' => false,
        'total_messages' => 0,
        'messages_sent' => 0,
        'client_id' => null,
        'sale_id' => null,
        'error' => 'Mensajes de bienvenida no encontrados'
      ];
    }

    $totalMessages = count($messages);
    $messagesSent = 0;
    $clientId = null;
    $saleId = null;

    foreach ($messages as $index => $msg) {
      $delay = isset($msg['delay']) ? (int)$msg['delay'] : 3;
      $text = $msg['message'] ?? '';
      $url = !empty($msg['url']) && $msg['type'] != 'text' ? $msg['url'] : '';

      if ($index > 0 && $delay > 0) {
        $iterations = ceil($delay / 3);

        for ($i = 0; $i < $iterations; $i++) {
          $remaining = $delay - ($i * 3);
          $duration = min($remaining, 3);
          $durationMs = $duration * 1000;

          try {
            chatapi::sendPresence($from, 'composing', $durationMs);
          } catch (Exception $e) {
            sleep($duration);
            continue;
          }
        }
      }

      $result = chatapi::send($from, $text, $url);
      $messagesSent++;

      if ($messagesSent === 1 && $result['success']) {
        require_once APP_PATH . '/workflows/infoproduct/actions/CreateSaleAction.php';
        $saleResult = CreateSaleAction::create($dataSale);

        if ($saleResult['success']) {
          $clientId = $saleResult['client_id'];
          $saleId = $saleResult['sale_id'];
        }
      }
    }

    return [
      'success' => true,
      'total_messages' => $totalMessages,
      'messages_sent' => $messagesSent,
      'client_id' => $clientId,
      'sale_id' => $saleId,
      'error' => null
    ];
  }
}