<?php

class AudioMessageProcessor implements MessageProcessorInterface {
  private $logMeta = ['module' => 'AudioMessageProcessor', 'layer' => 'app/workflows'];

  public function process(array $messages, array $context): array {
    $bot = $context['bot'];
    $person = $context['person'];
    $chatData = $context['chat_data'] ?? null;
    $audioMessage = null;
    $additionalText = [];

    foreach ($messages as $msg) {
      $type = strtoupper($msg['type'] ?? 'TEXT');

      if ($type === 'AUDIO' && !$audioMessage) {
        $audioMessage = $msg;
      } elseif ($type === 'TEXT') {
        $additionalText[] = $msg['text'] ?? '';
      }
    }

    if (!$audioMessage) {
      return [
        'success' => false,
        'error' => 'No audio found in messages'
      ];
    }

    // Enviar mensaje de espera si hay venta pendiente
    if ($this->shouldSendWaitMessage($chatData)) {
      ogLog::info("process - Enviando mensaje de espera", [ 'number' => $person['number'], 'reason' => 'pending_sale' ], $this->logMeta);
      $this->sendWaitMessage($person['number'], $context);
    } else {
      ogLog::info("process - Saltando mensaje de espera", [ 'number' => $person['number'], 'reason' => 'sin_venta_pendiente' ], $this->logMeta);
    }

    require_once ogApp()->getPath() . '/workflows/infoproduct/interpreters/AudioInterpreter.php';
    $interpretation = AudioInterpreter::interpret($audioMessage, $bot);

    if (!isset($interpretation['text']) || empty($interpretation['text'])) {
      return [
        'success' => false,
        'type' => 'audio',
        'error' => 'Audio transcription failed',
        'additional_text' => implode(' ', $additionalText)
      ];
    }

    // Construir el resultado como texto interpretado para la IA
    return [
      'success' => true,
      'type' => 'text',
      'interpreted_messages' => [
        [
          'type' => 'audio',
          'text' => $interpretation['text'],
          'metadata' => $interpretation['metadata']
        ]
      ],
      'ai_text' => "[audio transcrito]: " . $interpretation['text'],
      'additional_text' => implode(' ', $additionalText)
    ];
  }

  // Determina si debe enviar mensaje de espera
  private function shouldSendWaitMessage($chatData) {
    if (!$chatData) {
      ogLog::warn("shouldSendWaitMessage - No chatData", [], $this->logMeta);
      return false;
    }

    $currentSale = $chatData['current_sale'] ?? null;
    return $currentSale !== null;
  }

  private function sendWaitMessage($to, $context) {
    $chatapi = ogApp()->service('chatApi');
    $bot      = $context['bot'];
    $chatData = $context['chat_data'];

    $randomDelayMs = rand(12, 17) * 100;
    $chatapi::sendPresence($to, 'composing', $randomDelayMs);

    $message = "Un momento por favor ☺️ estoy escuchando tu audio... 🎧\n\n🕒 Si tardo en responder, no te preocupes, estoy procesando tu mensaje.";
    $chatapi::send($to, $message);

    // Registrar mensaje del bot en DB + JSON
    ogApp()->loadHandler('chat');
    ChatHandler::register(
      $bot['id'],
      $bot['number'],
      $chatData['client_id'],
      $to,
      $message,
      'B',
      'text',
      ['action' => 'wait_audio_transcription'],
      $chatData['current_sale']['sale_id'] ?? null
    );
    ChatHandler::addMessage([
      'number'    => $to,
      'bot_id'    => $bot['id'],
      'client_id' => $chatData['client_id'],
      'sale_id'   => $chatData['current_sale']['sale_id'] ?? null,
      'message'   => $message,
      'format'    => 'text',
      'metadata'  => ['action' => 'wait_audio_transcription']
    ], 'B');
  }
}