<?php

class StickerMessageProcessor implements MessageProcessorInterface {

  public function process(array $messages, array $context): array {
    $bot = $context['bot'];
    $person = $context['person'];
    $chatData = $context['chat_data'] ?? null;
    $stickerMessage = null;

    // Buscar mensaje de tipo sticker
    foreach ($messages as $msg) {
      $type = strtoupper($msg['type'] ?? 'TEXT');

      if ($type === 'STICKER') {
        $stickerMessage = $msg;
        break;
      }
    }

    if (!$stickerMessage) {
      return [
        'success' => false,
        'error' => 'No sticker found in messages'
      ];
    }

    // Solo guardar mensaje del usuario (sin responder)
    $this->saveUserStickerMessage($stickerMessage, $context);

    // NO enviar respuesta - simplemente ignorar el sticker

    return [
      'success' => true,
      'sticker_ignored' => true,
      'no_response' => true
    ];
  }

  private function saveUserStickerMessage($stickerMessage, $context) {
    $bot = $context['bot'];
    $person = $context['person'];
    $chatData = $context['chat_data'];

    $messageText = "[Sticker recibo]";

    ogApp()->loadHandler('chat');
    ChatHandler::register(
      $bot['id'],
      $bot['number'],
      $chatData['client_id'],
      $person['number'],
      $messageText,
      'P',
      'sticker',
      [
        'sticker_url' => $stickerMessage['media_url'] ?? null,
        'ignored' => true
      ],
      $chatData['current_sale']['sale_id'] ?? null
    );

    ChatHandler::addMessage([
      'number' => $person['number'],
      'bot_id' => $bot['id'],
      'client_id' => $chatData['client_id'],
      'sale_id' => $chatData['current_sale']['sale_id'] ?? null,
      'message' => $messageText,
      'format' => 'sticker',
      'metadata' => [
        'sticker_url' => $stickerMessage['media_url'] ?? null,
        'ignored' => true
      ]
    ], 'P');
  }
}