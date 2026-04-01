<?php

class ActiveConversationStrategy implements ConversationStrategyInterface {
  private $logMeta = ['module' => 'ActiveConversationStrategy', 'layer' => 'app/workflows'];

  public function execute(array $context): array {
    $bot = $context['bot'];
    $person = $context['person'];
    $processedData = $context['processed_data'];
    $chatData = $context['chat_data'];

    $this->saveUserMessages($processedData, $context);

    $aiText = $processedData['ai_text'];
    $chat = $chatData ?? [];

    $prompt = $this->buildPrompt($bot, $chat, $aiText);
    ogLog::info("execute - Prompt construido", $prompt, $this->logMeta);

    // Mostrar "escribiendo..." entre 1.5-3 segundos antes de llamar IA (múltiplo de 100ms)
    $randomDelayMs = rand(15, 30) * 100; // 1500-3000ms en pasos de 100ms
    $randomDelay = $randomDelayMs / 1000;
    $this->sendTypingIndicator($person['number'], $randomDelay, true);

    // Llamar IA después del composing
    $aiStartTime = microtime(true);
    $aiResponse = $this->callAI($prompt, $bot);
    $aiDuration = microtime(true) - $aiStartTime;

    if (!$aiResponse['success']) {
      ogLog::error("execute - Error en llamada a IA", [ 'error' => $aiResponse['error'] ?? 'unknown' ], $this->logMeta);

      return [
        'success' => false,
        'error' => $aiResponse['error'] ?? 'AI call failed'
      ];
    }

    ogLog::info("execute - Respuesta de IA recibida", [
      'response_length' => strlen($aiResponse['response']),
      'response_preview' => substr($aiResponse['response'], 0, 200) . '...',
      'ai_duration' => round($aiDuration, 2) . 's'
    ], $this->logMeta);

    // LOG TEMPORAL: respuesta cruda completa de la IA
    ogLog::info("execute - RAW AI RESPONSE COMPLETO", [
      'raw' => $aiResponse['response']
    ], $this->logMeta);

    $parsedMessages = $this->parseResponse($aiResponse['response']);

    if (!$parsedMessages) {
      ogLog::error("execute - JSON inválido de IA", [ 'raw_response' => $aiResponse['response'] ], $this->logMeta);

      return [
        'success' => false,
        'error' => 'Invalid AI response'
      ];
    }

    $firstMsg = $parsedMessages[0];
    ogLog::info("execute - Respuesta parseada correctamente", [ 'messages_count' => count($parsedMessages), 'message_length' => strlen($firstMsg['message'] ?? ''), 'message_preview' => substr($firstMsg['message'] ?? '', 0, 150), 'has_metadata' => isset($firstMsg['metadata']), 'action' => $firstMsg['metadata']['action'] ?? 'none' ], $this->logMeta);

    // Inyectar pre-payment media si aplica (antes de que la IA envíe los datos bancarios)
    $parsedMessages = $this->injectPrePaymentMedia($parsedMessages, $context);

    // Enviar cada mensaje con typing indicator entre ellos
    foreach ($parsedMessages as $i => $msg) {
      if ($i > 0) {
        $delayMs = rand(10, 20) * 100; // 1000-2000ms entre mensajes
        $this->sendTypingIndicator($person['number'], $delayMs / 1000, true);
      }
      $this->sendMessageDirect($msg, $context);
      $this->saveBotMessages($msg, $context);
    }

    $lastMsg = end($parsedMessages);
    return [
      'success' => true,
      'ai_response' => $lastMsg
    ];
  }

  private function saveUserMessages($processedData, $context) {
    $messages = $processedData['interpreted_messages'] ?? [];
    $chatData = $context['chat_data'];
    $bot = $context['bot'];
    $person = $context['person'];

    $skipUnread = $context['skip_unread'] ?? false;

    ogApp()->loadHandler('chat');
    foreach ($messages as $msg) {
      ChatHandler::register(
        $bot['id'],
        $bot['number'],
        $chatData['client_id'],
        $person['number'],
        $msg['text'],
        'P',
        $msg['type'] ?? 'text',
        null,
        $chatData['current_sale']['sale_id'] ?? null,
        $skipUnread
      );

      ChatHandler::addMessage([
        'number' => $person['number'],
        'bot_id' => $bot['id'],
        'client_id' => $chatData['client_id'],
        'sale_id' => $chatData['current_sale']['sale_id'] ?? null,
        'message' => $msg['text'],
        'format' => $msg['type'] ?? 'text',
        'metadata' => null
      ], 'P');
    }
  }

  private function buildPrompt($bot, $chat, $aiText) {
    require_once ogApp()->getPath() . '/workflows/core/builders/PromptBuilder.php';

    $promptFile = ogApp()->getPath() . '/workflows/prompts/infoproduct/' . ($bot['prompt_recibo'] ?? 'recibo.txt');
    if (!file_exists($promptFile)) {
      ogLog::throwError("Prompt file not found: {$promptFile}", [], self::$logMeta);
    }

    $promptSystem = file_get_contents($promptFile);
    $botPrompt = !empty($bot['personality']) ? "\n\n---\n\n## Personalidad Especifica\n" . $bot['personality'] : '';
    $promptSystem .= $botPrompt;

    return PromptBuilder::buildWithCache($bot, $chat, $aiText, $promptSystem);
  }

  private function callAI($prompt, $bot) {
    try {
      $ai = ogApp()->service('ai');
      return $ai->getChatCompletion($prompt, $bot, []);
    } catch (Exception $e) {
      return [
        'success' => false,
        'error' => $e->getMessage()
      ];
    }
  }

  private function parseResponse($response) {
    $decoded = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
      return null;
    }

    // Objeto único → envolver en array
    if (isset($decoded['message'])) {
      return [$decoded];
    }

    // Array de mensajes
    if (is_array($decoded) && !empty($decoded) && isset($decoded[0]['message'])) {
      return $decoded;
    }

    return null;
  }

  /**
   * Si la IA devuelve payment_method_template y el producto tiene un template
   * con template_id = "pre-payment-media" que aún no fue enviado en el historial,
   * lo inyecta como primer mensaje antes de los datos bancarios.
   */
  private function injectPrePaymentMedia(array $messages, array $context): array {
    $lastMsg = end($messages);
    $action  = $lastMsg['metadata']['action'] ?? '';

    $chatData      = $context['chat_data'];
    $historyMessages = $chatData['messages'] ?? [];

    // Determinar si es el primer mensaje del bot en esta conversación
    $hasBotMessages = false;
    foreach ($historyMessages as $hMsg) {
      if (($hMsg['type'] ?? '') === 'B') {
        $hasBotMessages = true;
        break;
      }
    }
    $isFirstBotMessage = !$hasBotMessages;

    // Inyectar solo en: primer mensaje del bot O cuando se envían métodos de pago
    if ($action !== 'payment_method_template' && !$isFirstBotMessage) {
      return $messages;
    }

    $productId = $chatData['current_sale']['product_id'] ?? null;
    if (!$productId) return $messages;

    // Recopilar URLs ya enviadas en el historial
    $sentUrls = [];
    foreach ($historyMessages as $hMsg) {
      $sentUrl = $hMsg['metadata']['source_url'] ?? '';
      if ($sentUrl !== '') $sentUrls[] = $sentUrl;
    }

    // Buscar el primer pre-payment-media que aún no fue enviado (en orden)
    ogApp()->loadHandler('product');
    $templates = ogApp()->handler('product')::getTemplatesFile($productId) ?? [];
    $preMedia  = null;
    foreach ($templates as $tpl) {
      if (($tpl['template_id'] ?? '') !== 'pre-payment-media') continue;
      $url = $tpl['url'] ?? '';
      if ($url === '') continue;
      if (!in_array($url, $sentUrls)) {
        $preMedia = $tpl;
        break;
      }
    }

    if (!$preMedia) return $messages;

    // Construir el mensaje previo con el audio/imagen
    $saleId    = $chatData['current_sale']['sale_id'] ?? null;
    $productId = $chatData['current_sale']['product_id'] ?? null;
    $preMsg = [
      'message'    => $preMedia['message'] ?? '',
      'type'       => 'text',
      'source_url' => $preMedia['url'] ?? '',
      'metadata'   => [
        'action'     => 'pre-payment-media',
        'sale_id'    => (string)$saleId,
        'product_id' => (string)$productId
      ]
    ];

    // Insertar al inicio del array de mensajes
    array_unshift($messages, $preMsg);

    return $messages;
  }

  private function sendMessageDirect($parsedResponse, $context) {
    $person = $context['person'];
    $message = $parsedResponse['message'] ?? '';
    $sourceUrl = $parsedResponse['source_url'] ?? '';
    $buttons = $parsedResponse['buttons'] ?? [];
    $footer  = $parsedResponse['footer']  ?? '';

    $action = $parsedResponse['metadata']['action'] ?? '';
    $noButtonActions = ['delivered_product', 'technical_support', 'refund_request'];
    if (in_array($action, $noButtonActions)) {
      $buttons = [];
      $footer  = '';
    }

    if (!empty($message) || !empty($sourceUrl)) {
      $chatapi = ogApp()->service('chatApi');
      $chatapi::send($person['number'], $message, $sourceUrl, [
        'buttons' => $buttons,
        'footer'  => $footer
      ]);
    }
  }

  private function sendMessages($parsedResponse, $context) {
    $this->sendMessageDirect($parsedResponse, $context);
  }

  private function sendTypingIndicator($to, $delay, $block = true) {

    try {
      $chatapi = ogApp()->service('chatApi');
      $provider = $chatapi::getProvider();

      if (!$block) {
        $delayMs = $delay * 1000;
        $chatapi::sendPresence($to, 'composing', $delayMs);
        return;
      }

      $iterations = ceil($delay / 3);

      for ($i = 0; $i < $iterations; $i++) {
        $remaining = $delay - ($i * 3);
        $duration = min($remaining, 3);
        $durationMs = $duration * 1000;

        try {
          $result = $chatapi::sendPresence($to, 'composing', $durationMs);
        } catch (Exception $e) {
          sleep($duration);
          continue;
        }
      }

    } catch (Exception $e) {
      ogLog::error("sendTypingIndicator - Error fatal", [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
      ], $this->logMeta);
    }
  }

  private function saveBotMessages($parsedResponse, $context) {
    $bot = $context['bot'];
    $person = $context['person'];
    $chatData = $context['chat_data'];

    $message = $parsedResponse['message'] ?? '';
    $metadata = $parsedResponse['metadata'] ?? [];
    $buttons   = $parsedResponse['buttons']    ?? [];
    $footer    = $parsedResponse['footer']     ?? '';
    $sourceUrl = $parsedResponse['source_url'] ?? '';
    $hasButtons = !empty($buttons);
    $format = $hasButtons ? 'interactive' : 'text';

    if ($hasButtons) {
      $metadata['buttons'] = $buttons;
    }
    if ($footer !== '') {
      $metadata['footer'] = $footer;
    }
    if ($sourceUrl !== '') {
      $metadata['source_url'] = $sourceUrl;
    }
    if (empty($metadata)) {
      $metadata = null;
    }

    ogApp()->loadHandler('chat');
    ChatHandler::register(
      $bot['id'],
      $bot['number'],
      $chatData['client_id'],
      $person['number'],
      $message,
      'B',
      $format,
      $metadata,
      $chatData['current_sale']['sale_id'] ?? null,
      true
    );

    ChatHandler::addMessage([
      'number' => $person['number'],
      'bot_id' => $bot['id'],
      'client_id' => $chatData['client_id'],
      'sale_id' => $chatData['current_sale']['sale_id'] ?? null,
      'message' => $message,
      'format' => $format,
      'metadata' => $metadata
    ], 'B');
  }
}