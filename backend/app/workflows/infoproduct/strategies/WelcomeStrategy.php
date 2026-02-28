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
        return $this->handleReWelcome($bot, $person, $product, $existingChat, $rawContext);
      }

      // CASO 2: Mismo producto, no comprado, conversación < 48h → derivar a conversación activa
      $sameProductPending = $this->hasSameProductPending($existingChat, $productId);
      if ($sameProductPending && $this->isWithin48Hours($existingChat)) {
        ogLog::info("execute - CASO: Mismo producto pendiente < 48h, derivando a conversación activa", [
          'number' => $person['number'], 'product_id' => $productId
        ], $this->logMeta);
        return ['success' => true, 'redirected_to_conversation' => true];
      }

      // CASO 3: Producto diferente (o mismo producto pero conversación > 48h) → Nueva venta
      ogLog::info("execute - CASO: Nueva venta (cliente existente)", [ 'number' => $person['number'], 'new_product_id' => $productId, 'action' => 'crear_nueva_venta' ], $this->logMeta);
      return $this->handleNewProductWelcome($bot, $person, $product, $productId, $rawContext);
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

  private function handleReWelcome($bot, $person, $product, $existingChat, $rawContext = []) {
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

    // Abrir ventana +72h solo si viene de anuncio (Meta da 72h en ad-initiated)
    $reWelcomeClientId = $existingChat['client_id'] ?? null;
    if ($reWelcomeClientId) {
      $this->openChatWindow($reWelcomeClientId, $bot['id'], $bot['config'] ?? [], $rawContext);
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
      $this->registerStartSale($bot, $person, $product, $clientId, $saleId, $welcomeResult);
      ogLog::info("handleNewProductWelcome - Chats registrados (DB + JSON)", [ 'client_id' => $clientId, 'sale_id' => $saleId, 'product_id' => $productId ], $this->logMeta);
    }

    // Abrir ventana +72h solo si viene de anuncio (Meta da 72h en ad-initiated)
    if ($clientId) {
      $this->openChatWindow($clientId, $bot['id'], $bot['config'] ?? [], $rawContext);
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

  // Verificar si hay una venta del mismo producto sin confirmar
  private function hasSameProductPending($chat, $productId) {
    $messages = $chat['messages'] ?? [];
    foreach ($messages as $msg) {
      $meta = $msg['metadata'] ?? [];
      if (($meta['action'] ?? null) === 'start_sale' && (int)($meta['product_id'] ?? 0) === (int)$productId) {
        $saleId = $meta['sale_id'] ?? null;
        // Verificar que esa venta no está confirmada
        foreach ($messages as $checkMsg) {
          $checkMeta = $checkMsg['metadata'] ?? [];
          if (($checkMeta['action'] ?? null) === 'sale_confirmed' && ($checkMeta['sale_id'] ?? null) == $saleId) {
            return false; // Ya fue confirmada, no es pending
          }
        }
        return true;
      }
    }
    return false;
  }

  // Verificar si la conversación empezó hace menos de 48 horas
  private function isWithin48Hours($chat) {
    $started = $chat['conversation_started'] ?? null;
    if (!$started) return false;
    return (time() - strtotime($started)) < (48 * 3600);
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
  private function openChatWindow(int $clientId, int $botId, array $botConfig, array $rawContext = []) {
    $provider = $botConfig['apis']['chat'][0]['config']['type_value'] ?? null;
    if ($provider !== 'whatsapp-cloud-api') return;

    // Detectar origen: solo los anuncios (ad) abren ventana de 72h en Meta.
    // Un template/welcome orgánico NO abre ventana — la abre el primer mensaje del cliente.
    $origin = $this->detectOrigin($rawContext);
    // Ad → 72h (Meta política de anuncios), orgánico → 24h (cliente inició conversación)
    $hours = $origin === 'ad' ? 72 : 24;

    $nowTimestamp = time();
    $expiry = date('Y-m-d H:i:s', $nowTimestamp + ($hours * 3600));
    $now    = date('Y-m-d H:i:s', $nowTimestamp);

    $existingMeta = ogDb::raw(
      "SELECT meta_value FROM client_bot_meta WHERE client_id = ? AND bot_id = ? AND meta_key = 'open_chat' ORDER BY meta_value DESC LIMIT 1",
      [$clientId, $botId]
    );
    $existingExpiry = $existingMeta[0]['meta_value'] ?? null;

    if ($existingExpiry) {
      ogDb::raw(
        "UPDATE client_bot_meta SET meta_value = ?, tc = ? WHERE client_id = ? AND bot_id = ? AND meta_key = 'open_chat'",
        [$expiry, $nowTimestamp, $clientId, $botId]
      );
    } else {
      ogDb::raw(
        "INSERT INTO client_bot_meta (client_id, bot_id, meta_key, meta_value, dc, tc) VALUES (?, ?, 'open_chat', ?, ?, ?)",
        [$clientId, $botId, $expiry, $now, $nowTimestamp]
      );
    }

    ogLog::info("WelcomeStrategy::openChatWindow - Ventana abierta", [
      'client_id' => $clientId, 'bot_id' => $botId, 'origin' => $origin, 'hours' => $hours, 'expires_at' => $expiry
    ], $this->logMeta);
  }

  private function detectOrigin(array $context): string {
    if (($context['is_fb_ads'] ?? false) === true)    return 'ad';
    if (!empty($context['source_app']))               return 'ad';
    if (($context['source'] ?? null) === 'FB_Ads')    return 'ad';
    if (($context['type'] ?? null) === 'conversion')  return 'ad';
    return 'organic';
  }

  private function registerStartSale($bot, $person, $product, $clientId, $saleId, $welcomeResult = []) {
    ogApp()->loadHandler('chat');

    // Obtener origin de la venta desde BD
    $sale = ogDb::table('sales')->where('id', $saleId)->first();
    $origin = $sale['origin'] ?? 'organic';

    $message = 'Nueva venta iniciada: ' . $product['name'];
    $metadata = [
      'action'          => 'start_sale',
      'sale_id'         => $saleId,
      'product_id'      => $product['id'],
      'product_name'    => $product['name'],
      'price'           => $product['price'],
      'description'     => $product['description'] ?? '',
      'instructions'    => $product['config']['prompt'] ?? '',
      'origin'          => $origin,
      'msgs_total'      => $welcomeResult['total_messages']  ?? null,
      'msgs_sent'       => $welcomeResult['messages_sent']   ?? null,
      'msgs_failed'     => $welcomeResult['messages_failed'] ?? null,
      'msgs_failed_idx' => !empty($welcomeResult['failed_messages'])
                            ? array_column($welcomeResult['failed_messages'], 'index')
                            : [],
      'duration_s'      => $welcomeResult['duration_seconds'] ?? null
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