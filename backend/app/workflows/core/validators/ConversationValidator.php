<?php

class ConversationValidator {
  private static $logMeta = ['module' => 'ConversationValidator', 'layer' => 'app/workflows'];
  static function quickCheck($number, $botId, $maxDays = 2) {
    $chat = ogApp()->handler('chat')::getChat($number, $botId, false, false);
    return [
      'exists' => $chat !== null,
      'chat' => $chat,
      'within_time_range' => $chat ? self::isWithinTimeRange($chat['conversation_started'], $maxDays) : null
    ];
  }

  static function getChatData($number, $botId, $reconstruct = false) {
    return ogApp()->handler('chat')::getChat($number, $botId);
  }

  static function isWithinTimeRange($conversationStarted, $maxDays) {
    $startDate = strtotime($conversationStarted);
    $currentDate = time();
    $daysDiff = floor(($currentDate - $startDate) / 86400);

    return $daysDiff < $maxDays;
  }

  static function chatExists($number, $botId) {
    $chatFile = CHATS_STORAGE_PATH . '/chat_' . $number . '_bot_' . $botId . '.json';
    return file_exists($chatFile);
  }
}