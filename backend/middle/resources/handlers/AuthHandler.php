<?php
/**
 * AuthHandler - Usando ogCache con configuracion personalizada para sesiones
 *
 * VENTAJAS:
 * - Reutiliza ogCache en lugar de codigo duplicado
 * - Sistema unificado de cache para todo
 * - Facil de mantener y escalar
 * - Limpieza automatica con cleanup()
 */
class AuthHandler {
  protected static $table;
  private static $logMeta = ['module' => 'auth', 'layer' => 'framework'];

  // Obtener tabla desde memoria cache (lazy loading)
  private static function getTable() {
    if (!self::$table) {
      $tables = ogCache::memoryGet('db_tables', []);
      self::$table = $tables['users'] ?? 'users';
    }
    return self::$table;
  }

  /**
   * Configurar ogCache para sesiones
   * Se llama automaticamente antes de operaciones de sesion
   */
  private static function setupSessionCache() {
    ogApp()->helper('cache')::setConfig([
      'dir' => ogApp()->getPath('storage') . '/sessions',
      'ext' => 'json',
      'format' => '{expires}_{var1}_{key:16}' // key:16 = primeros 16 chars del tokenShort
    ], 'session');
  }

  /**
   * Restaurar configuracion por defecto de cache
   */
  private static function restoreDefaultCache() {
    ogApp()->helper('cache')::setConfigDefault();
  }

  static function login($params) {
    $data = ogRequest::data();

    if (!isset($data['user']) || !isset($data['pass'])) {
      return ['success' => false, 'error' => __('auth.credentials.required')];
    }

    $user = ogDb::table(self::getTable())
      ->where('user', $data['user'])
      ->orWhere('email', $data['user'])
      ->first();

    if (!$user || !password_verify($data['pass'], $user['pass'])) {
      ogLog::warning('Login fallido', ['user' => $data['user']], self::$logMeta);
      return ['success' => false, 'error' => __('auth.credentials.invalid')];
    }

    $utils = ogApp()->helper('utils');
    $token = $utils->token(64);
    $expiresAt = time() + OG_SESSION_TTL;
    $expiresAtFormatted = date('Y-m-d H:i:s', $expiresAt);

    if (isset($user['config']) && is_string($user['config'])) {
      $user['config'] = json_decode($user['config'], true);
    }

    unset($user['pass']);

    self::saveSession($user, $token, $expiresAt);

    ogDb::table(self::getTable())->where('id', $user['id'])->update([
      'du' => date('Y-m-d H:i:s'),
      'tu' => time()
    ]);

    ogLog::info('Login exitoso', ['user' => $user['user'], 'id' => $user['id']], self::$logMeta);

    return [
      'success' => true,
      'message' => __('auth.login.success'),
      'data' => [
        'user' => $user,
        'token' => $token,
        'expires_at' => $expiresAtFormatted,
        'ttl' => OG_SESSION_TTL,
        'ttl_ms' => OG_SESSION_TTL_MS
      ]
    ];
  }

  static function logout($params) {
    $token = ogRequest::bearerToken();

    if (!$token) {
      return ['success' => false, 'error' => __('auth.token.missing')];
    }

    $deleted = self::deleteSessionByToken($token);

    if ($deleted) {
      ogLog::info('Logout exitoso', ['token' => substr($token, 0, 10) . '...'], self::$logMeta);
    }

    return ['success' => true, 'message' => __('auth.logout.success')];
  }

  /**
   * Obtener perfil del usuario autenticado
   */
  static function profile($params) {
    $token = ogRequest::bearerToken();

    if (!$token) {
      ogLog::warn('profile - token no recibido', [], self::$logMeta);
      return ['success' => false, 'error' => __('auth.token.missing')];
    }

    $session = self::getSessionByToken($token);

    if (!$session) {
      ogLog::error('profile - sesion no encontrada', [
        'token_short' => substr($token, 0, 16)
      ], self::$logMeta);
      return ['success' => false, 'error' => __('auth.token.invalid')];
    }

    if ($session['expires_timestamp'] < time()) {
      ogLog::warn('profile - sesion expirada', [
        'user_id' => $session['user_id'],
        'expired_since' => time() - $session['expires_timestamp']
      ], self::$logMeta);

      self::deleteSessionByToken($token);
      return ['success' => false, 'error' => __('auth.token.expired')];
    }

    return ['success' => true, 'data' => $session['user']];
  }

  /**
   * Guardar sesion usando ogCache
   */
  private static function saveSession($user, $token, $expiresAt) {
    self::setupSessionCache();

    $sessionData = [
      'user_id' => $user['id'],
      'user' => $user,
      'token' => $token,
      'expires_at' => date('Y-m-d H:i:s', $expiresAt),
      'expires_timestamp' => $expiresAt,
      'ip_address' => ogRequest::ip(),
      'user_agent' => ogRequest::userAgent(),
      'created_at' => date('Y-m-d H:i:s')
    ];

    // Key = solo los primeros 16 chars del token (sin prefijo)
    // Formato archivo: {expires}_{user_id}_{tokenShort}.json
    // Ejemplo: 1767200468_3_3f1101d2254c65f5.json
    $ttl = $expiresAt - time();
    $tokenShort = substr($token, 0, 16);
    
    ogApp()->helper('cache')::set($tokenShort, $sessionData, $ttl, [
      'var1' => $user['id']
    ]);

    self::restoreDefaultCache();
  }

  /**
   * Obtener sesion por token usando ogCache
   */
  static function getSessionByToken($token) {
    self::setupSessionCache();

    $tokenShort = substr($token, 0, 16);
    $session = ogApp()->helper('cache')::get($tokenShort);

    self::restoreDefaultCache();

    if ($session && isset($session['token']) && $session['token'] === $token) {
      return $session;
    }

    return null;
  }

  /**
   * Eliminar sesion por token usando ogCache
   */
  private static function deleteSessionByToken($token) {
    self::setupSessionCache();

    $tokenShort = substr($token, 0, 16);
    $session = ogApp()->helper('cache')::get($tokenShort);

    if ($session && isset($session['user_id'])) {
      ogApp()->helper('cache')::forget($tokenShort, [
        'var1' => $session['user_id']
      ]);

      self::restoreDefaultCache();
      return true;
    }

    self::restoreDefaultCache();
    return false;
  }

  /**
   * Invalidar todas las sesiones de un usuario
   */
  static function invalidateUserSessions($userId) {
    self::setupSessionCache();

    $config = ogApp()->helper('cache')::getConfig();
    $dir = $config['dir'];

    if (!is_dir($dir)) {
      self::restoreDefaultCache();
      return 0;
    }

    // Buscar archivos del usuario: *_{userId}_*.json
    $pattern = $dir . "/*_{$userId}_*.json";
    $files = glob($pattern);

    $cleaned = 0;
    foreach ($files as $file) {
      try {
        unlink($file);
        $cleaned++;
      } catch (Exception $e) {
        ogLog::error('Error eliminando sesion', ['file' => basename($file)], self::$logMeta);
      }
    }

    self::restoreDefaultCache();

    if ($cleaned > 0) {
      ogLog::info("Sesiones invalidadas", ['user_id' => $userId, 'count' => $cleaned], self::$logMeta);
    }

    return $cleaned;
  }
}

/*
 * FORMATO DE ARCHIVOS GENERADOS:
 * 1767200468_3_3f1101d2254c65f5.json
 * └─────────┘└┘ └──────────────┘
 * timestamp   ID  tokenShort (16 chars)
 *
 * VENTAJAS:
 * - Busqueda rapida por tokenShort
 * - User ID visible en nombre de archivo
 * - Timestamp para limpieza automatica
 *
 * LIMPIEZA:
 * ogApp()->helper('cache')::cleanup('session');
 */