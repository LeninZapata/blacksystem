<?php
class openAiProvider extends baseAIProvider {
  private $baseUrl = 'https://api.openai.com/v1';

  public function getProviderName(): string {
    return 'openai';
  }

  public function chatCompletion($prompt, $options = []): array {
    try {
      $messages = $this->buildMessages($prompt);
      $model = $this->getModel($options, 'gpt-4o-mini');
      $temperature = $this->getTemperature($options, 0.7);
      $maxTokens = $this->getMaxTokens($options, 3000);

      $payload = ['model' => $model, 'messages' => $messages, 'temperature' => $temperature, 'max_tokens' => $maxTokens];

      $response = ogHttp::post($this->baseUrl . '/chat/completions', $payload, [
        'headers' => ['Content-Type: application/json', 'Authorization: Bearer ' . $this->apiKey],
        'timeout' => 60
      ]);

      if (!$response['success']) throw new Exception(__('services.ai.http_error') . ' (HTTP ' . ($response['httpCode'] ?? '500') . ')');

      $data = $response['data'];
      if (!isset($data['choices'][0]['message']['content'])) throw new Exception(__('services.ai.invalid_response'));

      return [
        'success' => true,
        'provider' => $this->getProviderName(),
        'response' => trim($data['choices'][0]['message']['content']),
        'tokens_used' => $data['usage']['total_tokens'] ?? null,
        'model' => $data['model'] ?? $model,
        'usage' => $data['usage'] ?? []
      ];

    } catch (Exception $e) {
      $this->log('Error en chat completion', ['error' => $e->getMessage()]);
      return $this->errorResponse($e->getMessage());
    }
  }

  public function transcribeAudio($audioUrl): array {
    try {
      $audioContent = file_get_contents($audioUrl);
      if ($audioContent === false) throw new Exception(__('services.ai.transcription_failed') . ': No se pudo descargar el audio');

      $boundary = 'boundary' . uniqid();
      $body = "--{$boundary}\r\n";
      $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"audio.ogg\"\r\n";
      $body .= "Content-Type: audio/ogg\r\n\r\n";
      $body .= $audioContent . "\r\n";
      $body .= "--{$boundary}\r\n";
      $body .= "Content-Disposition: form-data; name=\"model\"\r\n\r\n";
      $body .= "whisper-1\r\n";
      $body .= "--{$boundary}--\r\n";

      $response = ogHttp::post($this->baseUrl . '/audio/transcriptions', $body, [
        'headers' => [
          'Authorization: Bearer ' . $this->apiKey,
          'Content-Type: multipart/form-data; boundary=' . $boundary
        ],
        'timeout' => 60,
        'raw' => true
      ]);

      if (!$response['success']) throw new Exception(__('services.ai.http_error') . ' (HTTP ' . ($response['httpCode'] ?? '500') . ')');

      $data = $response['data'];
      $text = $data['text'] ?? '';

      return ['success' => true, 'provider' => $this->getProviderName(), 'text' => trim($text), 'model' => 'whisper-1'];

    } catch (Exception $e) {
      $this->log('Error transcribiendo audio', ['error' => $e->getMessage()]);
      return $this->errorResponse($e->getMessage());
    }
  }

  public function analyzeImage($imageDataUri, $instruction): array {
    try {
      // Si imageDataUri no tiene el prefijo data:image, agregarlo
      if (strpos($imageDataUri, 'data:image') !== 0) {
        $imageDataUri = 'data:image/jpeg;base64,' . $imageDataUri;
      }

      $messages = [[
        'role' => 'user',
        'content' => [
          ['type' => 'text', 'text' => $instruction],
          ['type' => 'image_url', 'image_url' => ['url' => $imageDataUri]]
        ]
      ]];

      $payload = ['model' => 'gpt-4o-mini', 'messages' => $messages, 'max_tokens' => 2500];

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
        'model' => $data['model'] ?? 'gpt-4o-mini',
        'usage' => $data['usage'] ?? []
      ];

    } catch (Exception $e) {
      $this->log('Error analizando imagen', ['error' => $e->getMessage()]);
      return $this->errorResponse($e->getMessage());
    }
  }
}