<?php

class ImageInterpreter {
  private static $logMeta = ['module' => 'ImageInterpreter', 'layer' => 'app/workflows'];

  static function interpret($message, $bot, $number = null) {
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
          ogLog::throwError('interpret - No se pudo descargar imagen', [], self::$logMeta);
        }
        $base64 = base64_encode($imageData);
      }

      $mimeType = $imageUrl ? self::getMimeType($imageUrl) : 'image/jpeg';
      $dataUri = "data:{$mimeType};base64,{$base64}";

      $promptFile = ogApp()->getPath() . '/workflows/prompts/infoproduct/' . $bot['prompt_reccibo_imagen'] ?? 'recibo-img.txt';

      if (!file_exists($promptFile)) {
        ogLog::throwError("interpret - Prompt file not found: {$promptFile}", [], self::$logMeta);
      }

      $instruction = file_get_contents($promptFile);

      // Agregar personalidad del bot al prompt si existe
      if (!empty($bot['personality'])) {
        $instruction .= "\n\n---\n\n## INFORMACIÓN ADICIONAL DEL BOT:\n" . $bot['personality'];
      }

      $ai = ogApp()->service('ai');
      $result = $ai->analyzeImage($dataUri, $instruction, $bot);

      if (!$result['success']) {
        return [
          'success' => false,
          'error' => $result['error'] ?? 'Image analysis failed'
        ];
      }

      $description = $result['description'] ?? '';

      ogApp()->helper('str');
      if (!ogStr::isJson($description)) {
        return [
          'success' => false,
          'error' => 'Invalid JSON response from AI',
          'raw_response' => $description
        ];
      }

      $jsonData = json_decode($description, true);

      // Guardar imagen en storage/recibos/ para acceso permanente
      $receiptFile = self::saveReceipt($base64, $mimeType, $number);

      return [
        'success' => true,
        'type' => 'image',
        'text' => json_encode($jsonData, JSON_UNESCAPED_UNICODE),
        'metadata' => [
          'image_url'    => $imageUrl,
          'receipt_file' => $receiptFile,
          'caption'      => $caption,
          'description'  => $jsonData
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

  // Guarda el base64 en storage/recibos/ y retorna solo el nombre del archivo
  private static function saveReceipt($base64, $mimeType, $number) {
    try {
      $clean    = preg_replace('/[^0-9]/', '', $number ?? '');
      $filename = ($clean ?: 'unknown') . '_' . time() . '.webp';
      $dir      = ogApp()->getPath('storage') . '/recibos';

      if (!is_dir($dir)) mkdir($dir, 0755, true);

      $imgData = base64_decode($base64);
      $src     = imagecreatefromstring($imgData);

      if (!$src) {
        // Fallback: guardar sin comprimir si GD falla
        file_put_contents($dir . '/' . $filename, $imgData);
        return $filename;
      }

      $compressed = self::compressToWebp($src, $filename, $dir);
      imagedestroy($src);

      return $compressed ? $filename : null;
    } catch (Exception $e) {
      ogLog::error('saveReceipt - Error al guardar', ['error' => $e->getMessage()], self::$logMeta);
      return null;
    }
  }

  // Convierte a WebP y reduce tamaño/calidad hasta < 30KB
  private static function compressToWebp($src, $filename, $dir) {
    $targetBytes = 30 * 1024;
    $origW   = imagesx($src);
    $origH   = imagesy($src);
    $w       = $origW;
    $h       = $origH;
    $quality = 95;
    $tmpFile = $dir . '/' . $filename;

    // Primera conversión a WebP
    self::writeWebp($src, $origW, $origH, $w, $h, $quality, $tmpFile);

    // Loop: para si $w llega a 300px, sin importar el peso
    while (filesize($tmpFile) > $targetBytes && $w > 300) {
      $w       = (int)($w * 0.8);
      $h       = (int)($h * 0.8);
      $quality = max(10, $quality - 15);
      self::writeWebp($src, $origW, $origH, $w, $h, $quality, $tmpFile);
    }

    return file_exists($tmpFile);
  }

  // Escala $src desde tamaño original a $dstW x $dstH y guarda como WebP
  private static function writeWebp($src, $origW, $origH, $dstW, $dstH, $quality, $path) {
    $canvas = imagecreatetruecolor($dstW, $dstH);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
    imagecopyresampled($canvas, $src, 0, 0, 0, 0, $dstW, $dstH, $origW, $origH);
    imagewebp($canvas, $path, $quality);
    imagedestroy($canvas);
  }
}