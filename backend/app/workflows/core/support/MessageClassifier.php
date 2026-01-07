<?php

class MessageClassifier {

  static function classify($message) {
    ogLog::info("Classifying message: ", $message);
    $type = strtoupper($message['type'] ?? 'TEXT');

    $validTypes = ['TEXT', 'IMAGE', 'AUDIO', 'VIDEO', 'DOCUMENT', 'STICKER', 'LOCATION', 'CONTACT', 'REACTION'];

    return in_array($type, $validTypes) ? $type : 'TEXT';
  }

  static function requiresBuffering($type) {
    $bufferableTypes = ['TEXT', 'AUDIO', 'IMAGE'];
    return in_array(strtoupper($type), $bufferableTypes);
  }

  static function isMediaType($type) {
    $mediaTypes = ['IMAGE', 'VIDEO', 'AUDIO', 'DOCUMENT'];
    return in_array(strtoupper($type), $mediaTypes);
  }

  static function hasImageInMessages($messages) {
    foreach ($messages as $msg) {
      if (strtoupper($msg['type'] ?? '') === 'IMAGE') {
        return true;
      }
    }
    return false;
  }
}