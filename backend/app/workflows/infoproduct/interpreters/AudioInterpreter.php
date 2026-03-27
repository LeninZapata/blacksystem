<?php

class AudioInterpreter {

  static function interpret($message, $bot) {
    $base64 = $message['base64'] ?? null;
    $audioUrl = $message['media_url'] ?? null;

    // PRIORIDAD 1: Si hay base64, usarlo (dato más fresco)
    if (!empty($base64)) {
      // Evolution suele enviar audio en formato ogg
      $audioUrl = 'data:audio/ogg;base64,' . $base64;
    }
    // PRIORIDAD 2: Si no hay base64 pero hay URL, usar URL
    elseif (!empty($audioUrl)) {
    }
    // Si no hay ninguno
    else {
      return [
        'type' => 'audio',
        'text' => '[Audio sin URL ni base64]',
        'metadata' => null
      ];
    }

    try {
      $ai = ogApp()->service('ai');
      $result = $ai->transcribeAudio($audioUrl, $bot);

      if ($result['success']) {
        return [
          'type' => 'audio',
          'text' => $result['text'],
          'metadata' => [
            'audio_url' => $audioUrl,
            'provider' => $result['provider'] ?? null,
            'used_base64' => !empty($base64)
          ]
        ];
      }

      return [
        'type' => 'audio',
        'text' => '[Audio no transcrito]',
        'metadata' => ['error' => $result['error'] ?? null]
      ];

    } catch (Exception $e) {
      return [
        'type' => 'audio',
        'text' => '[Error transcribiendo audio]',
        'metadata' => ['error' => $e->getMessage()]
      ];
    }
  }
}