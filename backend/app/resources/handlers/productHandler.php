<?php
class productHandler {

  static function handleByContext($productData, $action = 'create') {
    if (!isset($productData['context'])) return false;

    switch ($productData['context']) {
      case 'infoproductws':
        return self::handleInfoproduct($productData, $action);
      default:
        return true;
    }
  }

  static function handleInfoproduct($productData, $action = 'create') {
    try {
      self::generateActivatorsFile(null, $productData['bot_id'] ?? null, $action);
      
      if (isset($productData['id'])) {
        $productId = $productData['id'];
        self::generateMessagesFile('welcome', $productId, $action);
        self::generateMessagesFile('welcome_upsell', $productId, $action);
        self::generateMessagesFile('follow', $productId, $action);
        self::generateMessagesFile('follow_upsell', $productId, $action);
      }
      
      return true;
    } catch (Exception $e) {
      log::error('productHandler::handleInfoproduct - Error', ['message' => $e->getMessage()], ['module' => 'product']);
      return false;
    }
  }

  static function getActivatorsFile($botNumber = null, $botId = null) {
    if (!$botNumber && !$botId) return null;

    if (!$botNumber) {
      $bot = db::table('bots')->find($botId);
      if (!$bot) return null;
      $botNumber = $bot['number'];
    }

    $path = SHARED_PATH . '/bots/infoproduct/rapid/activators_' . $botNumber . '.json';
    return file::getJson($path, function() use ($botNumber, $botId) {
      return self::generateActivatorsFile($botNumber, $botId, 'rebuild');
    });
  }

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

  static function generateMessagesFile($type, $productId, $action = 'create') {
    $product = db::table('products')->find($productId);
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

  static function generateActivatorsFile($botNumber = null, $botId = null, $action = 'create') {
    if (!$botId && !$botNumber) return false;

    if (!$botNumber) {
      $bot = db::table('bots')->find($botId);
      if (!$bot || !isset($bot['number'])) {
        log::error('productHandler::generateActivatorsFile - Bot no encontrado', ['bot_id' => $botId], ['module' => 'product']);
        return false;
      }
      $botNumber = $bot['number'];
    } else if (!$botId) {
      $bot = db::table('bots')->where('number', $botNumber)->first();
      if (!$bot) {
        log::error('productHandler::generateActivatorsFile - Bot no encontrado', ['bot_number' => $botNumber], ['module' => 'product']);
        return false;
      }
      $botId = $bot['id'];
    }

    $products = db::table('products')
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

  static function generateWelcomeMessages($productId, $action = 'create') {
    return self::generateMessagesFile('welcome', $productId, $action);
  }

  static function generateWelcomeUpsellMessages($productId, $action = 'create') {
    return self::generateMessagesFile('welcome_upsell', $productId, $action);
  }

  static function generateFollowMessages($productId, $action = 'create') {
    return self::generateMessagesFile('follow', $productId, $action);
  }

  static function generateFollowUpsellMessages($productId, $action = 'create') {
    return self::generateMessagesFile('follow_upsell', $productId, $action);
  }
}
