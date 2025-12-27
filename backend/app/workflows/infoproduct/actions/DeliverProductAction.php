<?php

class DeliverProductAction {

  static function send($productId, $context) {
    $person = $context['person'];
    $bot = $context['bot'];
    $chatData = $context['chat_data'];

    $templates = self::loadProductTemplates($productId);

    if (!$templates || empty($templates)) {
      log::error("No templates found for product: {$productId}", [], ['module' => 'deliver_product']);
      return [
        'success' => false,
        'error' => 'No templates found'
      ];
    }

    $productTemplate = self::findProductTemplate($templates);

    if (!$productTemplate) {
      log::warning("No link_product template found for product: {$productId}", [], ['module' => 'deliver_product']);
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

    log::info("DeliverProductAction - Enviando producto", [
      'template_id' => $template['template_id'] ?? 'unknown',
      'to' => $to
    ], ['module' => 'deliver_product']);

    chatapi::send($to, $message, $url);
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

  private static function rebuildChatAfterDelivery($number, $botId) {
    log::info("DeliverProductAction - Reconstruyendo chat después de entrega", [
      'number' => $number,
      'bot_id' => $botId
    ], ['module' => 'deliver_product']);

    // Forzar reconstrucción desde DB
    chatHandlers::rebuildFromDB($number, $botId);
  }

}