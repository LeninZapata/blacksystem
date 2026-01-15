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
    ogLog::debug("execute - Prompt construido", $prompt, $this->logMeta);

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

    $parsedResponse = $this->parseResponse($aiResponse['response']);

    if (!$parsedResponse) {
      ogLog::error("execute - JSON inválido de IA", [ 'raw_response' => $aiResponse['response'] ], $this->logMeta);

      return [
        'success' => false,
        'error' => 'Invalid AI response'
      ];
    }

    ogLog::info("execute - Respuesta parseada correctamente", [ 'message_length' => strlen($parsedResponse['message'] ?? ''), 'message_preview' => substr($parsedResponse['message'] ?? '', 0, 150), 'has_metadata' => isset($parsedResponse['metadata']), 'action' => $parsedResponse['metadata']['action'] ?? 'none' ], $this->logMeta);

    // Enviar mensaje inmediatamente
    $this->sendMessageDirect($parsedResponse, $context);
    $this->saveBotMessages($parsedResponse, $context);
    ogLog::info("execute - Completado exitosamente", [], $this->logMeta);
    return [
      'success' => true,
      'ai_response' => $parsedResponse
    ];
  }

  private function saveUserMessages($processedData, $context) {
    $messages = $processedData['interpreted_messages'] ?? [];
    $chatData = $context['chat_data'];
    $bot = $context['bot'];
    $person = $context['person'];

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
        $chatData['current_sale']['sale_id'] ?? null
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

    return $decoded;
  }

  private function sendMessageDirect($parsedResponse, $context) {
    $person = $context['person'];
    $message = $parsedResponse['message'] ?? '';
    $sourceUrl = $parsedResponse['source_url'] ?? '';

    if (!empty($message)) {
      $chatapi = ogApp()->service('chatApi');
      $chatapi::send($person['number'], $message, $sourceUrl);
    }
  }

  private function sendMessages($parsedResponse, $context) {
    $this->sendMessageDirect($parsedResponse, $context);
  }

  private function sendTypingIndicator($to, $delay, $block = true) {
    ogLog::debug("sendTypingIndicator - Iniciando", [
      'to' => $to,
      'delay_seconds' => $delay,
      'block' => $block
    ], $this->logMeta);

    try {
      $chatapi = ogApp()->service('chatApi');
      $provider = $chatapi::getProvider();

      ogLog::debug("sendTypingIndicator - Provider detectado", [
        'provider' => $provider
      ], $this->logMeta);

      if (!$block) {
        $delayMs = $delay * 1000;
        ogLog::debug("sendTypingIndicator - Modo no bloqueante", [
          'delay_ms' => $delayMs
        ], $this->logMeta);
        $chatapi::sendPresence($to, 'composing', $delayMs);
        return;
      }

      $iterations = ceil($delay / 3);
      ogLog::debug("sendTypingIndicator - Calculando iteraciones", [
        'iterations' => $iterations,
        'delay_total' => $delay
      ], $this->logMeta);

      for ($i = 0; $i < $iterations; $i++) {
        $remaining = $delay - ($i * 3);
        $duration = min($remaining, 3);
        $durationMs = $duration * 1000;

        ogLog::debug("sendTypingIndicator - Iteración", [
          'iteration' => $i + 1,
          'remaining_seconds' => $remaining,
          'duration_seconds' => $duration,
          'duration_ms' => $durationMs
        ], $this->logMeta);

        try {
          $result = $chatapi::sendPresence($to, 'composing', $durationMs);
          ogLog::debug("sendTypingIndicator - sendPresence ejecutado", [
            'iteration' => $i + 1,
            'result' => $result
          ], $this->logMeta);
        } catch (Exception $e) {
          ogLog::debug("sendTypingIndicator - Error en sendPresence, usando sleep", [
            'iteration' => $i + 1,
            'error' => $e->getMessage(),
            'sleep_duration' => $duration
          ], $this->logMeta);
          sleep($duration);
          continue;
        }
      }

      ogLog::debug("sendTypingIndicator - Completado exitosamente", [
        'total_iterations' => $iterations
      ], $this->logMeta);

    } catch (Exception $e) {
      ogLog::debug("sendTypingIndicator - Error fatal", [
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
    $metadata = $parsedResponse['metadata'] ?? null;

    ogApp()->loadHandler('chat');
    ChatHandler::register(
      $bot['id'],
      $bot['number'],
      $chatData['client_id'],
      $person['number'],
      $message,
      'B',
      'text',
      $metadata,
      $chatData['current_sale']['sale_id'] ?? null
    );

    ChatHandler::addMessage([
      'number' => $person['number'],
      'bot_id' => $bot['id'],
      'client_id' => $chatData['client_id'],
      'sale_id' => $chatData['current_sale']['sale_id'] ?? null,
      'message' => $message,
      'format' => 'text',
      'metadata' => $metadata
    ], 'B');
  }
}