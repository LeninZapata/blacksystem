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
  static function register($botId, $botNumber, $clientId, $clientNumber, $message, $type, $format, $metadata = null, $saleId = 0, $skipUnreadCount = false) {
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
        'dc' => gmdate('Y-m-d H:i:s'),
        'tc' => time()
      ];

      $chatId = ogDb::t('chats')->insert($data);

      // Actualizar actividad del cliente
      $now = gmdate('Y-m-d H:i:s');
      $clientUpdate = ['last_message_at' => $now];
      if ($type === 'P') {
        $clientUpdate['last_client_message_at'] = $now;
        if ($skipUnreadCount) {
          // Solo actualizar timestamps, sin incrementar unread_count
          ogDb::raw(
            "UPDATE " . ogDb::t('clients', true) . " SET last_client_message_at = ?, last_message_at = ? WHERE id = ?",
            [$now, $now, $clientId]
          );
        } else {
          ogDb::raw(
            "UPDATE " . ogDb::t('clients', true) . " SET unread_count = unread_count + 1, last_client_message_at = ?, last_message_at = ? WHERE id = ?",
            [$now, $now, $clientId]
          );
        }

        // Refrescar ventana de conversación: cliente responde → +24h solo si expiró
        // (solo WhatsApp Cloud API — Evolution API no tiene restricción de ventana)
        $currentBot = ogApp()->helper('cache')::memoryGet('current_bot');
        $botConfig  = $currentBot['config'] ?? [];

        // Incrementar unread_count en client_bot_meta (lo que lee el badge del chat)
        if (!$skipUnreadCount) {
          self::upsertBotMetaIncrement($clientId, $botId, 'unread_count');
        }

        if (($botConfig['apis']['chat'][0]['config']['type_value'] ?? null) === 'whatsapp-cloud-api') {
          $appPath = ogApp()->getPath();
          require_once $appPath . '/workflows/infoproduct/strategies/ChatWindowStrategy.php';
          ChatWindowStrategy::refresh($clientId, $botId);
        }
      } else {
        ogDb::raw(
          "UPDATE " . ogDb::t('clients', true) . " SET last_message_at = ? WHERE id = ?",
          [$now, $clientId]
        );
        // Actualizar last_message_at e incrementar unread_count en client_bot_meta (B y S)
        self::upsertBotMeta($clientId, $botId, 'last_message_at', $now);
        if (!$skipUnreadCount) {
          self::upsertBotMetaIncrement($clientId, $botId, 'unread_count');
        }
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
        'conversation_started' => gmdate('Y-m-d H:i:s'),
        'last_activity' => gmdate('Y-m-d H:i:s'),
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
    $chat['last_activity'] = gmdate('Y-m-d H:i:s');

    // Manejar tipo especial 'start_sale'
    if ($type === 'start_sale') {
      $productId = $data['product_id'] ?? null;
      $productName = $data['product_name'] ?? '';

      $chat['current_sale'] = [
        'sale_id' => $saleId,
        'product_id' => $productId,
        'product_name' => $productName,
        'sale_status' => 'initiated',
        'started_at' => gmdate('Y-m-d H:i:s'),
        'origin' => $metadata['origin'] ?? 'organic'
      ];

      if ($productId && !in_array($productId, $chat['summary']['sales_initiated'])) {
        $chat['summary']['sales_initiated'][] = $productId;
      }

      $type = 'S'; // Sistema
    }

    // Agregar mensaje al array
    $newMessage = [
      'date' => gmdate('Y-m-d H:i:s'),
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

      // Fuente de verdad: obtener process_status real de la tabla sales para este cliente/bot
      // Esto evita que cambios manuales de estado desde el admin queden desincronizados con el JSON
      $saleIdsInChat = [];
      foreach ($messages as $msg) {
        $m = !empty($msg['metadata']) ? json_decode($msg['metadata'], true) : null;
        if (($m['action'] ?? null) === 'start_sale' && !empty($m['sale_id'])) {
          $saleIdsInChat[] = (int)$m['sale_id'];
        }
      }
      $confirmedSaleIds = [];
      if (!empty($saleIdsInChat)) {
        $placeholders = implode(',', array_fill(0, count($saleIdsInChat), '?'));
        $salesRows = ogDb::raw(
          "SELECT id, process_status FROM " . ogDb::t('sales', true) . " WHERE id IN ($placeholders)",
          $saleIdsInChat
        );
        foreach ($salesRows ?: [] as $row) {
          if (($row['process_status'] ?? '') === 'sale_confirmed') {
            $confirmedSaleIds[] = (int)$row['id'];
          }
        }
      }

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
            // Usar process_status real de la tabla sales (no mensajes de chat)
            $isConfirmed = in_array((int)$saleId, $confirmedSaleIds);

            // Solo activar current_sale si la venta NO está confirmada en DB
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

          // Solo agregar al summary si la venta sigue confirmada en DB
          if ($saleId && in_array((int)$saleId, $confirmedSaleIds)) {
            if (!in_array($saleId, $chat['summary']['sales_confirmed'])) {
              $chat['summary']['sales_confirmed'][] = $saleId;
            }
            if ($productId && !in_array($productId, $chat['summary']['purchased_products'])) {
              $chat['summary']['purchased_products'][] = $productId;
            }
            // Limpiar current_sale si coincide
            if ($chat['current_sale'] && $chat['current_sale']['sale_id'] == $saleId) {
              $chat['current_sale'] = null;
            }
          }
        }
      }

      $chat['summary']['total_messages'] = count($chat['messages']);

      // Asegurar que ventas confirmadas en DB queden reflejadas en el summary aunque
      // no exista mensaje sale_confirmed en chats (ej: confirmación manual desde el admin)
      foreach ($confirmedSaleIds as $cSaleId) {
        if (!in_array((string)$cSaleId, array_map('strval', $chat['summary']['sales_confirmed']))) {
          $chat['summary']['sales_confirmed'][] = (string)$cSaleId;
        }
        // Limpiar current_sale si apunta a esta venta
        if ($chat['current_sale'] && (int)$chat['current_sale']['sale_id'] === $cSaleId) {
          $chat['current_sale'] = null;
        }
        // Buscar product_id en los mensajes start_sale para actualizar purchased_products
        foreach ($chat['messages'] as $msg) {
          $m = $msg['metadata'] ?? null;
          if (($m['action'] ?? null) === 'start_sale' && (int)($m['sale_id'] ?? 0) === $cSaleId) {
            $pId = $m['product_id'] ?? null;
            if ($pId && !in_array($pId, $chat['summary']['purchased_products'])) {
              $chat['summary']['purchased_products'][] = $pId;
            }
            break;
          }
        }
      }

      // Cargar plantillas quick_reply del producto en venta actual
      // Se almacenan en el JSON del chat para que el handler las lea sin I/O extra
      $qrProductId = $chat['current_sale']['product_id'] ?? null;
      $chat['summary']['quick_reply_templates'] = [];
      if ($qrProductId) {
        try {
          ogApp()->loadHandler('product');
          $rawTemplates = ProductHandler::getMessagesFile('template', $qrProductId) ?? [];
          $appPath = ogApp()->getPath();
          require_once $appPath . '/workflows/infoproduct/handlers/QuickReplyHandler.php';
          $chat['summary']['quick_reply_templates'] = QuickReplyHandler::buildFromTemplates($rawTemplates);
        } catch (Exception $e) {
          ogLog::warning('rebuildFromDB - No se pudieron cargar quick_reply_templates', [
            'product_id' => $qrProductId,
            'error'      => $e->getMessage()
          ], self::$logMeta);
        }
      }

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

  // Inicializar unread_count = 0 en client_bot_meta (si no existe aún)
  // Se llama durante el welcome para asegurar que el registro exista desde el inicio
  static function initUnreadCount($clientId, $botId) {
    $existing = ogDb::raw(
      "SELECT id FROM client_bot_meta WHERE client_id = ? AND bot_id = ? AND meta_key = 'unread_count'",
      [$clientId, $botId]
    );
    if (empty($existing[0]['id'])) {
      $now = gmdate('Y-m-d H:i:s');
      ogDb::raw(
        "INSERT INTO client_bot_meta (client_id, bot_id, meta_key, meta_value, dc, tc) VALUES (?, ?, 'unread_count', 0, ?, ?)",
        [$clientId, $botId, $now, time()]
      );
    }
  }

  // Upsert simple de un meta key en client_bot_meta
  private static function upsertBotMeta($clientId, $botId, $key, $value) {
    $existing = ogDb::raw(
      "SELECT id FROM client_bot_meta WHERE client_id = ? AND bot_id = ? AND meta_key = ?",
      [$clientId, $botId, $key]
    );
    $now = gmdate('Y-m-d H:i:s');
    if (!empty($existing[0]['id'])) {
      ogDb::raw(
        "UPDATE client_bot_meta SET meta_value = ?, tc = ? WHERE id = ?",
        [$value, time(), $existing[0]['id']]
      );
    } else {
      ogDb::raw(
        "INSERT INTO client_bot_meta (client_id, bot_id, meta_key, meta_value, dc, tc) VALUES (?, ?, ?, ?, ?, ?)",
        [$clientId, $botId, $key, $value, $now, time()]
      );
    }
  }

  // Incrementar un meta numérico en client_bot_meta (INSERT si no existe con valor 1)
  private static function upsertBotMetaIncrement($clientId, $botId, $key) {
    $existing = ogDb::raw(
      "SELECT id, meta_value FROM client_bot_meta WHERE client_id = ? AND bot_id = ? AND meta_key = ?",
      [$clientId, $botId, $key]
    );
    $now = gmdate('Y-m-d H:i:s');
    if (!empty($existing[0]['id'])) {
      ogDb::raw(
        "UPDATE client_bot_meta SET meta_value = meta_value + 1, tc = ? WHERE id = ?",
        [time(), $existing[0]['id']]
      );
    } else {
      ogDb::raw(
        "INSERT INTO client_bot_meta (client_id, bot_id, meta_key, meta_value, dc, tc) VALUES (?, ?, ?, 1, ?, ?)",
        [$clientId, $botId, $key, $now, time()]
      );
    }
  }

}