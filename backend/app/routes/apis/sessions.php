<?php
// routes/apis/session.php - Endpoints de sesiones

$router->group('/api/sessions', function($router) {

  $middleware = IS_DEV ? [] : ['auth'];

  // GET /api/sessions - Listar todas las sesiones
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

  // GET /api/sessions/stats - Estadísticas rápidas
  $router->get('/stats', function() {
    $stats = sessionCleanup::stats();
    response::success($stats);
  })->middleware($middleware);

  // GET /api/sessions/user/{user_id} - Sesiones de un usuario
  $router->get('/user/{user_id}', function($userId) {
    $sessions = sessionCleanup::getUserSessions($userId);
    response::success([
      'user_id' => (int)$userId,
      'sessions' => $sessions,
      'count' => count($sessions)
    ]);
  })->middleware($middleware);

  // DELETE /api/sessions/cleanup - Limpiar expiradas
  $router->delete('/cleanup', function() {
    $result = sessionCleanup::clean();
    response::success($result, __('session.cleanup.success'));
  })->middleware($middleware);

  // DELETE /api/sessions/user/{user_id} - Invalidar sesiones de usuario
  $router->delete('/user/{user_id}', function($userId) {
    $cleaned = sessionCleanup::cleanByUserId($userId);
    response::success([
      'user_id' => (int)$userId,
      'cleaned' => $cleaned
    ], __('session.invalidated', ['count' => $cleaned]));
  })->middleware($middleware);

});