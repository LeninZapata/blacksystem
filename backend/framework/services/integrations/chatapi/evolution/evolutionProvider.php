<?php
class evolutionProvider extends baseChatApiProvider {

  function getProviderName(): string {
    return 'Evolution API';
  }

  function sendMessage(string $number, string $message, string $url = ''): array {
    if (!$this->validateConfig()) {
      return $this->errorResponse('Configuración inválida');
    }

    $number = $this->formatNumber($number);
    $mediaType = $this->detectMediaType($url);

    $endpoint = match($mediaType) {
      'text' => 'message/sendText',
      'image' => 'message/sendMedia',
      'video' => 'message/sendMedia',
      'audio' => 'message/sendWhatsAppAudio',
      'document' => 'message/sendMedia',
      default => 'message/sendText'
    };

    $payload = ['number' => $number];

    if ($mediaType === 'text') {
      $payload['text'] = $message;
    } elseif ($mediaType === 'audio') {
      $payload['audioMessage'] = ['audio' => $url];
    } else {
      $payload['mediaMessage'] = ['mediatype' => $mediaType, 'media' => $url];
      if ($this->shouldIncludeText($mediaType, $message)) {
        $payload['mediaMessage']['caption'] = $message;
      }
    }

    try {
      $response = http::post($this->baseUrl . $endpoint . '/' . $this->instance, $payload, [
        'apikey: ' . $this->apiKey,
        'Content-Type: application/json'
      ]);

      if (!$response['success']) {
        return $this->errorResponse($response['error'] ?? 'Error en la petición HTTP');
      }

      $data = $response['data'];

      if (isset($data['key']['id'])) {
        return $this->successResponse([
          'message_id' => $data['key']['id'],
          'timestamp' => $data['messageTimestamp'] ?? time()
        ]);
      }

      return $this->errorResponse('Respuesta inesperada de Evolution API');

    } catch (Exception $e) {
      return $this->errorResponse($e->getMessage());
    }
  }

  function sendPresence(string $number, string $presenceType, int $delay = 1200): array {
    if (!$this->validateConfig()) {
      return $this->errorResponse('Configuración inválida');
    }

    $number = $this->formatNumber($number);

    $payload = [
      'number' => $number,
      'presence' => $presenceType,
      'delay' => $delay
    ];

    try {
      $response = http::post($this->baseUrl . 'chat/sendPresence/' . $this->instance, $payload, [
        'apikey: ' . $this->apiKey,
        'Content-Type: application/json'
      ]);

      if (!$response['success']) {
        return $this->errorResponse($response['error'] ?? 'Error en sendPresence');
      }

      return $this->successResponse(['presence_sent' => true]);

    } catch (Exception $e) {
      return $this->errorResponse($e->getMessage());
    }
  }

  function sendArchive(string $chatNumber, string $lastMessageId = 'archive', bool $archive = true): array {
    if (!$this->validateConfig()) {
      return $this->errorResponse('Configuración inválida');
    }

    $chatNumber = $this->formatNumber($chatNumber);

    $payload = [
      'lastMessage' => [
        'key' => [
          'remoteJid' => $chatNumber,
          'fromMe' => true,
          'id' => $lastMessageId
        ]
      ],
      'archive' => $archive
    ];

    try {
      $response = http::post($this->baseUrl . 'chat/archiveChat/' . $this->instance, $payload, [
        'apikey: ' . $this->apiKey,
        'Content-Type: application/json'
      ]);

      if (!$response['success']) {
        return $this->errorResponse($response['error'] ?? 'Error en archiveChat');
      }

      return $this->successResponse(['archived' => $archive]);

    } catch (Exception $e) {
      return $this->errorResponse($e->getMessage());
    }
  }
}