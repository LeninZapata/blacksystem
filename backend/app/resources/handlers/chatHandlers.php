<?php
class ChatHandlers {

  private static $table = DB_TABLES['chats'];

  // Registrar mensaje de chat - Método simplificado
  static function register($botId, $botNumber, $clientId, $clientNumber, $message, $type = 'P', $format = 'text', $metadata = null, $saleId = 0) {
    $data = [
      'bot_id' => $botId,
      'bot_number' => $botNumber,
      'client_id' => $clientId,
      'client_number' => $clientNumber,
      'sale_id' => $saleId,
      'type' => $type,
      'format' => $format,
      'message' => $message,
      'metadata' => is_array($metadata) ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : $metadata,
      'dc' => date('Y-m-d H:i:s'),
      'tc' => time()
    ];

    try {
      $id = db::table(self::$table)->insert($data);
      return [ 'success' => true, 'chat_id' => $id, 'data' => array_merge($data, ['id' => $id])
      ];
    } catch (Exception $e) {
      return [
        'success' => false, 'error' => __('chat.create.error'), 'details' => IS_DEV ? $e->getMessage() : null
      ];
    }
  }

  // Obtener historial de chat por cliente
  static function getByClient($params) {
    $clientId = $params['client_id'];
    $limit = request::query('limit', 50);

    $chats = db::table(self::$table)
      ->where('client_id', $clientId)
      ->orderBy('dc', 'DESC')
      ->limit($limit)
      ->get();

    if (!is_array($chats)) $chats = [];

    foreach ($chats as &$chat) {
      if (isset($chat['metadata']) && is_string($chat['metadata'])) {
        $chat['metadata'] = json_decode($chat['metadata'], true);
      }
    }

    return ['success' => true, 'data' => $chats];
  }

  // Obtener historial de chat por bot
  static function getByBot($params) {
    $botId = $params['bot_id'];
    $limit = request::query('limit', 50);

    $chats = db::table(self::$table)
      ->where('bot_id', $botId)
      ->orderBy('dc', 'DESC')
      ->limit($limit)
      ->get();

    if (!is_array($chats)) $chats = [];

    foreach ($chats as &$chat) {
      if (isset($chat['metadata']) && is_string($chat['metadata'])) {
        $chat['metadata'] = json_decode($chat['metadata'], true);
      }
    }

    return ['success' => true, 'data' => $chats];
  }

  // Obtener historial de chat por venta
  static function getBySale($params) {
    $saleId = $params['sale_id'];

    $chats = db::table(self::$table)
      ->where('sale_id', $saleId)
      ->orderBy('dc', 'ASC')
      ->get();

    if (!is_array($chats)) $chats = [];

    foreach ($chats as &$chat) {
      if (isset($chat['metadata']) && is_string($chat['metadata'])) {
        $chat['metadata'] = json_decode($chat['metadata'], true);
      }
    }

    return ['success' => true, 'data' => $chats];
  }

  // Obtener conversación entre bot y cliente
  static function getConversation($params) {
    $botId = $params['bot_id'];
    $clientId = $params['client_id'];
    $limit = request::query('limit', 100);

    $chats = db::table(self::$table)
      ->where('bot_id', $botId)
      ->where('client_id', $clientId)
      ->orderBy('dc', 'ASC')
      ->limit($limit)
      ->get();

    if (!is_array($chats)) $chats = [];

    foreach ($chats as &$chat) {
      if (isset($chat['metadata']) && is_string($chat['metadata'])) {
        $chat['metadata'] = json_decode($chat['metadata'], true);
      }
    }

    return ['success' => true, 'data' => $chats];
  }

  // Eliminar todos los chats de un cliente
  static function deleteByClient($params) {
    $clientId = $params['client_id'];

    try {
      $affected = db::table(self::$table)->where('client_id', $clientId)->delete();
      return [
        'success' => true,
        'message' => __('chat.delete_all.success'),
        'deleted' => $affected
      ];
    } catch (Exception $e) {
      return [
        'success' => false,
        'error' => __('chat.delete_all.error'),
        'details' => IS_DEV ? $e->getMessage() : null
      ];
    }
  }

  // Obtener estadísticas de chat
  static function getStats($params) {
    $botId = $params['bot_id'] ?? null;

    $query = db::table(self::$table);
    if ($botId) {
      $query = $query->where('bot_id', $botId);
    }

    $stats = [
      'total_messages' => $query->count(),
      'by_type' => [
        'system' => db::table(self::$table)->where('type', 'S')->count(),
        'bot' => db::table(self::$table)->where('type', 'B')->count(),
        'prospect' => db::table(self::$table)->where('type', 'P')->count()
      ],
      'by_format' => [
        'text' => db::table(self::$table)->where('format', 'text')->count(),
        'audio' => db::table(self::$table)->where('format', 'audio')->count(),
        'image' => db::table(self::$table)->where('format', 'image')->count(),
        'video' => db::table(self::$table)->where('format', 'video')->count(),
        'document' => db::table(self::$table)->where('format', 'document')->count()
      ]
    ];

    return ['success' => true, 'data' => $stats];
  }

  // Buscar mensajes por texto
  static function search($params) {
    $searchText = $params['text'] ?? '';
    $limit = request::query('limit', 50);

    if (empty($searchText)) {
      return ['success' => false, 'error' => __('chat.search_text_required')];
    }

    $chats = db::table(self::$table)
      ->where('message', 'LIKE', "%{$searchText}%")
      ->orderBy('dc', 'DESC')
      ->limit($limit)
      ->get();

    if (!is_array($chats)) $chats = [];

    foreach ($chats as &$chat) {
      if (isset($chat['metadata']) && is_string($chat['metadata'])) {
        $chat['metadata'] = json_decode($chat['metadata'], true);
      }
    }

    return ['success' => true, 'data' => $chats, 'total' => count($chats)];
  }

  // Agregar mensaje al chat JSON
  static function addMessage($data, $type = 'P') {
    $required = ['number', 'bot_id', 'client_id', 'sale_id'];
    foreach ($required as $field) {
      if (!isset($data[$field])) {
        log::error('ChatHandlers::addMessage - Campo requerido faltante', ['field' => $field], ['module' => 'chat']);
        return false;
      }
    }

    $number = $data['number'];
    $botId = $data['bot_id'];
    $chatId = "chat_{$number}_bot_{$botId}";
    $chatFile = SHARED_PATH . '/chats/infoproduct/' . $chatId . '.json';

    $chatData = self::getOrCreateChatStructure($chatFile, $data);

    // Normalizar tipo a 'P', 'B', 'S'
    $normalizedType = self::normalizeType($type);

    $message = [
      'date' => date('Y-m-d H:i:s'),
      'type' => $normalizedType,
      'format' => $data['format'] ?? 'text',
      'message' => $data['message'] ?? '',
      'metadata' => $data['metadata'] ?? null
    ];

    $chatData['messages'][] = $message;

    // Actualizar last_activity solo si es mensaje del cliente (tipo 'P')
    if ($normalizedType === 'P') {
      $chatData['last_activity'] = date('Y-m-d H:i:s');
    }

    return self::saveChatFile($chatFile, $chatData);
  }

  // Obtener chat desde JSON con opción de reconstrucción
  static function getChat($number, $botId, $skipReconstruction = false) {
    $chatId = "chat_{$number}_bot_{$botId}";
    $chatFile = SHARED_PATH . '/chats/infoproduct/' . $chatId . '.json';

    return file::getJson($chatFile, function() use ($number, $botId, $skipReconstruction) {
      return $skipReconstruction ? null : self::rebuildFromDB($number, $botId);
    });
  }

  // Reconstruir chat desde BD
  static function rebuildFromDB($number, $botId) {
    try {
      $client = db::table('clients')->where('number', $number)->first();
      if (!$client) {
        log::warning('ChatHandlers::rebuildFromDB - Cliente no encontrado', ['number' => $number], ['module' => 'chat']);
        return false;
      }

      $clientId = $client['id'];
      $clientName = $client['name'] ?? '';

      $messages = db::table(self::$table)
        ->where('client_id', $clientId)
        ->where('bot_id', $botId)
        ->orderBy('dc', 'ASC')
        ->get();

      if (!$messages || count($messages) === 0) {
        log::info('ChatHandlers::rebuildFromDB - No hay mensajes para reconstruir', ['number' => $number, 'bot_id' => $botId], ['module' => 'chat']);
        return false;
      }

      $currentSale = db::table('sales')
        ->where('client_id', $clientId)
        ->where('bot_id', $botId)
        ->whereIn('process_status', ['initiated', 'pending'])
        ->orderBy('dc', 'DESC')
        ->first();

      $salesInProcess = db::table('sales')
        ->where('client_id', $clientId)
        ->where('bot_id', $botId)
        ->whereIn('process_status', ['initiated', 'pending'])
        ->get();

      $completedSales = db::table('sales')
        ->where('client_id', $clientId)
        ->where('bot_id', $botId)
        ->where('process_status', 'sale_confirmed')
        ->get();

      // Calcular last_activity (último mensaje del cliente tipo 'P')
      $lastActivity = null;
      $conversationStarted = $messages[0]['dc'] ?? date('Y-m-d H:i:s');

      for ($i = count($messages) - 1; $i >= 0; $i--) {
        if (($messages[$i]['type'] ?? null) === 'P') {
          $lastActivity = $messages[$i]['dc'];
          break;
        }
      }

      if (!$lastActivity) {
        $lastActivity = $conversationStarted;
      }

      $chatData = [
        'chat_id' => "chat_{$number}_bot_{$botId}",
        'number' => $number,
        'client_id' => $clientId,
        'client_name' => $clientName,
        'bot_id' => $botId,
        'purchase_method' => 'Recibo de pago',
        'conversation_started' => $conversationStarted,
        'last_activity' => $lastActivity,
        'current_sale' => $currentSale ? [
          'sale_id' => $currentSale['id'],
          'product_id' => $currentSale['product_id'],
          'product_name' => $currentSale['product_name'],
          'sale_status' => $currentSale['process_status'],
          'sale_type' => $currentSale['sale_type'],
          'origin' => $currentSale['origin'] ?? 'organic'
        ] : null,
        'last_sale' => $currentSale['id'] ?? ($completedSales ? end($completedSales)['id'] : 0),
        'summary' => [
          'completed_sales' => count($completedSales),
          'sales_in_process' => count($salesInProcess),
          'total_value' => self::calculateTotalValue($completedSales),
          'purchased_products' => array_column($completedSales, 'product_name'),
          'upsells_offered' => []
        ],
        'messages' => []
      ];

      foreach ($messages as $msg) {
        $metadata = isset($msg['metadata']) && is_string($msg['metadata']) 
          ? json_decode($msg['metadata'], true) 
          : ($msg['metadata'] ?? null);

        $chatData['messages'][] = [
          'date' => $msg['dc'],
          'type' => $msg['type'],
          'format' => $msg['format'],
          'message' => $msg['message'],
          'metadata' => $metadata
        ];
      }

      $chatFile = SHARED_PATH . '/chats/infoproduct/chat_' . $number . '_bot_' . $botId . '.json';
      if (self::saveChatFile($chatFile, $chatData)) {
        log::info('ChatHandlers::rebuildFromDB - Chat reconstruido exitosamente', ['number' => $number, 'bot_id' => $botId], ['module' => 'chat']);
        return $chatData;
      }

      return false;

    } catch (Exception $e) {
      log::error('ChatHandlers::rebuildFromDB - Error', ['error' => $e->getMessage()], ['module' => 'chat']);
      return false;
    }
  }

  // Obtener o crear estructura inicial del chat
  private static function getOrCreateChatStructure($chatFile, $data) {
    if (file_exists($chatFile)) {
      $content = file_get_contents($chatFile);
      $chatData = json_decode($content, true);
      if ($chatData !== null) {
        return $chatData;
      }
    }

    $client = db::table('clients')->find($data['client_id']);
    $clientName = $client['name'] ?? '';

    return [
      'chat_id' => "chat_{$data['number']}_bot_{$data['bot_id']}",
      'number' => $data['number'],
      'client_id' => $data['client_id'],
      'client_name' => $clientName,
      'bot_id' => $data['bot_id'],
      'purchase_method' => ($data['bot_mode'] ?? 'R') === 'C' ? 'Checkout' : 'Recibo de Pago',
      'conversation_started' => date('Y-m-d H:i:s'),
      'last_activity' => date('Y-m-d H:i:s'),
      'current_sale' => isset($data['sale_id']) && $data['sale_id'] > 0 ? [
        'sale_id' => $data['sale_id'],
        'product_id' => $data['product_id'] ?? null,
        'product_name' => $data['product_name'] ?? null,
        'sale_status' => 'initiated',
        'sale_type' => 'main'
      ] : null,
      'last_sale' => $data['sale_id'] ?? 0,
      'summary' => [
        'completed_sales' => 0,
        'sales_in_process' => 1,
        'total_value' => 0,
        'purchased_products' => [],
        'upsells_offered' => []
      ],
      'messages' => []
    ];
  }

  // Guardar archivo de chat
  private static function saveChatFile($chatFile, $chatData) {
    $dir = dirname($chatFile);

    if (!is_dir($dir)) {
      if (!mkdir($dir, 0755, true)) {
        log::error('ChatHandlers::saveChatFile - No se pudo crear directorio', ['dir' => $dir], ['module' => 'chat']);
        return false;
      }
    }

    $json = json_encode($chatData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    if (file_put_contents($chatFile, $json) === false) {
      log::error('ChatHandlers::saveChatFile - Error al escribir archivo', ['file' => $chatFile], ['module' => 'chat']);
      return false;
    }

    return true;
  }

  // Normalizar tipo de mensaje a 'P', 'B', 'S'
  private static function normalizeType($type) {
    $type = strtolower($type);
    
    $map = [
      'start_sale' => 'S',
      's' => 'S',
      'system' => 'S',
      'b' => 'B',
      'bot' => 'B',
      'p' => 'P',
      'prospect' => 'P',
      'prospecto' => 'P'
    ];

    return $map[$type] ?? 'P';
  }

  private static function calculateTotalValue($sales) {
    $total = 0;

    foreach ($sales as $sale) {
      $value = $sale['billed_amount'] ?? $sale['amount'] ?? 0;
      $total += (float)$value;
    }

    return $total;
  }
}