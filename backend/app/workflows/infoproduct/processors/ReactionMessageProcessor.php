<?php

class ReactionMessageProcessor implements MessageProcessorInterface {

  public function process(array $messages, array $context): array {
    $bot = $context['bot'];
    $person = $context['person'];
    $chatData = $context['chat_data'] ?? null;
    $reactionMessage = null;

    // Buscar mensaje de tipo reaction
    foreach ($messages as $msg) {
      $type = strtoupper($msg['type'] ?? 'TEXT');

      if ($type === 'REACTION') {
        $reactionMessage = $msg;
        break;
      }
    }

    if (!$reactionMessage) {
      return [
        'success' => false,
        'error' => 'No reaction found in messages'
      ];
    }

    // Solo guardar mensaje del usuario (sin responder)
    $this->saveUserReactionMessage($reactionMessage, $context);

    // NO enviar respuesta - simplemente ignorar la reaction

    return [
      'success' => true,
      'reaction_ignored' => true,
      'no_response' => true
    ];
  }

  private function saveUserReactionMessage($reactionMessage, $context) {
    $bot = $context['bot'];
    $person = $context['person'];
    $chatData = $context['chat_data'];

    // Extraer emoji de la reaction si está disponible
    $reactionText = $reactionMessage['text'] ?? '';
    $messageText = empty($reactionText) ? "[Reacción enviada]" : "[Reacción: {$reactionText}]";

    ogApp()->loadHandler('chat');
    ChatHandler::register(
      $bot['id'],
      $bot['number'],
      $chatData['client_id'],
      $person['number'],
      $messageText,
      'P',
      'reaction',
      [
        'reaction_text' => $reactionText,
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
      'format' => 'reaction',
      'metadata' => [
        'reaction_text' => $reactionText,
        'ignored' => true
      ]
    ], 'P');
  }
}