<?php
// authHandlers - Handlers de autenticación
class authHandlers {

  // Login
  static function login($params) {
    $data = request::data();

    if (!isset($data['user']) || !isset($data['pass'])) {
      return ['success' => false, 'error' => __('auth.credentials.required')];
    }

    // Buscar usuario por username o email
    $user = db::table('user')
      ->where('user', $data['user'])
      ->orWhere('email', $data['user'])
      ->first();

    if (!$user || !password_verify($data['pass'], $user['pass'])) {
      log::warning('Login fallido', ['user' => $data['user']], ['module' => 'auth']);
      return ['success' => false, 'error' => __('auth.credentials.invalid')];
    }

    // Generar token
    $token = utils::token(64);

    // Calcular expiración
    $expiresAt = time() + SESSION_TTL;
    $expiresAtFormatted = date('Y-m-d H:i:s', $expiresAt);

    // Parsear config si es JSON string
    if (isset($user['config']) && is_string($user['config'])) {
      $user['config'] = json_decode($user['config'], true);
    }

    // Ocultar contraseña
    unset($user['pass']);

    // Guardar sesión con nombre optimizado
    self::saveSession($user, $token, $expiresAt);

    // Actualizar último acceso
    db::table('user')->where('id', $user['id'])->update([
      'du' => date('Y-m-d H:i:s'),
      'tu' => time()
    ]);

    log::info('Login exitoso', ['user' => $user['user'], 'id' => $user['id']], ['module' => 'auth']);

    return [
      'success' => true,
      'message' => __('auth.login.success'),
      'data' => [
        'user' => $user,
        'token' => $token,
        'expires_at' => $expiresAtFormatted,
        'ttl' => SESSION_TTL,
        'ttl_ms' => SESSION_TTL_MS
      ]
    ];
  }

  // Logout
  static function logout($params) {
    $token = request::bearerToken();

    if (!$token) {
      return ['success' => false, 'error' => __('auth.token.missing')];
    }

    // Buscar y eliminar archivo de sesión
    $deleted = self::deleteSessionByToken($token);

    if ($deleted) {
      log::info('Logout exitoso', ['token' => substr($token, 0, 10) . '...'], ['module' => 'auth']);
    }

    return ['success' => true, 'message' => __('auth.logout.success')];
  }

  // ============ MÉTODOS PRIVADOS HELPERS ============

  /**
   * Guardar sesión con nombre optimizado
   * Formato: {expires_timestamp}_{user_id}_{token_short}.json
   * 
   * Ventajas:
   * - Limpieza rápida sin leer archivos
   * - Búsqueda por user_id con glob()
   * - Ordenamiento natural por expiración
   */
  private static function saveSession($user, $token, $expiresAt) {
    $sessionsDir = STORAGE_PATH . '/sessions/';

    if (!is_dir($sessionsDir)) {
      mkdir($sessionsDir, 0755, true);
    }

    // Nombre optimizado: {timestamp}_{user_id}_{token_short}.json
    $tokenShort = substr($token, 0, 16);
    $filename = "{$expiresAt}_{$user['id']}_{$tokenShort}.json";
    $sessionFile = $sessionsDir . $filename;

    file_put_contents($sessionFile, json_encode([
      'user_id' => $user['id'],
      'user' => $user,
      'token' => $token,
      'expires_at' => date('Y-m-d H:i:s', $expiresAt),
      'expires_timestamp' => $expiresAt,
      'ip_address' => request::ip(),
      'user_agent' => request::userAgent(),
      'created_at' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE));
  }

  /**
   * Eliminar sesión por token
   * Busca el archivo que contiene el token
   */
  private static function deleteSessionByToken($token) {
    $sessionsDir = STORAGE_PATH . '/sessions/';

    if (!is_dir($sessionsDir)) {
      return false;
    }

    // Buscar archivo que contiene el token (primeros 16 chars)
    $tokenShort = substr($token, 0, 16);
    $pattern = $sessionsDir . "*_*_{$tokenShort}.json";
    $files = glob($pattern);

    foreach ($files as $file) {
      // Verificar que el token completo coincida
      $session = json_decode(file_get_contents($file), true);
      if ($session && $session['token'] === $token) {
        unlink($file);
        return true;
      }
    }

    return false;
  }
}