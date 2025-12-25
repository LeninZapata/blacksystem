<?php
class authMiddleware {

  // Validar versión PHP (solo 1 vez por sesión)
  // Solo validar versión PHP en desarrollo
  if (IS_DEV && !$this->validatePhpVersion()) {
    return false;
  }

  function handle() {
    $token = $this->getToken();

    if (!$token) {
      log::error('Token de autenticación:' . __('middleware.auth.token_missing'), [], ['module' => 'auth', 'layer' => 'middleware']);
      response::unauthorized(__('middleware.auth.token_missing'));
      return false;
    }

    $session = $this->getSessionFromToken($token);

    if (!$session) {
      log::error('Token de autenticación:' . __('middleware.auth.token_invalid'), ['token_short' => IS_DEV ? $token : substr($token, 0, 16)], ['module' => 'auth', 'layer' => 'middleware']);
      response::unauthorized(__('middleware.auth.token_invalid'));
      return false;
    }

    // Verificar expiración
    if ($session['expires_timestamp'] < time()) {
      $this->deleteSession($token);
      $this->cleanupExpiredSessions(10);
      log:error('Token de autenticación:' . __('middleware.auth.token_expired'), ['token_short' => IS_DEV ? $token : substr($token, 0, 16)], ['module' => 'auth', 'layer' => 'middleware']);
      response::unauthorized(__('middleware.auth.token_expired'));
      return false;
    }

    // Limpieza oportunista
    $cleaned = $this->cleanupExpiredSessions(10);
    
    if ($cleaned > 0) {
      log::info('Sesión verificada - Sesiones caducadas eliminadas', [
        'sessiones_eliminadas' => $cleaned
      ], ['module' => 'auth', 'layer' => 'middleware']);
    }

    $GLOBALS['auth_user_id'] = $session['user_id'];
    $GLOBALS['auth_user'] = $session['user'];

    return true;
  }

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
      log::warn('No se encontraron archivos de sesión para el token dado', ['token_short' => IS_DEV ? $token : substr($token, 0, 16)], ['module' => 'auth', 'layer' => 'middleware']);
      return null;
    }

    foreach ($files as $file) {
      $session = json_decode(file_get_contents($file), true);

      if ($session && isset($session['token']) && $session['token'] === $token) {
        return $session;
      }
    }

    return null;
  }

  private function deleteSession($token) {
    $sessionsDir = STORAGE_PATH . '/sessions/';
    $tokenShort = substr($token, 0, 16);
    $pattern = $sessionsDir . "*_*_{$tokenShort}.json";
    $files = glob($pattern);

    foreach ($files as $file) {
      $session = json_decode(file_get_contents($file), true);
      
      if ($session && isset($session['token']) && $session['token'] === $token) {
        unlink($file);
        return;
      }
    }
  }

  private function cleanupExpiredSessions($maxFiles = 10) {
    $sessionsDir = STORAGE_PATH . '/sessions/';
    
    if (!is_dir($sessionsDir)) {
      return 0;
    }

    $now = time();
    $cleaned = 0;
    $files = scandir($sessionsDir);
    
    foreach ($files as $file) {
      // Saltar . y ..
      if ($file === '.' || $file === '..') {
        continue;
      }

      // Si ya limpiamos suficientes, salir
      if ($cleaned >= $maxFiles) {
        break;
      }

      $filePath = $sessionsDir . $file;
      
      // Solo archivos .json
      if (!is_file($filePath) || !str_ends_with($file, '.json')) {
        continue;
      }

      // Extraer timestamp del nombre
      $parts = explode('_', $file);
      
      if (count($parts) >= 3) {
        $expiresTimestamp = (int)$parts[0];
        
        // Si expiró, eliminar
        if ($expiresTimestamp < $now) {
          try {
            unlink($filePath);
            $cleaned++;
          } catch (Exception $e) {
            // Continuar si hay error
          }
        }
      }
    }

    return $cleaned;
  }
}