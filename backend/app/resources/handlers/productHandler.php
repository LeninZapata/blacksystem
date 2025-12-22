<?php
class ProductHandler {

  protected static $table = DB_TABLES['products'];
  protected static $tableBots = DB_TABLES['bots'];

  // Handler principal por contexto
  static function handleByContext($productData, $action = 'create', $oldBotId = null) {
    if (!isset($productData['context'])) return false;

    switch ($productData['context']) {
      case 'infoproductws':
        return self::handleInfoproduct($productData, $action, $oldBotId);
      default:
        return true;
    }
  }

  // Handler para infoproductos
  static function handleInfoproduct($productData, $action = 'create', $oldBotId = null) {
    try {
      $currentBotId = $productData['bot_id'] ?? null;
      $productId = $productData['id'] ?? null;
      
      // Si cambió el bot (solo en update)
      if ($action === 'update' && $oldBotId && $oldBotId !== $currentBotId) {
        // Regenerar activators del bot antiguo (sin este producto)
        // Nombre de las tablas asociadas a este handler
        $oldBot = db::table(self::$tableBots)->find($oldBotId);
        if ($oldBot) {
          self::generateActivatorsFile($oldBot['number'], $oldBotId, 'update');
        }
        
        // Regenerar activators del bot nuevo (con este producto)
        $newBot = db::table(self::$tableBots)->find($currentBotId);
        if ($newBot) {
          self::generateActivatorsFile($newBot['number'], $currentBotId, 'update');
        }
      } else {
        // Regenerar activators del bot actual
        self::generateActivatorsFile(null, $currentBotId, $action);
      }
      
      // Generar archivos del producto
      if ($productId) {
        self::generateProductFile($productId, $action);
        self::generateMessagesFile('welcome', $productId, $action);
        self::generateMessagesFile('welcome_upsell', $productId, $action);
        self::generateMessagesFile('follow', $productId, $action);
        self::generateMessagesFile('follow_upsell', $productId, $action);
        self::generateTemplatesFile($productId, $action);
      }
      
      return true;
    } catch (Exception $e) {
      log::error('ProductHandler::handleInfoproduct - Error', ['message' => $e->getMessage()], ['module' => 'product']);
      return false;
    }
  }

  // Obtener archivo de activators
  static function getActivatorsFile($botNumber = null, $botId = null) {
    if (!$botNumber && !$botId) return null;

    if (!$botNumber) {
      $bot = db::table(self::$tableBots)->find($botId);
      if (!$bot) return null;
      $botNumber = $bot['number'];
    }

    $path = SHARED_PATH . '/bots/infoproduct/rapid/activators_' . $botNumber . '.json';
    return file::getJson($path, function() use ($botNumber, $botId) {
      return self::generateActivatorsFile($botNumber, $botId, 'rebuild');
    });
  }

  // Obtener archivo de producto
  static function getProductFile($productId) {
    $path = SHARED_PATH . '/bots/infoproduct/' . $productId . '.json';
    return file::getJson($path, function() use ($productId) {
      return self::generateProductFile($productId, 'rebuild');
    });
  }

  // Obtener archivo de mensajes
  static function getMessagesFile($type, $productId) {
    $typeMap = [
      'welcome' => 'welcome_messages',
      'welcome_upsell' => 'welcome_messages_upsell',
      'follow' => 'tracking_messages',
      'follow_upsell' => 'tracking_messages_upsell'
    ];

    if (!isset($typeMap[$type])) return null;

    $path = SHARED_PATH . '/bots/infoproduct/messages/' . $type . '_' . $productId . '.json';
    return file::getJson($path, function() use ($type, $productId) {
      return self::generateMessagesFile($type, $productId, 'rebuild');
    });
  }

  // Obtener archivo de templates
  static function getTemplatesFile($productId) {
    $path = SHARED_PATH . '/bots/infoproduct/messages/template_' . $productId . '.json';
    return file::getJson($path, function() use ($productId) {
      return self::generateTemplatesFile($productId, 'rebuild');
    });
  }

  /**
   * Generar archivo individual del producto /bots/infoproduct/{product_id}.json
   * NO incluye config.messages
   */
  static function generateProductFile($productId, $action = 'create') {
    $product = db::table(self::$table)->find($productId);
    if (!$product) return false;

    // Parsear config
    $config = isset($product['config']) && is_string($product['config']) 
      ? json_decode($product['config'], true) 
      : ($product['config'] ?? []);

    // Remover messages del config
    unset($config['messages']);

    // Construir datos del producto
    $productData = [
      'id' => $product['id'],
      'name' => $product['name'],
      'description' => $product['description'] ?? null,
      'price' => $product['price'] ?? 0.00,
      'bot_id' => $product['bot_id'],
      'context' => $product['context'],
      'config' => $config
    ];

    $path = SHARED_PATH . '/bots/infoproduct/' . $productId . '.json';
    return file::saveJson($path, $productData, 'product', $action);
  }

  // Generar archivo de mensajes
  static function generateMessagesFile($type, $productId, $action = 'create') {
    $product = db::table(self::$table)->find($productId);
    if (!$product) return false;

    $config = isset($product['config']) && is_string($product['config']) 
      ? json_decode($product['config'], true) 
      : ($product['config'] ?? []);

    $typeMap = [
      'welcome' => 'welcome_messages',
      'welcome_upsell' => 'welcome_messages_upsell',
      'follow' => 'tracking_messages',
      'follow_upsell' => 'tracking_messages_upsell'
    ];

    if (!isset($typeMap[$type])) return false;

    $messages = $config['messages'][$typeMap[$type]] ?? [];
    $path = SHARED_PATH . '/bots/infoproduct/messages/' . $type . '_' . $productId . '.json';
    return file::saveJson($path, $messages, 'product', $action);
  }

  // Generar archivo de templates
  static function generateTemplatesFile($productId, $action = 'create') {
    $product = db::table(self::$table)->find($productId);
    if (!$product) return false;

    $config = isset($product['config']) && is_string($product['config']) 
      ? json_decode($product['config'], true) 
      : ($product['config'] ?? []);

    $templates = $config['messages']['templates'] ?? [];
    $path = SHARED_PATH . '/bots/infoproduct/messages/template_' . $productId . '.json';
    return file::saveJson($path, $templates, 'product', $action);
  }

  // Generar archivo de activators
  static function generateActivatorsFile($botNumber = null, $botId = null, $action = 'create') {
    if (!$botId && !$botNumber) return false;

    if (!$botNumber) {
      $bot = db::table(self::$tableBots)->find($botId);
      if (!$bot || !isset($bot['number'])) {
        log::error('ProductHandler::generateActivatorsFile - Bot no encontrado', ['bot_id' => $botId], ['module' => 'product']);
        return false;
      }
      $botNumber = $bot['number'];
    } else if (!$botId) {
      $bot = db::table(self::$tableBots)->where('number', $botNumber)->first();
      if (!$bot) {
        log::error('ProductHandler::generateActivatorsFile - Bot no encontrado', ['bot_number' => $botNumber], ['module' => 'product']);
        return false;
      }
      $botId = $bot['id'];
    }

    $products = db::table(self::$table)
      ->where('context', 'infoproductws')
      ->where('bot_id', $botId)
      ->get();

    $activators = [];

    foreach ($products as $product) {
      $config = isset($product['config']) && is_string($product['config']) 
        ? json_decode($product['config'], true) 
        : ($product['config'] ?? []);

      $welcomeTriggers = $config['welcome_triggers'] ?? '';
      
      if (!empty($welcomeTriggers)) {
        $triggers = array_map('trim', explode(',', $welcomeTriggers));
        $triggers = array_filter($triggers, function($t) { return !empty($t); });
        $activators[$product['id']] = array_values($triggers);
      } else {
        $activators[$product['id']] = [];
      }
    }

    $path = SHARED_PATH . '/bots/infoproduct/rapid/activators_' . $botNumber . '.json';
    return file::saveJsonItems($path, $activators, 'product', $action);
  }
}