<?php

class ImageMessageProcessor implements MessageProcessorInterface {

  public function process(array $messages, array $context): array {
    $bot = $context['bot'];
    $person = $context['person'];
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

    $this->sendWaitMessage($person['number']);

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

  private function sendWaitMessage($to) {
    $message = "Listo ✅\nUn momento por favor ☺️ (estoy abriendo la foto de pago que me enviaste)\n\n🕐 Si tardo en responder, no te preocupes.\nEstoy procesando los pagos y pronto te enviaré tu acceso Tu compra está garantizada. ¡Gracias por tu paciencia! 😊💡";
    chatapi::send($to, $message);
  }
}