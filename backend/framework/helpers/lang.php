<?php
// lang - Helper de idiomas minimalista
class lang {
  private static $translations = [];
  private static $locale = 'es';
  private static $fallback = 'es';

  // Cargar idioma
  static function load($locale = 'es') {
    self::$locale = $locale;

    $file = APP_PATH . "/lang/{$locale}.php";

    if (file_exists($file)) {
      self::$translations = require $file;
    } else {
      // Fallback al idioma por defecto
      $file = APP_PATH . "/lang/" . self::$fallback . ".php";
      if (file_exists($file)) {
        self::$translations = require $file;
      }
    }
  }

  // Obtener traducción usando dot notation
  static function get($key, $replace = []) {
    $value = self::$translations;

    foreach (explode('.', $key) as $segment) {
      if (!isset($value[$segment])) {
        return $key; // Si no existe, retornar la key
      }
      $value = $value[$segment];
    }

    // Reemplazar variables {var}
    foreach ($replace as $k => $v) {
      $value = str_replace("{{$k}}", $v, $value);
    }

    return $value;
  }

  // Cambiar idioma en runtime
  static function setLocale($locale) {
    self::load($locale);
  }

  // Obtener idioma actual
  static function getLocale() {
    return self::$locale;
  }
}

// Helper global __()
function __($key, $replace = []) {
  return lang::get($key, $replace);
}