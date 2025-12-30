<?php
class UserHandler {
  protected static $table = DB_TABLES['users'];
  private static $logMeta = ['module' => 'user', 'layer' => 'framework'];

  /**
   * Actualizar configuracion del usuario
   */
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

    // Actualizar sesion si el usuario esta autenticado
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

  /**
   * Actualizar datos del usuario en la sesion activa
   */
  private static function updateSessionUserData($token, $updates) {
    $cache = ogApp()->helper('cache');
    $cache::setConfig([
      'dir' => STORAGE_PATH . '/sessions',
      'ext' => 'json',
      'format' => '{expires}_{var1}_{key:16}'
    ], 'session');

    $tokenShort = substr($token, 0, 16);
    $session = $cache::get($tokenShort);

    if (!$session) {
      $cache::setConfigDefault();
      return false;
    }

    // Actualizar datos del usuario en la sesion
    foreach ($updates as $key => $value) {
      $session['user'][$key] = $value;
    }

    // Guardar sesion actualizada
    $ttl = $session['expires_timestamp'] - time();
    $cache::set($tokenShort, $session, $ttl, [
      'var1' => $session['user_id']
    ]);

    $cache::setConfigDefault();
    return true;
  }
}