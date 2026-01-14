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

      $response = ogApp()->helper('http')::post($this->baseUrl . '/chat/completions', $payload, [
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
      // Detectar si es data URI o URL HTTP
      $audioContent = null;
      $mimeType = 'audio/ogg';
      $extension = 'ogg';

      if (strpos($audioUrl, 'data:audio') === 0) {
        // Es un data URI en base64
        $this->log('Audio recibido como data URI, decodificando...');

        // Extraer mime type y base64
        if (preg_match('/^data:(audio\/[a-zA-Z0-9+.-]+);base64,(.+)$/', $audioUrl, $matches)) {
          $mimeType = $matches[1];
          $base64Data = $matches[2];
          $audioContent = base64_decode($base64Data);

          // Determinar extensión desde mime type
          $extension = match($mimeType) {
            'audio/ogg' => 'ogg',
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/wav' => 'wav',
            'audio/m4a', 'audio/mp4' => 'm4a',
            'audio/webm' => 'webm',
            default => 'ogg'
          };

          $this->log('Audio decodificado', [
            'mime_type' => $mimeType,
            'extension' => $extension,
            'size' => strlen($audioContent)
          ]);
        } else {
          throw new Exception(__('services.ai.transcription_failed') . ': Formato data URI inválido');
        }
      } else {
        // Es una URL HTTP normal
        $this->log('Audio recibido como URL, descargando...', ['url' => $audioUrl]);

        $audioContent = file_get_contents($audioUrl);
        if ($audioContent === false) {
          throw new Exception(__('services.ai.transcription_failed') . ': No se pudo descargar el audio');
        }

        // Detectar extensión desde URL
        $parsedUrl = parse_url($audioUrl);
        $path = $parsedUrl['path'] ?? '';
        $detectedExt = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($detectedExt) {
          $extension = $detectedExt;
          $mimeType = match($extension) {
            'ogg' => 'audio/ogg',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'm4a' => 'audio/m4a',
            'webm' => 'audio/webm',
            default => 'audio/ogg'
          };
        }

        $this->log('Audio descargado', [
          'extension' => $extension,
          'mime_type' => $mimeType,
          'size' => strlen($audioContent)
        ]);
      }

      if (empty($audioContent)) {
        throw new Exception(__('services.ai.transcription_failed') . ': Audio vacío');
      }

      // Construir multipart/form-data
      $boundary = 'boundary' . uniqid();
      $filename = "audio.{$extension}";

      $body = "--{$boundary}\r\n";
      $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
      $body .= "Content-Type: {$mimeType}\r\n\r\n";
      $body .= $audioContent . "\r\n";
      $body .= "--{$boundary}\r\n";
      $body .= "Content-Disposition: form-data; name=\"model\"\r\n\r\n";
      $body .= "whisper-1\r\n";
      $body .= "--{$boundary}--\r\n";

      $this->log('Enviando audio a OpenAI Whisper', [
        'filename' => $filename,
        'mime_type' => $mimeType,
        'body_size' => strlen($body)
      ]);

      $response = ogApp()->helper('http')::post($this->baseUrl . '/audio/transcriptions', $body, [
        'headers' => [
          'Authorization: Bearer ' . $this->apiKey,
          'Content-Type: multipart/form-data; boundary=' . $boundary
        ],
        'timeout' => 60,
        'raw' => true
      ]);

      if (!$response['success']) {
        $errorDetails = [
          'http_code' => $response['httpCode'] ?? 'unknown',
          'response_data' => $response['data'] ?? null,
          'error' => $response['error'] ?? 'unknown'
        ];

        $this->log('Error en respuesta de OpenAI', $errorDetails);

        throw new Exception(
          __('services.ai.http_error') .
          ' (HTTP ' . ($response['httpCode'] ?? '500') . '): ' .
          ($response['error'] ?? 'Unknown error')
        );
      }

      $data = $response['data'];
      $text = $data['text'] ?? '';

      $this->log('Transcripción exitosa', [
        'text_length' => strlen($text),
        'text_preview' => substr($text, 0, 100)
      ]);

      return [
        'success' => true,
        'provider' => $this->getProviderName(),
        'text' => trim($text),
        'model' => 'whisper-1'
      ];

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

      $response = ogApp()->helper('http')::post($this->baseUrl . '/chat/completions', $payload, [
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