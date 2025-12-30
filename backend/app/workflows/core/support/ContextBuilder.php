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

    $chatData = self::loadChatData($person['number'], $bot['id']);
    
    if ($chatData) {
      $context['chat_data'] = $chatData;
      $context['has_active_conversation'] = true;
    }

    return $context;
  }

  static function loadChatData($number, $botId) {
    return ChatHandler::getChat($number, $botId, true);
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