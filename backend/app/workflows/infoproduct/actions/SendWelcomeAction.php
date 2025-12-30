<?php

class SendWelcomeAction {

  private static $logMeta = ['module' => 'SendWelcomeAction', 'layer' => 'app/workflows'];

  static function send($dataSale) {
    $bot = $dataSale['bot'];
    $person = $dataSale['person'];
    $product = $dataSale['product'];
    $productId = $dataSale['product_id'];

    $from = $person['number'];
    $name = $person['name'];

    ogApp()->loadHandler('product');
    $messages = ProductHandler::getMessagesFile('welcome', $productId);

    if (!$messages) {
      ogLog::error("send - No existe mensaje de bienvenida para este producto", [ 'product_id' => $productId, 'product_name' => $product['name'] ], self::$logMeta);
      return [ 'success' => false, 'error' => 'welcome_file_not_found' ];
    }

    if (empty($messages)) {
      ogLog::error("send - No hay mensajes configurados en el archivo de bienvenida", [ 'product_id' => $productId, 'product_name' => $product['name'] ], self::$logMeta);
      return [ 'success' => false, 'error' => 'no_messages_configured' ];
    }

    $totalMessages = count($messages);
    $messagesSent = 0;
    $clientId = null;
    $saleId = null;

    $chatapi = ogApp()->service('chatApi');
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
            $chatapi::sendPresence($from, 'composing', $durationMs);
          } catch (Exception $e) {
            sleep($duration);
            continue;
          }
        }
      }

      $result = $chatapi::send($from, $text, $url);
      $messagesSent++;

      if ($messagesSent === 1 && $result['success']) {
        $saleResult = CreateSaleAction::create($dataSale);

        if ($saleResult['success']) {
          ogLog::success("send - Venta creada exitosamente durante el primer mensaje del welcome", [ 'sale_id' => $saleResult['sale_id'], 'client_id' => $saleResult['client_id'], 'product_id' => $productId ], self::$logMeta);
          $clientId = $saleResult['client_id'];
          $saleId = $saleResult['sale_id'];

          // Registrar followups
          $followups = ProductHandler::getMessagesFile('follow', $productId);

          if (!empty($followups)) {
            $botTimezone = $bot['config']['timezone'] ?? 'America/Guayaquil';
            ogApp()->loadHandler('followup');
            FollowupHandler::registerFromSale(
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
            ogLog::info("send - Followups registrados exitosamente para la venta", [ 'sale_id' => $saleId, 'client_id' => $clientId, 'product_id' => $productId, 'timezone_bot' => $botTimezone ], self::$logMeta);
          }else{
            ogLog::info("send - No hay followups configurados para este producto", [ 'product_id' => $productId ], self::$logMeta);
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