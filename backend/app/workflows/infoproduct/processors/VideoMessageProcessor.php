<?php

class VideoMessageProcessor implements MessageProcessorInterface {

  public function process(array $messages, array $context): array {
    $bot = $context['bot'];
    $person = $context['person'];
    $chatData = $context['chat_data'] ?? null;
    $videoMessage = null;
    $caption = '';

    // Buscar mensaje de tipo video
    foreach ($messages as $msg) {
      $type = strtoupper($msg['type'] ?? 'TEXT');

      if ($type === 'VIDEO') {
        $videoMessage = $msg;
        $caption = trim($msg['text'] ?? '');
        break;
      }
    }

    if (!$videoMessage) {
      return [
        'success' => false,
        'error' => 'No video found in messages'
      ];
    }

    // Guardar mensaje del usuario en el chat
    $this->saveUserVideoMessage($videoMessage, $caption, $context);

    // Si NO tiene caption → Solo registrar, NO responder
    if (empty($caption)) {
      ogLog::info("VideoMessageProcessor - Video sin caption, solo registrado", [
        'number' => $person['number']
      ], ['module' => 'video_processor']);

      return [
        'success' => true,
        'video_without_caption' => true,
        'no_response' => true
      ];
    }

    // Si tiene caption → Procesar con IA
    ogLog::info("VideoMessageProcessor - Video con caption, procesando texto", [
      'number' => $person['number'],
      'caption' => $caption
    ], ['module' => 'video_processor']);

    return [
      'success' => true,
      'type' => 'text',
      'interpreted_messages' => [
        [
          'type' => 'video',
          'text' => $caption,
          'metadata' => [
            'video_url' => $videoMessage['media_url'] ?? null,
            'has_caption' => true
          ]
        ]
      ],
      'ai_text' => "[video: (caption: {$caption})]"
    ];
  }

  private function saveUserVideoMessage($videoMessage, $caption, $context) {
    $bot = $context['bot'];
    $person = $context['person'];
    $chatData = $context['chat_data'];

    // Construir texto del mensaje
    if (!empty($caption)) {
      $messageText = "[Video con texto]: {$caption}";
    } else {
      $messageText = "[Video enviado]";
    }

    ChatHandlers::register(
      $bot['id'],
      $bot['number'],
      $chatData['client_id'],
      $person['number'],
      $messageText,
      'P',
      'video',
      [
        'video_url' => $videoMessage['media_url'] ?? null,
        'caption' => $caption,
        'has_caption' => !empty($caption)
      ],
      $chatData['sale_id']
    );

    ChatHandlers::addMessage([
      'number' => $person['number'],
      'bot_id' => $bot['id'],
      'client_id' => $chatData['client_id'],
      'sale_id' => $chatData['sale_id'],
      'message' => $messageText,
      'format' => 'video',
      'metadata' => [
        'video_url' => $videoMessage['media_url'] ?? null,
        'caption' => $caption,
        'has_caption' => !empty($caption)
      ]
    ], 'P');
  }
}