<?php

class AudioInterpreter {

  static function interpret($message, $bot) {
    $audioUrl = $message['media_url'] ?? null;

    if (!$audioUrl) {
      return [
        'type' => 'audio',
        'text' => '[Audio sin URL]',
        'metadata' => null
      ];
    }

    try {
      $ai = service::integration('ai');
      $result = $ai->transcribeAudio($audioUrl, $bot);

      if ($result['success']) {
        return [
          'type' => 'audio',
          'text' => $result['text'],
          'metadata' => [
            'audio_url' => $audioUrl,
            'provider' => $result['provider'] ?? null
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