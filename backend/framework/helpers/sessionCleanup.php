<?php
// sessionCleanup - Limpieza optimizada de sesiones
class sessionCleanup {

  /**
   * Limpiar sesiones expiradas (OPTIMIZADO)
   * 
   * NO lee contenido de archivos
   * Solo extrae timestamp del nombre y compara
   * 
   * Formato de archivo: {expires_timestamp}_{user_id}_{token_short}.json
   * Ejemplo: 1733788800_5_30e3957cdebe.json
   */
  static function clean() {
    $sessionsDir = STORAGE_PATH . '/sessions/';

    if (!is_dir($sessionsDir)) {
      return ['cleaned' => 0, 'errors' => 0];
    }

    $cleaned = 0;
    $errors = 0;
    $now = time();

    $files = scandir($sessionsDir);

    foreach ($files as $file) {
      if ($file === '.' || $file === '..') continue;

      try {
        // Extraer timestamp del nombre (antes del primer _)
        $parts = explode('_', $file);
        
        if (count($parts) < 3) {
          // Archivo con formato incorrecto, eliminar
          unlink($sessionsDir . $file);
          $cleaned++;
          continue;
        }

        $expiresAt = (int)$parts[0];

        // Si expiró, eliminar (sin leer contenido)
        if ($expiresAt < $now) {
          unlink($sessionsDir . $file);
          $cleaned++;
        }
      } catch (Exception $e) {
        $errors++;
        log::error("Error procesando {$file}: " , $e->getMessage(), ['module' => 'session']);
      }
    }

    if ($cleaned > 0) {
      log::info(__('helper.session.cleanup_completed', ['cleaned' => $cleaned]), null, ['module' => 'session']);
    }

    return [
      'cleaned' => $cleaned,
      'errors' => $errors
    ];
  }

  /**
   * Obtener estadísticas de sesiones (OPTIMIZADO)
   * 
   * Cuenta archivos sin leer contenido
   * Usa timestamp del nombre para saber si está expirado
   */
  static function stats() {
    $sessionsDir = STORAGE_PATH . '/sessions/';

    if (!is_dir($sessionsDir)) {
      return [
        'total' => 0,
        'active' => 0,
        'expired' => 0
      ];
    }

    $total = 0;
    $active = 0;
    $expired = 0;
    $now = time();

    $files = scandir($sessionsDir);

    foreach ($files as $file) {
      if ($file === '.' || $file === '..') continue;

      $total++;

      try {
        // Extraer timestamp del nombre
        $parts = explode('_', $file);
        
        if (count($parts) < 3) {
          $expired++;
          continue;
        }

        $expiresAt = (int)$parts[0];

        if ($expiresAt < $now) {
          $expired++;
        } else {
          $active++;
        }
      } catch (Exception $e) {
        $expired++;
      }
    }

    return [
      'total' => $total,
      'active' => $active,
      'expired' => $expired
    ];
  }

  /**
   * Limpiar TODAS las sesiones de un usuario (ULTRA OPTIMIZADO)
   * 
   * Usa glob() con patrón para buscar solo archivos del usuario
   * NO itera sobre TODOS los archivos
   * 
   * Formato: {expires_timestamp}_{user_id}_{token_short}.json
   * Patrón: *_{user_id}_*.json
   */
  static function cleanByUserId($userId) {
    $sessionsDir = STORAGE_PATH . '/sessions/';

    if (!is_dir($sessionsDir)) {
      return 0;
    }

    // Buscar solo archivos de este usuario usando glob()
    $pattern = $sessionsDir . "*_{$userId}_*.json";
    $files = glob($pattern);

    $cleaned = 0;

    foreach ($files as $file) {
      try {
        unlink($file);
        $cleaned++;
      } catch (Exception $e) {
        log::error( __('helper.session.cleanup_error') , $e->getMessage(), ['module' => 'session']);
      }
    }

    if ($cleaned > 0) {
      log::info( __('helper.session.cleaned_user', ['cleaned' => $cleaned, 'userId' => $userId]), ['module' => 'session']);
    }

    return $cleaned;
  }

  /**
   * Obtener sesiones activas de un usuario (NUEVO)
   * 
   * Devuelve información básica sin leer todo el contenido
   */
  static function getUserSessions($userId) {
    $sessionsDir = STORAGE_PATH . '/sessions/';

    if (!is_dir($sessionsDir)) {
      return [];
    }

    $pattern = $sessionsDir . "*_{$userId}_*.json";
    $files = glob($pattern);

    $sessions = [];
    $now = time();

    foreach ($files as $file) {
      $filename = basename($file);
      $parts = explode('_', $filename);

      if (count($parts) < 3) continue;

      $expiresAt = (int)$parts[0];
      $tokenShort = str_replace('.json', '', $parts[2]);

      // Solo sesiones activas
      if ($expiresAt >= $now) {
        $sessions[] = [
          'token_short' => $tokenShort,
          'expires_at' => date('Y-m-d H:i:s', $expiresAt),
          'expires_timestamp' => $expiresAt,
          'remaining_seconds' => $expiresAt - $now,
          'file' => $filename
        ];
      }
    }

    return $sessions;
  }

  /**
   * Limpiar sesiones expiradas de forma automática (CRON)
   * 
   * Ejecutar cada hora mediante cron:
   * 0 * * * * curl http://tu-dominio.com/api/system/cleanup-sessions
   */
  static function autoclean() {
    $result = self::clean();
    
    log::info('sessionCleanup', "Auto-limpieza ejecutada", [
      'cleaned' => $result['cleaned'],
      'errors' => $result['errors']
    ], ['module' => 'session']);

    return $result;
  }
}