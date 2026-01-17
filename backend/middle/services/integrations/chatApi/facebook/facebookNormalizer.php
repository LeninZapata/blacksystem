<?php

class facebookNormalizer {

  static function normalize($rawData) {
    if (!isset($rawData['entry'][0]['changes'][0])) {
      return null;
    }

    $change = $rawData['entry'][0]['changes'][0];
    $value = $change['value'] ?? [];
    $metadata = $value['metadata'] ?? [];

    // Detectar si es un webhook de status/error
    if (isset($value['statuses'][0])) {
      return self::normalizeStatus($value['statuses'][0], $metadata, $rawData);
    }

    // Verificar que sea un mensaje entrante
    if (!isset($value['messages'][0])) {
      return null;
    }

    $message = $value['messages'][0];
    $contacts = $value['contacts'][0] ?? [];

    // Extraer datos básicos
    $from = $message['from'] ?? '';
    $messageId = $message['id'] ?? '';
    $timestamp = $message['timestamp'] ?? time();
    $type = $message['type'] ?? 'text';
    $pushName = $contacts['profile']['name'] ?? '';

    // Procesar según tipo de mensaje
    $text = '';
    $mediaUrl = '';
    $mimeType = '';
    $caption = '';
    $base64 = null;
    $isVoice = false;
    $isAnimated = false;

    switch ($type) {
      case 'text':
        $text = $message['text']['body'] ?? '';
        break;

      case 'reaction':
        $text = $message['reaction']['emoji'] ?? '';
        $mediaUrl = $message['reaction']['message_id'] ?? '';
        break;

      case 'image':
        $mediaId = $message['image']['id'] ?? '';
        $caption = $message['image']['caption'] ?? '';
        $mimeType = $message['image']['mime_type'] ?? 'image/jpeg';
        
        // Descargar y convertir a base64
        if ($mediaId) {
          $downloadResult = self::downloadMediaToBase64($mediaId, $metadata['phone_number_id'] ?? '');
          if ($downloadResult['success']) {
            $base64 = $downloadResult['base64'];
            $mediaUrl = $downloadResult['url'];
          } else {
            $mediaUrl = $mediaId;
          }
        }
        break;

      case 'video':
        $mediaId = $message['video']['id'] ?? '';
        $caption = $message['video']['caption'] ?? '';
        $mimeType = $message['video']['mime_type'] ?? 'video/mp4';
        
        if ($mediaId) {
          $downloadResult = self::downloadMediaToBase64($mediaId, $metadata['phone_number_id'] ?? '');
          if ($downloadResult['success']) {
            $base64 = $downloadResult['base64'];
            $mediaUrl = $downloadResult['url'];
          } else {
            $mediaUrl = $mediaId;
          }
        }
        break;

      case 'audio':
        $mediaId = $message['audio']['id'] ?? '';
        $mimeType = $message['audio']['mime_type'] ?? 'audio/ogg';
        $isVoice = $message['audio']['voice'] ?? false;
        
        if ($mediaId) {
          $downloadResult = self::downloadMediaToBase64($mediaId, $metadata['phone_number_id'] ?? '');
          if ($downloadResult['success']) {
            $base64 = $downloadResult['base64'];
            $mediaUrl = $downloadResult['url'];
          } else {
            $mediaUrl = $mediaId;
          }
        }
        break;

      case 'document':
        $mediaId = $message['document']['id'] ?? '';
        $caption = $message['document']['caption'] ?? '';
        $mimeType = $message['document']['mime_type'] ?? 'application/pdf';
        $text = $message['document']['filename'] ?? '';
        
        if ($mediaId) {
          $downloadResult = self::downloadMediaToBase64($mediaId, $metadata['phone_number_id'] ?? '');
          if ($downloadResult['success']) {
            $base64 = $downloadResult['base64'];
            $mediaUrl = $downloadResult['url'];
          } else {
            $mediaUrl = $mediaId;
          }
        }
        break;

      case 'sticker':
        $mediaId = $message['sticker']['id'] ?? '';
        $mimeType = $message['sticker']['mime_type'] ?? 'image/webp';
        $isAnimated = $message['sticker']['animated'] ?? false;
        
        if ($mediaId) {
          $downloadResult = self::downloadMediaToBase64($mediaId, $metadata['phone_number_id'] ?? '');
          if ($downloadResult['success']) {
            $base64 = $downloadResult['base64'];
            $mediaUrl = $downloadResult['url'];
          } else {
            $mediaUrl = $mediaId;
          }
        }
        break;

      case 'unsupported':
        $errors = $message['errors'] ?? [];
        if (!empty($errors)) {
          $firstError = $errors[0];
          $text = '[' . ($firstError['title'] ?? 'Unsupported') . ': ' . ($firstError['error_data']['details'] ?? 'Message type not supported') . ']';
        } else {
          $text = '[Tipo de mensaje no soportado]';
        }
        break;

      case 'location':
        $latitude = $message['location']['latitude'] ?? null;
        $longitude = $message['location']['longitude'] ?? null;
        $name = $message['location']['name'] ?? null;
        $address = $message['location']['address'] ?? null;
        
        if ($latitude && $longitude) {
          $text = "📍 Ubicación: {$latitude}, {$longitude}";
          if ($name) $text .= " - {$name}";
          if ($address) $text .= " ({$address})";
        } else {
          $text = '[Ubicación sin coordenadas]';
        }
        break;

      case 'contacts':
        $contacts = $message['contacts'] ?? [];
        $contactCount = count($contacts);
        
        if ($contactCount > 0) {
          if ($contactCount === 1) {
            $contact = $contacts[0];
            $contactName = $contact['name']['formatted_name'] ?? 'Sin nombre';
            $phone = $contact['phones'][0]['phone'] ?? 'Sin teléfono';
            $text = "👤 Contacto: {$contactName} ({$phone})";
          } else {
            $text = "👥 {$contactCount} contactos compartidos";
          }
        } else {
          $text = '[Contacto sin información]';
        }
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
      'filename' => $type === 'document' ? ($message['document']['filename'] ?? '') : null,
      'audio' => $type === 'audio' ? [
        'is_voice' => $isVoice
      ] : null,
      'sticker' => $type === 'sticker' ? [
        'is_animated' => $isAnimated
      ] : null,
      'reaction' => $type === 'reaction' ? [
        'emoji' => $message['reaction']['emoji'] ?? '',
        'message_id' => $message['reaction']['message_id'] ?? ''
      ] : null,
      'location' => $type === 'location' ? [
        'latitude' => $message['location']['latitude'] ?? null,
        'longitude' => $message['location']['longitude'] ?? null,
        'name' => $message['location']['name'] ?? null,
        'address' => $message['location']['address'] ?? null
      ] : null,
      'contacts' => $type === 'contacts' ? ($message['contacts'] ?? []) : null,
      'errors' => $type === 'unsupported' ? ($message['errors'] ?? []) : null,
      'raw' => $rawData
    ];
  }

  // Descargar media de Facebook y convertir a base64
  private static function downloadMediaToBase64($mediaId, $phoneNumberId) {
    try {
      // Obtener access token desde el rawData (lo guardamos en el normalize)
      // Por ahora retornamos solo el mediaId, el access_token debe venir del WebhookController
      // que ya tiene acceso al bot config
      
      // NOTA: Este método será llamado después de que setConfig() se ejecute en el WebhookController
      // así que podemos acceder al servicio chatApi
      $chatApiService = ogApp()->service('chatApi');
      
      // Buscar la config de whatsapp-cloud-api
      $botConfig = $chatApiService::getBotConfig();
      if (!$botConfig || !isset($botConfig['config']['apis']['chat'])) {
        ogLog::warning('downloadMediaToBase64 - No hay config del bot', ['media_id' => $mediaId], ['module' => 'facebookNormalizer']);
        return ['success' => false];
      }

      // Buscar access_token en las APIs configuradas
      $accessToken = null;
      foreach ($botConfig['config']['apis']['chat'] as $api) {
        if (($api['config']['type_value'] ?? '') === 'whatsapp-cloud-api') {
          $accessToken = $api['config']['access_token'] ?? null;
          break;
        }
      }

      if (!$accessToken) {
        ogLog::warning('downloadMediaToBase64 - No se encontró access_token para whatsapp-cloud-api', ['media_id' => $mediaId], ['module' => 'facebookNormalizer']);
        return ['success' => false];
      }

      // Paso 1: Obtener URL de descarga del media
      $http = ogApp()->helper('http');
      $mediaInfoUrl = "https://graph.facebook.com/v21.0/{$mediaId}";
      
      $mediaInfoResponse = $http::get($mediaInfoUrl, [
        'headers' => [
          'Authorization: Bearer ' . $accessToken
        ]
      ]);

      if (!$mediaInfoResponse['success'] || !isset($mediaInfoResponse['data']['url'])) {
        ogLog::warning('downloadMediaToBase64 - Error obteniendo info del media', ['media_id' => $mediaId, 'response' => $mediaInfoResponse], ['module' => 'facebookNormalizer']);
        return ['success' => false];
      }

      $mediaUrl = $mediaInfoResponse['data']['url'];

      // Paso 2: Descargar el archivo
      $mediaContentResponse = $http::get($mediaUrl, [
        'headers' => [
          'Authorization: Bearer ' . $accessToken
        ],
        'return_binary' => true
      ]);

      if (!$mediaContentResponse['success']) {
        ogLog::warning('downloadMediaToBase64 - Error descargando media', ['media_id' => $mediaId, 'url' => $mediaUrl], ['module' => 'facebookNormalizer']);
        return ['success' => false];
      }

      // Paso 3: Convertir a base64
      $base64 = base64_encode($mediaContentResponse['data']);

      ogLog::info('downloadMediaToBase64 - Media descargado', ['media_id' => $mediaId, 'size_kb' => round(strlen($base64) / 1024, 2)], ['module' => 'facebookNormalizer']);

      return [
        'success' => true,
        'base64' => $base64,
        'url' => $mediaUrl
      ];

    } catch (Exception $e) {
      ogLog::error('downloadMediaToBase64 - Error', ['error' => $e->getMessage(), 'media_id' => $mediaId], ['module' => 'facebookNormalizer']);
      return ['success' => false];
    }
  }

  // Normalizar webhooks de status (sent, delivered, read, failed)
  private static function normalizeStatus($status, $metadata, $rawData) {
    $statusType = $status['status'] ?? 'unknown';
    $messageId = $status['id'] ?? '';
    $timestamp = $status['timestamp'] ?? time();
    $recipientId = $status['recipient_id'] ?? '';
    $errors = $status['errors'] ?? [];

    // Si hay errores, extraer info del primer error
    $errorCode = null;
    $errorTitle = null;
    $errorMessage = null;
    $errorDetails = null;

    if (!empty($errors)) {
      $firstError = $errors[0];
      $errorCode = $firstError['code'] ?? null;
      $errorTitle = $firstError['title'] ?? null;
      $errorMessage = $firstError['message'] ?? null;
      $errorDetails = $firstError['error_data']['details'] ?? null;
    }

    return [
      'provider' => 'whatsapp-cloud-api',
      'message_id' => $messageId,
      'from' => $recipientId,
      'push_name' => '',
      'timestamp' => $timestamp,
      'type' => 'status',
      'text' => '',
      'caption' => '',
      'media_url' => '',
      'mime_type' => '',
      'base64' => null,
      'phone_number_id' => $metadata['phone_number_id'] ?? '',
      'display_phone_number' => $metadata['display_phone_number'] ?? '',
      'status' => $statusType,
      'error' => !empty($errors),
      'error_code' => $errorCode,
      'error_title' => $errorTitle,
      'error_message' => $errorMessage,
      'error_details' => $errorDetails,
      'raw' => $rawData
    ];
  }

  static function standardize($normalized) {
    if (!$normalized) {
      return null;
    }

    // Manejo especial para webhooks de status
    if ($normalized['type'] === 'status') {
      return [
        'webhook' => [
          'provider' => 'whatsapp-cloud-api',
          'instance' => $normalized['phone_number_id'],
          'event' => 'status',
          'timestamp' => $normalized['timestamp'],
          'server_url' => null
        ],
        'sender' => [
          'id' => $normalized['phone_number_id'],
          'number' => $normalized['display_phone_number'],
          'instance' => $normalized['phone_number_id']
        ],
        'person' => [
          'id' => $normalized['from'],
          'number' => $normalized['from'],
          'name' => '',
          'is_me' => false,
          'platform' => 'whatsapp'
        ],
        'message' => [
          'id' => $normalized['message_id'],
          'type' => 'STATUS',
          'text' => '',
          'timestamp' => $normalized['timestamp'],
          'status' => $normalized['status'],
          'media_url' => null,
          'base64' => null,
          'raw' => []
        ],
        'status' => [
          'type' => $normalized['status'],
          'error' => $normalized['error'],
          'error_code' => $normalized['error_code'],
          'error_title' => $normalized['error_title'],
          'error_message' => $normalized['error_message'],
          'error_details' => $normalized['error_details']
        ],
        'context' => [
          'type' => 'normal',
          'source' => null,
          'source_app' => null,
          'source_url' => null,
          'is_fb_ads' => false,
          'ad_data' => null,
          'raw' => []
        ],
        'raw' => $normalized
      ];
    }

    // Manejo normal para mensajes
    $type = strtoupper($normalized['type']);
    $text = $normalized['text'] ?: $normalized['caption'] ?: '';

    return [
      'webhook' => [
        'provider' => 'whatsapp-cloud-api',
        'instance' => $normalized['phone_number_id'],
        'event' => 'messages.upsert',
        'timestamp' => $normalized['timestamp'],
        'server_url' => null
      ],
      'sender' => [
        'id' => $normalized['phone_number_id'],
        'number' => $normalized['display_phone_number'],
        'instance' => $normalized['phone_number_id']
      ],
      'person' => [
        'id' => $normalized['from'],
        'number' => $normalized['from'],
        'name' => $normalized['push_name'],
        'is_me' => false,
        'platform' => 'whatsapp'
      ],
      'message' => [
        'id' => $normalized['message_id'],
        'type' => $type,
        'text' => $text,
        'timestamp' => $normalized['timestamp'],
        'status' => null,
        'media_url' => $normalized['media_url'],
        'base64' => $normalized['base64'],
        'raw' => [
          'mime_type' => $normalized['mime_type'],
          'filename' => $normalized['filename'] ?? null,
          'audio' => $normalized['audio'] ?? null,
          'sticker' => $normalized['sticker'] ?? null,
          'reaction' => $normalized['reaction'] ?? null,
          'location' => $normalized['location'] ?? null,
          'contacts' => $normalized['contacts'] ?? null,
          'errors' => $normalized['errors'] ?? null
        ]
      ],
      'context' => [
        'type' => 'normal',
        'source' => null,
        'source_app' => null,
        'source_url' => null,
        'is_fb_ads' => false,
        'ad_data' => null,
        'raw' => []
      ],
      'raw' => $normalized
    ];
  }
}