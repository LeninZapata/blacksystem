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

  /**
   * Decodifica patrones dinámicos en mensajes para evitar spam
   * Transforma:
   * - {pN} → N puntos (ej: {p3} → ...)
   * - {p} → 1-5 puntos aleatorios
   * - {e} → emoji aleatorio genérico
   * - {e-happy} → emoji feliz aleatorio
   * - {e-sad} → emoji triste aleatorio
   * - {e-arrow} → emoji de flecha aleatorio
   * - {e-think} → emoji pensativo aleatorio
   * - {e-like} → emoji de aprobación aleatorio
   *
   * @param string $message Mensaje con patrones a decodificar
   * @return string Mensaje con patrones reemplazados
   */
  public static function decodeMessagePatterns($message) {
    if (empty($message) || !is_string($message)) {
      return $message;
    }

    // Catálogos de emojis por categoría
    $emojiCatalogs = [
      'generic' => ['😊', '👍', '✨', '🙂', '😄', '👌', '💪', '🎉', '✅', '💯'],
      'happy' => ['😊', '😄', '😃', '🙂', '😁', '🥰', '😍', '🤗', '😆', '🥳'],
      'sad' => ['😢', '😞', '😔', '🥺', '😿', '💔', '😪', '😥'],
      'arrow' => ['→', '⬇️', '⬆️', '➡️', '⬅️', '↗️', '↘️', '⤵️', '⤴️', '🔽'],
      'think' => ['🤔', '💭', '🧐', '🤨', '💡', '🎯', '👀', '🔍'],
      'like' => ['👍', '👏', '🙌', '💪', '✨', '⭐', '🌟', '💯', '🔥', '✅']
    ];

    // Reemplazar {pN} - N puntos específicos (ej: {p3} → ...)
    $message = preg_replace_callback('/\{p(\d+)\}/', function($matches) { $count = min((int)$matches[1], 10); // Máximo 10 puntos
      return str_repeat('.', $count);
    }, $message);

    // Reemplazar {p} - 1-5 puntos aleatorios
    $message = preg_replace_callback('/\{p\}/', function($matches) { $count = rand(1, 5);
      return str_repeat('.', $count);
    }, $message);

    // Reemplazar {e-categoria} - emoji de categoría específica
    $message = preg_replace_callback('/\{e-([a-z]+)\}/', function($matches) use ($emojiCatalogs) {
      $category = $matches[1];

      if (isset($emojiCatalogs[$category])) {
        $emojis = $emojiCatalogs[$category];
        return $emojis[array_rand($emojis)];
      }

      // Si no existe la categoría, usar genérico
      return $emojiCatalogs['generic'][array_rand($emojiCatalogs['generic'])];
    }, $message);

    // Reemplazar {e} - emoji genérico aleatorio
    $message = preg_replace_callback('/\{e\}/', function($matches) use ($emojiCatalogs) {
      return $emojiCatalogs['generic'][array_rand($emojiCatalogs['generic'])];
    }, $message);

    return $message;
  }
}