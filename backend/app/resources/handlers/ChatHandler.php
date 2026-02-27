<?php
class ChatHandler {
  private static $logMeta = ['module' => 'ChatHandler', 'layer' => 'app/resources'];

  // Variable estática para almacenar el user_id globalmente
  private static $currentUserId = null;

  // Establecer el user_id global para la sesión actual
  // Debe llamarse al inicio del flujo (desde WebhookController o infoproduct-v2)
  static function setUserId($userId) {
    self::$currentUserId = $userId;
  }

  // Obtener el user_id actual
  static function getUserId() {
    return self::$currentUserId;
  }

  // Obtener user_id desde el bot_id (fallback si no está seteado)
  private static function resolveUserId($botId) {
    // Prioridad 1: user_id seteado globalmente
    if (self::$currentUserId !== null) {
      return self::$currentUserId;
    }

    // Prioridad 2: Obtener desde la tabla bots
    try {
      $bot = ogDb::t('bots')->where('id', $botId)->first();

      if ($bot && isset($bot['user_id'])) {
        ogLog::info("ChatHandler - user_id obtenido desde bot", [ 'bot_id' => $botId, 'user_id' => $bot['user_id'] ], self::$logMeta);

        // Guardarlo para próximas llamadas
        self::$currentUserId = $bot['user_id'];
        return $bot['user_id'];
      }
    } catch (Exception $e) {
      ogLog::error("ChatHandler - Error obteniendo user_id desde bot", [ 'bot_id' => $botId, 'error' => $e->getMessage() ], self::$logMeta);
    }

    // Si no se puede resolver, lanzar error
    ogLog::throwError("ChatHandler - No se pudo resolver user_id", [ 'bot_id' => $botId ], self::$logMeta);
  }

  // Registrar mensaje en la base de datos
  static function register($botId, $botNumber, $clientId, $clientNumber, $message, $type, $format, $metadata = null, $saleId = 0) {
    try {
      // Resolver user_id automáticamente
      $userId = self::resolveUserId($botId);

      $data = [
        'user_id' => $userId, // SIEMPRE INCLUIDO
        'bot_id' => $botId,
        'bot_number' => $botNumber,
        'client_id' => $clientId,
        'client_number' => $clientNumber,
        'type' => $type,
        'format' => $format,
        'message' => $message,
        'metadata' => $metadata ? json_encode($metadata) : null,
        'status' => 1,
        'dc' => date('Y-m-d H:i:s'),
        'tc' => time()
      ];

      $chatId = ogDb::t('chats')->insert($data);

      // Actualizar actividad del cliente
      $now = date('Y-m-d H:i:s');
      $clientUpdate = ['last_message_at' => $now];
      if ($type === 'P') {
        $clientUpdate['last_client_message_at'] = $now;
        // Incrementar contador de no leídos
        ogDb::raw(
          "UPDATE " . ogDb::t('clients', true) . " SET unread_count = unread_count + 1, last_client_message_at = ?, last_message_at = ? WHERE id = ?",
          [$now, $now, $clientId]
        );

        // Refrescar ventana de conversación: cliente responde → +24h (solo WhatsApp Cloud API)
        $currentBot = ogApp()->helper('cache')::memoryGet('current_bot');
        $botChatProvider = $currentBot['config']['apis']['chat'][0]['config']['type_value'] ?? null;

        ogLog::info("ChatHandler::register - current_bot leído de memoria", [
          'current_bot_is_null' => $currentBot === null,
          'bot_chat_provider'   => $botChatProvider,
          'bot_id_in_memory'    => $currentBot['id'] ?? null,
          'has_config'          => isset($currentBot['config']),
          'has_apis'            => isset($currentBot['config']['apis']['chat'][0]),
        ], self::$logMeta);

        if ($botChatProvider === 'whatsapp-cloud-api') {
          $candidateExpiry = date('Y-m-d H:i:s', strtotime('+24 hours'));

          ogLog::info("ChatHandler::register - candidateExpiry calculado", [
            'now_date'         => date('Y-m-d H:i:s'),
            'now_time'         => time(),
            'timezone'         => date_default_timezone_get(),
            'candidate_expiry' => $candidateExpiry,
            'client_id'        => $clientId,
            'bot_id'           => $botId,
          ], self::$logMeta);

          // Consultar expiry existente para no reducir la ventana
          $existingMeta   = ogDb::raw(
            "SELECT meta_value FROM client_bot_meta WHERE client_id = ? AND bot_id = ? AND meta_key = 'open_chat' ORDER BY meta_value DESC LIMIT 1",
            [$clientId, $botId]
          );
          $existingExpiry = $existingMeta[0]['meta_value'] ?? null;

          // Actualizar solo si el candidato es MAYOR que el existente.
          // Esto preserva una ventana de +72h (ad) que supere al +24h del mensaje.
          $shouldUpdate = !$existingExpiry || strtotime($candidateExpiry) > strtotime($existingExpiry);

          if ($shouldUpdate) {
            if ($existingExpiry) {
              ogDb::raw(
                "UPDATE client_bot_meta SET meta_value = ?, tc = ? WHERE client_id = ? AND bot_id = ? AND meta_key = 'open_chat'",
                [$candidateExpiry, time(), $clientId, $botId]
              );
            } else {
              ogDb::raw(
                "INSERT INTO client_bot_meta (client_id, bot_id, meta_key, meta_value, dc, tc) VALUES (?, ?, 'open_chat', ?, ?, ?)",
                [$clientId, $botId, $candidateExpiry, $now, time()]
              );
            }
          }

          ogLog::info("ChatHandler::register - Ventana open_chat", [
            'client_id'       => $clientId,
            'bot_id'          => $botId,
            'existing_expiry' => $existingExpiry,
            'candidate_expiry'=> $candidateExpiry,
            'action'          => $shouldUpdate ? 'actualizada' : 'no_actualizada (existente es mayor)'
          ], self::$logMeta);
        }
      } else {
        ogDb::raw(
          "UPDATE " . ogDb::t('clients', true) . " SET last_message_at = ? WHERE id = ?",
          [$now, $clientId]
        );
      }

      return $chatId;

    } catch (Exception $e) {
      ogLog::error("ChatHandler::register - Error", [
        'error' => $e->getMessage(),
        'bot_id' => $botId
      ], self::$logMeta);

      throw $e;
    }
  }

  // Agregar mensaje al archivo JSON del chat
  // AHORA RESUELVE user_id AUTOMÁTICAMENTE
  static function addMessage($data, $type = 'P') {
    $number = $data['number'];
    $botId = $data['bot_id'];
    $clientId = $data['client_id'];
    $saleId = $data['sale_id'];
    $message = $data['message'];
    $format = $data['format'] ?? 'text';
    $metadata = $data['metadata'] ?? null;

    // Resolver user_id automáticamente
    $userId = self::resolveUserId($botId);

    $chatFile = ogApp()->getPath('storage/json/chats') . '/chat_' . $number . '_bot_' . $botId . '.json';
    $file = ogApp()->helper('file');

    $chat = $file->getJson($chatFile, function() use ($userId, $number, $botId, $clientId) {
      return [
        'user_id' => $userId, // INCLUIR EN NUEVA CONVERSACIÓN
        'client_id' => $clientId,
        'client_number' => $number,
        'bot_id' => $botId,
        'conversation_started' => date('Y-m-d H:i:s'),
        'last_activity' => date('Y-m-d H:i:s'),
        'current_sale' => null,
        'summary' => [
          'total_messages' => 0,
          'sales_initiated' => [],
          'sales_confirmed' => [],
          'purchased_products' => []
        ],
        'messages' => []
      ];
    });

    // Asegurar que el chat tenga user_id (para chats existentes)
    if (!isset($chat['user_id'])) {
      $chat['user_id'] = $userId;
    }

    // Actualizar última actividad
    $chat['last_activity'] = date('Y-m-d H:i:s');

    // Manejar tipo especial 'start_sale'
    if ($type === 'start_sale') {
      $productId = $data['product_id'] ?? null;
      $productName = $data['product_name'] ?? '';

      $chat['current_sale'] = [
        'sale_id' => $saleId,
        'product_id' => $productId,
        'product_name' => $productName,
        'sale_status' => 'initiated',
        'started_at' => date('Y-m-d H:i:s'),
        'origin' => $metadata['origin'] ?? 'organic'
      ];

      if ($productId && !in_array($productId, $chat['summary']['sales_initiated'])) {
        $chat['summary']['sales_initiated'][] = $productId;
      }

      $type = 'S'; // Sistema
    }

    // Agregar mensaje al array
    $newMessage = [
      'date' => date('Y-m-d H:i:s'),
      'type' => $type,
      'format' => $format,
      'message' => $message,
      'metadata' => $metadata
    ];

    $chat['messages'][] = $newMessage;
    $chat['summary']['total_messages'] = count($chat['messages']);

    // Guardar archivo actualizado
    $file->saveJson($chatFile, $chat, 'chat', 'update');

    return $chat;
  }

  
  // Reconstruir chat desde la base de datos
  // AHORA INCLUYE user_id EN LA CABECERA
  static function rebuildFromDB($number, $botId) {
    try {
      ogLog::info("rebuildFromDB - INICIO", [
        'number' => $number,
        'bot_id' => $botId
      ], self::$logMeta);

      // Obtener todos los mensajes de la BD
      $messages = ogDb::t('chats')
        ->where('client_number', $number)
        ->where('bot_id', $botId)
        ->where('status', 1)
        ->orderBy('dc', 'ASC')
        ->get();

      if (empty($messages)) {
        ogLog::info("rebuildFromDB - No hay mensajes para reconstruir", [
          'number' => $number,
          'bot_id' => $botId
        ], self::$logMeta);
        return null;
      }

      // OBTENER user_id DEL PRIMER MENSAJE
      $userId = $messages[0]['user_id'] ?? null;

      if (!$userId) {
        // Fallback: intentar resolver desde bot
        $userId = self::resolveUserId($botId);
      }

      ogLog::info("rebuildFromDB - user_id detectado", [
        'user_id' => $userId
      ], self::$logMeta);

      $clientId = $messages[0]['client_id'] ?? null;
      $conversationStarted = $messages[0]['dc'] ?? null;
      $lastActivity = end($messages)['dc'] ?? null;

      // Construir estructura del chat
      $chat = [
        'user_id' => $userId, // AGREGAR EN CABECERA
        'client_id' => $clientId,
        'client_number' => $number,
        'bot_id' => $botId,
        'conversation_started' => $conversationStarted,
        'last_activity' => $lastActivity,
        'current_sale' => null,
        'summary' => [
          'total_messages' => 0,
          'sales_initiated' => [],
          'sales_confirmed' => [],
          'purchased_products' => []
        ],
        'messages' => []
      ];

      // Procesar mensajes
      foreach ($messages as $msg) {
        $metadata = !empty($msg['metadata']) ? json_decode($msg['metadata'], true) : null;
        $action = $metadata['action'] ?? null;

        // Construir mensaje
        $chatMessage = [
          'date' => $msg['dc'],
          'type' => $msg['type'],
          'format' => $msg['format'],
          'message' => $msg['message'],
          'metadata' => $metadata
        ];

        $chat['messages'][] = $chatMessage;

        // Procesar acciones especiales
        if ($action === 'start_sale') {
          $saleId = $metadata['sale_id'] ?? null;
          $productId = $metadata['product_id'] ?? null;
          $productName = $metadata['product_name'] ?? '';

          if ($saleId) {
            // Verificar si esta venta fue confirmada
            $isConfirmed = false;
            foreach ($messages as $checkMsg) {
              $checkMeta = !empty($checkMsg['metadata']) ? json_decode($checkMsg['metadata'], true) : null;
              if (
                ($checkMeta['action'] ?? null) === 'sale_confirmed' &&
                ($checkMeta['sale_id'] ?? null) == $saleId
              ) {
                $isConfirmed = true;
                break;
              }
            }

            // Solo actualizar current_sale si NO está confirmada
            if (!$isConfirmed) {
              $chat['current_sale'] = [
                'sale_id' => $saleId,
                'product_id' => $productId,
                'product_name' => $productName,
                'sale_status' => 'initiated',
                'started_at' => $msg['dc'],
                'origin' => $metadata['origin'] ?? 'organic'
              ];
            }

            if ($productId && !in_array($productId, $chat['summary']['sales_initiated'])) {
              $chat['summary']['sales_initiated'][] = $productId;
            }
          }
        }

        if ($action === 'sale_confirmed') {
          $saleId = $metadata['sale_id'] ?? null;
          $productId = $metadata['product_id'] ?? null;

          if ($saleId && !in_array($saleId, $chat['summary']['sales_confirmed'])) {
            $chat['summary']['sales_confirmed'][] = $saleId;
          }

          if ($productId && !in_array($productId, $chat['summary']['purchased_products'])) {
            $chat['summary']['purchased_products'][] = $productId;
          }

          // Limpiar current_sale si es la venta actual
          if (
            $chat['current_sale'] &&
            $chat['current_sale']['sale_id'] == $saleId
          ) {
            $chat['current_sale'] = null;
          }
        }
      }

      $chat['summary']['total_messages'] = count($chat['messages']);

      // Guardar archivo reconstruido
      $chatFile = ogApp()->getPath('storage/json/chats') . '/chat_' . $number . '_bot_' . $botId . '.json';
      $file = ogApp()->helper('file');
      $file->saveJson($chatFile, $chat, 'chat', 'rebuild');

      ogLog::success("rebuildFromDB - Chat reconstruido exitosamente", [ 'number' => $number, 'bot_id' => $botId, 'user_id' => $userId, 'total_messages' => $chat['summary']['total_messages'], 'current_sale' => $chat['current_sale'] ? $chat['current_sale']['sale_id'] : null ], self::$logMeta);

      return $chat;

    } catch (Exception $e) {
      ogLog::error("rebuildFromDB - Error", [
        'number' => $number,
        'bot_id' => $botId,
        'error' => $e->getMessage()
      ], self::$logMeta);

      throw $e;
    }
  }

  // Obtener chat (desde JSON o reconstruir desde BD)
  static function getChat($number, $botId, $rebuildIfNeeded = true, $forceRebuild = false ) {

    if ($forceRebuild) {
      $rebuiltChat = self::rebuildFromDB($number, $botId);

      if ($rebuiltChat) {
        return $rebuiltChat;
      }
    }

    $chatFile = ogApp()->getPath('storage/json/chats') . '/chat_' . $number . '_bot_' . $botId . '.json';
    return ogApp()->helper('file')::getJson($chatFile, function() use ($number, $botId, $rebuildIfNeeded) {
      if ( $rebuildIfNeeded == false ) return null;
      $rebuiltChat = self::rebuildFromDB($number, $botId);
      if( $rebuiltChat ){
        $rebuiltChat['sale_id'] = $rebuiltChat['current_sale']['sale_id'] ?? 0;
      }
      return $rebuiltChat;
    });

    return null;
  }

}