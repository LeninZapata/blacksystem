<?php

class DocumentMessageProcessor implements MessageProcessorInterface {

  public function process(array $messages, array $context): array {
    $bot = $context['bot'];
    $person = $context['person'];
    $chatData = $context['chat_data'] ?? null;
    $documentMessage = null;
    $caption = '';

    // Buscar mensaje de tipo documento
    foreach ($messages as $msg) {
      $type = strtoupper($msg['type'] ?? 'TEXT');

      if ($type === 'DOCUMENT') {
        $documentMessage = $msg;
        $caption = $msg['text'] ?? '';
        break;
      }
    }

    if (!$documentMessage) {
      return [
        'success' => false,
        'error' => 'No document found in messages'
      ];
    }

    // Enviar mensaje educado rechazando el documento
    $this->sendDocumentRejectionMessage($person['number']);

    // Guardar mensaje del usuario en el chat
    $this->saveUserDocumentMessage($documentMessage, $caption, $context);

    // Guardar respuesta del bot
    $this->saveBotRejectionMessage($context);

    return [
      'success' => true,
      'document_rejected' => true,
      'caption' => $caption
    ];
  }

  private function sendDocumentRejectionMessage($to) {
    $message = "🚫 No puedo procesar documentos PDF.\n\n";
    $message .= "📸 Si enviaste un *comprobante de pago*, por favor:\n";
    $message .= "1️⃣ Abre el PDF en tu dispositivo\n";
    $message .= "2️⃣ Toma una *captura de pantalla*\n";
    $message .= "3️⃣ Envíame la imagen aquí\n\n";
    $message .= "Así podré verificar tu pago y confirmar tu pedido. ✅";

    $chatapi = ogApp()->service('chatApi');
    $chatapi::send($to, $message);
  }

  private function saveUserDocumentMessage($documentMessage, $caption, $context) {
    $bot = $context['bot'];
    $person = $context['person'];
    $chatData = $context['chat_data'];

    $messageText = !empty($caption) ? "[Documento PDF]: {$caption}" : "[Documento PDF enviado]";

    ogApp()->loadHandler('chat');
    ChatHandler::register(
      $bot['id'],
      $bot['number'],
      $chatData['client_id'],
      $person['number'],
      $messageText,
      'P',
      'document',
      [
        'document_url' => $documentMessage['media_url'] ?? null,
        'caption' => $caption,
        'rejected' => true,
        'reason' => 'document_not_supported'
      ],
      $chatData['current_sale']['sale_id'] ?? null
    );

    ChatHandler::addMessage([
      'number' => $person['number'],
      'bot_id' => $bot['id'],
      'client_id' => $chatData['client_id'],
      'sale_id' => $chatData['current_sale']['sale_id'] ?? null,
      'message' => $messageText,
      'format' => 'document',
      'metadata' => [
        'document_url' => $documentMessage['media_url'] ?? null,
        'caption' => $caption,
        'rejected' => true,
        'reason' => 'document_not_supported'
      ]
    ], 'P');
  }

  private function saveBotRejectionMessage($context) {
    $bot = $context['bot'];
    $person = $context['person'];
    $chatData = $context['chat_data'];

    $message = "🚫 No puedo procesar documentos PDF/DOC. Si enviaste un comprobante de pago, por favor toma una captura de pantalla y envíamela como imagen.";

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
        'action' => 'document_rejected',
        'reason' => 'document_not_supported'
      ],
      $chatData['current_sale']['sale_id'] ?? null
    );

    ChatHandler::addMessage([
      'number' => $person['number'],
      'bot_id' => $bot['id'],
      'client_id' => $chatData['client_id'],
      'sale_id' => $chatData['current_sale']['sale_id'] ?? null,
      'message' => $message,
      'format' => 'text',
      'metadata' => [
        'action' => 'document_rejected',
        'reason' => 'document_not_supported'
      ]
    ], 'B');
  }
}