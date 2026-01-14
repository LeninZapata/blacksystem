<?php
class testingProvider extends baseChatApiProvider {

  private $simulateError = false;
  private $errorType = 'connection';

  function __construct(array $config) {
    parent::__construct($config);
    $this->simulateError = $config['simulate_error'] ?? false;
    $this->errorType = $config['error_type'] ?? 'connection';
  }

  function validateConfig(): bool {
    return true;
  }

  function getProviderName(): string {
    return 'Testing API';
  }

  function sendMessage(string $number, string $message, string $url = ''): array {
    $number = $this->formatNumber($number);
    $mediaType = $this->detectMediaType($url);

    if ($this->simulateError) return $this->generateError('sendMessage');

    usleep(200000);

    return [
      'success' => true,
      'api' => $this->getProviderName(),
      'number' => $number,
      'message_type' => $mediaType,
      'message_id' => 'TEST-' . uniqid(),
      'timestamp' => time(),
      'status' => 'sent',
      'payload_sent' => ['number' => $number, 'message' => $message, 'url' => $url, 'media_type' => $mediaType]
    ];
  }

  function sendPresence(string $number, string $presenceType, int $delay = 1200): array {
    $number = $this->formatNumber($number);
    $validPresences = ['composing', 'recording', 'paused'];
    if (!in_array($presenceType, $validPresences)) return $this->errorResponse('Tipo de presencia no válido');

    if ($this->simulateError) return $this->generateError('sendPresence');

    usleep(200000);

    return [
      'success' => true,
      'api' => $this->getProviderName(),
      'number' => $number,
      'presence' => $presenceType,
      'delay' => $delay,
      'status' => 'presence_sent',
      'timestamp' => time()
    ];
  }

  private function generateError($method): array {
    $errors = [
      'connection' => ['success' => false, 'error' => 'Error de conexión con el servidor', 'error_code' => 'CONNECTION_FAILED'],
      'authentication' => ['success' => false, 'error' => 'Error de autenticación', 'error_code' => 'AUTH_FAILED'],
      'rate_limit' => ['success' => false, 'error' => 'Límite de tasa excedido', 'error_code' => 'RATE_LIMIT_EXCEEDED'],
      'invalid_number' => ['success' => false, 'error' => 'Número de teléfono inválido', 'error_code' => 'INVALID_NUMBER'],
      'media_error' => ['success' => false, 'error' => 'Error al procesar el medio', 'error_code' => 'MEDIA_PROCESSING_FAILED']
    ];

    $error = $errors[$this->errorType] ?? $errors['connection'];
    $error['method'] = $method;
    $error['timestamp'] = time();
    $error['api'] = $this->getProviderName();

    return $error;
  }

  function sendArchive(string $chatNumber, string $lastMessageId = 'archive', bool $archive = true): array {
    $chatNumber = $this->formatNumber($chatNumber);

    if ($this->simulateError) return $this->generateError('sendArchive');

    usleep(200000);

    return [
      'success' => true,
      'api' => $this->getProviderName(),
      'chat' => $chatNumber,
      'archive' => $archive,
      'last_message_id' => $lastMessageId,
      'status' => $archive ? 'archived' : 'unarchived',
      'timestamp' => time(),
      'payload_sent' => [
        'chat' => $chatNumber,
        'archive' => $archive,
        'lastMessage' => ['key' => ['remoteJid' => $chatNumber, 'fromMe' => false, 'id' => $lastMessageId]]
      ]
    ];
  }
}