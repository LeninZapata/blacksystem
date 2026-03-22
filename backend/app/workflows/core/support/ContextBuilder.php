<?php

class ContextBuilder {

  static function build($bot, $person, $message, $rawContext = []) {
    $context = [
      'bot' => $bot,
      'person' => $person,
      'message' => $message,
      'raw_context' => $rawContext,
      'chat_data' => null,
      'has_active_conversation' => false
    ];

    $clientKey = $person['number'] ?: ('bsuid_' . ($person['bsuid'] ?? ''));
    $chatData = self::loadChatData($clientKey, $bot['id']);
    
    if ($chatData) {
      $context['chat_data'] = $chatData;
      $context['has_active_conversation'] = true;
    }

    return $context;
  }

  static function loadChatData($number, $botId) {
    return ChatHandler::getChat($number, $botId);
  }

  static function buildEmptyContext($bot, $person) {
    return [
      'bot' => $bot,
      'person' => $person,
      'message' => null,
      'raw_context' => [],
      'chat_data' => null,
      'has_active_conversation' => false
    ];
  }
}