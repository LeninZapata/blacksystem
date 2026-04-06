<?php
class ProductHandler {

  private static $logMeta = ['module' => 'CRUD/ProductHandler', 'layer' => 'app/resources'];

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
        $oldBot = ogDb::t('bots')->find($oldBotId);
        if ($oldBot) {
          self::generateActivatorsFile($oldBot['number'], $oldBotId, 'update');
        }

        // Regenerar activators del bot nuevo (con este producto)
        $newBot = ogDb::t('bots')->find($currentBotId);
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

      // Regenerar source_ids global (mapeo de anuncios de Facebook para redireccionamiento)
      self::generateSourceIdsFile($action);

      return true;
    } catch (Exception $e) {
      ogLog::error('handleInfoproduct - Error', ['message' => $e->getMessage()], self::$logMeta);
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
    $productBasePath = ogApp()->getPath('storage/json/bots/infoproduct/rapid') . '/' . $productId;

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
      $bot = ogDb::t('products')->find($botId);
      if ($bot) {
        self::generateActivatorsFile($bot['number'], $botId, 'update');
      }
    }


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
      $bot = ogDb::t('products')->find($botId);
      if (!$bot) return null;
      $botNumber = $bot['number'];
    }

    $path = ogApp()->getPath('storage/json/bots/infoproduct/rapid') . '/activators_' . $botNumber . '.json';

    return ogApp()->helper('file')::getJson($path, function() use ($botNumber, $botId) {
      return self::generateActivatorsFile($botNumber, $botId, 'rebuild');
    });
  }

  // Obtener archivo de producto
  static function getProductFile($productId) {
    $path = ogApp()->getPath('storage/json/bots/infoproduct') . '/' . $productId . '/' . $productId . '.json';
    return ogApp()->helper('file')::getJson($path, function() use ($productId) {
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

    $path = ogApp()->getPath('storage/json/bots/infoproduct') . '/' . $productId . '/messages/' . $type . '_' . $productId . '.json';
    return ogApp()->helper('file')::getJson($path, function() use ($type, $productId) {
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
    $path = ogApp()->getPath('storage/json/bots/infoproduct') . '/' . $productId . '/messages/template_' . $productId . '.json';
    return ogApp()->helper('file')::getJson($path, function() use ($productId) {
      return self::generateTemplatesFile($productId, 'rebuild');
    });
  }

  // Obtener archivo de upsell
  static function getUpsellFile($productId) {
    $path = ogApp()->getPath('storage/json/bots/infoproduct') . '/' . $productId . '/messages/upsell_' . $productId . '.json';
    return ogApp()->helper('file')::getJson($path, function() use ($productId) {
      return self::generateUpsellFile($productId, 'rebuild');
    });
  }

  // Generar archivo individual del producto
  static function generateProductFile($productId, $action = 'create') {
    $product = ogDb::t('products')->find($productId);
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

    $path = ogApp()->getPath('storage/json/bots/infoproduct') . '/' . $productId . '/' . $productId . '.json';
    return ogApp()->helper('file')::saveJson($path, $productData, 'product', $action);
  }

  // Generar archivo de mensajes
  static function generateMessagesFile($type, $productId, $action = 'create') {
    $product = ogDb::t('products')->find($productId);
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
    $path = ogApp()->getPath('storage/json/bots/infoproduct') . '/' . $productId . '/messages/' . $type . '_' . $productId . '.json';
    return ogApp()->helper('file')::saveJson($path, $messages, 'product', $action);
  }

  // Generar archivo de templates
  static function generateTemplatesFile($productId, $action = 'create') {
    $product = ogDb::t('products')->find($productId);
    if (!$product) return false;

    $config = isset($product['config']) && is_string($product['config'])
      ? json_decode($product['config'], true)
      : ($product['config'] ?? []);

    $templates = $config['messages']['templates'] ?? [];
    $path = ogApp()->getPath('storage/json/bots/infoproduct') . '/' . $productId . '/messages/template_' . $productId . '.json';
    return ogApp()->helper('file')::saveJson($path, $templates, 'product', $action);
  }

  // Generar archivo de upsell
  static function generateUpsellFile($productId, $action = 'create') {
    $product = ogDb::t('products')->find($productId);
    if (!$product) return false;

    $config = isset($product['config']) && is_string($product['config'])
      ? json_decode($product['config'], true)
      : ($product['config'] ?? []);

    $upsells = $config['messages']['upsell_products'] ?? [];
    $path = ogApp()->getPath('storage/json/bots/infoproduct') . '/' . $productId . '/messages/upsell_' . $productId . '.json';
    return ogApp()->helper('file')::saveJson($path, $upsells, 'product', $action);
  }

  // Generar archivo de activators (compartido por bot)
  static function generateActivatorsFile($botNumber = null, $botId = null, $action = 'create') {
    if (!$botId && !$botNumber) return false;

    if (!$botNumber) {
      $bot = ogDb::t('bots')->find($botId);
      if (!$bot || !isset($bot['number'])) {
        ogLog::error('generateActivatorsFile - Bot no encontrado', ['bot_id' => $botId], self::$logMeta);
        return false;
      }
      $botNumber = $bot['number'];
    } else if (!$botId) {
      $bot = ogDb::t('bots')->where('number', $botNumber)->first();
      if (!$bot) {
        ogLog::error('generateActivatorsFile - Bot no encontrado', ['bot_number' => $botNumber], self::$logMeta);
        return false;
      }
      $botId = $bot['id'];
    }

    $products = ogDb::t('products')
      ->where('context', 'infoproductws')
      ->where('bot_id', $botId)
      ->where('status', 1)
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

    $path = ogApp()->getPath('storage/json/bots/infoproduct/rapid') . '/activators_' . $botNumber . '.json';
    return ogApp()->helper('file')::saveJsonItems($path, $activators, 'product', $action);
  }

  /**
   * Obtener productos con el código de país del bot enlazado concatenado al nombre.
   * Retorna cada producto con un campo `display_name` = "[CC] Nombre del producto".
   * Útil para selects donde se necesita identificar el país de cada producto.
   */
  // Generar source_ids.json global (mapeo source_id → {product_id, bot_id, bot_number})
  // Usado para redireccionamiento cuando Meta enruta un anuncio al bot incorrecto
  static function generateSourceIdsFile($action = 'create') {
    $products = ogDb::t('products')
      ->where('context', 'infoproductws')
      ->where('status', 1)
      ->get();

    $sourceIds = [];

    foreach ($products as $product) {
      $config = isset($product['config']) && is_string($product['config'])
        ? json_decode($product['config'], true)
        : ($product['config'] ?? []);

      $fbSourceIds = $config['fb_source_ids'] ?? [];
      if (empty($fbSourceIds) || !is_array($fbSourceIds)) continue;

      $botNumber = null;
      if (!empty($product['bot_id'])) {
        $bot = ogDb::t('bots')->find($product['bot_id']);
        if ($bot) $botNumber = $bot['number'];
      }

      foreach ($fbSourceIds as $item) {
        $sourceId = $item['source_id'] ?? null;
        if (empty($sourceId)) continue;

        $sourceIds[$sourceId] = [
          'product_id' => (int)$product['id'],
          'bot_id'     => (int)$product['bot_id'],
          'bot_number' => $botNumber
        ];
      }
    }

    $path = ogApp()->getPath('storage/json/ads') . '/source_ids.json';
    return ogApp()->helper('file')::saveJson($path, $sourceIds, 'ads', $action);
  }

  // Obtener source_ids.json global con auto-regeneración
  static function getSourceIdsFile() {
    $path = ogApp()->getPath('storage/json/ads') . '/source_ids.json';
    return ogApp()->helper('file')::getJson($path, function() {
      return self::generateSourceIdsFile('rebuild');
    });
  }

  static function getProductsWithCountry($userId = null) {
    $query = ogDb::t('products')
      ->select(['products.id', 'products.name', 'products.status', 'bots.country_code'])
      ->leftJoin('bots', 'products.bot_id', '=', 'bots.id')
      ->where('products.status', 1)
      ->orderBy('bots.country_code', 'ASC')
      ->orderBy('products.name', 'ASC');

    if ($userId) {
      $query = $query->where('products.user_id', (int)$userId);
    }

    $products = $query->get();

    return array_map(function($p) {
      $code = !empty($p['country_code']) ? $p['country_code'] : '??';
      $p['display_name'] = '[' . $code . '] ' . $p['name'];
      return $p;
    }, $products ?: []);
  }
}