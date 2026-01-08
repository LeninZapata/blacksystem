<?php
class evolutionProvider extends baseChatApiProvider {

  function getProviderName(): string {
    return 'evolution';
  }

  function sendMessage(string $number, string $message, string $url = ''): array {
    if (!$this->validateConfig()) {
      return $this->errorResponse(__('services.evolution.config.invalid'));
    }

    $number = $this->formatNumber($number);
    $mediaType = $this->detectMediaType($url);

    // Determinar endpoint según tipo de media
    $endpoint = match($mediaType) {
      'text' => '/message/sendText',
      'audio' => '/message/sendWhatsAppAudio',
      default => '/message/sendMedia'
    };

    // Payload base
    $payload = ['number' => $number];

    if ($mediaType === 'text') {
      // Mensaje de texto simple
      $payload['text'] = $message;
    } elseif ($mediaType === 'audio') {
      // Audio de WhatsApp

      // Extraer información del archivo de audio
      $parsedUrl = parse_url($url, PHP_URL_PATH);
      $extension = pathinfo($parsedUrl, PATHINFO_EXTENSION);
      $originalName = pathinfo($parsedUrl, PATHINFO_FILENAME);

      // Generar filename: {nombre-archivo}-timestamp.{extension}
      $filename = ($originalName ?: 'audio') . '-' . time() . '.' . ($extension ?: 'ogg');

      // Obtener mimetype del audio
      $mimetype = $this->getMimeType($extension ?: 'ogg');

      // Payload para audio
      //$payload['audioMessage'] = ['audio' => $url];
      $payload['mediatype'] = $mediaType;
      $payload['media'] = $url ?: '';
      $payload['audio'] = $url ?: '';
      $payload['filename'] = $filename;
      $payload['mimetype'] = $mimetype;
    } else {
      // Media (image, video, document)
      // Extraer información del archivo
      $parsedUrl = parse_url($url, PHP_URL_PATH);
      $extension = pathinfo($parsedUrl, PATHINFO_EXTENSION);
      $originalName = pathinfo($parsedUrl, PATHINFO_FILENAME);

      // Generar filename: {nombre-archivo}-timestamp.{extension}
      $filename = ($originalName ?: 'file') . '-' . time() . '.' . ($extension ?: 'jpg');

      // Obtener mimetype
      $mimetype = $this->getMimeType($extension ?: 'jpg');

      // Campos directos en payload (no dentro de mediaMessage)
      $payload['mediatype'] = $mediaType;
      $payload['media'] = $url ?: '';
      $payload['filename'] = $filename;
      $payload['caption'] = $message ?: '';
      $payload['text'] = $message ?: ''; // Mismo valor que caption
      $payload['mimetype'] = $mimetype;
    }

    try {
      $http = ogApp()->helper('http');
      $response = $http::post($this->baseUrl . $endpoint . '/' . $this->instance, $payload, [
        'headers' => [
          'apikey: ' . $this->apiKey,
          'Content-Type: application/json'
        ]
      ]);

      if (!$response['success']) {
        return $this->errorResponse(
          $response['error'] ?? __('services.evolution.send_message.http_error')
        );
      }

      $data = $response['data'];

      if (isset($data['key']['id'])) {
        return $this->successResponse([
          'message_id' => $data['key']['id'],
          'timestamp' => $data['messageTimestamp'] ?? time()
        ]);
      }

      return $this->errorResponse(__('services.evolution.send_message.unexpected_response'));

    } catch (Exception $e) {
      return $this->errorResponse($e->getMessage());
    }
  }

  function sendPresence(string $number, string $presenceType, int $delay = 1200): array {
    if (!$this->validateConfig()) {
      return $this->errorResponse(__('services.evolution.config.invalid'));
    }

    $number = $this->formatNumber($number);

    $payload = [
      'number' => $number,
      'presence' => $presenceType,
      'delay' => $delay
    ];

    try {
      $http = ogApp()->helper('http');
      $response = $http::post($this->baseUrl . '/chat/sendPresence/' . $this->instance, $payload, [
        'headers' => [
          'apikey: ' . $this->apiKey,
          'Content-Type: application/json'
        ]
      ]);

      if (!$response['success']) {
        return $this->errorResponse(
          $response['error'] ?? __('services.evolution.send_presence.failed')
        );
      }

      return $this->successResponse(['presence_sent' => true]);

    } catch (Exception $e) {
      return $this->errorResponse($e->getMessage());
    }
  }

  function sendArchive(string $chatNumber, string $lastMessageId = 'archive', bool $archive = true): array {
    if (!$this->validateConfig()) {
      return $this->errorResponse(__('services.evolution.config.invalid'));
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
      $http = ogApp()->helper('http');
      $response = $http::post($this->baseUrl . '/chat/archiveChat/' . $this->instance, $payload, [
        'headers' => [
          'apikey: ' . $this->apiKey,
          'Content-Type: application/json'
        ]
      ]);

      if (!$response['success']) {
        return $this->errorResponse(
          $response['error'] ?? __('services.evolution.archive_chat.failed')
        );
      }

      return $this->successResponse(['archived' => $archive]);

    } catch (Exception $e) {
      return $this->errorResponse($e->getMessage());
    }
  }
}