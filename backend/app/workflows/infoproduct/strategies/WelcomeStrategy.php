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

    // ✅ NUEVO: Detectar si ya existe conversación previa (sin límite de días)
    $existingChat = $this->checkExistingConversation($person['number'], $bot['id']);

    if ($existingChat) {
      // ✅ Ya tiene conversación previa (puede ser mismo producto u otro)
      $alreadyPurchased = $this->hasAlreadyPurchased($existingChat, $productId);

      if ($alreadyPurchased) {
        log::info("WelcomeStrategy - Re-welcome detectado (mismo producto ya comprado)", [
          'number' => $person['number'],
          'product_id' => $productId,
          'action' => 'enviar_bienvenida_sin_crear_venta'
        ], ['module' => 'welcome_strategy']);

        // Enviar mensajes de bienvenida pero NO crear venta
        return $this->handleReWelcome($bot, $person, $product, $existingChat);
      } else {
        log::info("WelcomeStrategy - Nuevo producto para cliente existente", [
          'number' => $person['number'],
          'product_id' => $productId,
          'previous_products' => $existingChat['summary']['purchased_products'] ?? []
        ], ['module' => 'welcome_strategy']);

        // Producto diferente, crear venta normal
        return $this->handleNewProductWelcome($bot, $person, $product, $productId, $rawContext);
      }
    }

    // ✅ Cliente completamente nuevo
    log::info("WelcomeStrategy - Cliente nuevo", [
      'number' => $person['number'],
      'product_id' => $productId
    ], ['module' => 'welcome_strategy']);

    return $this->handleNewProductWelcome($bot, $person, $product, $productId, $rawContext);
  }

  /**
   * ✅ NUEVO: Verificar si existe conversación previa (sin límite de días)
   */
  private function checkExistingConversation($number, $botId) {
    $chatFile = SHARED_PATH . '/chats/infoproduct/chat_' . $number . '_bot_' . $botId . '.json';

    if (!file_exists($chatFile)) {
      return null;
    }

    $content = file_get_contents($chatFile);
    $chat = json_decode($content, true);

    return $chat;
  }

  /**
   * ✅ NUEVO: Verificar si ya compró este producto
   */
  private function hasAlreadyPurchased($chat, $productId) {
    $purchasedProducts = $chat['summary']['purchased_products'] ?? [];
    $messages = $chat['messages'] ?? [];

    // Método 1: Buscar en summary
    foreach ($messages as $msg) {
      $metadata = $msg['metadata'] ?? [];
      $action = $metadata['action'] ?? null;

      if ($action === 'sale_confirmed') {
        // Buscar el start_sale asociado
        $saleId = $metadata['sale_id'] ?? null;
        
        if ($saleId) {
          foreach ($messages as $startMsg) {
            $startMeta = $startMsg['metadata'] ?? [];
            if (
              ($startMeta['action'] ?? null) === 'start_sale' &&
              ($startMeta['sale_id'] ?? null) == $saleId &&
              ($startMeta['product_id'] ?? null) == $productId
            ) {
              return true; // Ya compró este producto
            }
          }
        }
      }
    }

    return false;
  }

  /**
   * ✅ NUEVO: Manejar re-welcome (mismo producto ya comprado)
   * Solo envía mensajes + registra en chat, NO crea venta
   */
  private function handleReWelcome($bot, $person, $product, $existingChat) {
    log::info("WelcomeStrategy::handleReWelcome - INICIO", [
      'product_id' => $product['id']
    ], ['module' => 'welcome_strategy']);

    // 1. Enviar mensajes de bienvenida
    $messages = ProductHandler::getMessagesFile('welcome', $product['id']);

    if (!$messages || empty($messages)) {
      return [
        'success' => false,
        'error' => 'Mensajes de bienvenida no encontrados'
      ];
    }

    $messagesSent = 0;
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
            chatapi::sendPresence($person['number'], 'composing', $durationMs);
          } catch (Exception $e) {
            sleep($duration);
            continue;
          }
        }
      }

      chatapi::send($person['number'], $text, $url);
      $messagesSent++;
    }

    // 2. Registrar mensaje de sistema (NO start_sale)
    $systemMessage = "Bienvenida repetida enviada: {$product['name']} (ya comprado anteriormente)";
    $metadata = [
      'action' => 're_welcome',
      'product_id' => $product['id'],
      'product_name' => $product['name'],
      'reason' => 'producto_ya_comprado'
    ];

    ChatHandlers::register(
      $bot['id'],
      $bot['number'],
      $existingChat['client_id'],
      $person['number'],
      $systemMessage,
      'S',
      'text',
      $metadata,
      0 // sale_id = 0 (no hay venta nueva)
    );

    ChatHandlers::addMessage([
      'number' => $person['number'],
      'bot_id' => $bot['id'],
      'client_id' => $existingChat['client_id'],
      'sale_id' => 0,
      'message' => $systemMessage,
      'format' => 'text',
      'metadata' => $metadata
    ], 'S');

    log::info("WelcomeStrategy::handleReWelcome - Completado", [
      'messages_sent' => $messagesSent
    ], ['module' => 'welcome_strategy']);

    return [
      'success' => true,
      'client_id' => $existingChat['client_id'],
      'sale_id' => null, // No se crea venta nueva
      'messages_sent' => $messagesSent,
      're_welcome' => true
    ];
  }

  /**
   * ✅ Manejar welcome normal (producto nuevo o cliente nuevo)
   */
  private function handleNewProductWelcome($bot, $person, $product, $productId, $rawContext) {
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
    return ProductHandler::getProductFile($productId);
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

    ChatHandlers::register(
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

    ChatHandlers::addMessage($chatData, 'start_sale');
  }
}