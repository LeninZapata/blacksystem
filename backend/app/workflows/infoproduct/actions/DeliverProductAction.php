<?php

class DeliverProductAction {

  private static $logMeta = ['module' => 'DeliverProductAction', 'layer' => 'app/workflows'];
  static function send($productId, $context) {
    $person = $context['person'];
    $bot = $context['bot'];
    $chatData = $context['chat_data'];

    $templates = self::loadProductTemplates($productId);

    if (!$templates || empty($templates)) {
      ogLog::error("send - templates found for product: {$productId}", [], self::$logMeta);
      return [
        'success' => false,
        'error' => 'No templates found'
      ];
    }

    $productTemplate = self::findProductTemplate($templates);

    if (!$productTemplate) {
      ogLog::warning("send - No link_product template found for product: {$productId}", [],  self::$logMeta);
      return [
        'success' => false,
        'error' => 'No link_product template found'
      ];
    }

    self::sendProductTemplate($productTemplate, $person['number']);

    self::registerDelivery($bot, $person, $chatData, $productId);

    // Reconstruir chat JSON después de entregar
    self::rebuildChatAfterDelivery($person['number'], $bot['id']);

    return [
      'success' => true,
      'template_sent' => $productTemplate['template_id'] ?? 'unknown'
    ];
  }

  private static function loadProductTemplates($productId) {
    ogApp()->loadHandler('product');
    return productHandler::getMessagesFile('template', $productId);
  }

  private static function findProductTemplate($templates) {
    foreach ($templates as $template) {
      $templateType = $template['template_type'] ?? null;

      if ($templateType === 'link_product') {
        return $template;
      }
    }

    return null;
  }

  private static function sendProductTemplate($template, $to) {
    $message = $template['message'] ?? '';
    $url = $template['url'] ?? '';

    ogLog::info("sendProductTemplate - Enviando producto", [ 'template_id' => $template['template_id'] ?? 'unknown', 'to' => $to ], self::$logMeta);
    $chatapi = ogApp()->service('chatApi');
    $chatapi::send($to, $message, $url);
  }

  private static function registerDelivery($bot, $person, $chatData, $productId) {
    $product     = productHandler::getProductFile($productId);
    $productName = $product['name'] ?? "ID {$productId}";

    $message  = "Producto entregado: {$productName}";
    $metadata = [
      'action'       => 'delivered_product',
      'product_id'   => $productId,
      'product_name' => $productName,
      'delivered_at' => date('Y-m-d H:i:s')
    ];

    ogApp()->loadHandler('chat');
    chatHandler::register(
      $bot['id'],
      $bot['number'],
      $chatData['client_id'],
      $person['number'],
      $message,
      'S',
      'text',
      $metadata,
      $chatData['current_sale']['sale_id'] ?? null
    );

    chatHandler::addMessage([
      'number' => $person['number'],
      'bot_id' => $bot['id'],
      'client_id' => $chatData['client_id'],
      'sale_id' => $chatData['current_sale']['sale_id'] ?? null,
      'message' => $message,
      'format' => 'text',
      'metadata' => $metadata
    ], 'S');
  }

  private static function rebuildChatAfterDelivery($number, $botId) {
    ogLog::info("rebuildChatAfterDelivery - Reconstruyendo chat después de entrega", [ 'number' => $number, 'bot_id' => $botId ], self::$logMeta);

    // Forzar reconstrucción desde DB
    ogApp()->loadHandler('chat');
    chatHandler::rebuildFromDB($number, $botId);
  }

}