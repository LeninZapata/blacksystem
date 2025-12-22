<?php
class messageInterpreter {

  // Interpretar mensaje y convertir a formato legible para IA
  static function interpret($message, $bot) {
    $type = $message['type'] ?? 'text';
    $raw = $message['raw'] ?? [];

    switch ($type) {
      case 'text':
        return self::interpretText($message);
      
      case 'audio':
        return self::interpretAudio($message, $bot);
      
      case 'image':
        return self::interpretImage($message, $bot);
      
      case 'video':
        return self::interpretVideo($message);
      
      case 'document':
        return self::interpretDocument($message);
      
      case 'sticker':
        return self::interpretSticker($message);
      
      case 'location':
        return self::interpretLocation($message);
      
      case 'contact':
        return self::interpretContact($message);
      
      default:
        return ['type' => 'unknown', 'text' => '[Mensaje no soportado]', 'metadata' => null];
    }
  }

  // Interpretar texto
  private static function interpretText($message) {
    return ['type' => 'text', 'text' => $message['text'] ?? '', 'metadata' => null];
  }

  // Interpretar audio (transcribir con IA)
  private static function interpretAudio($message, $bot) {
    $audioUrl = $message['media_url'] ?? null;
    if (!$audioUrl) return ['type' => 'audio', 'text' => '[Audio sin URL]', 'metadata' => null];

    try {
      $ai = service::integration('ai');
      $result = $ai->transcribeAudio($audioUrl, $bot);

      if ($result['success']) {
        return ['type' => 'audio', 'text' => $result['text'], 'metadata' => ['audio_url' => $audioUrl, 'provider' => $result['provider'] ?? null]];
      }

      return ['type' => 'audio', 'text' => '[Audio no transcrito]', 'metadata' => ['error' => $result['error'] ?? null]];
    } catch (Exception $e) {
      return ['type' => 'audio', 'text' => '[Error transcribiendo audio]', 'metadata' => ['error' => $e->getMessage()]];
    }
  }

  // Interpretar imagen (analizar con Vision IA)
  private static function interpretImage($message, $bot) {
    $imageUrl = $message['media_url'] ?? null;
    $caption = $message['text'] ?? '';
    $base64 = $message['base64'] ?? null;

    // TODO: meensaje de espera debe ser con de key de idioma
    chatapi::send($message['from'] ?? null, "Listo ✅
Un momento por favor ☺️ (estoy abriendo la foto de pago que me enviaste)

🕐 Si tardo en responder, no te preocupes.
Estoy procesando los pagos y pronto te enviaré tu acceso Tu compra está garantizada. ¡Gracias por tu paciencia! 😊💡");

    if (!$base64 && !$imageUrl){
      return ['type' => 'image', 'text' => "[Imagen: {$caption}]", 'metadata' => null];
    }

    try {
      // Usar base64 directamente si está disponible
      if (!$base64) {
        $imageData = file_get_contents($imageUrl);
        if ($imageData === false) throw new Exception('No se pudo descargar imagen');
        $base64 = base64_encode($imageData);
      }

      $mimeType = $imageUrl ? self::getMimeType($imageUrl) : 'image/jpeg';
      $dataUri = "data:{$mimeType};base64,{$base64}";

      // TODO: este instruction debería venir del bot
      $promptFile = APP_PATH . '/workflows/prompts/infoproduct/recibo-img.txt';
    if (!file_exists($promptFile)) {
      log::error('Archivo de prompt no encontrado', ['file' => $promptFile], ['module' => 'conversation']);
      throw new Exception("Archivo de prompt no encontrado: {$promptFile}"); // hay que usar throw para que el workflow capture el error
    } $instruction = file_get_contents($promptFile) ?? 'Describe brevemente esta imagen';

      $ai = service::integration('ai');
      $result = $ai->analyzeImage($dataUri, $instruction, $bot);

      if ($result['success']) {
        // Validar si $result['description'] es un json o texto
        $description = $result['description'] ?? '';

        if (!str::isJson($description)) {

          // Es texto plano, retornar error
          return [
            'success' => false,
            'type' => 'image',
            'text' => 'No pude procesar la imagen correctamente. Por favor, envíala nuevamente.',
            'metadata' => ['error' => 'invalid_image_analysis', 'raw_response' => $description]
          ];
        }

        // Es JSON válido, decodificar y usar el contenido
        $jsonData = json_decode($description, true);
        $text = json_encode($jsonData, JSON_UNESCAPED_UNICODE);

        return [
          'success' => true,
          'type' => 'image',
          'text' => $text,
          'metadata' => [
            'image_url' => $imageUrl,
            'caption' => $caption,
            'description' => $jsonData
          ]
        ];
      }

      return ['type' => 'image', 'text' => "[Imagen: {$caption}]", 'metadata' => ['error' => $result['error'] ?? null]];
    } catch (Exception $e) {
      return ['type' => 'image', 'text' => "[Imagen: {$caption}]", 'metadata' => ['error' => $e->getMessage()]];
    }
  }

  // Interpretar video
  private static function interpretVideo($message) {
    $caption = $message['text'] ?? '';
    $videoUrl = $message['media_url'] ?? null;
    $text = empty($caption) ? '[Video sin caption]' : "[Video: {$caption}]";
    return ['type' => 'video', 'text' => $text, 'metadata' => ['video_url' => $videoUrl, 'caption' => $caption]];
  }

  // Interpretar documento
  private static function interpretDocument($message) {
    $caption = $message['text'] ?? '';
    $docUrl = $message['media_url'] ?? null;
    $text = empty($caption) ? '[Documento]' : "[Documento: {$caption}]";
    return ['type' => 'document', 'text' => $text, 'metadata' => ['document_url' => $docUrl, 'caption' => $caption]];
  }

  // Interpretar sticker
  private static function interpretSticker($message) {
    return ['type' => 'sticker', 'text' => '[Sticker enviado]', 'metadata' => ['sticker_url' => $message['media_url'] ?? null]];
  }

  // Interpretar ubicación
  private static function interpretLocation($message) {
    $raw = $message['raw'] ?? [];
    $lat = $raw['locationMessage']['degreesLatitude'] ?? null;
    $lng = $raw['locationMessage']['degreesLongitude'] ?? null;
    $text = ($lat && $lng) ? "[Ubicación: {$lat}, {$lng}]" : '[Ubicación enviada]';
    return ['type' => 'location', 'text' => $text, 'metadata' => ['latitude' => $lat, 'longitude' => $lng]];
  }

  // Interpretar contacto
  private static function interpretContact($message) {
    $raw = $message['raw'] ?? [];
    $name = $raw['contactMessage']['displayName'] ?? 'Desconocido';
    return ['type' => 'contact', 'text' => "[Contacto: {$name}]", 'metadata' => ['name' => $name]];
  }

  // Obtener mime type de URL
  private static function getMimeType($url) {
    $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
    $mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
    return $mimes[$ext] ?? 'image/jpeg';
  }
}