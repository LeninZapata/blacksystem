<?php
/**
 * CACHE - Sistema híbrido de cache
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * REGLA DE ORO: Usar prefijo 'global_' para decidir dónde cachear
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * 1. CACHE GLOBAL (prefijo 'global_'):
 *    - Dónde: $_SESSION (memoria)
 *    - Velocidad: ⚡ 0.01ms (super rápido)
 *    - Uso: Datos que son IGUALES para TODOS los usuarios
 *    - Problema: NO funciona con Authorization: Bearer (usa cookies)
 *
 *    Ejemplos:
 *    ✅ ogCache::set('global_php_version_valid', true)
 *    ✅ ogCache::set('global_app_config', [...])
 *    ✅ ogCache::set('global_maintenance_mode', false)
 *
 * 2. CACHE POR USUARIO (sin prefijo 'global_'):
 *    - Dónde: Archivo en /storage/cache/
 *    - Velocidad: ✓ 0.1ms (un poco más lento pero aceptable)
 *    - Uso: Datos específicos de usuario/token
 *    - Ventaja: Funciona con Bearer tokens, compartido entre web/móvil/API
 *
 *    Ejemplos:
 *    ✅ ogCache::set('auth_session_abc123', $sessionData)
 *    ✅ ogCache::set('user_permissions_5', ['read', 'write'])
 *    ✅ ogCache::set('user_config_10', [...])
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ¿POR QUÉ ESTE SISTEMA?
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * $_SESSION usa cookie PHPSESSID:
 * - Web browser: Tiene cookie → $_SESSION funciona ✓
 * - Mobile app: NO tiene cookie → $_SESSION crea sesión NUEVA cada vez ✗
 * - API client: NO tiene cookie → $_SESSION crea sesión NUEVA cada vez ✗
 *
 * Por eso:
 * - Versión PHP (igual para todos) → 'global_' → $_SESSION ⚡
 * - Sesión usuario (distinta por token) → archivo → funciona con Bearer ✓
 *
 * ═══════════════════════════════════════════════════════════════════════════
 */
class ogCache {
  private static $sessionStarted = false;
  private static $cacheDir;

  // Inicializar sesión
  private static function startSession() {
    if (self::$sessionStarted) return;

    if (session_status() === PHP_SESSION_NONE) {
      ini_set('session.gc_maxlifetime', OG_SESSION_TTL);
      session_start();
    }

    self::$sessionStarted = true;
  }

  // Inicializar directorio de cache
  private static function initDir() {
    if (self::$cacheDir) return;

    self::$cacheDir = STORAGE_PATH . '/cache/';

    if (!is_dir(self::$cacheDir)) {
      mkdir(self::$cacheDir, 0755, true);
    }
  }

  /**
   * GET - Obtener valor del cache
   *
   * REGLA SIMPLE:
   * - Si la key empieza con 'global_' → usa $_SESSION (rápido, pero NO funciona con Bearer tokens)
   * - Si NO empieza con 'global_' → usa archivo (funciona con Bearer tokens)
   *
   * EJEMPLOS:
   *
   * ✅ USAR 'global_' para datos que NO dependen del usuario:
   * ogCache::get('global_php_version_valid')     → $_SESSION ⚡ 0.01ms
   * ogCache::get('global_app_config')            → $_SESSION ⚡
   * ogCache::get('global_timezone')              → $_SESSION ⚡
   *
   * ✅ NO USAR 'global_' para datos específicos de usuarios/tokens:
   * ogCache::get('auth_session_abc123')          → Archivo ✓ 0.1ms (funciona con Bearer)
   * ogCache::get('user_permissions_5')           → Archivo ✓
   * ogCache::get('user_config_10')               → Archivo ✓
   *
   * ¿POR QUÉ?
   * $_SESSION usa cookies PHPSESSID, NO funciona con Authorization: Bearer
   * Archivos funcionan independiente del cliente (web, móvil, API)
   */
  static function get($key, $default = null) {
    // Datos globales (no dependen del usuario) → $_SESSION
    if (str_starts_with($key, 'global_')) {
      self::startSession();
      return $_SESSION['cache'][$key] ?? $default;
    }

    // Datos por usuario/token → Archivo
    return self::getFromFile($key, $default);
  }

  /**
   * SET - Guardar valor en cache
   *
   * REGLA SIMPLE:
   * - Si la key empieza con 'global_' → guarda en $_SESSION
   * - Si NO empieza con 'global_' → guarda en archivo
   *
   * EJEMPLOS:
   *
   * ✅ Datos globales (same value para TODOS los usuarios):
   * ogCache::set('global_php_version_valid', true)
   * ogCache::set('global_maintenance_mode', false)
   *
   * ✅ Datos específicos (different value por usuario/token):
   * ogCache::set('auth_session_abc123', $sessionData)
   * ogCache::set('user_permissions_5', ['read', 'write'])
   *
   * @param string $key Nombre del cache
   * @param mixed $value Valor a guardar
   * @param int|null $ttl Tiempo de vida en segundos (solo para archivos, default: OG_SESSION_TTL)
   */
  static function set($key, $value, $ttl = null) {
    // Datos globales → $_SESSION
    if (str_starts_with($key, 'global_')) {
      self::startSession();
      $_SESSION['cache'][$key] = $value;
      return;
    }

    // Datos por usuario/token → Archivo
    self::setToFile($key, $value, $ttl);
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
   */
  static function forget($key) {
    if (str_starts_with($key, 'global_')) {
      self::startSession();
      unset($_SESSION['cache'][$key]);
      return;
    }

    self::forgetFile($key);
  }

  /**
   * CLEAR - Limpiar todo
   */
  static function clear() {
    // Limpiar $_SESSION
    self::startSession();
    $_SESSION['cache'] = [];

    // Limpiar archivos
    self::initDir();
    $files = glob(self::$cacheDir . '*.cache');
    foreach ($files as $file) {
      unlink($file);
    }
  }

  /**
   * REMEMBER - Obtener o ejecutar callback
   */
  static function remember($key, $callback, $ttl = null) {
    $value = self::get($key);

    if ($value !== null) {
      return $value;
    }

    $value = $callback();
    self::set($key, $value, $ttl);
    return $value;
  }

  // ========== MÉTODOS PRIVADOS PARA ARCHIVOS ==========

  private static function getPath($key) {
    self::initDir();
    return self::$cacheDir . md5($key) . '.cache';
  }

  private static function getFromFile($key, $default = null) {
    $file = self::getPath($key);

    if (!file_exists($file)) {
      return $default;
    }

    $data = json_decode(file_get_contents($file), true);

    // Verificar expiración
    if (isset($data['expires_at']) && $data['expires_at'] < time()) {
      unlink($file);
      return $default;
    }

    return $data['value'] ?? $default;
  }

  private static function setToFile($key, $value, $ttl = null) {
    $file = self::getPath($key);
    $ttl = $ttl ?? OG_SESSION_TTL;

    $data = [
      'value' => $value,
      'created_at' => time(),
      'expires_at' => time() + $ttl
    ];

    file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE));
  }

  private static function forgetFile($key) {
    $file = self::getPath($key);

    if (file_exists($file)) {
      unlink($file);
    }
  }

  /**
   * CLEANUP - Limpiar archivos expirados (ejecutar con cron)
   */
  static function cleanup() {
    self::initDir();

    $cleaned = 0;
    $files = glob(self::$cacheDir . '*.cache');

    foreach ($files as $file) {
      $data = json_decode(file_get_contents($file), true);

      if (isset($data['expires_at']) && $data['expires_at'] < time()) {
        unlink($file);
        $cleaned++;
      }
    }

    return $cleaned;
  }
}

/*
 * ═══════════════════════════════════════════════════════════════════════════
 * EJEMPLOS DE USO
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * // ✅ EJEMPLO 1: Validar versión PHP (dato global)
 * $isValid = ogCache::remember('global_php_version_valid', function() {
 *   return version_compare(PHP_VERSION, '8.1.0', '>=');
 * });
 * // Resultado: Se guarda en $_SESSION (0.01ms) ⚡
 *
 *
 * // ✅ EJEMPLO 2: Cachear sesión de usuario (dato específico)
 * ogCache::set('auth_session_abc123', [
 *   'user_id' => 5,
 *   'token' => 'abc123...',
 *   'expires_at' => time() + 86400
 * ]);
 * // Resultado: Se guarda en archivo (0.1ms) ✓
 *
 *
 * // ✅ EJEMPLO 3: Leer sesión desde móvil/web/API (mismo token)
 * // Request desde Web:
 * $session = ogCache::get('auth_session_abc123');
 * // Request desde Mobile App (mismo token):
 * $session = ogCache::get('auth_session_abc123'); // ← Obtiene el MISMO valor ✓
 * // Resultado: Funciona porque usa archivo, no depende de cookies
 *
 *
 * // ❌ EJEMPLO 4: Error común - usar global_ para datos de usuario
 * ogCache::set('global_user_session_5', $sessionData); // ← MAL ✗
 * // Problema: Si el usuario hace login desde móvil, $_SESSION es diferente
 *
 *
 * // ✅ EJEMPLO 5: Login - crear cache
 * ogCache::set('auth_session_' . substr($token, 0, 16), $sessionData);
 *
 * // ✅ EJEMPLO 6: Logout - borrar cache
 * ogCache::forget('auth_session_' . substr($token, 0, 16));
 *
 *
 * // ✅ EJEMPLO 7: Limpiar cache expirado (cron job)
 * $cleaned = ogCache::cleanup();
 * // Ejecutar cada hora: 0 * * * * curl http://domain.com/api/cache/cleanup
 *
 * ═══════════════════════════════════════════════════════════════════════════
 */