<?php
// routes/apis/session.php - Endpoints de sesiones

$router->group('/api/sessions', function($router) {

  $middleware = IS_DEV ? [] : ['auth'];
  $logMeta = ['module' => 'session', 'layer' => 'app'];

  // Listar todas las sesiones activas y expiradas
  $router->get(['/',''], function() {
    $sessionsDir = STORAGE_PATH . '/sessions/';

    if (!is_dir($sessionsDir)) {
      response::success(['active' => [], 'expired' => []]);
      return;
    }

    $active = [];
    $expired = [];
    $now = time();

    foreach (scandir($sessionsDir) as $file) {
      if ($file === '.' || $file === '..') continue;

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
        $session['expired_since'] = $now - $expiresAt;
        $expired[] = $session;
      } else {
        $session['remaining_seconds'] = $expiresAt - $now;
        $active[] = $session;
      }
    }

    response::success([
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
      response::success([
        'total' => 0,
        'active' => 0,
        'expired' => 0
      ]);
      return;
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

    response::success([
      'total' => $total,
      'active' => $active,
      'expired' => $expired
    ]);
  })->middleware($middleware);

  // Listar sesiones de un usuario específico
  $router->get('/user/{user_id}', function($userId) use ($logMeta) {
    $sessionsDir = STORAGE_PATH . '/sessions/';

    if (!is_dir($sessionsDir)) {
      response::success([
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

    response::success([
      'user_id' => (int)$userId,
      'sessions' => $sessions,
      'count' => count($sessions)
    ]);
  })->middleware($middleware);

  // Limpiar sesiones expiradas del sistema
  $router->delete('/cleanup', function() use ($logMeta) {
    $sessionsDir = STORAGE_PATH . '/sessions/';

    if (!is_dir($sessionsDir)) {
      response::success(['cleaned' => 0, 'errors' => 0]);
      return;
    }

    $cleaned = 0;
    $errors = 0;
    $now = time();

    $files = scandir($sessionsDir);

    foreach ($files as $file) {
      if ($file === '.' || $file === '..') continue;

      try {
        $parts = explode('_', $file);

        if (count($parts) < 3) {
          unlink($sessionsDir . $file);
          $cleaned++;
          continue;
        }

        $expiresAt = (int)$parts[0];

        if ($expiresAt < $now) {
          unlink($sessionsDir . $file);
          $cleaned++;
        }
      } catch (Exception $e) {
        $errors++;
        log::error("Error procesando {$file}: ", $e->getMessage(), $logMeta);
      }
    }

    if ($cleaned > 0) {
      log::info(__('api.session.cleanup_completed', ['cleaned' => $cleaned]), null, $logMeta);
    }

    response::success(['cleaned' => $cleaned, 'errors' => $errors], __('api.session.cleanup.success'));
  })->middleware($middleware);

  // Invalidar todas las sesiones de un usuario
  $router->delete('/user/{user_id}', function($userId) use ($logMeta) {
    // Usar UserController::invalidateSessions para centralizar la lógica
    $cleaned = UserController::invalidateSessions($userId);
    response::success([
      'user_id' => (int)$userId,
      'cleaned' => $cleaned
    ], __('api.session.invalidated', ['count' => $cleaned]));
  })->middleware($middleware);

});