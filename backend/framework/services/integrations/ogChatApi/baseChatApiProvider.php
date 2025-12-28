<?php
abstract class baseChatApiProvider implements chatApiProviderInterface {

  // Meta para logs
  protected static $logMeta = ['module' => 'ogChatApi', 'layer' => 'framework'];

  protected $apiKey;
  protected $instance;
  protected $baseUrl;
  protected $config;

  function __construct(array $config) {
    $this->config = $config;
    $this->apiKey = $config['api_key'] ?? $config['api'] ?? $config['token'] ?? '';
    $this->instance = $config['instance'] ?? $config['instance_name'] ?? '';
    $this->baseUrl = $config['base_url'] ?? $config['server_url'] ?? $config['api_url'] ?? '';
    $this->validateConfig();
  }

  function validateConfig(): bool {
    if (empty($this->apiKey)) ogLog::throwError(__('services.ogChatApi.api_key_required', ['provider' => $this->getProviderName()]), [], self::$logMeta);
    if (empty($this->instance)) ogLog::throwError(__('services.ogChatApi.instance_required', ['provider' => $this->getProviderName()]), [], self::$logMeta);
    return true;
  }

  protected function formatNumber(string $number): string {
    $number = preg_replace('/[^0-9]/', '', $number);
    if (!str_contains($number, '@')) $number = $number . '@s.whatsapp.net';
    return $number;
  }

  protected function detectMediaType(string $url): string {
    if (empty($url)) return 'text';
    $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
    
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'])) return 'image';
    if (in_array($ext, ['mp4', 'avi', 'mov', 'mkv', 'webm', 'flv'])) return 'video';
    if (in_array($ext, ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'opus', 'flac'])) return 'audio';
    
    return 'document';
  }

  protected function shouldIncludeText(string $mediaType, string $message): bool {
    return !empty($message) && $mediaType !== 'audio';
  }

  protected function successResponse($data): array {
    return array_merge(['success' => true, 'api' => $this->getProviderName(), 'timestamp' => time()], $data);
  }

  protected function errorResponse($message, $code = null): array {
    $response = ['success' => false, 'api' => $this->getProviderName(), 'error' => $message, 'timestamp' => time()];
    if ($code !== null) $response['error_code'] = $code;
    return $response;
  }

  protected function getMimeType(string $extension): string {
    $mimeMap = [
      'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
      'webp' => 'image/webp', 'bmp' => 'image/bmp', 'mp4' => 'video/mp4', 'avi' => 'video/x-msvideo',
      'mov' => 'video/quicktime', 'mkv' => 'video/x-matroska', 'webm' => 'video/webm',
      'mp3' => 'audio/mp3', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'm4a' => 'audio/mp4',
      'aac' => 'audio/aac', 'opus' => 'audio/opus', 'pdf' => 'application/pdf',
      'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    ];
    return $mimeMap[$extension] ?? 'application/octet-stream';
  }

  abstract function sendArchive(string $chatNumber, string $lastMessageId = 'archive', bool $archive = true): array;
}