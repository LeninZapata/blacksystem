<?php
// authMiddleware - Verificar autenticación usando sesiones optimizadas
class authMiddleware {
  function handle() {
    $token = $this->getToken();

    if (!$token) {
      response::unauthorized(__('middleware.auth.token_missing'));
      return false;
    }

    // Validar usando archivo de sesión
    $session = $this->getSessionFromToken($token);

    if (!$session) {
      response::unauthorized(__('middleware.auth.token_invalid'));
      return false;
    }

    // Verificar expiración (usando timestamp del archivo)
    if ($session['expires_timestamp'] < time()) {
      $this->deleteSession($token);
      response::unauthorized(__('middleware.auth.token_expired'));
      return false;
    }

    // Guardar user_id y user en globals
    $GLOBALS['auth_user_id'] = $session['user_id'];
    $GLOBALS['auth_user'] = $session['user'];

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

  /**
   * Obtener sesión desde archivo optimizado
   * Busca por patrón usando los primeros 16 chars del token
   */
  private function getSessionFromToken($token) {
    $sessionsDir = STORAGE_PATH . '/sessions/';

    if (!is_dir($sessionsDir)) {
      return null;
    }

    // Buscar archivo con patrón: *_*_{token_short}.json
    $tokenShort = substr($token, 0, 16);
    $pattern = $sessionsDir . "*_*_{$tokenShort}.json";
    $files = glob($pattern);

    if (empty($files)) {
      log::warning(__('middleware.auth.token_not_found') . ": {$tokenShort}...",  null, ['module' => 'auth']);
      return null;
    }

    // Leer el primer archivo encontrado
    $sessionFile = $files[0];
    $session = json_decode(file_get_contents($sessionFile), true);

    if (!$session || !isset($session['user_id'])) {
      log::error(__('middleware.auth.session_corrupted'), null, ['module' => 'auth']);
      return null;
    }

    // Verificar que el token completo coincida
    if ($session['token'] !== $token) {
      log::warning(__('middleware.auth.token_mismatch'), null, ['module' => 'auth']);
      return null;
    }

    return $session;
  }

  // Eliminar sesión expirada
  private function deleteSession($token) {
    $sessionsDir = STORAGE_PATH . '/sessions/';
    $tokenShort = substr($token, 0, 16);
    $pattern = $sessionsDir . "*_*_{$tokenShort}.json";
    $files = glob($pattern);

    foreach ($files as $file) {
      $session = json_decode(file_get_contents($file), true);
      if ($session && $session['token'] === $token) {
        unlink($file);
        log::info(__('middleware.auth.token_expired_deleted'), [], ['module' => 'auth']);
        return;
      }
    }
  }
}