<?php
class messageBuffer {
  private $bufferDir;
  private $delaySeconds;

  function __construct($delaySeconds = 10) {
    $this->bufferDir = SHARED_PATH . '/chats/buffer/';
    $this->delaySeconds = $delaySeconds;

    if (!is_dir($this->bufferDir)) {
      mkdir($this->bufferDir, 0777, true);
    }
  }

  // Procesar mensaje y acumular en buffer
  function process($number, $botId, $message) {
    $bufferFile = $this->getBufferFile($number, $botId);
    $buffer = $this->readBuffer($bufferFile);

    // Si existe buffer activo (otro webhook esperando)
    if ($buffer && !$this->hasExpired($buffer)) {
      $buffer['messages'][] = $message;
      $buffer['last_update'] = time();
      $this->writeBuffer($bufferFile, $buffer);

      log::debug('Mensaje agregado a buffer activo', ['total_messages' => count($buffer['messages'])], ['module' => 'buffer']);
      return null; // Este webhook termina aquí
    }

    // Si buffer expiró, procesar mensajes acumulados
    if ($buffer) {
      $accumulated = $buffer['messages'];
      @unlink($bufferFile);

      log::info('Buffer expirado - Procesando mensajes', ['total' => count($accumulated)], ['module' => 'buffer']);
      return $this->prepareMessages($accumulated);
    }

    // Primer mensaje - crear buffer y esperar
    $this->createBuffer($bufferFile, $message);
    log::info('Primer mensaje - Iniciando espera de ' . $this->delaySeconds . 's', [], ['module' => 'buffer']);

    return $this->waitAndProcess($bufferFile);
  }

  // Esperar y procesar mensajes acumulados
  private function waitAndProcess($bufferFile) {
    $startTime = time();
    $checkInterval = 1;

    while (true) {
      sleep($checkInterval);

      $buffer = $this->readBuffer($bufferFile);
      if (!$buffer) return null;

      $timeSinceLast = time() - $buffer['last_update'];

      // Si pasó el delay, procesar
      if ($timeSinceLast >= $this->delaySeconds) {
        @unlink($bufferFile);
        log::success('Timer completadoaaa - Procesando ' . count($buffer['messages']) . ' mensajes', [], ['module' => 'buffer']);
        return $this->prepareMessages($buffer['messages']);
      }

      // Timeout de seguridad (60s máximo)
      if (time() - $startTime > 60) {
        @unlink($bufferFile);
        log::warning('Timeout alcanzado - Procesando buffer', [], ['module' => 'buffer']);
        return $this->prepareMessages($buffer['messages']);
      }
    }
  }

  // Preparar mensajes para retornar
  private function prepareMessages($messages) {
    return ['accumulated' => true, 'count' => count($messages), 'messages' => $messages];
  }

  // Crear nuevo buffer
  private function createBuffer($bufferFile, $message) {
    $buffer = ['messages' => [$message], 'first_time' => time(), 'last_update' => time()];
    $this->writeBuffer($bufferFile, $buffer);
  }

  // Leer buffer
  private function readBuffer($file) {
    if (!file_exists($file)) return null;
    $content = file_get_contents($file);
    return $content ? json_decode($content, true) : null;
  }

  // Escribir buffer
  private function writeBuffer($file, $buffer) {
    file_put_contents($file, json_encode($buffer, JSON_PRETTY_PRINT));
  }

  // Verificar si expiró
  private function hasExpired($buffer) {
    return (time() - $buffer['last_update']) >= $this->delaySeconds;
  }

  // Obtener archivo de buffer
  private function getBufferFile($number, $botId) {
    return $this->bufferDir . "chat_{$number}_bot_{$botId}.json";
  }

  // Limpiar buffers antiguos (>1 hora)
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

    if ($cleaned > 0) log::info('Buffers antiguos limpiados', ['count' => $cleaned], ['module' => 'buffer']);
  }
}