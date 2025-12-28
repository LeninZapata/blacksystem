<?php
class deepSeekProvider extends baseAIProvider {
  private $baseUrl = 'https://api.deepseek.com';
  protected static $logMeta = ['module' => 'ai', 'layer' => 'service'];

  public function getProviderName(): string {
    return 'deepseek';
  }

  public function chatCompletion($prompt, $options = []): array {
    try {
      $messages = $this->buildMessages($prompt);
      $model = $this->getModel($options, 'deepseek-chat');
      $temperature = $this->getTemperature($options, 0.7);
      $maxTokens = $this->getMaxTokens($options, 8000);

      $payload = ['model' => $model, 'messages' => $messages, 'temperature' => $temperature, 'max_tokens' => $maxTokens, 'stream' => false];

      $response = ogHttp::post($this->baseUrl . '/chat/completions', $payload, [
        'headers' => ['Content-Type: application/json', 'Authorization: Bearer ' . $this->apiKey],
        'timeout' => 60
      ]);

      if (!$response['success']){
        ogLog::error('Error HTTP en chat completion', $response, self::$logMeta);
        throw new Exception(__('services.ai.http_error') . ' (HTTP ' . ($response['httpCode'] ?? '500') . ')');
      }

      $data = $response['data'];
      if (!isset($data['choices'][0]['message']['content'])){
        ogLog::error('Respuesta con formato inválido', ['response_data' => $data], self::$logMeta);
        throw new Exception(__('services.ai.invalid_response'));
      }

      $usage = $data['usage'] ?? [];
      if (isset($usage['prompt_cache_hit_tokens'])) {
        $usage['cache_efficiency'] = $this->calculateCacheEfficiency($usage);
        $this->log('Cache utilizado', $usage);
      }

      return [
        'success' => true,
        'provider' => $this->getProviderName(),
        'response' => trim($data['choices'][0]['message']['content']),
        'tokens_used' => $usage['total_tokens'] ?? null,
        'model' => $data['model'] ?? $model,
        'usage' => $usage
      ];

    } catch (Exception $e) {
      $this->log('Error en chat completion', ['error' => $e->getMessage()]);
      return $this->errorResponse($e->getMessage());
    }
  }

  public function transcribeAudio($audioUrl): array {
    return $this->errorResponse(__('services.ai.not_supported', ['provider' => 'DeepSeek']), 'NOT_SUPPORTED');
  }

  public function analyzeImage($imageDataUri, $instruction): array {
    try {
      $messages = [[
        'role' => 'user',
        'content' => [
          ['type' => 'text', 'text' => $instruction],
          ['type' => 'image_url', 'image_url' => ['url' => $imageDataUri]]
        ]
      ]];

      $payload = ['model' => 'deepseek-vision', 'messages' => $messages, 'temperature' => 0.7, 'max_tokens' => 1500];

      $response = ogHttp::post($this->baseUrl . '/chat/completions', $payload, [
        'headers' => ['Content-Type: application/json', 'Authorization: Bearer ' . $this->apiKey],
        'timeout' => 60
      ]);

      if (!$response['success']) throw new Exception(__('services.ai.http_error') . ' (HTTP ' . ($response['httpCode'] ?? '500') . ')');

      $data = $response['data'];
      $description = $data['choices'][0]['message']['content'] ?? '';

      return [
        'success' => true,
        'provider' => $this->getProviderName(),
        'description' => trim($description),
        'model' => $data['model'] ?? 'deepseek-vision',
        'usage' => $data['usage'] ?? []
      ];

    } catch (Exception $e) {
      $this->log('Error analizando imagen', ['error' => $e->getMessage()]);
      return $this->errorResponse($e->getMessage());
    }
  }
}