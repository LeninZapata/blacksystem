<?php

class MessageBuffer {
  private $bufferDir;
  private $delaySeconds;

  function __construct($delaySeconds = 10) {
    $this->bufferDir = ogApp()->getPath('storage/json/chats/buffer') . '/';
    $this->delaySeconds = $delaySeconds;

    if (!is_dir($this->bufferDir)) {
      mkdir($this->bufferDir, 0777, true);
    }
  }

  function process($number, $botId, $message) {
    $bufferFile = $this->getBufferFile($number, $botId);
    $buffer = $this->readBuffer($bufferFile);

    if ($buffer && !$this->hasExpired($buffer)) {
      $buffer['messages'][] = $message;
      $buffer['last_update'] = time();
      $this->writeBuffer($bufferFile, $buffer);
      return null;
    }

    if ($buffer) {
      $accumulated = $buffer['messages'];
      @unlink($bufferFile);
      return $this->prepareMessages($accumulated);
    }

    $this->createBuffer($bufferFile, $message);

    return $this->waitAndProcess($bufferFile);
  }

  private function waitAndProcess($bufferFile) {
    $startTime = time();
    $checkInterval = 1;
    $iteration = 0;

    while (true) {
      sleep($checkInterval);
      $iteration++;

      $buffer = $this->readBuffer($bufferFile);
      if (!$buffer) {
        ogLog::warning('Buffer - Archivo desapareció durante espera', [
          'iteration' => $iteration
        ], ['module' => 'buffer']);
        return null;
      }

      $now = time();
      $timeSinceLast = $now - $buffer['last_update'];
      $timeSinceStart = $now - $startTime;

      if ($timeSinceLast >= $this->delaySeconds) {
        @unlink($bufferFile);
        return $this->prepareMessages($buffer['messages']);
      }

      if ($timeSinceStart > 60) {
        @unlink($bufferFile);
        return $this->prepareMessages($buffer['messages']);
      }
    }
  }

  private function prepareMessages($messages) {
    return [
      'messages' => $messages,
      'accumulated' => true,
      'count' => count($messages)
    ];
  }

  private function createBuffer($bufferFile, $message) {
    $buffer = [
      'messages' => [$message],
      'first_time' => time(),
      'last_update' => time()
    ];
    $this->writeBuffer($bufferFile, $buffer);
  }

  private function readBuffer($file) {
    clearstatcache(true, $file); // Limpiar cache antes de leer
    if (!file_exists($file)) return null;
    $content = file_get_contents($file);
    return $content ? json_decode($content, true) : null;
  }

  private function writeBuffer($file, $buffer) {
    file_put_contents($file, json_encode($buffer, JSON_PRETTY_PRINT));
  }

  private function hasExpired($buffer) {
    return (time() - $buffer['last_update']) >= $this->delaySeconds;
  }

  private function getBufferFile($number, $botId) {
    return $this->bufferDir . "chat_{$number}_bot_{$botId}.json";
  }

  function cleanOld() {
    $files = glob($this->bufferDir . 'chat_*.json');
    $cleaned = 0;

    foreach ($files as $file) {
      $buffer = $this->readBuffer($file);
      if ($buffer && isset($buffer['first_time'])) {
        if (time() - $buffer['first_time'] > TIME_HOUR) {
          @unlink($file);
          $cleaned++;
        }
      }
    }

    // cleaned silently
  }
}