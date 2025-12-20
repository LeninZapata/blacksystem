<?php
class deepSeekProvider extends baseAIProvider {
  private $baseUrl = 'https://api.deepseek.com';

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

      $response = http::post($this->baseUrl . '/chat/completions', $payload, [
        'headers' => ['Content-Type: application/json', 'Authorization: Bearer ' . $this->apiKey],
        'timeout' => 60
      ]);

      if (!$response['success']){
        log::error('Error HTTP en chat completion', $response, ['module' => 'ai']);
        throw new Exception('Error HTTP ' . ($response['httpCode'] ?? '500'));
      }

      $data = $response['data'];
      if (!isset($data['choices'][0]['message']['content'])){
        log::error('Respuesta con formato inválido', ['response_data' => $data]);
        throw new Exception('Respuesta con formato inválido');
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
    return $this->errorResponse('DeepSeek no soporta transcripción de audio', 'NOT_SUPPORTED');
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

      $response = http::post($this->baseUrl . '/chat/completions', $payload, [
        'headers' => ['Content-Type: application/json', 'Authorization: Bearer ' . $this->apiKey],
        'timeout' => 60
      ]);

      if (!$response['success']) throw new Exception('Error HTTP ' . ($response['httpCode'] ?? '500'));

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