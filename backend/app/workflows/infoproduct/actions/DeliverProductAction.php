<?php

class DeliverProductAction {

  static function send($productId, $context) {
    $person = $context['person'];
    $bot = $context['bot'];
    $chatData = $context['chat_data'];

    $templates = self::loadProductTemplates($productId);

    if (!$templates || empty($templates)) {
      log::warning("No templates found for product: {$productId}", [], ['module' => 'deliver_product']);
      return [
        'success' => false,
        'error' => 'No templates found'
      ];
    }

    self::sendTemplates($templates, $person['number']);

    self::registerDelivery($bot, $person, $chatData, $productId);

    return [
      'success' => true,
      'templates_sent' => count($templates)
    ];
  }

  private static function loadProductTemplates($productId) {
    return productHandler::getMessagesFile('template', $productId);
  }

  private static function sendTemplates($templates, $to) {
    foreach ($templates as $template) {
      $message = $template['message'] ?? '';
      $url = $template['url'] ?? '';
      $delay = 2;

      if ($delay > 0) {
        sleep($delay);
      }

      chatapi::send($to, $message, $url);
    }
  }

  private static function registerDelivery($bot, $person, $chatData, $productId) {
    $message = "Producto entregado: ID {$productId}";
    $metadata = [
      'action' => 'delivered_product',
      'product_id' => $productId,
      'delivered_at' => date('Y-m-d H:i:s')
    ];

    chatHandlers::register(
      $bot['id'],
      $bot['number'],
      $chatData['client_id'],
      $person['number'],
      $message,
      'S',
      'text',
      $metadata,
      $chatData['sale_id']
    );

    chatHandlers::addMessage([
      'number' => $person['number'],
      'bot_id' => $bot['id'],
      'client_id' => $chatData['client_id'],
      'sale_id' => $chatData['sale_id'],
      'message' => $message,
      'format' => 'text',
      'metadata' => $metadata
    ], 'S');
  }
}