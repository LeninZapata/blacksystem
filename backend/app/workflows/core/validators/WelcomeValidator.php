<?php

class WelcomeValidator {

  static function detect($bot, $message, $context) {
    $isFBAds = ($context['is_fb_ads'] ?? false) && !empty($context['source_app'] ?? null);
    
    if ($isFBAds) {
      $productId = self::detectProduct($bot, $message, $context);
      return [
        'is_welcome' => $productId !== null,
        'product_id' => $productId,
        'source' => 'fb_ads'
      ];
    }

    $productId = self::detectProduct($bot, $message, $context);
    
    return [
      'is_welcome' => $productId !== null,
      'product_id' => $productId,
      'source' => 'normal'
    ];
  }

  static function detectProduct($bot, $message, $context) {
    $botNumber = $bot['number'] ?? null;
    
    if (!$botNumber) {
      return null;
    }

    ogApp()->loadHandler('ProductHandler');
    $activators = ProductHandler::getActivatorsFile($botNumber);
    
    if (empty($activators)) {
      return null;
    }

    $fields = self::extractSearchFields($message, $context);

    foreach ($activators as $productId => $triggers) {
      if (empty($triggers)) {
        continue;
      }

      foreach ($triggers as $trigger) {
        if (self::searchTrigger($trigger, $fields)) {
          return (int)$productId;
        }
      }
    }

    return null;
  }

  static function extractSearchFields($message, $context) {
    $fields = [];

    if (!empty($message['text'])) {
      $fields[] = $message['text'];
    }

    if (!empty($context['ad_data']['body'])) {
      $fields[] = $context['ad_data']['body'];
    }

    if (!empty($context['ad_data']['source_url'])) {
      $fields[] = $context['ad_data']['source_url'];
    }

    return $fields;
  }

  static function searchTrigger($trigger, $fields) {
    if (empty($trigger) || empty($fields)) {
      return false;
    }

    $parts = array_map('trim', explode(',', $trigger));

    foreach ($parts as $part) {
      if (empty($part)) {
        continue;
      }

      if (self::searchInFields($part, $fields)) {
        return true;
      }
    }

    return false;
  }

  static function searchInFields($needle, $fields) {
    foreach ($fields as $field) {
      if (ogApp()->helper('str')::containsAllWords($needle, $field)) {
        return true;
      }
    }

    return false;
  }

  static function isFromFBAds($context) {
    return ($context['is_fb_ads'] ?? false) && !empty($context['source_app'] ?? null);
  }
}