<?php

class ImageMessageProcessor implements MessageProcessorInterface {

  public function process(array $messages, array $context): array {
    $bot = $context['bot'];
    $person = $context['person'];
    $chatData = $context['chat_data'] ?? null;
    $imageMessage = null;
    $additionalText = [];

    foreach ($messages as $msg) {
      $type = strtoupper($msg['type'] ?? 'TEXT');

      if ($type === 'IMAGE' && !$imageMessage) {
        $imageMessage = $msg;
      } elseif ($type === 'TEXT') {
        $additionalText[] = $msg['text'] ?? '';
      }
    }

    if (!$imageMessage) {
      return [
        'success' => false,
        'error' => 'No image found in messages'
      ];
    }

    // ✅ VALIDACIÓN INTELIGENTE: Solo enviar mensaje de espera si hay venta pendiente
    if ($this->shouldSendWaitMessage($chatData)) {
      ogLog::info("ImageMessageProcessor - Enviando mensaje de espera", [
        'number' => $person['number'],
        'reason' => 'venta_pendiente'
      ], ['module' => 'image_processor']);

      $this->sendWaitMessage($person['number']);
    } else {
      ogLog::info("ImageMessageProcessor - Saltando mensaje de espera", [
        'number' => $person['number'],
        'reason' => 'sin_venta_pendiente'
      ], ['module' => 'image_processor']);
    }

    require_once APP_PATH . '/workflows/infoproduct/interpreters/ImageInterpreter.php';
    $analysis = ImageInterpreter::interpret($imageMessage, $bot);

    if (!$analysis['success']) {
      return [
        'success' => false,
        'type' => 'image',
        'error' => $analysis['error'] ?? 'Image analysis failed',
        'additional_text' => implode(' ', $additionalText)
      ];
    }

    return [
      'success' => true,
      'type' => 'image',
      'analysis' => $analysis,
      'additional_text' => implode(' ', $additionalText)
    ];
  }

  /**
   * ✅ Determina si debe enviar mensaje de espera
   * Solo lo envía si hay una venta pendiente (current_sale !== null)
   */
  private function shouldSendWaitMessage($chatData) {
    if (!$chatData) {
      ogLog::debug("ImageMessageProcessor::shouldSendWaitMessage - No chatData", [], ['module' => 'image_processor']);
      return false;
    }

    $currentSale = $chatData['full_chat']['current_sale'] ?? null;

    ogLog::debug("ImageMessageProcessor::shouldSendWaitMessage - Validación", [
      'has_current_sale' => $currentSale !== null
    ], ['module' => 'image_processor']);

    // Solo enviar mensaje si hay venta pendiente
    return $currentSale !== null;
  }

  private function sendWaitMessage($to) {
    $message = "Listo ✅\nUn momento por favor ☺️ (estoy abriendo la foto de pago que me enviaste)\n\n🕐 Si tardo en responder, no te preocupes.\nEstoy procesando los pagos y pronto te enviaré tu acceso Tu compra está garantizada. ¡Gracias por tu paciencia! 😊💡";
    ogChatApi::send($to, $message);
  }
}