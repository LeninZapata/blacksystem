<?php
// Helper para detectar mensajes de bienvenida
class workflowWelcome {
  
  /**
   * Detectar si es un mensaje de bienvenida
   * Retorna: ['is_welcome' => bool, 'product_id' => int|null]
   */
  static function detect($bot, $message, $context) {
    // Validación rápida: si viene de FB Ads con sourceApp
    $isFBAds = $context['is_fb_ads'] ?? false;
    $hasSourceApp = !empty($context['ad_data']['source_app'] ?? null);
    
    if ($isFBAds && $hasSourceApp) {
      // Es claramente un lead de FB Ads
      $productId = self::detectProduct($bot, $message, $context);
      return [
        'is_welcome' => $productId !== null,
        'product_id' => $productId,
        'source' => 'fb_ads'
      ];
    }
    
    // Buscar producto en mensaje normal
    $productId = self::detectProduct($bot, $message, $context);
    
    return [
      'is_welcome' => $productId !== null,
      'product_id' => $productId,
      'source' => 'normal'
    ];
  }
  
  /**
   * Detectar producto basado en activators
   */
  static function detectProduct($bot, $message, $context) {
    $botNumber = $bot['number'] ?? null;
    
    if (!$botNumber) return null;
    
    // Obtener activators del bot
    $activators = ProductHandler::getActivatorsFile($botNumber);
    
    if (empty($activators)) return null;
    
    // Extraer campos donde buscar (orden de prioridad)
    $fields = self::extractSearchFields($message, $context);
    
    // Buscar en activators
    foreach ($activators as $productId => $triggers) {
      if (empty($triggers)) continue;
      
      foreach ($triggers as $trigger) {
        if (self::searchTrigger($trigger, $fields)) {
          return (int)$productId;
        }
      }
    }
    
    return null;
  }
  
  /**
   * Extraer campos donde buscar activators
   */
  private static function extractSearchFields($message, $context) {
    $fields = [];
    
    // Campo 1: Texto del mensaje
    if (!empty($message['text'])) {
      $fields[] = $message['text'];
    }
    
    // Campo 2: Body del anuncio (FB Ads)
    if (!empty($context['ad_data']['body'])) {
      $fields[] = $context['ad_data']['body'];
    }
    
    // Campo 3: Source URL (FB Ads)
    if (!empty($context['ad_data']['source_url'])) {
      $fields[] = $context['ad_data']['source_url'];
    }
    
    return $fields;
  }
  
  /**
   * Buscar trigger en los campos
   * Formato trigger: "palabra1,palabra2" o "palabra_sola"
   */
  private static function searchTrigger($trigger, $fields) {
    if (empty($trigger) || empty($fields)) return false;
    
    // Dividir trigger por comas
    $parts = array_map('trim', explode(',', $trigger));
    
    // Buscar cada parte del trigger
    foreach ($parts as $part) {
      if (empty($part)) continue;
      
      // Buscar parte en todos los campos
      if (self::searchInFields($part, $fields)) {
        return true; // Basta con que UNA parte coincida
      }
    }
    
    return false;
  }
  
  /**
   * Buscar término en todos los campos
   */
  private static function searchInFields($needle, $fields) {
    foreach ($fields as $field) {
      if (str::containsAllWords($needle, $field)) {
        return true;
      }
    }
    
    return false;
  }

  // Enviar mensajes de bienvenida con delays y presence
  // Retorna: ['success' => bool, 'total_messages' => int, 'messages_sent' => int, 'client_id' => int|null, 'sale_id' => int|null, 'error' => string|null]
  static function sendMessages($dataSale = null) {
    $person = $dataSale['person'];
    $productId = $dataSale['product_id'];
    $bot = $dataSale['bot'];

    $from = $person['number'];
    $name = $person['name'];
    $logMetada = [ 'module' => 'whatsapp_message_received', 'tags' => ['workflow', $from] ];

    // Obtener producto
    $product = ProductHandler::getProductFile($productId);
    if (!$product) {
      return [
        'success' => false,
        'total_messages' => 0,
        'messages_sent' => 0,
        'client_id' => null,
        'sale_id' => null,
        'error' => 'Producto no encontrado'
      ];
    }

    // Mas contexto de informacion de la venta
    $dataSale['product'] = $product;

    // Obtener mensajes de bienvenida
    $messages = ProductHandler::getMessagesFile('welcome', $productId);
    if (!$messages || empty($messages)) {
      return [
        'success' => false,
        'total_messages' => 0,
        'messages_sent' => 0,
        'client_id' => null,
        'sale_id' => null,
        'error' => 'Mensajes de bienvenida no encontrados'
      ];
    }

    $totalMessages = count($messages);
    $messagesSent = 0;
    $clientId = null;
    $saleId = null;

    foreach ($messages as $index => $msg) {
      $delay = isset($msg['delay']) ? (int)$msg['delay'] : 3;
      $text = $msg['message'] ?? '';
      // $type = $msg['type'] ?? 'text';
      $url = !empty($msg['url']) && $msg['type'] != 'text' ? $msg['url'] : '';

      // Si no es el primer mensaje, aplicar delay con presence
      if ($index > 0 && $delay > 0) {
        $iterations = ceil($delay / 3);

        for ($i = 0; $i < $iterations; $i++) {
          $remaining = $delay - ($i * 3);
          $duration = min($remaining, 3);
          $durationMs = $duration * 1000;

          try {
            chatapi::sendPresence($from, 'composing', $durationMs);
          } catch (Exception $e) {
            log::error("Error enviando presence", [ 'error' => $e->getMessage(), 'from' => $from ], $logMetada);
            sleep($duration); continue;
          }
        }
      }

      $result = chatapi::send($from, $text, $url);
      //$result['success'] = true; // Simular envío exitoso
      $messagesSent++;
      log::info("Mensaje de bienvenida {$messagesSent} enviado", $result, $logMetada);

      // Registrar venta (y cliente) solo después del primer mensaje exitoso
      if ($messagesSent === 1 && $result['success']) {
        require_once APP_PATH . '/workflows/helpers/conversation/sale.php';
        $saleResult = workflowSale::create($dataSale);

        if ($saleResult['success']) {
          $clientId = $saleResult['client_id'];
          $saleId = $saleResult['sale_id'];
          log::info("Venta creada", [ 'sale_id' => $saleId, 'client_id' => $clientId ], $logMetada);

          // Agregar mensaje de venta iniciada al chat JSON
          require_once APP_PATH . '/workflows/helpers/conversation/message.php';
          workflowMessage::addStartSaleMessage([
            'bot' => $bot,
            'person' => $person,
            'product' => $product,
            'client_id' => $clientId,
            'sale_id' => $saleId
          ]);
        } else {
          log::error("Error creando venta", [ 'error' => $saleResult['error'] ?? 'Unknown', 'message' => $saleResult['message'] ], $logMetada);
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