<?php

class PaymentProofValidator {

  static function validate($imageAnalysis) {
    if (!isset($imageAnalysis['metadata']['description'])) {
      return [
        'is_valid' => false,
        'errors' => ['No image analysis data'],
        'data' => null
      ];
    }

    $analysis = $imageAnalysis['metadata']['description'];
    $errors = [];

    if (!self::isValidProof($analysis)) {
      $errors[] = 'No es un comprobante de pago válido';
    }

    if (!self::hasValidAmount($analysis)) {
      $errors[] = 'Monto inválido o no encontrado';
    }

    $isValid = empty($errors);

    return [
      'is_valid' => $isValid,
      'errors' => $errors,
      'data' => $isValid ? self::extractPaymentData($analysis) : $analysis
    ];
  }

  static function isValidProof($analysis) {
    return ($analysis['is_proof_payment'] ?? false) === true;
  }

  static function hasValidAmount($analysis) {
    if (($analysis['valid_amount'] ?? false) !== true) {
      return false;
    }

    $amount = $analysis['amount_found'] ?? '';
    return !empty($amount) && is_numeric(str_replace(',', '.', $amount));
  }

  static function hasValidName($analysis) {
    return ($analysis['valid_name'] ?? false) === true;
  }

  static function extractPaymentData($analysis) {
    $amount = $analysis['amount_found'] ?? '0';
    $amount = str_replace(',', '.', $amount);

    return [
      'amount' => (float)$amount,
      'name' => $analysis['name_found'] ?? '',
      'resume' => $analysis['resume'] ?? ''
    ];
  }

  static function buildValidationErrors($analysis) {
    $errors = [];

    if (!self::isValidProof($analysis)) {
      $errors[] = 'is_proof_payment';
    }

    if (!self::hasValidAmount($analysis)) {
      $errors[] = 'valid_amount';
    }

    if (!self::hasValidName($analysis)) {
      $errors[] = 'valid_name';
    }

    return $errors;
  }
}