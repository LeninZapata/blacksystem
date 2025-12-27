<?php
abstract class baseAIProvider implements aiProviderInterface {
  protected $apiKey;
  protected $config;
  protected static $logMeta = ['module' => 'ai', 'layer' => 'service'];

  public function __construct(array $config) {
    $this->config = $config;
    $this->apiKey = $config['credential_value'] ?? $config['api_key'] ?? $config['api'] ?? '';
    $this->validateConfig();
  }

  public function validateConfig(): bool {
    if (empty($this->apiKey)) {
      log::error('API key no configurada para ' . $this->getProviderName(), [], self::$logMeta);
      throw new Exception(__('services.ai.api_key_missing', ['provider' => $this->getProviderName()]));
    }
    return true;
  }

  protected function successResponse($data, $provider = null): array {
    return array_merge(['success' => true, 'provider' => $provider ?? $this->getProviderName(), 'timestamp' => time()], $data);
  }

  protected function errorResponse($message, $code = null): array {
    $response = ['success' => false, 'provider' => $this->getProviderName(), 'error' => $message, 'timestamp' => time()];
    if ($code !== null) $response['error_code'] = $code;
    return $response;
  }

  protected function log($message, $data = []): void {
    log::debug(sprintf('[%s] %s', $this->getProviderName(), $message), $data, self::$logMeta);
  }

  protected function buildMessages($prompt): array {
    if (is_array($prompt) && isset($prompt[0]['role'])) return $prompt;
    
    if (is_array($prompt) && (isset($prompt['system']) || isset($prompt['user']))) {
      $messages = [];
      if (!empty($prompt['system'])) $messages[] = ['role' => 'system', 'content' => $prompt['system']];
      $messages[] = ['role' => 'user', 'content' => $prompt['user'] ?? ''];
      return $messages;
    }

    return [['role' => 'user', 'content' => $prompt]];
  }

  protected function getModel($options, $default): string {
    return $options['model'] ?? $default;
  }

  protected function getTemperature($options, $default = 0.7): float {
    return (float)($options['temperature'] ?? $default);
  }

  protected function getMaxTokens($options, $default = 2500): int {
    return (int)($options['max_tokens'] ?? $default);
  }

  protected function calculateCacheEfficiency($usage): string {
    $cacheHit = $usage['prompt_cache_hit_tokens'] ?? 0;
    $total = $usage['prompt_tokens'] ?? 1;
    if ($total == 0) return '0%';
    return round(($cacheHit / $total) * 100, 2) . '%';
  }
}