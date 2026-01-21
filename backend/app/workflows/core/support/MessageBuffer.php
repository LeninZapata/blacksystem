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

    ogLog::info('Buffer - process() llamado', [
      'number' => $number,
      'bot_id' => $botId,
      'buffer_exists' => $buffer !== null,
      'buffer_expired' => $buffer ? $this->hasExpired($buffer) : null,
      'buffer_file' => basename($bufferFile)
    ], ['module' => 'buffer']);

    if ($buffer && !$this->hasExpired($buffer)) {
      $buffer['messages'][] = $message;
      $buffer['last_update'] = time();
      $this->writeBuffer($bufferFile, $buffer);

      ogLog::info('Buffer - Mensaje agregado a buffer activo', [
        'number' => $number,
        'total_messages' => count($buffer['messages']),
        'time_since_first' => time() - $buffer['first_time'],
        'seconds_since_last_update' => 0
      ], ['module' => 'buffer']);
      
      return null;
    }

    if ($buffer) {
      $accumulated = $buffer['messages'];
      @unlink($bufferFile);

      ogLog::info('Buffer - Expirado, procesando mensajes acumulados', [
        'number' => $number,
        'total' => count($accumulated)
      ], ['module' => 'buffer']);
      
      return $this->prepareMessages($accumulated);
    }

    $this->createBuffer($bufferFile, $message);
    
    ogLog::info('Buffer - Primer mensaje, iniciando espera de ' . $this->delaySeconds . 's', [
      'number' => $number,
      'bot_id' => $botId
    ], ['module' => 'buffer']);

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

      ogLog::info('Buffer - Esperando mensajes', [
        'iteration' => $iteration,
        'messages_count' => count($buffer['messages']),
        'seconds_since_last_message' => $timeSinceLast,
        'total_wait_time' => $timeSinceStart,
        'delay_threshold' => $this->delaySeconds
      ], ['module' => 'buffer']);

      if ($timeSinceLast >= $this->delaySeconds) {
        @unlink($bufferFile);
        
        ogLog::success('Buffer - Timer completado, procesando ' . count($buffer['messages']) . ' mensajes', [
          'total_messages' => count($buffer['messages']),
          'total_wait_time' => $timeSinceStart
        ], ['module' => 'buffer']);
        
        return $this->prepareMessages($buffer['messages']);
      }

      if ($timeSinceStart > 60) {
        @unlink($bufferFile);
        
        ogLog::warning('Buffer - Timeout alcanzado, procesando buffer', [
          'messages_count' => count($buffer['messages']),
          'total_wait_time' => $timeSinceStart
        ], ['module' => 'buffer']);
        
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

    if ($cleaned > 0) {
      ogLog::info('Buffers antiguos limpiados', [
        'count' => $cleaned
      ], ['module' => 'buffer']);
    }
  }
}