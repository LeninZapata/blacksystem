<?php

class WelcomeStrategy implements ConversationStrategyInterface {
  
  /**
   * FLUJO DE BIENVENIDAS Y PRODUCTOS
   * 
   * CASO 1: Dentro de 2 días + NO es welcome
   *   → continueConversation() → IA maneja todo
   *   → NO se ejecuta WelcomeStrategy
   * 
   * CASO 2: Dentro de 2 días + SÍ es welcome (nuevo producto)
   *   → executeWelcome() → handleNewProductWelcome()
   *   → Envía bienvenida + CREA NUEVA VENTA
   *   → Ahora tiene 2 ventas activas (producto A y B)
   *   → current_sale se actualiza al nuevo producto B
   * 
   * CASO 3: Después de 2 días + MISMO producto
   *   → executeWelcome() → hasAlreadyPurchased(producto X) = TRUE
   *   → handleReWelcome() → Envía bienvenida SIN crear venta
   *   → Registra: type='S', action='re_welcome', sale_id=0
   * 
   * CASO 4: Después de 2 días + PRODUCTO DIFERENTE
   *   → executeWelcome() → hasAlreadyPurchased(producto Y) = FALSE
   *   → handleNewProductWelcome() → Envía bienvenida + CREA NUEVA VENTA
   *   → Registra: type='S', action='start_sale', sale_id=NEW
   * 
   * VALIDACIÓN:
   *   - Por bot_id + product_id (un número puede tener múltiples productos)
   *   - Si tiene producto A y B pendientes, el bot ve ambos en historial
   *   - current_sale siempre apunta a la última venta iniciada
   *   - El bot debe atender según el contexto del último mensaje
   *   - PromptBuilder incluye TODAS las ventas en "PRODUCTOS EN CONVERSACIÓN"
   */
  
  public function execute(array $context): array {   
    $bot = $context['bot'];
    $person = $context['person'];
    $productId = $context['product_id'];
    $rawContext = $context['context'] ?? [];

    // Cargar ProductHandler bajo demanda
    ogApp()->loadHandler('ProductHandler');
    
    $product = $this->loadProduct($productId);
    if (!$product) return ['success' => false, 'error' => 'Producto no encontrado'];

    // ✅ Detectar si ya existe conversación previa (sin límite de días)
    $existingChat = $this->checkExistingConversation($person['number'], $bot['id']);

    if ($existingChat) {
      // Ya tiene conversación previa
      $alreadyPurchased = $this->hasAlreadyPurchased($existingChat, $productId);

      if ($alreadyPurchased) {
        // CASO 1: Mismo producto ya comprado → Re-welcome (sin venta)
        ogLog::info("WelcomeStrategy - CASO: Re-welcome (producto ya comprado)", [
          'number' => $person['number'], 
          'product_id' => $productId,
          'action' => 'enviar_bienvenida_sin_crear_venta'
        ], ['module' => 'welcome_strategy']);
        
        return $this->handleReWelcome($bot, $person, $product, $existingChat);
      } else {
        // CASO 2: Producto diferente → Nueva venta
        ogLog::info("WelcomeStrategy - CASO: Producto diferente (cliente existente)", [
          'number' => $person['number'],
          'new_product_id' => $productId,
          'previous_products' => $existingChat['summary']['purchased_products'] ?? [],
          'action' => 'crear_nueva_venta'
        ], ['module' => 'welcome_strategy']);
        
        return $this->handleNewProductWelcome($bot, $person, $product, $productId, $rawContext);
      }
    }

    // CASO 3: Cliente completamente nuevo → Nueva venta
    ogLog::info("WelcomeStrategy - CASO: Cliente nuevo", [
      'number' => $person['number'],
      'product_id' => $productId,
      'action' => 'crear_primera_venta'
    ], ['module' => 'welcome_strategy']);
    
    return $this->handleNewProductWelcome($bot, $person, $product, $productId, $rawContext);
  }

  // Verificar si existe conversación previa (sin límite de días)
  private function checkExistingConversation($number, $botId) {
    $chatFile = CHATS_STORAGE_PATH . '/chat_' . $number . '_bot_' . $botId . '.json';

    if (!file_exists($chatFile)) {
      return null;
    }

    $content = file_get_contents($chatFile);
    $chat = json_decode($content, true);

    return $chat;
  }

  // Verificar si ya compró ESTE producto específico - Busca en mensajes con action='sale_confirmed' que tengan este product_id
  private function hasAlreadyPurchased($chat, $productId) {
    $messages = $chat['messages'] ?? [];

    // Buscar sale_confirmed de este producto específico
    foreach ($messages as $msg) {
      $metadata = $msg['metadata'] ?? [];
      $action = $metadata['action'] ?? null;

      if ($action === 'sale_confirmed') {
        $saleId = $metadata['sale_id'] ?? null;
        
        if ($saleId) {
          // Buscar el start_sale asociado a esta venta confirmada
          foreach ($messages as $startMsg) {
            $startMeta = $startMsg['metadata'] ?? [];
            
            if (
              ($startMeta['action'] ?? null) === 'start_sale' &&
              ($startMeta['sale_id'] ?? null) == $saleId &&
              ($startMeta['product_id'] ?? null) == $productId
            ) {
              ogLog::debug("WelcomeStrategy - Producto ya comprado detectado", [
                'product_id' => $productId,
                'sale_id' => $saleId
              ], ['module' => 'welcome_strategy']);
              
              return true; // Ya compró ESTE producto
            }
          }
        }
      }
    }

    return false; // NO ha comprado este producto
  }

  // Manejar re-welcome (mismo producto ya comprado) * Solo envía mensajes + registra en chat, NO crea venta
  private function handleReWelcome($bot, $person, $product, $existingChat) {
    ogLog::info("WelcomeStrategy::handleReWelcome - INICIO", ['product_id' => $product['id']], ['module' => 'welcome_strategy']);

    // Cargar ProductHandler bajo demanda
    ogApp()->loadHandler('ProductHandler');
    
    // 1. Enviar mensajes de bienvenida
    $messages = ProductHandler::getMessagesFile('welcome', $product['id']);

    if (!$messages || empty($messages)) {
      return [
        'success' => false,
        'error' => 'Mensajes de bienvenida no encontrados'
      ];
    }

    // Cargar servicio chatapi bajo demanda
    $chatapi = ogApp()->service('chatapi');

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
            $chatapi->sendPresence($person['number'], 'composing', $durationMs);
          } catch (Exception $e) {
            sleep($duration);
            continue;
          }
        }
      }

      $chatapi->send($person['number'], $text, $url);
      $messagesSent++;
    }

    // Cargar ChatHandlers bajo demanda
    ogApp()->loadHandler('ChatHandlers');

    // 2. Registrar mensaje de sistema en BD
    $systemMessage = "Bienvenida repetida enviada: {$product['name']} (ya comprado anteriormente)";
    $metadata = [
      'action' => 're_welcome',
      'product_id' => $product['id'],
      'product_name' => $product['name'],
      'reason' => 'producto_ya_comprado',
      'messages_sent' => $messagesSent
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
      0
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

    // ✅ 3. Reconstruir chat JSON para actualizar cabecera
    ChatHandlers::rebuildFromDB($person['number'], $bot['id']);

    ogLog::info("WelcomeStrategy::handleReWelcome - Completado", ['messages_sent' => $messagesSent, 'chat_rebuilt' => true], ['module' => 'welcome_strategy']);

    return [
      'success' => true,
      'client_id' => $existingChat['client_id'],
      'sale_id' => null,
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

    // Obtener path dinámico y cargar actions
    $appPath = ogApp()->getPath();
    require_once $appPath . '/workflows/infoproduct/actions/CreateSaleAction.php';
    require_once $appPath . '/workflows/infoproduct/actions/SendWelcomeAction.php';

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

    // Cargar ChatHandlers bajo demanda
    ogApp()->loadHandler('ChatHandlers');

    // ✅ Reconstruir chat JSON para actualizar cabecera (current_sale, summary, etc)
    ChatHandlers::rebuildFromDB($person['number'], $bot['id']);

    ogLog::info("WelcomeStrategy::handleNewProductWelcome - Completado", [
      'sale_id' => $saleId,
      'messages_sent' => $welcomeResult['messages_sent'],
      'chat_rebuilt' => true
    ], ['module' => 'welcome_strategy']);

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
    // Cargar ChatHandlers bajo demanda
    ogApp()->loadHandler('ChatHandlers');
    
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