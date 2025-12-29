<?php
class UserHandler {
  protected static $table = DB_TABLES['users'];
  private static $logMeta = ['module' => 'user', 'layer' => 'framework'];

  static function profile($params) {
    $token = ogRequest::bearerToken();

    if (!$token) {
      ogLog::warn('profile - token no recibido', [], self::$logMeta);
      return ['success' => false, 'error' => __('auth.token.missing')];
    }

    $session = self::getSessionFromToken($token);

    if (!$session) {
      ogLog::error('profile - sesión no encontrada en archivo', [ 'token_short' => substr($token, 0, 16) ], self::$logMeta);
      return ['success' => false, 'error' => __('auth.token.invalid')];
    }

    if ($session['expires_timestamp'] < time()) {
      ogLog::warn('profile - sesión expirada', [
        'user_id' => $session['user_id'],
        'expired_since' => time() - $session['expires_timestamp']
      ], self::$logMeta);

      self::deleteSession($token);
      return ['success' => false, 'error' => __('auth.token.expired')];
    }

    return ['success' => true, 'data' => $session['user']];
  }

  static function updateConfig($params) {
    $id = $params['id'];
    $data = ogRequest::data();

    if (!isset($data['config'])) {
      return ['success' => false, 'error' => __('user.config.required')];
    }

    $config = is_string($data['config']) ? $data['config'] : json_encode($data['config']);

    if (json_decode($config) === null) {
      return ['success' => false, 'error' => __('user.config.invalid')];
    }

    $affected = ogDb::table(self::$table)->where('id', $id)->update([
      'config' => $config,
      'du' => date('Y-m-d H:i:s'),
      'tu' => time()
    ]);

    $token = ogRequest::bearerToken();
    if ($token) {
      self::updateSessionUserData($token, ['config' => json_decode($config, true)]);
    }

    return [
      'success' => true,
      'message' => __('user.config.updated'),
      'affected' => $affected
    ];
  }

  private static function getSessionFromToken($token) {
    $sessionsDir = STORAGE_PATH . '/sessions/';

    if (!is_dir($sessionsDir)) {
      ogLog::error('getSessionFromToken - directorio no existe', ['dir' => $sessionsDir], self::$logMeta);
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
      ogLog::error('getSessionFromToken - sesión inválida en archivo', [
        'file' => basename($files[0])
      ], self::$logMeta);
      return null;
    }

    return $session;
  }

  private static function updateSessionUserData($token, $updates) {
    $session = self::getSessionFromToken($token);

    if (!$session) return false;

    foreach ($updates as $key => $value) {
      $session['user'][$key] = $value;
    }

    $tokenShort = substr($token, 0, 16);
    $pattern = STORAGE_PATH . "/sessions/*_*_{$tokenShort}.json";
    $files = glob($pattern);

    if (!empty($files)) {
      file_put_contents($files[0], json_encode($session, JSON_UNESCAPED_UNICODE));
    }

    return true;
  }

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