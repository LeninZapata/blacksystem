<?php
/**
 * CACHE - Sistema hibrido de cache con configuraciones personalizables
 *
 * REGLA DE ORO: Usar prefijo 'global_' para decidir donde cachear
 *
 * CONFIGURACIONES PERSONALIZABLES:
 * - Formato de nombre de archivo
 * - Directorio de almacenamiento
 * - Extension de archivo
 * - Variables personalizadas en el nombre
 *
 * USO:
 * 1. Cache normal (por defecto):
 *    ogCache::set('my_data', $value);
 *
 * 2. Cache con configuracion personalizada (sesiones):
 *    ogCache::setConfig([
 *      'dir' => STORAGE_PATH . '/sessions',
 *      'ext' => 'json',
 *      'format' => '{expires}_{var1}_{hash}'
 *    ]);
 *    ogCache::set('session_data', $value, null, ['var1' => $userId]);
 *    ogCache::setConfigDefault(); // Restaurar config por defecto
 */
class ogCache {
  private static $sessionStarted = false;
  private static $configs = [];
  private static $activeConfigKey = 'default';
  private static $memory = [];

  // Configuracion por defecto
  private static $defaultConfig = [
    'dir' => null, // Se inicializa en initConfig()
    'ext' => 'cache',
    'format' => '{expires}_{hash}' // Variables: {expires}, {hash}, {var1}, {var2}, etc
  ];

  // Inicializar configuracion
  private static function initConfig() {
    if (!isset(self::$configs['default'])) {
      self::$configs['default'] = array_merge(self::$defaultConfig, [
        'dir' => ogApp()->getPath('storage') . '/cache'
      ]);
    }
  }

  // Obtener configuracion activa
  public static function getConfig() {
    self::initConfig();
    return self::$configs[self::$activeConfigKey];
  }

  /**
   * SET CONFIG - Establecer configuracion personalizada
   *
   * @param array $config Configuracion personalizada
   * @param string $key Clave unica para esta configuracion (opcional)
   *
   * Ejemplo para sesiones:
   * ogCache::setConfig([
   *   'dir' => STORAGE_PATH . '/sessions',
   *   'ext' => 'json',
   *   'format' => '{expires}_{var1}_{hash}'
   * ], 'session');
   */
  static function setConfig($config, $key = 'custom') {
    self::initConfig();
    self::$configs[$key] = array_merge(self::$defaultConfig, $config);
    self::$activeConfigKey = $key;
  }

  /**
   * SET CONFIG DEFAULT - Restaurar configuracion por defecto
   */
  static function setConfigDefault() {
    self::$activeConfigKey = 'default';
  }

  /**
   * GET CONFIG KEY - Obtener la clave de configuracion activa
   */
  static function getConfigKey() {
    return self::$activeConfigKey;
  }

  // Inicializar sesion
  private static function startSession() {
    if (self::$sessionStarted) return;

    if (session_status() === PHP_SESSION_NONE) {
      ini_set('session.gc_maxlifetime', OG_SESSION_TTL);
      session_start();
    }

    self::$sessionStarted = true;
  }

  // Inicializar directorio
  private static function initDir() {
    $config = self::getConfig();
    $dir = $config['dir'];

    if (!is_dir($dir)) {
      mkdir($dir, 0755, true);
    }
  }

  /**
   * GET - Obtener valor del cache
   * @param bool $forget Si es true, elimina el cache despues de obtenerlo
   */
  static function get($key, $default = null, $forget = false) {
    if (str_starts_with($key, 'global_')) {
      self::startSession();
      $value = $_SESSION['cache'][$key] ?? $default;

      if ($forget && isset($_SESSION['cache'][$key])) {
        unset($_SESSION['cache'][$key]);
      }

      return $value;
    }

    $value = self::getFromFile($key, $default);

    if ($forget && $value !== $default) {
      self::forgetFile($key);
    }

    return $value;
  }

  /**
   * PULL - Obtener y eliminar en una sola operacion
   */
  static function pull($key, $default = null) {
    return self::get($key, $default, true);
  }

  /**
   * SET - Guardar valor en cache
   * @param int|null $ttl Tiempo de vida en segundos
   * @param array $vars Variables personalizadas para el nombre del archivo
   */
  static function set($key, $value, $ttl = null, $vars = []) {
    if (str_starts_with($key, 'global_')) {
      self::startSession();
      $_SESSION['cache'][$key] = $value;
      return;
    }

    self::setToFile($key, $value, $ttl, $vars);
  }

  /**
   * HAS - Verificar existencia
   */
  static function has($key) {
    if (str_starts_with($key, 'global_')) {
      self::startSession();
      return isset($_SESSION['cache'][$key]);
    }

    return self::get($key) !== null;
  }

  /**
   * FORGET - Eliminar del cache
   * @param array $vars Variables para encontrar el archivo (si es necesario)
   */
  static function forget($key, $vars = []) {
    if (str_starts_with($key, 'global_')) {
      self::startSession();
      unset($_SESSION['cache'][$key]);
      return;
    }

    self::forgetFile($key, $vars);
  }

  /**
   * CLEAR - Limpiar todo
   */
  static function clear() {
    self::startSession();
    $_SESSION['cache'] = [];

    $config = self::getConfig();
    self::initDir();
    $files = glob($config['dir'] . '/*.' . $config['ext']);
    foreach ($files as $file) {
      unlink($file);
    }
  }

  /**
   * REMEMBER - Obtener o ejecutar callback
   */
  static function remember($key, $callback, $ttl = null, $vars = []) {
    $value = self::get($key);

    if ($value !== null) {
      return $value;
    }

    $value = $callback();
    self::set($key, $value, $ttl, $vars);
    return $value;
  }

  /**
   * REMEMBER_ONCE - Ejecutar callback, guardar y auto-eliminar al obtenerlo
   */
  static function rememberOnce($key, $callback, $ttl = null, $vars = []) {
    $value = self::get($key);

    if ($value !== null) {
      self::forget($key, $vars);
      return $value;
    }

    $value = $callback();
    self::set($key, $value, $ttl, $vars);
    return $value;
  }

  // Metodos privados para archivos

  /**
   * Generar nombre de archivo segun formato configurado
   * Formato: {expires}_{var1}_{hash}
   * Variables especiales:
   * - {hash}: md5 completo de la key
   * - {key}: key original (sin hashear)
   * - {key:N}: primeros N caracteres de la key
   */
  private static function buildFilename($key, $expiresAt, $vars = []) {
    $config = self::getConfig();

    $filename = $config['format'];
    $filename = str_replace('{expires}', $expiresAt, $filename);

    // Reemplazar {key:N} con primeros N caracteres de la key
    if (preg_match('/\{key:(\d+)\}/', $filename, $matches)) {
      $length = (int)$matches[1];
      $keyShort = substr($key, 0, $length);
      $filename = str_replace('{key:' . $length . '}', $keyShort, $filename);
    }

    // Reemplazar {key} con key completa
    $filename = str_replace('{key}', $key, $filename);

    // Reemplazar {hash} con md5 completo
    $hash = md5($key);
    $filename = str_replace('{hash}', $hash, $filename);

    // Reemplazar variables personalizadas
    foreach ($vars as $varKey => $varValue) {
      $filename = str_replace('{' . $varKey . '}', $varValue, $filename);
    }

    return $filename . '.' . $config['ext'];
  }

  /**
   * Buscar archivo por key (puede tener diferentes timestamps/vars)
   */
  private static function findFile($key, $vars = []) {
    $config = self::getConfig();
    self::initDir();

    // Construir pattern segun formato
    $pattern = $config['format'];
    $pattern = str_replace('{expires}', '*', $pattern);

    // Manejar {key:N} - primeros N caracteres de la key
    if (preg_match('/\{key:(\d+)\}/', $pattern, $matches)) {
      $length = (int)$matches[1];
      $keyShort = substr($key, 0, $length);
      $pattern = str_replace('{key:' . $length . '}', $keyShort, $pattern);
    }

    // Manejar {key} - key completa
    $pattern = str_replace('{key}', $key, $pattern);

    // Manejar {hash} - md5 completo
    $hash = md5($key);
    $pattern = str_replace('{hash}', $hash, $pattern);

    // Reemplazar variables conocidas
    foreach ($vars as $varKey => $varValue) {
      $pattern = str_replace('{' . $varKey . '}', $varValue, $pattern);
    }

    // Reemplazar variables desconocidas con *
    $pattern = preg_replace('/\{[a-z0-9_:]+\}/i', '*', $pattern);

    $fullPattern = $config['dir'] . '/' . $pattern . '.' . $config['ext'];
    $files = glob($fullPattern);

    return !empty($files) ? $files[0] : null;
  }

  private static function getFromFile($key, $default = null) {
    $file = self::findFile($key);

    if (!$file || !file_exists($file)) {
      return $default;
    }

    // Verificar expiracion por nombre de archivo
    $config = self::getConfig();
    $filename = basename($file, '.' . $config['ext']);
    $parts = explode('_', $filename);

    // Asumir que el primer elemento es el timestamp de expiracion
    if (!empty($parts) && is_numeric($parts[0])) {
      $expiresAt = (int)$parts[0];

      if ($expiresAt < time()) {
        unlink($file);
        return $default;
      }
    }

    $content = file_get_contents($file);
    $data = json_decode($content, true);

    // Verificar expiracion del contenido (backup)
    if (isset($data['expires_at']) && $data['expires_at'] < time()) {
      unlink($file);
      return $default;
    }

    return $data['value'] ?? $default;
  }

  private static function setToFile($key, $value, $ttl = null, $vars = []) {
    $config = self::getConfig();
    self::initDir();

    $ttl = $ttl ?? OG_SESSION_TTL;
    $expiresAt = time() + $ttl;

    // Eliminar archivo antiguo si existe
    $oldFile = self::findFile($key, $vars);
    if ($oldFile && file_exists($oldFile)) {
      unlink($oldFile);
    }

    // Crear nuevo archivo
    $filename = self::buildFilename($key, $expiresAt, $vars);
    $filepath = $config['dir'] . '/' . $filename;

    $data = [
      'value' => $value,
      'created_at' => time(),
      'expires_at' => $expiresAt
    ];

    file_put_contents($filepath, json_encode($data, JSON_UNESCAPED_UNICODE));
  }

  private static function forgetFile($key, $vars = []) {
    $file = self::findFile($key, $vars);

    if ($file && file_exists($file)) {
      unlink($file);
    }
  }

  /**
   * CLEANUP - Limpiar archivos expirados
   * Optimizado: lee timestamp del nombre sin abrir archivo
   * @param string|null $configKey Clave de configuracion a limpiar (null = activa)
   */
  static function cleanup($configKey = null) {
    $originalConfigKey = self::$activeConfigKey;

    if ($configKey !== null) {
      if (!isset(self::$configs[$configKey])) {
        return 0;
      }
      self::$activeConfigKey = $configKey;
    }

    $config = self::getConfig();
    self::initDir();

    $cleaned = 0;
    $now = time();
    $files = scandir($config['dir']);

    foreach ($files as $file) {
      if ($file === '.' || $file === '..' || !str_ends_with($file, '.' . $config['ext'])) {
        continue;
      }

      $filepath = $config['dir'] . '/' . $file;

      try {
        $filename = basename($file, '.' . $config['ext']);
        $parts = explode('_', $filename);

        // Primer elemento debe ser timestamp de expiracion
        if (!empty($parts) && is_numeric($parts[0])) {
          $expiresAt = (int)$parts[0];

          if ($expiresAt < $now) {
            unlink($filepath);
            $cleaned++;
          }
        } else {
          // Formato invalido, eliminar
          unlink($filepath);
          $cleaned++;
        }
      } catch (Exception $e) {
        // Ignorar errores
      }
    }

    // Restaurar configuracion original
    self::$activeConfigKey = $originalConfigKey;

    return $cleaned;
  }

  /**
   * CLEANUP ALL - Limpiar todas las configuraciones
   */
  static function cleanupAll() {
    $total = 0;

    foreach (array_keys(self::$configs) as $configKey) {
      $cleaned = self::cleanup($configKey);
      $total += $cleaned;
    }

    return $total;
  }

  /**
   * MEMORY SET - Guardar en memoria (solo durante ejecución)
   */
  static function memorySet($key, $value) {
    self::$memory[$key] = $value;
  }

  /**
   * MEMORY GET - Obtener de memoria
   */
  static function memoryGet($key, $default = null) {
    return self::$memory[$key] ?? $default;
  }

  /**
   * MEMORY HAS - Verificar existencia en memoria
   */
  static function memoryHas($key) {
    return isset(self::$memory[$key]);
  }

  /**
   * MEMORY FORGET - Eliminar de memoria
   */
  static function memoryForget($key) {
    unset(self::$memory[$key]);
  }

  /**
   * MEMORY CLEAR - Limpiar toda la memoria
   */
  static function memoryClear() {
    self::$memory = [];
  }

  /**
   * MEMORY REMEMBER - Obtener o ejecutar callback (solo memoria)
   */
  static function memoryRemember($key, $callback) {
    if (isset(self::$memory[$key])) {
      return self::$memory[$key];
    }

    $value = $callback();
    self::$memory[$key] = $value;
    return $value;
  }
}

/*
 * EJEMPLOS DE USO
 *
 * // ============ CACHE NORMAL (por defecto) ============
 * ogCache::set('user_data', $data);
 * $data = ogCache::get('user_data');
 * ogCache::forget('user_data');
 *
 * // Archivos: 1735689600_abc123.cache
 *
 *
 * // ============ CACHE PERSONALIZADO (sesiones) ============
 * // Configurar para sesiones
 * ogCache::setConfig([
 *   'dir' => STORAGE_PATH . '/sessions',
 *   'ext' => 'json',
 *   'format' => '{expires}_{var1}_{hash}'
 * ], 'session');
 *
 * // Guardar sesion
 * ogCache::set('session_' . $token, $sessionData, 86400, ['var1' => $userId]);
 * // Archivo: 1735689600_10_abc123.json
 *
 * // Obtener sesion
 * $session = ogCache::get('session_' . $token);
 *
 * // Eliminar sesion
 * ogCache::forget('session_' . $token, ['var1' => $userId]);
 *
 * // Restaurar config por defecto
 * ogCache::setConfigDefault();
 *
 * // Ahora usa config normal de nuevo
 * ogCache::set('other_data', $value);
 * // Archivo: 1735689600_def456.cache
 *
 *
 * // ============ LIMPIEZA ============
 * // Limpiar config activa
 * ogCache::cleanup();
 *
 * // Limpiar config especifica
 * ogCache::cleanup('session');
 *
 * // Limpiar todas las configs
 * ogCache::cleanupAll();
 */

/*  Usos de Memory volatil
// Guardar
ogCache::memorySet('user_data', $userData);
ogCache::memorySet('temp_result', ['count' => 100]);

// Obtener
$data = ogCache::memoryGet('user_data');
$result = ogCache::memoryGet('temp_result', []); // con default

// Verificar
if (ogCache::memoryHas('user_data')) {
  // existe
}

// Remember (ejecuta callback solo si no existe)
$users = ogCache::memoryRemember('all_users', function() {
  return ogDb::table('users')->get();
});

// Eliminar
ogCache::memoryForget('user_data');

// Limpiar todo
ogCache::memoryClear();
*/