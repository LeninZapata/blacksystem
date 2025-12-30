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

    // Guardar mensaje del usuario
    $this->saveUserStickerMessage($stickerMessage, $context);

    // Enviar respuesta educada
    $this->sendStickerRejectionMessage($person['number']);

    // Guardar respuesta del bot
    $this->saveBotRejectionMessage($context);

    return [
      'success' => true,
      'sticker_rejected' => true
    ];
  }

  private function sendStickerRejectionMessage($to) {
    $message = "😅 No me carga el sticker, ¿me podrías ayudar escribiendo el mensaje por favor?";
    $chatapi = ogApp()->service('chatapi');
    $chatapi::send($to, $message);
  }

  private function saveUserStickerMessage($stickerMessage, $context) {
    $bot = $context['bot'];
    $person = $context['person'];
    $chatData = $context['chat_data'];

    $messageText = "[Sticker enviado]";

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
        'sticker_url' => $stickerMessage['media_url'] ?? null
      ],
      $chatData['sale_id']
    );

    ChatHandler::addMessage([
      'number' => $person['number'],
      'bot_id' => $bot['id'],
      'client_id' => $chatData['client_id'],
      'sale_id' => $chatData['sale_id'],
      'message' => $messageText,
      'format' => 'sticker',
      'metadata' => [
        'sticker_url' => $stickerMessage['media_url'] ?? null
      ]
    ], 'P');
  }

  private function saveBotRejectionMessage($context) {
    $bot = $context['bot'];
    $person = $context['person'];
    $chatData = $context['chat_data'];

    $message = "😅 No me carga el sticker, ¿me podrías ayudar escribiendo el mensaje por favor?";

    ogApp()->loadHandler('chat');
    ChatHandler::register(
      $bot['id'],
      $bot['number'],
      $chatData['client_id'],
      $person['number'],
      $message,
      'B',
      'text',
      [
        'action' => 'sticker_rejected',
        'reason' => 'sticker_not_supported'
      ],
      $chatData['sale_id']
    );

    ChatHandler::addMessage([
      'number' => $person['number'],
      'bot_id' => $bot['id'],
      'client_id' => $chatData['client_id'],
      'sale_id' => $chatData['sale_id'],
      'message' => $message,
      'format' => 'text',
      'metadata' => [
        'action' => 'sticker_rejected',
        'reason' => 'sticker_not_supported'
      ]
    ], 'B');
  }
}