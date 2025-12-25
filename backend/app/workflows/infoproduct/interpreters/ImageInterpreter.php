<?php

class ImageInterpreter {
  private static $logMeta = ['module' => 'workflow', 'layer' => 'app'];

  static function interpret($message, $bot) {
    $imageUrl = $message['media_url'] ?? null;
    $caption = $message['text'] ?? '';
    $base64 = $message['base64'] ?? null;

    if (!$base64 && !$imageUrl) {
      return [
        'success' => false,
        'error' => 'No image data available'
      ];
    }

    try {
      if (!$base64) {
        $imageData = file_get_contents($imageUrl);
        if ($imageData === false) {
          log::throwError('No se pudo descargar imagen', [], self::$logMeta);
        }
        $base64 = base64_encode($imageData);
      }

      $mimeType = $imageUrl ? self::getMimeType($imageUrl) : 'image/jpeg';
      $dataUri = "data:{$mimeType};base64,{$base64}";

      $promptFile = APP_PATH . '/workflows/prompts/infoproduct/recibo-img.txt';

      if (!file_exists($promptFile)) {
        log::throwError("Prompt file not found: {$promptFile}", [], self::$logMeta);
      }

      $instruction = file_get_contents($promptFile);

      // Agregar personalidad del bot al prompt si existe
      if (!empty($bot['personality'])) {
        $instruction .= "\n\n---\n\n## INFORMACIÓN ADICIONAL DEL BOT:\n" . $bot['personality'];
      }

      $ai = service::integration('ai');
      $result = $ai->analyzeImage($dataUri, $instruction, $bot);

      if (!$result['success']) {
        return [
          'success' => false,
          'error' => $result['error'] ?? 'Image analysis failed'
        ];
      }

      $description = $result['description'] ?? '';

      if (!str::isJson($description)) {
        return [
          'success' => false,
          'error' => 'Invalid JSON response from AI',
          'raw_response' => $description
        ];
      }

      $jsonData = json_decode($description, true);

      return [
        'success' => true,
        'type' => 'image',
        'text' => json_encode($jsonData, JSON_UNESCAPED_UNICODE),
        'metadata' => [
          'image_url' => $imageUrl,
          'caption' => $caption,
          'description' => $jsonData
        ]
      ];

    } catch (Exception $e) {
      return [
        'success' => false,
        'error' => $e->getMessage()
      ];
    }
  }

  private static function getMimeType($url) {
    $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
    $mimes = [
      'jpg' => 'image/jpeg',
      'jpeg' => 'image/jpeg',
      'png' => 'image/png',
      'gif' => 'image/gif',
      'webp' => 'image/webp'
    ];
    return $mimes[$ext] ?? 'image/jpeg';
  }
}