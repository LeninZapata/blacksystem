<?php
class facebookProvider extends baseChatApiProvider {

  private $phoneNumberId;
  private $businessAccountId;

  function __construct(array $config) {
    $this->config = $config;
    $this->apiKey = $config['access_token'] ?? $config['api_key'] ?? '';
    $this->phoneNumberId = $config['phone_number_id'] ?? '';
    $this->businessAccountId = $config['business_account_id'] ?? '';

    // Facebook no usa instance ni baseUrl, pero se los asignamos vacíos para compatibilidad
    $this->instance = '';
    $this->baseUrl = '';

    $this->validateFacebookConfig();
  }

  function getProviderName(): string {
    return 'whatsapp-cloud-api';
  }

  // Facebook usa números limpios sin sufijo @s.whatsapp.net
  protected function formatNumber(string $number): string {
    return preg_replace('/[^0-9]/', '', $number);
  }

  private function validateFacebookConfig(): void {
    if (empty($this->apiKey)) {
      ogLog::throwError("validateFacebookConfig - access_token requerido", [], ['module' => 'facebookProvider']);
    }

    if (empty($this->phoneNumberId)) {
      ogLog::throwError("validateFacebookConfig - phone_number_id requerido", [], ['module' => 'facebookProvider']);
    }
  }

  private function postToGraph(array $payload): array {
    $endpoint = "https://graph.facebook.com/v21.0/{$this->phoneNumberId}/messages";
    $http = ogApp()->helper('http');

    $response = $http::post($endpoint, $payload, [
      'headers' => [
        'Authorization: Bearer ' . $this->apiKey,
        'Content-Type: application/json'
      ]
    ]);

    if (!$response['success']) {
      ogLog::error('facebookProvider.postToGraph - HTTP error', ['error' => $response['error'] ?? null, 'payload' => $payload], self::$logMeta);
      return $this->errorResponse($response['error'] ?? 'HTTP error al llamar Graph API');
    }

    $data = $response['data'];

    // Graph API retorna error en el body con código 200 a veces
    if (isset($data['error'])) {
      ogLog::error('facebookProvider.postToGraph - Graph API error', ['graph_error' => $data['error'], 'payload' => $payload], self::$logMeta);
      return $this->errorResponse($data['error']['message'] ?? 'Graph API error', $data['error']['code'] ?? null);
    }

    if (isset($data['messages'][0]['id'])) {
      return $this->successResponse([
        'message_id' => $data['messages'][0]['id'],
        'timestamp'  => time()
      ]);
    }

    return $this->errorResponse('Graph API - respuesta inesperada: ' . json_encode($data));
  }

  function sendMessage(string $number, string $message, string $url = ''): array {
    $number = $this->formatNumber($number);
    $mediaType = $this->detectMediaType($url);

    $payload = [
      'messaging_product' => 'whatsapp',
      'recipient_type'    => 'individual',
      'to'                => $number
    ];

    if ($mediaType === 'text') {
      $payload['type'] = 'text';
      $payload['text'] = ['body' => $message];
    } elseif ($mediaType === 'audio') {
      $payload['type']  = 'audio';
      $payload['audio'] = ['link' => $url];
    } elseif ($mediaType === 'voice') {
      // ogg/opus con voice:true → llega como nota de voz grabada
      $payload['type']  = 'audio';
      $payload['audio'] = ['link' => $url, 'voice' => true];
    } elseif ($mediaType === 'image') {
      $media = ['link' => $url];
      if ($message) $media['caption'] = $message; // omitir si vacío, null rompe Graph API
      $payload['type']  = 'image';
      $payload['image'] = $media;
    } elseif ($mediaType === 'video') {
      $media = ['link' => $url];
      if ($message) $media['caption'] = $message;
      $payload['type']  = 'video';
      $payload['video'] = $media;
    } else {
      $media = ['link' => $url, 'filename' => $this->extractFilename($url)];
      if ($message) $media['caption'] = $message;
      $payload['type']     = 'document';
      $payload['document'] = $media;
    }

    ogLog::debug('facebookProvider.sendMessage - payload', ['media_type' => $mediaType, 'payload' => $payload], self::$logMeta);

    // Con media: reintentar hasta 3 veces si Graph API falla (hosting lento)
    $maxAttempts = !empty($url) ? 3 : 1;
    $retryDelay  = 4; // segundos entre reintentos
    $lastResult  = null;

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
      try {
        $result = $this->postToGraph($payload);
        if ($result['success']) return $result;

        $lastResult = $result;
        ogLog::warning('facebookProvider.sendMessage - Intento fallido', [
          'attempt'    => $attempt,
          'max'        => $maxAttempts,
          'media_type' => $mediaType,
          'error'      => $result['error'] ?? 'unknown'
        ], self::$logMeta);
      } catch (Exception $e) {
        $lastResult = $this->errorResponse($e->getMessage());
        ogLog::warning('facebookProvider.sendMessage - Excepción intento', [
          'attempt' => $attempt,
          'error'   => $e->getMessage()
        ], self::$logMeta);
      }

      if ($attempt < $maxAttempts) sleep($retryDelay);
    }

    return $lastResult ?? $this->errorResponse('Sin respuesta de Graph API');
  }

  // $interactive: objeto completo del campo "interactive" del payload de Facebook
  function sendInteractive(string $number, array $interactive): array {
    $number = $this->formatNumber($number);

    $payload = [
      'messaging_product' => 'whatsapp',
      'recipient_type'    => 'individual',
      'to'                => $number,
      'type'              => 'interactive',
      'interactive'       => $interactive
    ];

    try {
      return $this->postToGraph($payload);
    } catch (Exception $e) {
      return $this->errorResponse($e->getMessage());
    }
  }

  function sendPresence(string $number, string $presenceType, int $delay = 1200): array {
    // Facebook no soporta typing indicators, solo simulamos el delay
    if ($delay > 0) usleep($delay * 1000);
    return $this->successResponse(['presence_sent' => false, 'note' => 'Facebook API does not support typing indicators']);
  }

  function sendArchive(string $chatNumber, string $lastMessageId = 'archive', bool $archive = true): array {
    return $this->successResponse(['archived' => false, 'note' => 'Facebook API does not support chat archiving via API']);
  }

  private function extractFilename(string $url): string {
    $parsedUrl = parse_url($url, PHP_URL_PATH);
    $filename = pathinfo($parsedUrl, PATHINFO_BASENAME);
    return $filename ?: 'document';
  }
}