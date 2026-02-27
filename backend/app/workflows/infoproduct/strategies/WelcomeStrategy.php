<?php

class WelcomeStrategy implements ConversationStrategyInterface {

  private $logMeta = ['module' => 'WelcomeStrategy', 'layer' => 'app/workflow'];

  public function execute(array $context): array {
    $bot = $context['bot'];
    $person = $context['person'];
    $productId = $context['product_id'];
    $rawContext = $context['context'] ?? [];

    $product = $this->loadProduct($productId);
    if (!$product) ogLog::throwError("execute - Producto no encontrado", ['product_id' => $productId], $this->logMeta );

    // Detectar si ya existe conversación previa (sin límite de días)
    $existingChat = $this->checkExistingConversation($person['number'], $bot['id']);
    ogLog::info("checkExistingConversation - Buscando conversación previa", $existingChat, $this->logMeta);

    if (! empty( $existingChat )) {
      // Ya tiene conversación previa
      ogLog::info("execute - Conversación previa detectada", null, $this->logMeta);
      $alreadyPurchased = $this->hasAlreadyPurchased($existingChat, $productId);

      if ($alreadyPurchased) {
        // CASO 1: Mismo producto ya comprado → Re-welcome (sin venta)
        ogLog::info("execute - CASO: Re-welcome (producto ya comprado)", [ 'action' => 'send_welcome_without_creating_sale' ], $this->logMeta);
        return $this->handleReWelcome($bot, $person, $product, $existingChat);

      } else {

        // CASO 2: Producto diferente → Nueva venta
        ogLog::info("execute - CASO: Producto diferente (cliente existente)", [ 'number' => $person['number'], 'new_product_id' => $productId, 'previous_products' => $existingChat['summary']['purchased_products'] ?? [], 'action' => 'crear_nueva_venta' ],  $this->logMeta);
        return $this->handleNewProductWelcome($bot, $person, $product, $productId, $rawContext);
      }
    }

    // CASO 3: Cliente completamente nuevo → Nueva venta
    ogLog::info("WelcomeStrategy - CASO: Cliente nuevo", [ 'number' => $person['number'], 'product_id' => $productId, 'action' => 'crear_primera_venta' ], $this->logMeta);
    return $this->handleNewProductWelcome($bot, $person, $product, $productId, $rawContext);
  }

  private function checkExistingConversation($number, $botId) {
    ogApp()->loadHandler('chat');
    return ChatHandler::getChat($number, $botId);
  }

  private function hasAlreadyPurchased($chat, $productId) {
    $messages = $chat['messages'] ?? [];

    foreach ($messages as $msg) {
      $metadata = $msg['metadata'] ?? [];
      $action = $metadata['action'] ?? null;

      if ($action === 'sale_confirmed') {
        $saleId = $metadata['sale_id'] ?? null;

        if ($saleId) {
          foreach ($messages as $startMsg) {
            $startMeta = $startMsg['metadata'] ?? [];

            if (
              ($startMeta['action'] ?? null) === 'start_sale' &&
              ($startMeta['sale_id'] ?? null) == $saleId &&
              ($startMeta['product_id'] ?? null) == $productId
            ) {
              ogLog::debug("hasAlreadyPurchased - Producto ya comprado detectado", [ 'product_id' => $productId, 'sale_id' => $saleId ], $this->logMeta);
              return true;
            }
          }
        }
      }
    }

    return false;
  }

  private function handleReWelcome($bot, $person, $product, $existingChat) {
    ogLog::info("handleReWelcome - INICIO", ['product_id' => $product['id']], $this->logMeta);

    ogApp()->loadHandler('product');
    $messages = ProductHandler::getMessagesFile('welcome', $product['id']);

    if (!$messages || empty($messages)) {
      ogLog::throwError("handleReWelcome - Mensajes de bienvenida no encontrados", ['product_id' => $product['id']], $this->logMeta);
    }

    $chatapi = ogApp()->service('chatApi');

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
            sleep($duration); continue;
          }
        }
      }

      $chatapi->send($person['number'], $text, $url);
      $messagesSent++;
    }

    ogApp()->loadHandler('chat');

    // ChatHandler ahora resuelve user_id automáticamente
    $systemMessage = "Bienvenida repetida enviada: {$product['name']} (ya comprado anteriormente)";
    $metadata = [
      'action' => 're_welcome',
      'product_id' => $product['id'],
      'product_name' => $product['name'],
      'reason' => 'product_already_purchased',
      'messages_sent' => $messagesSent
    ];

    ChatHandler::register(
      $bot['id'],
      $bot['number'],
      $existingChat['client_id'],
      $person['number'],
      $systemMessage,
      'S',
      'text',
      $metadata,
      0
      // NO PASAR user_id - ChatHandler lo resuelve automáticamente
    );

    ChatHandler::addMessage([
      'number' => $person['number'],
      'bot_id' => $bot['id'],
      'client_id' => $existingChat['client_id'],
      'sale_id' => 0,
      'message' => $systemMessage,
      'format' => 'text',
      'metadata' => $metadata
      // NO PASAR user_id - ChatHandler lo resuelve automáticamente
    ], 'S');

    ChatHandler::rebuildFromDB($person['number'], $bot['id']);

    // Abrir ventana de conversación +72h por re-welcome (solo WhatsApp Cloud API)
    $reWelcomeClientId = $existingChat['client_id'] ?? null;
    if ($reWelcomeClientId) {
      $this->openChatWindow($reWelcomeClientId, $bot['id'], $bot['config'] ?? []);
    }

    ogLog::info("handleReWelcome - Completado", ['messages_sent' => $messagesSent, 'chat_rebuilt' => true], $this->logMeta);

    return [
      'success' => true,
      'client_id' => $existingChat['client_id'],
      'sale_id' => null,
      'messages_sent' => $messagesSent,
      're_welcome' => true
    ];
  }

  private function handleNewProductWelcome($bot, $person, $product, $productId, $rawContext) {
    $dataSale = [ 'person' => $person, 'bot' => $bot, 'product' => $product, 'product_id' => $productId, 'context' => $rawContext ];

    $appPath = ogApp()->getPath();
    require_once $appPath . '/workflows/infoproduct/actions/CreateSaleAction.php';
    require_once $appPath . '/workflows/infoproduct/actions/SendWelcomeAction.php';

    $welcomeResult = SendWelcomeAction::send($dataSale);
    ogLog::info("Welcome result", $welcomeResult, $this->logMeta);
    if (!$welcomeResult['success']) {
      ogLog::error("handleNewProductWelcome - Error enviando bienvenida", [ 'product_id' => $productId, 'error' => $welcomeResult['error'] ?? 'Desconocido' ], $this->logMeta);
      return [ 'success' => false, 'error' => $welcomeResult['error'] ?? 'Error enviando bienvenida' ];
    }

    $clientId = $welcomeResult['client_id'];
    $saleId = $welcomeResult['sale_id'];

    if ($clientId && $saleId) {
      $this->registerStartSale($bot, $person, $product, $clientId, $saleId);
      ogLog::info("handleNewProductWelcome - Chats registrados (DB + JSON)", [ 'client_id' => $clientId, 'sale_id' => $saleId, 'product_id' => $productId ], $this->logMeta);
    }

    // Abrir ventana de conversación +72h (solo WhatsApp Cloud API)
    if ($clientId) {
      $this->openChatWindow($clientId, $bot['id'], $bot['config'] ?? []);
    }

    ogApp()->loadHandler('chat');
    ChatHandler::rebuildFromDB($person['number'], $bot['id']);
    ogLog::info("handleNewProductWelcome - Completado ➜ Chat reconstruido desde BD", [ 'number' => $person['number'], 'bot_id' => $bot['id'],  'sale_id' => $saleId, 'messages_sent' => $welcomeResult['messages_sent'], 'chat_rebuilt' => true ], $this->logMeta);

    return [
      'success' => true,
      'client_id' => $clientId,
      'sale_id' => $saleId,
      'messages_sent' => $welcomeResult['messages_sent']
    ];
  }

  private function loadProduct($productId) {
    ogApp()->loadHandler('product');
    return ProductHandler::getProductFile($productId);
  }

  /**
   * Abre/renueva la ventana de conversación gratuita en client_bot_meta.
   * Solo aplica para WhatsApp Cloud API (+72h desde bienvenida).
   * Evolution API no tiene límite de tiempo.
   */
  private function openChatWindow(int $clientId, int $botId, array $botConfig) {
    $provider = $botConfig['apis']['chat'][0]['config']['type_value'] ?? null;
    if ($provider !== 'whatsapp-cloud-api') return;

    $expiry = date('Y-m-d H:i:s', strtotime('+72 hours'));
    $now    = date('Y-m-d H:i:s');
    ogDb::raw(
      "INSERT INTO client_bot_meta (client_id, bot_id, meta_key, meta_value, dc, tc)
       VALUES (?, ?, 'open_chat', ?, ?, ?)
       ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), tc = VALUES(tc)",
      [$clientId, $botId, $expiry, $now, time()]
    );
    ogLog::info("WelcomeStrategy - Ventana open_chat abierta +72h", [
      'client_id' => $clientId, 'bot_id' => $botId, 'expires_at' => $expiry
    ], $this->logMeta);
  }

  private function registerStartSale($bot, $person, $product, $clientId, $saleId) {
    ogApp()->loadHandler('chat');

    // Obtener origin de la venta desde BD
    $sale = ogDb::table('sales')->where('id', $saleId)->first();
    $origin = $sale['origin'] ?? 'organic';

    $message = 'Nueva venta iniciada: ' . $product['name'];
    $metadata = [
      'action' => 'start_sale',
      'sale_id' => $saleId,
      'product_id' => $product['id'],
      'product_name' => $product['name'],
      'price' => $product['price'],
      'description' => $product['description'] ?? '',
      'instructions' => $product['config']['prompt'] ?? '',
      'origin' => $origin
    ];

    // ChatHandler ahora resuelve user_id automáticamente
    ChatHandler::register(
      $bot['id'],
      $bot['number'],
      $clientId,
      $person['number'],
      $message,
      'S',
      'text',
      $metadata,
      $saleId
      // NO PASAR user_id - ChatHandler lo resuelve automáticamente
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
      // NO PASAR user_id - ChatHandler lo resuelve automáticamente
    ];

    ChatHandler::addMessage($chatData, 'start_sale');
  }
}