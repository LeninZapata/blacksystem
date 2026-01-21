<?php
class evolutionNormalizer {

  // Detectar si es un webhook de Evolution API
  static function detect($rawData) {
    // Manejar si viene como array con índice 0 (formato n8n)
    $webhook = is_array($rawData) && isset($rawData[0]) ? $rawData[0] : $rawData;

    // FORMATO 1: Webhook directo de Evolution (producción)
    // Validación 1: server_url contiene 'evo-api' (directo en root)
    $serverUrl = $webhook['server_url'] ?? '';
    $hasEvoApiDirect = !empty($serverUrl) && stripos($serverUrl, 'evo-api') !== false;

    // Validación 2: Estructura data.key.id (específica de Evolution, directo en root)
    $hasKeyStructureDirect = isset($webhook['data']['key']['id']);

    // Validación 3: Evolution API siempre tiene event, instance y sender
    $hasEventAndInstanceDirect = isset($webhook['event']) && isset($webhook['instance']) && isset($webhook['sender']);

    // FORMATO 2: Webhook envuelto en body (desarrollo/n8n)
    $serverUrlBody = $webhook['body']['server_url'] ?? '';
    $hasEvoApiBody = !empty($serverUrlBody) && stripos($serverUrlBody, 'evo-api') !== false;

    $hasKeyStructureBody = isset($webhook['body']['data']['key']['id']);
    $hasEventAndInstanceBody = isset($webhook['body']['event']) && isset($webhook['body']['instance']);

    // Debe cumplir al menos 2 validaciones en CUALQUIERA de los dos formatos
    $validationsDirect = [$hasEvoApiDirect, $hasKeyStructureDirect, $hasEventAndInstanceDirect];
    $validationsBody = [$hasEvoApiBody, $hasKeyStructureBody, $hasEventAndInstanceBody];

    $passedDirect = count(array_filter($validationsDirect));
    $passedBody = count(array_filter($validationsBody));

    $result = $passedDirect >= 2 || $passedBody >= 2;
    ogLog::debug( 'result of evolutionNormalizer::detect', [ 'passedDirect' => $passedDirect, 'passedBody' => $passedBody, 'result' => $result ], ['module' => 'evolutionNormalizer'] );
    if ($result) {
      $filteredData = self::truncateDebugData($webhook);
      ogLog::debug('Evolution API detectado - Webhook recibido', $filteredData, ['module' => 'evolutionNormalizer']);
    }

    return $result;
  }

  // Normalizar webhook de Evolution API (formato crudo del provider)
  static function normalize($rawData) {
    // Manejar si viene como array con índice 0
    $webhook = is_array($rawData) && isset($rawData[0]) ? $rawData[0] : $rawData;

    // Detectar si viene en formato directo (producción) o envuelto en body (n8n)
    $isDirect = isset($webhook['event']) && isset($webhook['data']) && !isset($webhook['body']['event']);

    if ($isDirect) {
      // FORMATO DIRECTO (producción)
      return [
        'provider' => 'evolution',
        'headers' => [],
        'params' => [],
        'query' => [],
        'body' => $webhook,
        'webhookUrl' => $webhook['destination'] ?? '',
        'executionMode' => 'direct',
        'data' => $webhook['data'] ?? [],
        'event' => $webhook['event'] ?? null,
        'instance' => $webhook['instance'] ?? null,
        'serverUrl' => $webhook['server_url'] ?? null,
        'apiKey' => $webhook['apikey'] ?? null,
        'raw' => $webhook
      ];
    } else {
      // FORMATO ENVUELTO EN BODY (desarrollo/n8n)
      return [
        'provider' => 'evolution',
        'headers' => $webhook['headers'] ?? [],
        'params' => $webhook['params'] ?? [],
        'query' => $webhook['query'] ?? [],
        'body' => $webhook['body'] ?? [],
        'webhookUrl' => $webhook['webhookUrl'] ?? '',
        'executionMode' => $webhook['executionMode'] ?? 'wrapped',
        'data' => $webhook['body']['data'] ?? [],
        'event' => $webhook['body']['event'] ?? null,
        'instance' => $webhook['body']['instance'] ?? null,
        'serverUrl' => $webhook['body']['server_url'] ?? null,
        'apiKey' => $webhook['body']['apikey'] ?? null,
        'raw' => $webhook
      ];
    }
  }

  // Estandarizar a formato universal
  static function standardize($normalizedData) {
    $data = $normalizedData['data'] ?? [];
    $key = $data['key'] ?? [];
    $message = $data['message'] ?? [];
    $contextInfo = $data['contextInfo'] ?? [];
    $body = $normalizedData['body'] ?? [];

    // Detectar tipo de mensaje real (text, audio, image, video, etc)
    $messageType = self::detectRealMessageType($message);

    // Detectar tipo de contexto (fb_ads, normal, reply, etc)
    $contextType = self::detectContextType($contextInfo);

    // Extraer texto del mensaje
    $text = self::extractText($message);

    // Extraer número del bot (sender) - puede estar en body o en root
    $botNumber = self::extractBotNumber($body, $normalizedData);

    // Extraer número de la persona (remoteJid)
    $personNumber = self::extractPersonNumber($key);

    // Construir context
    $context = [
      'type' => $contextType,
      'source' => $contextInfo['conversionSource'] ?? null,
      'source_app' => $contextInfo['entryPointConversionApp'] ?? $contextInfo['sourceApp'] ?? $contextInfo['externalAdReply']['sourceApp'] ?? null,
      'source_url' => $contextInfo['externalAdReply']['sourceUrl'] ?? null,
      'is_fb_ads' => $contextType === 'conversion' && !empty($contextInfo['conversionSource']) && $contextInfo['conversionSource'] === 'FB_Ads',
      'ad_data' => self::extractAdData($contextInfo),
      'raw' => $contextInfo
    ];

    // Formato estándar universal (mismo para todos los providers)
    return [
      // Metadata del webhook
      'webhook' => [
        'provider' => 'evolutionapi',
        'instance' => $normalizedData['instance'],
        'event' => $normalizedData['event'],
        'timestamp' => $data['messageTimestamp'] ?? time(),
        'server_url' => $normalizedData['serverUrl'],
        'source' => $data['source'] ?? 'unknown'
      ],

      // Remitente (bot que recibe el mensaje)
      'sender' => [
        'id' => $body['sender'] ?? $normalizedData['raw']['sender'] ?? null,
        'number' => $botNumber,
        'instance' => $normalizedData['instance']
      ],

      // Persona (quien envía el mensaje)
      'person' => [
        'id' => $key['remoteJid'] ?? null,
        'number' => $personNumber,
        'name' => $data['pushName'] ?? 'Unknown',
        'is_me' => $key['fromMe'] ?? false,
        'platform' => $data['source'] ?? 'unknown'
      ],

      // Mensaje (message)
      'message' => [
        'id' => $key['id'] ?? null,
        'type' => $messageType,
        'text' => $text,
        'timestamp' => $data['messageTimestamp'] ?? null,
        'status' => $data['status'] ?? null,
        'media_url' => self::extractMediaUrl($message),
        'base64' => $message['base64'] ?? null,
        'raw' => $message
      ],

      // Contexto (context)
      'context' => $context,

      // Datos crudos originales
      'raw' => $normalizedData
    ];
  }

  // Métodos legacy para compatibilidad
  static function extractSender($normalizedData) {
    $body = $normalizedData['body'] ?? [];
    return [
      'id' => $body['sender'] ?? $normalizedData['raw']['sender'] ?? null,
      'number' => self::extractBotNumber($body, $normalizedData),
      'instance' => $normalizedData['instance'] ?? null
    ];
  }

  static function extractMessage($normalizedData) {
    $data = $normalizedData['data'] ?? [];
    $message = $data['message'] ?? [];
    return [
      'type' => $data['messageType'] ?? 'unknown',
      'text' => self::extractText($message),
      'timestamp' => $data['messageTimestamp'] ?? null,
      'status' => $data['status'] ?? null,
      'messageId' => $data['key']['id'] ?? null,
      'raw' => $message
    ];
  }

  static function extractContext($normalizedData) {
    $data = $normalizedData['data'] ?? [];
    $contextInfo = $data['contextInfo'] ?? [];
    return [
      'source' => $contextInfo['conversionSource'] ?? null,
      'sourceApp' => $contextInfo['sourceApp'] ?? null,
      'externalAdReply' => $contextInfo['externalAdReply'] ?? null,
      'raw' => $contextInfo
    ];
  }

  // === MÉTODOS PRIVADOS ===

  // Truncar datos largos para debug
  private static function truncateDebugData($webhook) {
    $filtered = $webhook;

    // Navegar a data.message si existe
    if (isset($filtered['data']['message'])) {
      $message = &$filtered['data']['message'];

      // Truncar imageMessage
      if (isset($message['imageMessage'])) {
        if (isset($message['imageMessage']['url'])) {
          $message['imageMessage']['url'] = substr($message['imageMessage']['url'], 0, 50) . '...[truncado]';
        }
        if (isset($message['imageMessage']['fileSha256'])) {
          $message['imageMessage']['fileSha256'] = array_slice($message['imageMessage']['fileSha256'], 0, 2);
        }
        if (isset($message['imageMessage']['mediaKey'])) {
          $message['imageMessage']['mediaKey'] = array_slice($message['imageMessage']['mediaKey'], 0, 2);
        }
        if (isset($message['imageMessage']['fileEncSha256'])) {
          $message['imageMessage']['fileEncSha256'] = array_slice($message['imageMessage']['fileEncSha256'], 0, 2);
        }
        if (isset($message['imageMessage']['directPath'])) {
          $message['imageMessage']['directPath'] = substr($message['imageMessage']['directPath'], 0, 30) . '...[truncado]';
        }
        if (isset($message['imageMessage']['jpegThumbnail'])) {
          $message['imageMessage']['jpegThumbnail'] = array_slice($message['imageMessage']['jpegThumbnail'], 0, 2);
        }
      }

      // Truncar base64
      if (isset($message['base64'])) {
        $message['base64'] = substr($message['base64'], 0, 50) . '...[truncado]';
      }

      // Truncar messageContextInfo
      if (isset($message['messageContextInfo']['deviceListMetadata'])) {
        $metadata = &$message['messageContextInfo']['deviceListMetadata'];
        if (isset($metadata['senderKeyHash'])) {
          $metadata['senderKeyHash'] = array_slice($metadata['senderKeyHash'], 0, 2);
        }
        if (isset($metadata['recipientKeyHash'])) {
          $metadata['recipientKeyHash'] = array_slice($metadata['recipientKeyHash'], 0, 2);
        }
      }
      if (isset($message['messageContextInfo']['messageSecret'])) {
        $message['messageContextInfo']['messageSecret'] = array_slice($message['messageContextInfo']['messageSecret'], 0, 2);
      }
    }

    // También verificar en body.data.message (formato envuelto)
    if (isset($filtered['body']['data']['message'])) {
      $message = &$filtered['body']['data']['message'];

      if (isset($message['imageMessage'])) {
        if (isset($message['imageMessage']['url'])) {
          $message['imageMessage']['url'] = substr($message['imageMessage']['url'], 0, 50) . '...[truncado]';
        }
        if (isset($message['imageMessage']['fileSha256'])) {
          $message['imageMessage']['fileSha256'] = array_slice($message['imageMessage']['fileSha256'], 0, 2);
        }
        if (isset($message['imageMessage']['mediaKey'])) {
          $message['imageMessage']['mediaKey'] = array_slice($message['imageMessage']['mediaKey'], 0, 2);
        }
        if (isset($message['imageMessage']['fileEncSha256'])) {
          $message['imageMessage']['fileEncSha256'] = array_slice($message['imageMessage']['fileEncSha256'], 0, 2);
        }
        if (isset($message['imageMessage']['directPath'])) {
          $message['imageMessage']['directPath'] = substr($message['imageMessage']['directPath'], 0, 30) . '...[truncado]';
        }
        if (isset($message['imageMessage']['jpegThumbnail'])) {
          $message['imageMessage']['jpegThumbnail'] = array_slice($message['imageMessage']['jpegThumbnail'], 0, 2);
        }
      }

      if (isset($message['base64'])) {
        $message['base64'] = substr($message['base64'], 0, 50) . '...[truncado]';
      }

      if (isset($message['messageContextInfo']['deviceListMetadata'])) {
        $metadata = &$message['messageContextInfo']['deviceListMetadata'];
        if (isset($metadata['senderKeyHash'])) {
          $metadata['senderKeyHash'] = array_slice($metadata['senderKeyHash'], 0, 2);
        }
        if (isset($metadata['recipientKeyHash'])) {
          $metadata['recipientKeyHash'] = array_slice($metadata['recipientKeyHash'], 0, 2);
        }
      }
      if (isset($message['messageContextInfo']['messageSecret'])) {
        $message['messageContextInfo']['messageSecret'] = array_slice($message['messageContextInfo']['messageSecret'], 0, 2);
      }
    }

    return $filtered;
  }

  // Extraer número del bot (sender) - soporta ambos formatos
  private static function extractBotNumber($body, $normalizedData = null) {
    // Intentar desde body primero
    $sender = $body['sender'] ?? null;
    
    // Si no está en body, intentar desde raw (formato directo)
    if (empty($sender) && $normalizedData) {
      $sender = $normalizedData['raw']['sender'] ?? null;
    }

    if (empty($sender)) return null;

    $sender = (string)$sender;
    if (stripos($sender, '@s.whatsapp.net') !== false) {
      return str_replace('@s.whatsapp.net', '', $sender);
    }

    return $sender;
  }

  // Extraer número de la persona (remoteJid) desde key
  private static function extractPersonNumber($key) {
    $fields = ['remoteJid', 'remoteJidAlt', 'senderPn', 'senderLid'];

    foreach ($fields as $field) {
      $value = $key[$field] ?? null;
      if (empty($value)) continue;

      $value = (string)$value;
      if (stripos($value, '@s.whatsapp.net') !== false) {
        return str_replace('@s.whatsapp.net', '', $value);
      }
    }

    return null;
  }

  // Detectar tipo de mensaje real
  private static function detectRealMessageType($message) {
    if (isset($message['reactionMessage'])) return 'reaction';
    if (isset($message['audioMessage'])) return 'audio';
    if (isset($message['imageMessage'])) return 'image';
    if (isset($message['videoMessage'])) return 'video';
    if (isset($message['documentMessage'])) return 'document';
    if (isset($message['stickerMessage'])) return 'sticker';
    if (isset($message['lottieStickerMessage'])) return 'sticker';
    if (isset($message['locationMessage'])) return 'location';
    if (isset($message['contactMessage']) || isset($message['contactsArrayMessage'])) return 'contact';
    if (isset($message['extendedTextMessage']) || isset($message['conversation'])) return 'text';
    return 'unknown';
  }

  // Extraer texto del mensaje
  private static function extractText($message) {
    if (isset($message['reactionMessage']['text'])) return $message['reactionMessage']['text'];
    if (isset($message['conversation'])) return $message['conversation'];
    if (isset($message['extendedTextMessage']['text'])) return $message['extendedTextMessage']['text'];
    if (isset($message['imageMessage']['caption'])) return $message['imageMessage']['caption'];
    if (isset($message['videoMessage']['caption'])) return $message['videoMessage']['caption'];
    return '';
  }

  // Extraer URL de media
  private static function extractMediaUrl($message) {
    if (isset($message['imageMessage']['url'])) return $message['imageMessage']['url'];
    if (isset($message['videoMessage']['url'])) return $message['videoMessage']['url'];
    if (isset($message['audioMessage']['url'])) return $message['audioMessage']['url'];
    if (isset($message['documentMessage']['url'])) return $message['documentMessage']['url'];
    return null;
  }

  // Detectar tipo de contexto
  private static function detectContextType($contextInfo) {
    if (empty($contextInfo)) return 'normal';
    if (isset($contextInfo['conversionSource'])) return 'conversion';
    if (isset($contextInfo['quotedMessage'])) return 'reply';
    return 'normal';
  }

  // Extraer datos del anuncio de Facebook
  private static function extractAdData($contextInfo) {
    if (empty($contextInfo['externalAdReply'])) return null;

    $adReply = $contextInfo['externalAdReply'];
    return [
      'title' => $adReply['title'] ?? null,
      'body' => $adReply['body'] ?? null,
      'media_type' => $adReply['mediaType'] ?? null,
      'media_url' => $adReply['mediaUrl'] ?? null,
      'thumbnail_url' => $adReply['thumbnailUrl'] ?? null,
      'source_id' => $adReply['sourceId'] ?? null,
      'source_url' => $adReply['sourceUrl'] ?? null,
      'source_app' => $adReply['sourceApp'] ?? null,
      'ctwa_clid' => $adReply['ctwaClid'] ?? null,
      'greeting_shown' => $adReply['automatedGreetingMessageShown'] ?? false,
      'greeting_body' => $adReply['greetingMessageBody'] ?? null
    ];
  }
}