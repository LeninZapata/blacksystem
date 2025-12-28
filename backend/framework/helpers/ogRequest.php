<?php
// Request - Manejo de peticiones HTTP
class ogRequest {
  // Datos del request (JSON o form)
  static function data() {
    $type = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($type, 'application/json') !== false) {
      return json_decode(file_get_contents('php://input'), true) ?? [];
    }
    return $_REQUEST;
  }

  // Método HTTP
  static function method() {
    return $_SERVER['REQUEST_METHOD'];
  }

  // Es AJAX?
  static function isAjax() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
  }

  // IP del cliente
  static function ip() {
    $keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];
    foreach ($keys as $key) {
      if (isset($_SERVER[$key])) {
        foreach (explode(',', $_SERVER[$key]) as $ip) {
          $ip = trim($ip);
          if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
      }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
  }

  // User agent
  static function userAgent() {
    return $_SERVER['HTTP_USER_AGENT'] ?? '';
  }

  // Query params
  static function query($key, $default = null) {
    return $_GET[$key] ?? $default;
  }

  // Post data
  static function post($key, $default = null) {
    return $_POST[$key] ?? $default;
  }

  // Path actual
  static function path() {
    $uri = $_SERVER['REQUEST_URI'];
    if (($pos = strpos($uri, '?')) !== false) $uri = substr($uri, 0, $pos);
    return parse_url($uri, PHP_URL_PATH);
  }

  // Obtener token Bearer del header Authorization
  static function bearerToken() {
    // Método 1: HTTP_AUTHORIZATION
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
      if (preg_match('/Bearer\s+(.*)$/i', $_SERVER['HTTP_AUTHORIZATION'], $matches)) {
        return $matches[1];
      }
    }

    // Método 2: getallheaders()
    if (function_exists('getallheaders')) {
      $headers = getallheaders();
      if (isset($headers['Authorization'])) {
        if (preg_match('/Bearer\s+(.*)$/i', $headers['Authorization'], $matches)) {
          return $matches[1];
        }
      }
    }

    // Método 3: apache_request_headers()
    if (function_exists('apache_request_headers')) {
      $headers = apache_request_headers();
      if (isset($headers['Authorization'])) {
        if (preg_match('/Bearer\s+(.*)$/i', $headers['Authorization'], $matches)) {
          return $matches[1];
        }
      }
    }

    return null;
  }
}