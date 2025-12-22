<?php
// UserHandler - Handlers personalizados para user
class UserHandler {
  // Nombre de la tabla asociada a este handler
  protected static $table = DB_TABLES['users'];

  private static $logMeta = ['module' => 'user', 'layer' => 'app'];

  /**
   * Profile optimizado - SIN consulta a BD
   * Usa sesión del archivo para obtener datos del usuario
   */
  static function profile($params) {
    $token = request::bearerToken();

    if (!$token) {
      return ['success' => false, 'error' => __('auth.token.missing')];
    }

    // Verificar cache primero
    $cacheKey = 'auth_session_' . substr($token, 0, 16);
    $cachedSession = cache::get($cacheKey);

    // Si hay cache válido, usarlo
    if ($cachedSession && $cachedSession['expires_timestamp'] >= time() && $cachedSession['token'] === $token) {
      return ['success' => true, 'data' => $cachedSession['user']];
    }

    // Buscar en archivo
    $session = self::getSessionFromToken($token);

    if (!$session) {
      return ['success' => false, 'error' => __('auth.token.invalid')];
    }

    // Verificar expiración
    if ($session['expires_timestamp'] < time()) {
      self::deleteSession($token);
      cache::forget($cacheKey);
      return ['success' => false, 'error' => __('auth.token.expired')];
    }

    // Guardar en cache
    cache::set($cacheKey, $session);

    return ['success' => true, 'data' => $session['user']];
  }

  // Actualizar config del usuario
  static function updateConfig($params) {
    $id = $params['id'];
    $data = request::data();

    if (!isset($data['config'])) {
      return ['success' => false, 'error' => __('user.config.required')];
    }

    // Validar JSON
    $config = is_string($data['config']) ? $data['config'] : json_encode($data['config']);

    if (json_decode($config) === null) {
      return ['success' => false, 'error' => __('user.config.invalid')];
    }

    $affected = db::table(self::$table)->where('id', $id)->update([
      'config' => $config,
      'du' => date('Y-m-d H:i:s'),
      'tu' => time()
    ]);

    // Actualizar también en la sesión activa
    $token = request::bearerToken();
    if ($token) {
      self::updateSessionUserData($token, ['config' => json_decode($config, true)]);
    }

    return [
      'success' => true,
      'message' => __('user.config.updated'),
      'affected' => $affected
    ];
  }

  // ============ MÉTODOS PRIVADOS HELPERS ============

  /**
   * Obtener sesión desde archivo optimizado
   * Busca usando patrón con primeros 16 chars del token
   */
  private static function getSessionFromToken($token) {
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

  /**
   * Actualizar datos del usuario en sesión
   */
  private static function updateSessionUserData($token, $updates) {
    $session = self::getSessionFromToken($token);

    if (!$session) return false;

    // Actualizar campos específicos del usuario
    foreach ($updates as $key => $value) {
      $session['user'][$key] = $value;
    }

    // Guardar de nuevo (mantener nombre de archivo)
    $tokenShort = substr($token, 0, 16);
    $pattern = STORAGE_PATH . "/sessions/*_*_{$tokenShort}.json";
    $files = glob($pattern);

    if (!empty($files)) {
      file_put_contents($files[0], json_encode($session, JSON_UNESCAPED_UNICODE));
    }

    return true;
  }

  /**
   * Eliminar sesión
   */
  private static function deleteSession($token) {
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