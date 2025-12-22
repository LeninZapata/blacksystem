<?php

class ActiveConversationStrategy implements ConversationStrategyInterface {

  public function execute(array $context): array {
    $bot = $context['bot'];
    $person = $context['person'];
    $processedData = $context['processed_data'];
    $chatData = $context['chat_data'];

    $this->saveUserMessages($processedData, $context);

    $aiText = $processedData['ai_text'];
    $chat = $chatData['full_chat'] ?? [];

    $prompt = $this->buildPrompt($bot, $chat, $aiText);

    $aiResponse = $this->callAI($prompt, $bot);

    if (!$aiResponse['success']) {
      return [
        'success' => false,
        'error' => $aiResponse['error'] ?? 'AI call failed'
      ];
    }

    $parsedResponse = $this->parseResponse($aiResponse['response']);

    if (!$parsedResponse) {
      return [
        'success' => false,
        'error' => 'Invalid AI response'
      ];
    }

    $this->sendMessages($parsedResponse, $context);
    $this->saveBotMessages($parsedResponse, $context);

    return [
      'success' => true,
      'ai_response' => $parsedResponse
    ];
  }

  private function saveUserMessages($processedData, $context) {
    $messages = $processedData['interpreted_messages'] ?? [];
    $bot = $context['bot'];
    $person = $context['person'];
    $chatData = $context['chat_data'];

    foreach ($messages as $msg) {
      chatHandlers::register(
        $bot['id'],
        $bot['number'],
        $chatData['client_id'],
        $person['number'],
        $msg['text'],
        'P',
        $msg['type'] ?? 'text',
        null,
        $chatData['sale_id']
      );

      chatHandlers::addMessage([
        'number' => $person['number'],
        'bot_id' => $bot['id'],
        'client_id' => $chatData['client_id'],
        'sale_id' => $chatData['sale_id'],
        'message' => $msg['text'],
        'format' => $msg['type'] ?? 'text',
        'metadata' => null
      ], 'P');
    }
  }

  private function buildPrompt($bot, $chat, $aiText) {
    require_once APP_PATH . '/workflows/core/builders/PromptBuilder.php';

    $promptFile = APP_PATH . '/workflows/prompts/infoproduct/recibo.txt';

    if (!file_exists($promptFile)) {
      throw new Exception("Prompt file not found: {$promptFile}");
    }

    $promptSystem = file_get_contents($promptFile);
    $botPrompt = !empty($bot['personality']) ? "\n\n---\n\n## Personalidad Especifica\n" . $bot['personality'] : '';
    $promptSystem .= $botPrompt;

    return PromptBuilder::buildWithCache($bot, $chat, $aiText, $promptSystem);
  }

  private function callAI($prompt, $bot) {
    try {
      $ai = service::integration('ai');
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

  private function sendMessages($parsedResponse, $context) {
    $person = $context['person'];
    $message = $parsedResponse['message'] ?? '';
    $sourceUrl = $parsedResponse['source_url'] ?? '';

    if (!empty($message)) {
      chatapi::send($person['number'], $message, $sourceUrl);
    }
  }

  private function saveBotMessages($parsedResponse, $context) {
    $bot = $context['bot'];
    $person = $context['person'];
    $chatData = $context['chat_data'];

    $message = $parsedResponse['message'] ?? '';
    $metadata = $parsedResponse['metadata'] ?? null;

    chatHandlers::register(
      $bot['id'],
      $bot['number'],
      $chatData['client_id'],
      $person['number'],
      $message,
      'B',
      'text',
      $metadata,
      $chatData['sale_id']
    );

    chatHandlers::addMessage([
      'number' => $person['number'],
      'bot_id' => $bot['id'],
      'client_id' => $chatData['client_id'],
      'sale_id' => $chatData['sale_id'],
      'message' => $message,
      'format' => 'text',
      'metadata' => $metadata
    ], 'B');
  }
}