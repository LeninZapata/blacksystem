<?php

class ConversationValidator {
  private static $logMeta = ['module' => 'ConversationValidator', 'layer' => 'app/workflows'];
  static function quickCheck($number, $botId, $maxDays = 2) {
    $chatFile = CHATS_STORAGE_PATH . '/chat_' . $number . '_bot_' . $botId . '.json';

    if (!file_exists($chatFile)) {
      return false;
    }

    $content = file_get_contents($chatFile);
    $chat = json_decode($content, true);

    if (!$chat || !isset($chat['conversation_started'])) {
      return false;
    }

    return self::isWithinTimeRange($chat['conversation_started'], $maxDays);
  }

  static function getChatData($number, $botId, $reconstruct = true) {
    if ($reconstruct) {
      ogApp()->loadHandler('chat');
      $chat = ChatHandler::getChat($number, $botId, false);
    } else {
      $chatFile = CHATS_STORAGE_PATH . '/chat_' . $number . '_bot_' . $botId . '.json';

      if (!file_exists($chatFile)) {
        return null;
      }

      $content = file_get_contents($chatFile);
      $chat = json_decode($content, true);
    }

    if (!$chat) {
      return ['client_id' => null, 'sale_id' => 0];
    }

    return [
      'client_id' => $chat['client_id'] ?? null,
      'sale_id' => $chat['current_sale']['sale_id'] ?? 0,
      'full_chat' => $chat
    ];
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