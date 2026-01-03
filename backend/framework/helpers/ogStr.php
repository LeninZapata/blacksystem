<?php
// Helpers de manipulación de strings
class ogStr {

  /**
   * Normalizar texto para comparación case-insensitive sin tildes
   */
  static function normalize($text) {
    if (empty($text)) return '';

    // Minúsculas
    $text = mb_strtolower($text, 'UTF-8');

    // Remover tildes
    $unwanted = [
      'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
      'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
      'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u',
      'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u',
      'ã' => 'a', 'õ' => 'o', 'ç' => 'c', 'ñ' => 'n'
    ];

    return strtr($text, $unwanted);
  }

  // Verificar si todas las palabras de needle están en haystack
  static function containsAllWords($needle, $haystack) {
    if (empty($needle) || empty($haystack)) return false;

    $needleNorm = self::normalize($needle);
    $haystackNorm = self::normalize($haystack);

    // Dividir needle en palabras
    $words = preg_split('/\s+/', $needleNorm, -1, PREG_SPLIT_NO_EMPTY);

    // Verificar que TODAS las palabras estén presentes
    foreach ($words as $word) {
      if (strpos($haystackNorm, $word) === false) {
        return false;
      }
    }

    return true;
  }

  // Validar si un string es JSON válido
  static function isJson($string) {
    if (empty($string) || !is_string($string)) return false;

    json_decode($string);
    return json_last_error() === JSON_ERROR_NONE;
  }

  // Convertir kebab-case o snake_case a camelCase
  static function toCamelCase($string) {
    if (empty($string)) return '';

    // Convertir kebab-case (ad-metrics) o snake_case (ad_metrics) a camelCase
    $string = str_replace(['-', '_'], ' ', $string);
    $string = ucwords($string);
    $string = str_replace(' ', '', $string);
    $string = lcfirst($string); // Primera letra en minúscula

    return $string;
  }
}