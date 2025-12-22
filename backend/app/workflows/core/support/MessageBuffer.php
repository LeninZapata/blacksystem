<?php

class MessageBuffer {
  private $bufferDir;
  private $delaySeconds;

  function __construct($delaySeconds = 10) {
    $this->bufferDir = SHARED_PATH . '/chats/buffer/';
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

      log::debug('Mensaje agregado a buffer activo', [
        'total_messages' => count($buffer['messages'])
      ], ['module' => 'buffer']);
      
      return null;
    }

    if ($buffer) {
      $accumulated = $buffer['messages'];
      @unlink($bufferFile);

      log::info('Buffer expirado - Procesando mensajes', [
        'total' => count($accumulated)
      ], ['module' => 'buffer']);
      
      return $this->prepareMessages($accumulated);
    }

    $this->createBuffer($bufferFile, $message);
    
    log::info('Primer mensaje - Iniciando espera de ' . $this->delaySeconds . 's', [], ['module' => 'buffer']);

    return $this->waitAndProcess($bufferFile);
  }

  private function waitAndProcess($bufferFile) {
    $startTime = time();
    $checkInterval = 1;

    while (true) {
      sleep($checkInterval);

      $buffer = $this->readBuffer($bufferFile);
      if (!$buffer) return null;

      $timeSinceLast = time() - $buffer['last_update'];

      if ($timeSinceLast >= $this->delaySeconds) {
        @unlink($bufferFile);
        
        log::success('Timer completado - Procesando ' . count($buffer['messages']) . ' mensajes', [], ['module' => 'buffer']);
        
        return $this->prepareMessages($buffer['messages']);
      }

      if (time() - $startTime > 60) {
        @unlink($bufferFile);
        
        log::warning('Timeout alcanzado - Procesando buffer', [], ['module' => 'buffer']);
        
        return $this->prepareMessages($buffer['messages']);
      }
    }
  }

  private function prepareMessages($messages) {
    return [
      'messages' => $messages,  // ← CAMBIO: usar 'messages' en vez de todo el objeto
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

    if ($cleaned > 0) {
      log::info('Buffers antiguos limpiados', [
        'count' => $cleaned
      ], ['module' => 'buffer']);
    }
  }
}