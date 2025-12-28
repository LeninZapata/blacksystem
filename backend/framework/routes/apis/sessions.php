<?php
// routes/apis/sessions.php - Endpoints de sesiones mejorados
// desde CROM curl http://yourdomain.com/api/sessions/cleanup para eliminar session caducadas
$router->group('/api/sessions', function($router) {

  $middleware = OG_IS_DEV ? [] : ['auth'];
  $logMeta = ['module' => 'session', 'layer' => 'app'];

  // Listar todas las sesiones activas y expiradas
  $router->get(['/',''], function() {
    $sessionsDir = STORAGE_PATH . '/sessions/';

    if (!is_dir($sessionsDir)) {
      ogResponse::success(['active' => [], 'expired' => []]);
      return;
    }

    $active = [];
    $expired = [];
    $now = time();

    foreach (scandir($sessionsDir) as $file) {
      if ($file === '.' || $file === '..' || !str_ends_with($file, '.json')) continue;

      $parts = explode('_', $file);
      if (count($parts) < 3) continue;

      $expiresAt = (int)$parts[0];
      $userId = (int)$parts[1];
      $tokenShort = str_replace('.json', '', $parts[2]);

      $session = [
        'user_id' => $userId,
        'token_short' => $tokenShort,
        'expires_at' => date('Y-m-d H:i:s', $expiresAt),
        'expires_timestamp' => $expiresAt,
        'file' => $file
      ];

      if ($expiresAt < $now) {
        $session['expired_since_seconds'] = $now - $expiresAt;
        $session['expired_since_hours'] = round(($now - $expiresAt) / 3600, 2);
        $expired[] = $session;
      } else {
        $session['remaining_seconds'] = $expiresAt - $now;
        $session['remaining_hours'] = round(($expiresAt - $now) / 3600, 2);
        $active[] = $session;
      }
    }

    ogResponse::success([
      'active' => $active,
      'expired' => $expired,
      'count' => [
        'active' => count($active),
        'expired' => count($expired),
        'total' => count($active) + count($expired)
      ]
    ]);
  })->middleware($middleware);

  // Obtener estadísticas de sesiones
  $router->get('/stats', function() use ($logMeta) {
    $sessionsDir = STORAGE_PATH . '/sessions/';

    if (!is_dir($sessionsDir)) {
      ogResponse::success([
        'total' => 0,
        'active' => 0,
        'expired' => 0,
        'total_size_kb' => 0
      ]);
      return;
    }

    $total = 0;
    $active = 0;
    $expired = 0;
    $totalSize = 0;
    $now = time();

    $files = scandir($sessionsDir);

    foreach ($files as $file) {
      if ($file === '.' || $file === '..' || !str_ends_with($file, '.json')) continue;

      $filePath = $sessionsDir . $file;
      $total++;
      $totalSize += filesize($filePath);

      try {
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

    ogResponse::success([
      'total' => $total,
      'active' => $active,
      'expired' => $expired,
      'total_size_kb' => round($totalSize / 1024, 2),
      'avg_size_kb' => $total > 0 ? round(($totalSize / $total) / 1024, 2) : 0
    ]);
  })->middleware($middleware);

  // Listar sesiones de un usuario específico
  $router->get('/user/{user_id}', function($userId) use ($logMeta) {
    $sessionsDir = STORAGE_PATH . '/sessions/';

    if (!is_dir($sessionsDir)) {
      ogResponse::success([
        'user_id' => (int)$userId,
        'sessions' => [],
        'count' => 0
      ]);
      return;
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

      $session = [
        'token_short' => $tokenShort,
        'expires_at' => date('Y-m-d H:i:s', $expiresAt),
        'expires_timestamp' => $expiresAt,
        'file' => $filename,
        'is_expired' => $expiresAt < $now
      ];

      if ($expiresAt >= $now) {
        $session['remaining_seconds'] = $expiresAt - $now;
        $session['remaining_hours'] = round(($expiresAt - $now) / 3600, 2);
      } else {
        $session['expired_since_seconds'] = $now - $expiresAt;
        $session['expired_since_hours'] = round(($now - $expiresAt) / 3600, 2);
      }

      $sessions[] = $session;
    }

    ogResponse::success([
      'user_id' => (int)$userId,
      'sessions' => $sessions,
      'count' => count($sessions)
    ]);
  })->middleware($middleware);

  // Limpiar sesiones expiradas del sistema
  $router->delete('/cleanup', function() use ($logMeta) {
    $sessionsDir = STORAGE_PATH . '/sessions/';

    if (!is_dir($sessionsDir)) {
      ogResponse::success([
        'cleaned' => 0,
        'errors' => 0,
        'size_freed_kb' => 0
      ]);
      return;
    }

    $cleaned = 0;
    $errors = 0;
    $totalSize = 0;
    $now = time();

    $files = scandir($sessionsDir);

    foreach ($files as $file) {
      if ($file === '.' || $file === '..' || !str_ends_with($file, '.json')) continue;

      $filePath = $sessionsDir . $file;

      try {
        $fileSize = filesize($filePath);
        $parts = explode('_', $file);

        // Archivo con formato inválido
        if (count($parts) < 3) {
          unlink($filePath);
          $cleaned++;
          $totalSize += $fileSize;
          continue;
        }

        $expiresAt = (int)$parts[0];

        // Archivo expirado
        if ($expiresAt < $now) {
          unlink($filePath);
          $cleaned++;
          $totalSize += $fileSize;
        }
      } catch (Exception $e) {
        $errors++;
        ogLog::error("Error limpiando sesión", [
          'file' => $file,
          'error' => $e->getMessage()
        ], $logMeta);
      }
    }

    if ($cleaned > 0) {
      ogLog::info('Limpieza de sesiones completada', [
        'cleaned' => $cleaned,
        'errors' => $errors,
        'size_freed_kb' => round($totalSize / 1024, 2)
      ], $logMeta);
    }

    ogResponse::success([
      'cleaned' => $cleaned,
      'errors' => $errors,
      'size_freed_kb' => round($totalSize / 1024, 2)
    ], __('api.session.cleanup.success', ['count' => $cleaned]));
  })->middleware($middleware);

  // Invalidar todas las sesiones de un usuario
  $router->delete('/user/{user_id}', function($userId) use ($logMeta) {
    $cleaned = UserController::invalidateSessions($userId);
    
    ogResponse::success([
      'user_id' => (int)$userId,
      'cleaned' => $cleaned
    ], __('api.session.invalidated', ['count' => $cleaned]));
  })->middleware($middleware);

});