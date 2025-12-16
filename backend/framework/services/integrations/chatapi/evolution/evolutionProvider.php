<?php
class evolutionProvider extends baseChatApiProvider {

  function validateConfig(): bool {
    parent::validateConfig();
    if (empty($this->baseUrl)) $this->baseUrl = 'https://evolution-api.com';
    return true;
  }

  function getProviderName(): string {
    return 'Evolution API';
  }

  function sendMessage(string $number, string $message, string $url = ''): array {
    $number = $this->formatNumber($number);
    $mediaType = $this->detectMediaType($url);
    $payload = $this->buildPayload($number, $message, $url, $mediaType);
    $endpoint = $this->getEndpoint($mediaType);
    $result = $this->sendRequest($endpoint, $payload);

    return [
      'success' => true,
      'api' => $this->getProviderName(),
      'number' => $number,
      'message_type' => $mediaType,
      'message_id' => 'EVO-' . uniqid(),
      'timestamp' => time()
    ];
  }

  function sendPresence(string $number, string $presenceType, int $delay = 1200): array {
    $number = $this->formatNumber($number);
    $validPresences = ['composing', 'recording', 'paused'];
    if (!in_array($presenceType, $validPresences)) return $this->errorResponse('Tipo de presencia no válido');

    $payload = ['number' => $number, 'presence' => $presenceType, 'delay' => $delay];
    $endpoint = "{$this->baseUrl}/chat/sendPresence/{$this->instance}";
    $this->sendRequest($endpoint, $payload);

    return ['success' => true, 'api' => $this->getProviderName(), 'number' => $number, 'presence' => $presenceType, 'delay' => $delay];
  }

  private function buildPayload($number, $message, $url, $mediaType): array {
    $payload = [
      'number' => $number,
      'text' => $mediaType === 'text' ? $message : '',
      'mediatype' => $mediaType,
      'mimetype' => '',
      'media' => $mediaType !== 'text' ? $url : '',
      'caption' => $mediaType !== 'text' && $this->shouldIncludeText($mediaType, $message) ? $message : '',
      'filename' => '',
      'audio' => ''
    ];

    if ($mediaType !== 'text' && !empty($url)) {
      $filename = basename(parse_url($url, PHP_URL_PATH));
      $payload['filename'] = time() . '-' . $filename;
      $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
      $payload['mimetype'] = $this->getMimeType($ext);
      if ($mediaType === 'audio') $payload['audio'] = $url;
    }

    return $payload;
  }

  private function getEndpoint($mediaType): string {
    $base = "{$this->baseUrl}/message";
    if ($mediaType === 'audio') return "{$base}/sendWhatsAppAudio/{$this->instance}";
    if ($mediaType === 'text') return "{$base}/sendText/{$this->instance}";
    return "{$base}/sendMedia/{$this->instance}";
  }

  private function sendRequest($endpoint, $payload): array {
    try {
      $ch = curl_init($endpoint);
      curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
          'apikey: ' . $this->apiKey,
          'Content-Type: application/json'
        ],
        CURLOPT_TIMEOUT => 30
      ]);

      $response = curl_exec($ch);
      $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);

      $data = json_decode($response, true) ?? [];
      return ['status' => 'success', 'data' => $data, 'httpCode' => $httpCode];
    } catch (Exception $e) {
      log::error('evolutionProvider::sendRequest - Error', ['error' => $e->getMessage()], ['module' => 'chatapi']);
      return ['status' => 'error', 'data' => [], 'httpCode' => null, 'error' => $e->getMessage()];
    }
  }

  function sendArchive(string $chatNumber, string $lastMessageId = 'archive', bool $archive = true): array {
    $chatNumber = $this->formatNumber($chatNumber);
    $payload = [
      'chat' => $chatNumber,
      'archive' => $archive,
      'lastMessage' => [
        'key' => [
          'remoteJid' => $chatNumber,
          'fromMe' => false,
          'id' => $lastMessageId . '-' . time()
        ]
      ]
    ];

    $endpoint = "{$this->baseUrl}/chat/archiveChat/{$this->instance}";
    $result = $this->sendRequest($endpoint, $payload);

    if ($result['status'] === 'success') {
      return $this->successResponse(['chat' => $chatNumber, 'archive' => $archive, 'status' => 'archived']);
    }

    return $this->errorResponse('Error al archivar chat', $result['httpCode'] ?? null);
  }
}