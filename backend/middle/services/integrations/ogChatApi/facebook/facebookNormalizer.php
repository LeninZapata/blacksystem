<?php

class facebookNormalizer {

  static function normalize($rawData) {
    if (!isset($rawData['entry'][0]['changes'][0])) {
      return null;
    }

    $change = $rawData['entry'][0]['changes'][0];
    $value = $change['value'] ?? [];

    // Verificar que sea un mensaje entrante
    if (!isset($value['messages'][0])) {
      return null;
    }

    $message = $value['messages'][0];
    $metadata = $value['metadata'] ?? [];
    $contacts = $value['contacts'][0] ?? [];

    // Extraer datos básicos
    $from = $message['from'] ?? '';
    $messageId = $message['id'] ?? '';
    $timestamp = $message['timestamp'] ?? time();
    $type = $message['type'] ?? 'text';

    // Nombre del contacto
    $pushName = $contacts['profile']['name'] ?? '';

    // Procesar según tipo de mensaje
    $text = '';
    $mediaUrl = '';
    $mimeType = '';
    $caption = '';
    $base64 = null;

    switch ($type) {
      case 'text':
        $text = $message['text']['body'] ?? '';
        break;

      case 'image':
        $mediaUrl = $message['image']['id'] ?? ''; // ID que se debe convertir a URL
        $caption = $message['image']['caption'] ?? '';
        $mimeType = $message['image']['mime_type'] ?? 'image/jpeg';
        break;

      case 'video':
        $mediaUrl = $message['video']['id'] ?? '';
        $caption = $message['video']['caption'] ?? '';
        $mimeType = $message['video']['mime_type'] ?? 'video/mp4';
        break;

      case 'audio':
        $mediaUrl = $message['audio']['id'] ?? '';
        $mimeType = $message['audio']['mime_type'] ?? 'audio/ogg';
        break;

      case 'document':
        $mediaUrl = $message['document']['id'] ?? '';
        $caption = $message['document']['caption'] ?? '';
        $mimeType = $message['document']['mime_type'] ?? 'application/pdf';
        break;

      case 'sticker':
        $mediaUrl = $message['sticker']['id'] ?? '';
        $mimeType = $message['sticker']['mime_type'] ?? 'image/webp';
        break;

      default:
        $text = '[Tipo de mensaje no soportado: ' . $type . ']';
    }

    return [
      'provider' => 'whatsapp-cloud-api',
      'message_id' => $messageId,
      'from' => $from,
      'push_name' => $pushName,
      'timestamp' => $timestamp,
      'type' => $type,
      'text' => $text,
      'caption' => $caption,
      'media_url' => $mediaUrl,
      'mime_type' => $mimeType,
      'base64' => $base64,
      'phone_number_id' => $metadata['phone_number_id'] ?? '',
      'display_phone_number' => $metadata['display_phone_number'] ?? '',
      'raw' => $rawData
    ];
  }

  static function standardize($normalized) {
    if (!$normalized) {
      return null;
    }

    $type = strtoupper($normalized['type']);
    $text = $normalized['text'] ?: $normalized['caption'] ?: '';

    return [
      'sender' => [
        'number' => $normalized['from'],
        'push_name' => $normalized['push_name'],
        'phone_number_id' => $normalized['phone_number_id']
      ],
      'person' => [
        'number' => $normalized['from'],
        'name' => $normalized['push_name'],
        'platform' => 'whatsapp'
      ],
      'message' => [
        'id' => $normalized['message_id'],
        'type' => $type,
        'text' => $text,
        'media_url' => $normalized['media_url'],
        'mime_type' => $normalized['mime_type'],
        'base64' => $normalized['base64'],
        'timestamp' => $normalized['timestamp']
      ],
      'bot' => [
        'number' => $normalized['display_phone_number']
      ],
      'webhook' => [
        'source' => 'facebook', // ← IMPORTANTE para la detección
        'raw' => $normalized
      ]
    ];
  }
}