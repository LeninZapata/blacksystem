<?php

class WelcomeStrategy implements ConversationStrategyInterface {
  public function execute(array $context): array {   
    $bot = $context['bot'];
    $person = $context['person'];
    $productId = $context['product_id'];
    $rawContext = $context['context'] ?? [];

    $product = $this->loadProduct($productId);

    if (!$product) {
      return [
        'success' => false,
        'error' => 'Producto no encontrado'
      ];
    }

    $dataSale = [
      'person' => $person,
      'bot' => $bot,
      'product' => $product,
      'product_id' => $productId,
      'context' => $rawContext
    ];

    require_once APP_PATH . '/workflows/infoproduct/actions/CreateSaleAction.php';
    require_once APP_PATH . '/workflows/infoproduct/actions/SendWelcomeAction.php';

    $welcomeResult = SendWelcomeAction::send($dataSale);

    if (!$welcomeResult['success']) {
      return [
        'success' => false,
        'error' => $welcomeResult['error'] ?? 'Error enviando bienvenida'
      ];
    }

    $clientId = $welcomeResult['client_id'];
    $saleId = $welcomeResult['sale_id'];

    if ($clientId && $saleId) {
      $this->registerStartSale($bot, $person, $product, $clientId, $saleId);
    }

    return [
      'success' => true,
      'client_id' => $clientId,
      'sale_id' => $saleId,
      'messages_sent' => $welcomeResult['messages_sent']
    ];
  }

  private function loadProduct($productId) {
    return productHandler::getProductFile($productId);
  }

  private function registerStartSale($bot, $person, $product, $clientId, $saleId) {
    $message = 'Nueva venta iniciada: ' . $product['name'];
    $metadata = [
      'action' => 'start_sale',
      'sale_id' => $saleId,
      'product_id' => $product['id'],
      'product_name' => $product['name'],
      'price' => $product['price'],
      'description' => $product['description'] ?? '',
      'instructions' => $product['config']['prompt'] ?? ''
    ];

    chatHandlers::register(
      $bot['id'],
      $bot['number'],
      $clientId,
      $person['number'],
      $message,
      'S',
      'text',
      $metadata,
      $saleId
    );

    $chatData = [
      'number' => $person['number'],
      'bot_id' => $bot['id'],
      'bot_mode' => $bot['mode'],
      'client_id' => $clientId,
      'sale_id' => $saleId,
      'product_id' => $product['id'],
      'product_name' => $product['name'],
      'message' => $message,
      'format' => 'text',
      'metadata' => $metadata
    ];

    chatHandlers::addMessage($chatData, 'start_sale');
  }
}