<?php
class evolutionNormalizer {

  // Detectar si es un webhook de Evolution API
  static function detect($rawData) {
    $webhook = is_array($rawData) && isset($rawData[0]) ? $rawData[0] : $rawData;

    // Validación 1: Verificar que server_url contenga 'evo-api'
    $serverUrl = $webhook['body']['server_url'] ?? '';
    $hasEvoApi = !empty($serverUrl) && stripos($serverUrl, 'evo-api') !== false;

    // Validación 2: Verificar estructura body.data.key.id (específica de Evolution)
    $hasKeyStructure = isset($webhook['body']['data']['key']['id']);

    // Validación 3: Evolution API siempre tiene body->event y body->instance
    $hasEventAndInstance = isset($webhook['body']['event']) && isset($webhook['body']['instance']);

    // Debe cumplir al menos 2 de las 3 validaciones
    $validations = [$hasEvoApi, $hasKeyStructure, $hasEventAndInstance];
    $passedValidations = count(array_filter($validations));

    return $passedValidations >= 2;
  }

  // Normalizar webhook de Evolution API (formato crudo del provider)
  static function normalize($rawData) {
    // Extraer primer elemento si viene como array
    $webhook = is_array($rawData) && isset($rawData[0]) ? $rawData[0] : $rawData;

    return [
      'provider' => 'evolution',
      'headers' => $webhook['headers'] ?? [],
      'params' => $webhook['params'] ?? [],
      'query' => $webhook['query'] ?? [],
      'body' => $webhook['body'] ?? [],
      'webhookUrl' => $webhook['webhookUrl'] ?? '',
      'executionMode' => $webhook['executionMode'] ?? '',
      'data' => $webhook['body']['data'] ?? [],
      'event' => $webhook['body']['event'] ?? null,
      'instance' => $webhook['body']['instance'] ?? null,
      'serverUrl' => $webhook['body']['server_url'] ?? null,
      'apiKey' => $webhook['body']['apikey'] ?? null,
      'raw' => $webhook
    ];
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

    // Extraer número del bot (sender)
    $botNumber = self::extractBotNumber($body);

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
        'provider' => 'evolution',
        'instance' => $normalizedData['instance'],
        'event' => $normalizedData['event'],
        'timestamp' => $data['messageTimestamp'] ?? time(),
        'server_url' => $normalizedData['serverUrl']
      ],

      // Remitente (bot que recibe el mensaje)
      'sender' => [
        'id' => $body['sender'] ?? null,
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

  // Extraer información del remitente/bot (método legacy)
  static function extractSender($normalizedData) {
    $body = $normalizedData['body'] ?? [];

    return [
      'id' => $body['sender'] ?? null,
      'number' => self::extractBotNumber($body),
      'instance' => $normalizedData['instance'] ?? null
    ];
  }

  // Extraer mensaje (método legacy)
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

  // Extraer context info (método legacy)
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

  // === MÉTODOS PRIVADOS DE AYUDA ===

  // Extraer número del bot (sender) desde body
  private static function extractBotNumber($body) {
    $sender = $body['sender'] ?? null;

    if (empty($sender)) return null;

    $sender = (string)$sender;

    if (stripos($sender, '@s.whatsapp.net') !== false) {
      return str_replace('@s.whatsapp.net', '', $sender);
    }

    return $sender;
  }

  // Extraer número de la persona (remoteJid) desde key
  private static function extractPersonNumber($key) {
    $fields = ['remoteJid', 'senderPn', 'senderLid'];

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

  // Extraer número del remitente (robusto - busca en múltiples campos) - LEGACY
  private static function extractNumber($key) {
    return self::extractPersonNumber($key);
  }

  // Detectar tipo de mensaje
  private static function detectMessageType($data, $contextInfo) {
    // Si viene de FB Ads
    if (!empty($contextInfo['conversionSource']) && $contextInfo['conversionSource'] === 'FB_Ads') {
      return 'fb_ads_lead';
    }

    // Tipo de mensaje según Evolution
    return $data['messageType'] ?? 'conversation';
  }

  // Detectar tipo de mensaje real (text, audio, image, video, document, sticker, reaction, location, contact)
  private static function detectRealMessageType($message) {
    if (isset($message['reactionMessage'])) return 'reaction';
    if (isset($message['audioMessage'])) return 'audio';
    if (isset($message['imageMessage'])) return 'image';
    if (isset($message['videoMessage'])) return 'video';
    if (isset($message['documentMessage'])) return 'document';
    if (isset($message['stickerMessage'])) return 'sticker';
    if (isset($message['locationMessage'])) return 'location';
    if (isset($message['contactMessage']) || isset($message['contactsArrayMessage'])) return 'contact';
    if (isset($message['extendedTextMessage']) || isset($message['conversation'])) return 'text';
    
    return 'unknown';
  }

  // Extraer texto del mensaje
  private static function extractText($message) {
    // Prioridad 1: Reacciones
    if (isset($message['reactionMessage']['text'])) {
      return $message['reactionMessage']['text'];
    }

    // Prioridad 2: Conversación directa
    if (isset($message['conversation'])) {
      return $message['conversation'];
    }

    if (isset($message['extendedTextMessage']['text'])) {
      return $message['extendedTextMessage']['text'];
    }

    if (isset($message['imageMessage']['caption'])) {
      return $message['imageMessage']['caption'];
    }

    if (isset($message['videoMessage']['caption'])) {
      return $message['videoMessage']['caption'];
    }

    return '';
  }

  // Extraer URL de media
  private static function extractMediaUrl($message) {
    if (isset($message['imageMessage']['url'])) {
      return $message['imageMessage']['url'];
    }

    if (isset($message['videoMessage']['url'])) {
      return $message['videoMessage']['url'];
    }

    if (isset($message['audioMessage']['url'])) {
      return $message['audioMessage']['url'];
    }

    if (isset($message['documentMessage']['url'])) {
      return $message['documentMessage']['url'];
    }

    return null;
  }

  // Detectar tipo de contexto
  private static function detectContextType($contextInfo) {
    if (empty($contextInfo)) {
      return 'normal';
    }

    if (isset($contextInfo['conversionSource'])) {
      return 'conversion';
    }

    if (isset($contextInfo['quotedMessage'])) {
      return 'reply';
    }

    return 'normal';
  }

  // Extraer datos del anuncio de Facebook
  private static function extractAdData($contextInfo) {
    if (empty($contextInfo['externalAdReply'])) {
      return null;
    }

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