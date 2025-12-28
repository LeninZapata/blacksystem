<?php
class AuthHandler {
  protected static $table = DB_TABLES['users'];
  private static $logMeta = ['module' => 'auth', 'layer' => 'framework'];

  static function login($params) {
    $data = ogRequest::data();

    if (!isset($data['user']) || !isset($data['pass'])) {
      return ['success' => false, 'error' => __('auth.credentials.required')];
    }

    $user = ogDb::table(self::$table)
      ->where('user', $data['user'])
      ->orWhere('email', $data['user'])
      ->first();

    if (!$user || !password_verify($data['pass'], $user['pass'])) {
      ogLog::warning('Login fallido', ['user' => $data['user']], self::$logMeta);
      return ['success' => false, 'error' => __('auth.credentials.invalid')];
    }

    // Cargar ogUtils bajo demanda
    $utils = ogApp()->helper('utils');
    $token = $utils->token(64);
    $expiresAt = time() + OG_SESSION_TTL;
    $expiresAtFormatted = date('Y-m-d H:i:s', $expiresAt);

    if (isset($user['config']) && is_string($user['config'])) {
      $user['config'] = json_decode($user['config'], true);
    }

    unset($user['pass']);

    self::saveSession($user, $token, $expiresAt);

    ogDb::table(self::$table)->where('id', $user['id'])->update([
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

  private static function saveSession($user, $token, $expiresAt) {
    $sessionsDir = STORAGE_PATH . '/sessions/';

    if (!is_dir($sessionsDir)) {
      mkdir($sessionsDir, 0755, true);
    }

    $tokenShort = substr($token, 0, 16);
    $filename = "{$expiresAt}_{$user['id']}_{$tokenShort}.json";
    $sessionFile = $sessionsDir . $filename;

    file_put_contents($sessionFile, json_encode([
      'user_id' => $user['id'],
      'user' => $user,
      'token' => $token,
      'expires_at' => date('Y-m-d H:i:s', $expiresAt),
      'expires_timestamp' => $expiresAt,
      'ip_address' => ogRequest::ip(),
      'user_agent' => ogRequest::userAgent(),
      'created_at' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE));
  }

  private static function deleteSessionByToken($token) {
    $sessionsDir = STORAGE_PATH . '/sessions/';

    if (!is_dir($sessionsDir)) {
      return false;
    }

    $tokenShort = substr($token, 0, 16);
    $pattern = $sessionsDir . "*_*_{$tokenShort}.json";
    $files = glob($pattern);

    foreach ($files as $file) {
      $session = json_decode(file_get_contents($file), true);
      if ($session && $session['token'] === $token) {
        unlink($file);
        return true;
      }
    }

    return false;
  }
}