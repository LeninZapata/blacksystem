<?php

class TextInterpreter {

  static function interpret($message) {
    return [
      'type' => 'text',
      'text' => $message['text'] ?? '',
      'metadata' => null
    ];
  }
}