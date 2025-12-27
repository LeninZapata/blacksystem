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
        self::generateUpsellFile($productId, $action);
      }

      return true;
    } catch (Exception $e) {
      log::error('ProductHandler::handleInfoproduct - Error', ['message' => $e->getMessage()], ['module' => 'product']);
      return false;
    }
  }

  /**
   * Eliminar todos los archivos asociados a un producto
   */
  static function deleteProductFiles($productId, $botId = null) {
    $deletedFiles = [];
    $errors = [];

    // Ruta base del producto
    $productBasePath = BOTS_INFOPRODUCT_RAPID_PATH . '/' . $productId;

    // 1. Archivo principal del producto
    $productFile = $productBasePath . '/' . $productId . '.json';
    if (file_exists($productFile)) {
      if (@unlink($productFile)) {
        $deletedFiles[] = $productFile;
      } else {
        $errors[] = $productFile;
      }
    }

    // 2. Archivos de mensajes
    $messageTypes = ['welcome', 'welcome_upsell', 'follow', 'follow_upsell', 'template', 'upsell'];
    foreach ($messageTypes as $type) {
      $messageFile = $productBasePath . '/messages/' . $type . '_' . $productId . '.json';
      if (file_exists($messageFile)) {
        if (@unlink($messageFile)) {
          $deletedFiles[] = $messageFile;
        } else {
          $errors[] = $messageFile;
        }
      }
    }

    // 3. Archivo de templates
    $templateFile = $productBasePath . '/messages/template_' . $productId . '.json';
    if (file_exists($templateFile)) {
      if (@unlink($templateFile)) {
        $deletedFiles[] = $templateFile;
      } else {
        $errors[] = $templateFile;
      }
    }

    // 4. Archivo de upsell
    $upsellFile = $productBasePath . '/messages/upsell_' . $productId . '.json';
    if (file_exists($upsellFile)) {
      if (@unlink($upsellFile)) {
        $deletedFiles[] = $upsellFile;
      } else {
        $errors[] = $upsellFile;
      }
    }

    // 5. Intentar eliminar directorios vacíos
    @rmdir($productBasePath . '/messages');
    @rmdir($productBasePath . '/rapid');
    @rmdir($productBasePath);

    // 6. Regenerar activators del bot (si se proporciona botId)
    if ($botId) {
      $bot = db::table(self::$tableBots)->find($botId);
      if ($bot) {
        self::generateActivatorsFile($bot['number'], $botId, 'update');
      }
    }

    log::info('ProductHandler::deleteProductFiles - Archivos eliminados', [
      'product_id' => $productId,
      'deleted_count' => count($deletedFiles),
      'errors_count' => count($errors),
      'deleted_files' => $deletedFiles,
      'errors' => $errors
    ], ['module' => 'product']);

    return [
      'success' => empty($errors),
      'deleted' => $deletedFiles,
      'errors' => $errors
    ];
  }

  // Obtener archivo de activators
  static function getActivatorsFile($botNumber = null, $botId = null) {
    if (!$botNumber && !$botId) return null;

    if (!$botNumber) {
      $bot = db::table(self::$tableBots)->find($botId);
      if (!$bot) return null;
      $botNumber = $bot['number'];
    }

    $path = BOTS_INFOPRODUCT_RAPID_PATH . '/activators_' . $botNumber . '.json';
    return file::getJson($path, function() use ($botNumber, $botId) {
      return self::generateActivatorsFile($botNumber, $botId, 'rebuild');
    });
  }

  // Obtener archivo de producto
  static function getProductFile($productId) {
    $path = BOTS_INFOPRODUCT_PATH . '/' . $productId . '/' . $productId . '.json';
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
      'follow_upsell' => 'tracking_messages_upsell',
      'template' => 'templates',
      'upsell' => 'upsell_products'
    ];

    if (!isset($typeMap[$type])) return null;

    $path = BOTS_INFOPRODUCT_PATH . '/' . $productId . '/messages/' . $type . '_' . $productId . '.json';
    return file::getJson($path, function() use ($type, $productId) {
      if ($type === 'upsell') {
        return self::generateUpsellFile($productId, 'rebuild');
      }
      if ($type === 'template') {
        return self::generateMessagesFile('template', $productId, 'rebuild');
      }
      return self::generateMessagesFile($type, $productId, 'rebuild');
    });
  }

  // Obtener archivo de templates
  static function getTemplatesFile($productId) {
    $path = BOTS_INFOPRODUCT_PATH . '/' . $productId . '/messages/template_' . $productId . '.json';
    return file::getJson($path, function() use ($productId) {
      return self::generateTemplatesFile($productId, 'rebuild');
    });
  }

  // Obtener archivo de upsell
  static function getUpsellFile($productId) {
    $path = BOTS_INFOPRODUCT_PATH . '/' . $productId . '/messages/upsell_' . $productId . '.json';
    return file::getJson($path, function() use ($productId) {
      return self::generateUpsellFile($productId, 'rebuild');
    });
  }

  /**
   * Generar archivo individual del producto
   * /bots/infoproduct/{product_id}/{product_id}.json
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

    $path = BOTS_INFOPRODUCT_PATH . '/' . $productId . '/' . $productId . '.json';
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
      'follow_upsell' => 'tracking_messages_upsell',
      'template' => 'templates',
      'upsell' => 'upsell_products'
    ];

    if (!isset($typeMap[$type])) return false;

    $messages = $config['messages'][$typeMap[$type]] ?? [];
    $path = BOTS_INFOPRODUCT_PATH . '/' . $productId . '/messages/' . $type . '_' . $productId . '.json';
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
    $path = BOTS_INFOPRODUCT_PATH . '/' . $productId . '/messages/template_' . $productId . '.json';
    return file::saveJson($path, $templates, 'product', $action);
  }

  // Generar archivo de upsell
  static function generateUpsellFile($productId, $action = 'create') {
    $product = db::table(self::$table)->find($productId);
    if (!$product) return false;

    $config = isset($product['config']) && is_string($product['config'])
      ? json_decode($product['config'], true)
      : ($product['config'] ?? []);

    $upsells = $config['messages']['upsell_products'] ?? [];
    $path = BOTS_INFOPRODUCT_PATH . '/' . $productId . '/messages/upsell_' . $productId . '.json';
    return file::saveJson($path, $upsells, 'product', $action);
  }

  // Generar archivo de activators (compartido por bot)
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

    $path = BOTS_INFOPRODUCT_RAPID_PATH . '/activators_' . $botNumber . '.json';
    return file::saveJsonItems($path, $activators, 'product', $action);
  }
}