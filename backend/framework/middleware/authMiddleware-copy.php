<?php
class authMiddleware {

  function handle() {
    // Validar versión PHP (solo 1 vez por sesión)
    // Solo validar versión PHP en desarrollo
    /*if (IS_DEV && !$this->validatePhpVersion()) {
      return false;
    }*/

    $token = $this->getToken();

    if (!$token) {
      response::unauthorized(__('middleware.auth.token_missing'));
      return false;
    }

    // Leer archivo de sesión directamente (sin cache intermedio)
    $session = $this->getSessionFromToken($token);

    if (!$session) {
      response::unauthorized(__('middleware.auth.token_invalid'));
      return false;
    }

    // Verificar expiración
    if ($session['expires_timestamp'] < time()) {
      $this->deleteSession($token);
      response::unauthorized(__('middleware.auth.token_expired'));
      return false;
    }

    $GLOBALS['auth_user_id'] = $session['user_id'];
    $GLOBALS['auth_user'] = $session['user'];

    return true;
  }

  // Validar versión PHP usando cache
  private function validatePhpVersion() {
    $required = defined('PHP_MIN_VERSION') ? PHP_MIN_VERSION : '8.1.0';
    
    // Cache GLOBAL (en $_SESSION porque no depende del usuario)
    $isValid = cache::remember('global_php_version_valid', function() use ($required) {
      return version_compare(PHP_VERSION, $required, '>=');
    });

    if (!$isValid) {
      response::error(
        __('middleware.auth.php_version_required', [
          'required' => $required,
          'current' => PHP_VERSION
        ]),
        500
      );
      return false;
    }

    return true;
  }

  // Obtener token del header Authorization
  private function getToken() {
    if (function_exists('getallheaders')) {
      $headers = getallheaders();
      if (isset($headers['Authorization'])) {
        if (preg_match('/Bearer\s+(.*)$/i', $headers['Authorization'], $matches)) {
          return $matches[1];
        }
      }
    }

    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
      if (preg_match('/Bearer\s+(.*)$/i', $_SERVER['HTTP_AUTHORIZATION'], $matches)) {
        return $matches[1];
      }
    }

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

  private function getSessionFromToken($token) {
    $sessionsDir = STORAGE_PATH . '/sessions/';

    if (!is_dir($sessionsDir)) {
      return null;
    }

    $tokenShort = substr($token, 0, 16);
    $pattern = $sessionsDir . "*_*_{$tokenShort}.json";
    $files = glob($pattern);

    if (empty($files)) {
      return null;
    }

    $session = json_decode(file_get_contents($files[0]), true);

    if (!$session || !isset($session['user_id']) || $session['token'] !== $token) {
      return null;
    }

    return $session;
  }

  private function deleteSession($token) {
    $sessionsDir = STORAGE_PATH . '/sessions/';
    $tokenShort = substr($token, 0, 16);
    $pattern = $sessionsDir . "*_*_{$tokenShort}.json";
    $files = glob($pattern);

    foreach ($files as $file) {
      $session = json_decode(file_get_contents($file), true);
      if ($session && $session['token'] === $token) {
        unlink($file);
        return;
      }
    }
  }
}