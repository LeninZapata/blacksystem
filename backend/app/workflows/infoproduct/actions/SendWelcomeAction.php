<?php

class SendWelcomeAction {

  static function send($dataSale) {
    $bot = $dataSale['bot'];
    $person = $dataSale['person'];
    $product = $dataSale['product'];
    $productId = $dataSale['product_id'];

    $from = $person['number'];
    $name = $person['name'];

    $messages = ProductHandler::getMessagesFile('welcome', $productId);

    if (!$messages) {
      log::error("SendWelcomeAction::send - No existe mensaje de bienvenida para este producto", [
        'product_id' => $productId,
        'product_name' => $product['name']
      ], ['module' => 'infoproduct', 'layer' => 'app']);
      return [
        'success' => false,
        'error' => 'welcome_file_not_found'
      ];
    }

    if (empty($messages)) {
      return [
        'success' => false,
        'error' => 'no_messages_configured'
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
        $saleResult = CreateSaleAction::create($dataSale);

        if ($saleResult['success']) {
          $clientId = $saleResult['client_id'];
          $saleId = $saleResult['sale_id'];

          // Registrar followups
          $followups = ProductHandler::getMessagesFile('follow', $productId);

          if (!empty($followups)) {
            $botTimezone = $bot['config']['timezone'] ?? 'America/Guayaquil';

            FollowupHandlers::registerFromSale(
              [
                'sale_id' => $saleId,
                'product_id' => $productId,
                'client_id' => $clientId,
                'bot_id' => $bot['id'],
                'number' => $from
              ],
              $followups,
              $botTimezone
            );
          }
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