<?php
// Validation - Validación de datos
class ogValidation {
  // Email válido
  static function email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
  }

  // Teléfono válido
  static function phone($phone) {
    return preg_match('/^[\+\-\d\s\(\)]+$/', $phone);
  }

  // URL válida
  static function url($url) {
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
  }

  // Es numérico
  static function numeric($val) {
    return is_numeric($val);
  }

  // En rango
  static function range($val, $min, $max) {
    return $val >= $min && $val <= $max;
  }

  // Sanitizar texto
  static function sanitizeText($text) {
    return htmlspecialchars(strip_tags(trim($text)), ENT_QUOTES, 'UTF-8');
  }

  // Sanitizar email
  static function sanitizeEmail($email) {
    return filter_var(trim($email), FILTER_SANITIZE_EMAIL);
  }

  // Validar campos requeridos
  static function required($data, $required) {
    $errors = [];
    foreach ($required as $field) {
      if (!isset($data[$field]) || trim($data[$field]) === '') {
        $errors[] = "El campo '$field' es requerido";
      }
    }
    return ['valid' => empty($errors), 'errors' => $errors];
  }
}