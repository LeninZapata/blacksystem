<?php

class TextMessageProcessor implements MessageProcessorInterface {

  public function process(array $messages, array $context): array {
    $bot = $context['bot'];
    $interpretedMessages = [];

    require_once ogApp()->getPath() . '/workflows/infoproduct/interpreters/TextInterpreter.php';
    require_once ogApp()->getPath() . '/workflows/infoproduct/interpreters/AudioInterpreter.php';

    foreach ($messages as $msg) {
      $type = strtoupper($msg['type'] ?? 'TEXT');

      if ($type === 'TEXT') {
        $interpreted = TextInterpreter::interpret($msg);
      } elseif ($type === 'AUDIO') {
        $interpreted = AudioInterpreter::interpret($msg, $bot);
      } else {
        $interpreted = ['type' => 'text', 'text' => $msg['text'] ?? ''];
      }

      $interpretedMessages[] = $interpreted;
    }

    $aiText = $this->buildAIText($interpretedMessages);

    return [
      'type' => 'text',
      'interpreted_messages' => $interpretedMessages,
      'ai_text' => $aiText
    ];
  }

  private function buildAIText($interpretedMessages) {
    $lines = [];

    foreach ($interpretedMessages as $msg) {
      $type = $msg['type'];
      $text = $msg['text'];
      $lines[] = "[{$type}]: {$text}";
    }

    return implode("\n", $lines);
  }
}