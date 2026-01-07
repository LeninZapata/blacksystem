<?php

class AudioPaymentValidator {
  private static $logMeta = ['module' => 'AudioPaymentValidator', 'layer' => 'app/workflows'];

  // Validar si el audio transcrito contiene información de pago
  static function validate($audioTranscription) {
    if (empty($audioTranscription)) {
      return [
        'is_valid' => false,
        'errors' => ['Audio transcription is empty'],
        'data' => null
      ];
    }

    $errors = [];
    $hasPaymentKeywords = self::containsPaymentKeywords($audioTranscription);
    $hasAmount = self::extractAmount($audioTranscription);

    if (!$hasPaymentKeywords) {
      $errors[] = 'No contiene palabras clave de pago';
    }

    if (!$hasAmount) {
      $errors[] = 'No se detectó un monto mencionado';
    }

    $isValid = empty($errors);

    return [
      'is_valid' => $isValid,
      'errors' => $errors,
      'data' => $isValid ? self::extractPaymentData($audioTranscription) : null,
      'transcription' => $audioTranscription
    ];
  }

  // Detectar palabras clave relacionadas con pagos
  static function containsPaymentKeywords($text) {
    $keywords = [
      'pagué', 'pague', 'pagado', 'pago', 'transferí', 'transferi', 'transferencia',
      'depósito', 'deposito', 'deposité', 'deposite', 'envié', 'envie',
      'realicé', 'realice', 'hice el pago', 'comprobante', 'recibo',
      'yape', 'plin', 'tunki', 'billetera', 'banco', 'cuenta'
    ];

    $textLower = mb_strtolower($text, 'UTF-8');

    foreach ($keywords as $keyword) {
      if (mb_strpos($textLower, $keyword) !== false) {
        ogLog::debug("containsPaymentKeywords - Keyword encontrado", [ 'keyword' => $keyword ], self::$logMeta);
        return true;
      }
    }

    return false;
  }

  // Extraer monto del texto
  static function extractAmount($text) {
    // Patrones para detectar montos: $50, 50 dólares, 50.00, etc.
    $patterns = [
      '/\$\s*(\d+(?:[.,]\d{1,2})?)/i',
      '/(\d+(?:[.,]\d{1,2})?)\s*(?:dólares|dolares|soles|pesos|euros)/i',
      '/(?:por|de|fue)\s+(\d+(?:[.,]\d{1,2})?)/i'
    ];

    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $text, $matches)) {
        $amount = str_replace(',', '.', $matches[1]);
        ogLog::debug("extractAmount - Monto detectado", [ 'amount' => $amount, 'pattern' => $pattern ], self::$logMeta);
        return $amount;
      }
    }

    return null;
  }

  // Extraer datos de pago del audio
  static function extractPaymentData($text) {
    $amount = self::extractAmount($text);

    return [
      'amount' => $amount ? (float)$amount : 0,
      'transcription' => $text,
      'has_payment_keywords' => self::containsPaymentKeywords($text)
    ];
  }
}