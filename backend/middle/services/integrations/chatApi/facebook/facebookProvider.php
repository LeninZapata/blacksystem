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

    // Validar config específico de Facebook
    $this->validateFacebookConfig();
  }

  function getProviderName(): string {
    return 'whatsapp-cloud-api';
  }

  // Facebook usa números limpios sin sufijo @s.whatsapp.net
  protected function formatNumber(string $number): string {
    return preg_replace('/[^0-9]/', '', $number);
  }

  // Validación específica de Facebook (no sobrescribe validateConfig)
  private function validateFacebookConfig(): void {
    if (empty($this->apiKey)) {
      ogLog::throwError("validateFacebookConfig - access_token requerido", [], ['module' => 'facebookProvider']);
    }

    if (empty($this->phoneNumberId)) {
      ogLog::throwError("validateFacebookConfig - phone_number_id requerido", [], ['module' => 'facebookProvider']);
    }
  }

  // Helper interno: POST al Graph API
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
      return $this->errorResponse($response['error'] ?? 'HTTP error al llamar Graph API');
    }

    $data = $response['data'];

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
    } elseif ($mediaType === 'image') {
      $payload['type']  = 'image';
      $payload['image'] = ['link' => $url, 'caption' => $message ?: null];
    } elseif ($mediaType === 'video') {
      $payload['type']  = 'video';
      $payload['video'] = ['link' => $url, 'caption' => $message ?: null];
    } else {
      $payload['type']     = 'document';
      $payload['document'] = [
        'link'     => $url,
        'caption'  => $message ?: null,
        'filename' => $this->extractFilename($url)
      ];
    }

    try {
      return $this->postToGraph($payload);
    } catch (Exception $e) {
      return $this->errorResponse($e->getMessage());
    }
  }

  // Enviar mensaje interactivo (botones, listas)
  // $interactive: el objeto completo del campo "interactive" del payload de Facebook
  // Ej: ['type'=>'button','body'=>['text'=>'...'],'action'=>['buttons'=>[...]]]
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
    // Facebook no soporta typing indicators directamente por API
    // Retornar éxito silenciosamente (solo hacer sleep si es necesario)
    if ($delay > 0) {
      usleep($delay * 1000); // Convertir ms a microsegundos
    }

    return $this->successResponse(['presence_sent' => false, 'note' => 'Facebook API does not support typing indicators']);
  }

  function sendArchive(string $chatNumber, string $lastMessageId = 'archive', bool $archive = true): array {
    // Facebook no tiene endpoint directo para archivar chats
    // Retornar éxito silenciosamente
    return $this->successResponse([
      'archived' => false,
      'note' => 'Facebook API does not support chat archiving via API'
    ]);
  }

  // Helper: Extraer filename de URL
  private function extractFilename(string $url): string {
    $parsedUrl = parse_url($url, PHP_URL_PATH);
    $filename = pathinfo($parsedUrl, PATHINFO_BASENAME);
    return $filename ?: 'document';
  }
}