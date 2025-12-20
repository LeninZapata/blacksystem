<?php
class url {

  /**
   * Normaliza una URL eliminando barras dobles y barra final
   * 
   * @param string $url URL a normalizar
   * @return string URL normalizada
   */
  static function normalizeUrl($url) {
    if (empty($url) || !is_string($url)) {
      return $url;
    }

    // Separar el protocolo del resto de la URL
    $parts = explode('://', $url, 2);

    if (count($parts) === 2) {
      $protocol = $parts[0];
      $path = $parts[1];

      // Reemplazar barras múltiples por una sola en el path
      $path = preg_replace('#/+#', '/', $path);

      // Eliminar barra final si existe
      $path = rtrim($path, '/');

      return $protocol . '://' . $path;
    }

    // Si no hay protocolo, normalizar directamente
    $url = preg_replace('#/+#', '/', $url);
    $url = rtrim($url, '/');

    return $url;
  }

  /**
   * Añade parámetros query a una URL
   * 
   * @param string $url URL base
   * @param array $params Parámetros a añadir
   * @return string URL con parámetros
   */
  static function addQueryParams($url, $params) {
    if (empty($params) || !is_array($params)) {
      return $url;
    }

    $queryString = http_build_query($params);

    if (empty($queryString)) {
      return $url;
    }

    $separator = strpos($url, '?') !== false ? '&' : '?';

    return $url . $separator . $queryString;
  }

  /**
   * Valida si una cadena es una URL válida
   * 
   * @param string $url URL a validar
   * @return bool
   */
  static function isValid($url) {
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
  }

  /**
   * Extrae el dominio de una URL
   * 
   * @param string $url URL completa
   * @return string|null Dominio o null si no es válida
   */
  static function getDomain($url) {
    $parsed = parse_url($url);
    return $parsed['host'] ?? null;
  }

  /**
   * Combina segmentos de URL asegurando barras correctas
   * 
   * @param string ...$segments Segmentos a combinar
   * @return string URL combinada
   */
  static function join(...$segments) {
    $url = implode('/', array_map(function($segment) {
      return trim($segment, '/');
    }, $segments));

    return self::normalizeUrl($url);
  }
}
