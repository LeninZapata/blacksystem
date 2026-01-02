<?php
// Helpers de sistema - Métodos estáticos para detectar entorno

class ogSystem {
  // Detecta si la aplicación está corriendo en localhost
  public static function isLocalhost() {
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
    return in_array($host, ['localhost', '127.0.0.1', '::1'])
      || strpos($host, 'localhost:') === 0
      || strpos($host, '127.0.0.1:') === 0;
  }

  // Detecta si la aplicación está en modo desarrollo
  public static function isDev() {
    return self::isLocalhost() || (defined('OG_IS_DEV') && OG_IS_DEV);
  }

  // Detecta si la aplicación está en modo producción
  public static function isProduction() {
    return !self::isDev();
  }
}
