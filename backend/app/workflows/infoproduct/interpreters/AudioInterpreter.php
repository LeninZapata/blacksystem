<?php

class AudioInterpreter {

  static function interpret($message, $bot) {
    $base64 = $message['base64'] ?? null;
    $audioUrl = $message['media_url'] ?? null;

    // PRIORIDAD 1: Si hay base64, usarlo (dato más fresco)
    if (!empty($base64)) {
      // Evolution suele enviar audio en formato ogg
      $audioUrl = 'data:audio/ogg;base64,' . $base64;
      ogLog::debug("AudioInterpreter - Usando base64 (prioridad 1)", [
        'base64_length' => strlen($base64)
      ], ['module' => 'AudioInterpreter']);
    }
    // PRIORIDAD 2: Si no hay base64 pero hay URL, usar URL
    elseif (!empty($audioUrl)) {
      ogLog::debug("AudioInterpreter - Usando media_url (prioridad 2)", [
        'url' => substr($audioUrl, 0, 100)
      ], ['module' => 'AudioInterpreter']);
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