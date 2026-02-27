<?php
// routes/apis/chat.php
$router->group('/api/chat', function($router) {

  // Middleware condicional: sin auth en desarrollo, con auth en producción
  $devMiddleware = OG_IS_DEV ? [] : ['auth'];

  // Reconstruir chat JSON desde DB
  $router->get('/rebuild/{number}/{bot_id}', function($number, $bot_id) {
    ogLog::info("chat/rebuild - INICIO", ['number' => $number, 'bot_id' => $bot_id], ['module' => 'chat_api']);

    if (empty($number) || empty($bot_id)) ogResponse::error('Parámetros requeridos: number, bot_id', 400);

    try {
      $chat = ogApp()->handler('chat')::rebuildFromDB($number, $bot_id);

      if (!$chat) {
        ogLog::warning("chat/rebuild - Sin mensajes en BD", ['number' => $number, 'bot_id' => $bot_id], ['module' => 'chat_api']);
        ogResponse::error('No se pudo reconstruir el chat. Verifica que existan mensajes en la BD.', 404);
      }

      ogLog::success("chat/rebuild - Reconstruido", ['number' => $number, 'bot_id' => $bot_id, 'messages' => count($chat['messages'] ?? [])], ['module' => 'chat_api']);

      ogResponse::success([
        'chat' => $chat,
        'rebuilt' => true,
        'messages_count' => count($chat['messages'] ?? []),
        'conversation_started' => $chat['conversation_started'] ?? null,
        'current_sale' => $chat['current_sale'] ?? null,
        'completed_sales' => $chat['summary']['completed_sales'] ?? 0
      ], 'Chat reconstruido exitosamente');

    } catch (Exception $e) {
      ogLog::error("chat/rebuild - Error: {$e->getMessage()}", ['number' => $number, 'bot_id' => $bot_id], ['module' => 'chat_api']);
      ogResponse::serverError('Error al reconstruir chat', OG_IS_DEV ? $e->getMessage() : null);
    }
  })->middleware($devMiddleware);

  // Obtener chat JSON actual (sin reconstruir)
  $router->get('/show/{number}/{bot_id}', function($number, $bot_id) {
    if (empty($number) || empty($bot_id)) ogResponse::error('Parámetros requeridos: number, bot_id', 400);

    $chat = ogApp()->handler('chat')::getChat($number, $bot_id, true);
    if (!$chat) ogResponse::error('Chat no encontrado', 404);

    ogResponse::success([
      'chat' => $chat,
      'messages_count' => count($chat['messages'] ?? []),
      'conversation_started' => $chat['conversation_started'] ?? null,
      'current_sale' => $chat['current_sale'] ?? null
    ]);
  })->middleware($devMiddleware);

  // Eliminar chat JSON
  $router->delete('/delete/{number}/{bot_id}', function($number, $bot_id) {
    if (empty($number) || empty($bot_id)) ogResponse::error('Parámetros requeridos: number, bot_id', 400);

    $chatFile = ogApp()->getPath('storage/json/chats') . '/chat_' . $number . '_bot_' . $bot_id . '.json';
    if (!file_exists($chatFile)) ogResponse::error('Chat JSON no encontrado', 404);

    if (unlink($chatFile)) {
      ogLog::info("chat/delete - Eliminado", ['number' => $number, 'bot_id' => $bot_id], ['module' => 'chat_api']);
      ogResponse::success(['deleted' => true, 'ogFile' => basename($chatFile)], 'Chat JSON eliminado');
    } else {
      ogResponse::serverError('Error al eliminar chat JSON');
    }
  })->middleware($devMiddleware);

  // Validar conversación (quickCheck)
  $router->get('/validate/{number}/{bot_id}', function($number, $bot_id) {
    if (empty($number) || empty($bot_id)) ogResponse::error('Parámetros requeridos: number, bot_id', 400);

    $maxDays = ogRequest::query('max_days', 2);
    require_once ogApp()->getPath() . '/workflows/core/validators/ConversationValidator.php';

    $hasConversation = ConversationValidator::quickCheck($number, $bot_id, $maxDays);
    $chatData = ConversationValidator::getChatData($number, $bot_id, false, false);

    ogResponse::success([
      'has_conversation' => $hasConversation,
      'max_days' => $maxDays,
      'conversation_started' => $chatData['conversation_started'] ?? null,
      'current_sale' => $chatData['current_sale'] ?? null,
      'completed_sales' => $chatData['summary']['completed_sales'] ?? 0,
      'purchased_products' => $chatData['summary']['purchased_products'] ?? []
    ]);
  })->middleware($devMiddleware);

  // Limpieza automática de chats antiguos (JSON)
  $router->get('/cleanup/old-chats', function() {
    $logMeta = ['module' => 'routes/chat', 'layer' => 'backend/app'];
    try {
      $daysOld = ogRequest::query('days', 14); // Por defecto 2 semanas
      $limit = ogRequest::query('limit', 100); // Máximo 100 chats por ejecución
      $dryRun = ogRequest::query('dry_run', false); // Simulación sin eliminar

      ogLog::info("chat/cleanup - INICIO", [
        'days_old' => $daysOld,
        'limit' => $limit,
        'dry_run' => $dryRun
      ], $logMeta);

      // Calcular fecha límite
      $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$daysOld} days"));

      // Obtener chats antiguos agrupados por client_number y bot_id
      $oldChats = ogDb::t('chats')
        ->select('client_number', 'bot_id', 'MAX(dc) as last_message')
        ->where('dc', '<', $cutoffDate)
        ->groupBy(['client_number', 'bot_id'])
        ->limit($limit)
        ->get();

      if (empty($oldChats)) {
        ogLog::info("chat/cleanup - No hay chats antiguos para limpiar", [
          'cutoff_date' => $cutoffDate
        ], $logMeta);

        ogResponse::success([
          'deleted_count' => 0,
          'cutoff_date' => $cutoffDate,
          'days_old' => $daysOld,
          'message' => 'No hay chats antiguos para limpiar'
        ], 'No hay chats para limpiar');
      }

      $deletedFiles = [];
      $failedFiles = [];
      $skippedFiles = [];

      foreach ($oldChats as $chat) {
        $number = $chat['client_number'];
        $botId = $chat['bot_id'];
        $chatFile = ogApp()->getPath('storage/json/chats') . '/chat_' . $number . '_bot_' . $botId . '.json';

        // Verificar que el archivo existe
        if (!file_exists($chatFile)) {
          $skippedFiles[] = [
            'file' => basename($chatFile),
            'reason' => 'no_existe'
          ];
          continue;
        }

        // Modo dry_run: solo listar sin eliminar
        if ($dryRun) {
          $deletedFiles[] = [
            'file' => basename($chatFile),
            'number' => $number,
            'bot_id' => $botId,
            'last_message' => $chat['last_message'],
            'action' => 'would_delete'
          ];
          continue;
        }

        // Eliminar archivo
        if (unlink($chatFile)) {
          $deletedFiles[] = [
            'file' => basename($chatFile),
            'number' => $number,
            'bot_id' => $botId,
            'last_message' => $chat['last_message']
          ];

          ogLog::info("chat/cleanup - Archivo eliminado", [
            'file' => basename($chatFile),
            'number' => $number,
            'bot_id' => $botId,
            'last_message' => $chat['last_message']
          ], $logMeta);
        } else {
          $failedFiles[] = [
            'file' => basename($chatFile),
            'number' => $number,
            'bot_id' => $botId,
            'reason' => 'error_eliminar'
          ];

          ogLog::error("chat/cleanup - Error al eliminar archivo", [
            'file' => basename($chatFile),
            'number' => $number,
            'bot_id' => $botId
          ], $logMeta);
        }
      }

      $summary = [
        'deleted_count' => count($deletedFiles),
        'failed_count' => count($failedFiles),
        'skipped_count' => count($skippedFiles),
        'cutoff_date' => $cutoffDate,
        'days_old' => $daysOld,
        'limit' => $limit,
        'dry_run' => $dryRun,
        'deleted_files' => $deletedFiles,
        'failed_files' => $failedFiles,
        'skipped_files' => $skippedFiles
      ];

      ogLog::success("chat/cleanup - COMPLETADO", [
        'deleted' => count($deletedFiles),
        'failed' => count($failedFiles),
        'skipped' => count($skippedFiles)
      ], $logMeta);

      ogResponse::success($summary, $dryRun ? 'Simulación completada' : 'Limpieza completada');

    } catch (Exception $e) {
      ogLog::error("chat/cleanup - Error: {$e->getMessage()}", [
        'trace' => $e->getTraceAsString()
      ], $logMeta);

      ogResponse::serverError('Error al limpiar chats antiguos', OG_IS_DEV ? $e->getMessage() : null);
    }
  })->middleware($devMiddleware);

  // Envío manual de mensaje desde el panel admin
  // POST /api/chat/manual-send
  $router->post('/manual-send', function() {
    $data          = ogRequest::data();
    $botNumber     = $data['bot_number']    ?? null;
    $clientNumber  = $data['client_number'] ?? null;
    $clientId      = $data['client_id']     ?? null;
    $message       = trim($data['message']  ?? '');

    if (!$botNumber)    ogResponse::error('bot_number requerido', 400);
    if (!$clientNumber) ogResponse::error('client_number requerido', 400);
    if (!$message)      ogResponse::error('message requerido', 400);

    $logMeta = ['module' => 'chat/manual-send', 'layer' => 'backend/app'];

    try {
      // 1. Cargar datos del bot desde JSON
      ogApp()->loadHandler('bot');
      $botData = BotHandler::getDataFile($botNumber);
      if (!$botData) ogResponse::error("Bot no encontrado: {$botNumber}", 404);

      $userId = $botData['user_id'] ?? null;
      $botId  = $botData['id']      ?? null;

      // Verificar que el bot pertenece al usuario autenticado
      if ($userId && isset($GLOBALS['auth_user_id']) && (int)$userId !== (int)$GLOBALS['auth_user_id']) {
        ogResponse::json(['success' => false, 'error' => 'No autorizado'], 403);
      }

      // 2. Configurar ChatAPI con el bot
      $chatapi = ogApp()->service('chatApi');
      $chatapi::setConfig($botData);

      // Detectar provider disponible (usar el primero en config.apis.chat)
      $chatApis = $botData['config']['apis']['chat'] ?? [];
      $provider = $chatApis[0]['config']['type_value'] ?? 'evolutionapi';
      $chatapi::setProvider($provider);

      ogLog::info("manual-send - Enviando", [
        'bot_number'    => $botNumber,
        'client_number' => $clientNumber,
        'provider'      => $provider,
        'length'        => strlen($message)
      ], $logMeta);

      // 3. Enviar mensaje
      $result = $chatapi::send($clientNumber, $message);

      if (!($result['success'] ?? false)) {
        ogResponse::error($result['error'] ?? 'Error al enviar', 500);
      }

      // 4. Registrar en tabla chats como tipo 'B' (Bot/Manual)
      if ($botId && $clientId) {
        ogApp()->loadHandler('chat');
        ChatHandler::setUserId($userId);
        ChatHandler::register(
          $botId, $botNumber,
          (int)$clientId, $clientNumber,
          $message, 'B', 'text',
          ['source' => 'manual_send']
        );
      }

      ogLog::success("manual-send - OK", [
        'bot_number'    => $botNumber,
        'client_number' => $clientNumber,
        'provider'      => $provider
      ], $logMeta);

      ogResponse::success(['sent' => true, 'provider' => $provider], 'Mensaje enviado');

    } catch (Exception $e) {
      ogLog::error("manual-send - Error: {$e->getMessage()}", [
        'bot_number'    => $botNumber,
        'client_number' => $clientNumber
      ], $logMeta);
      ogResponse::serverError('Error al enviar mensaje', OG_IS_DEV ? $e->getMessage() : null);
    }
  })->middleware('auth');

  // ============================================
  // RUTAS PERSONALIZADAS (siempre con auth)
  // ============================================

  $router->get('/conversation/{bot_id}/{client_id}', function($bot_id, $client_id) {
    ogResponse::json(ogApp()->handler('chat')::getConversation(['bot_id' => $bot_id, 'client_id' => $client_id]));
  })->middleware('auth');

  $router->get('/by-bot/{bot_id}', function($bot_id) {
    ogResponse::json(ogApp()->handler('chat')::getByBot(['bot_id' => $bot_id]));
  })->middleware('auth');

  $router->get('/by-client/{client_id}', function($client_id) {
    ogResponse::json(ogApp()->handler('chat')::getByClient(['client_id' => $client_id]));
  })->middleware('auth');

  $router->get('/search/{text}', function($text) {
    ogResponse::json(ogApp()->handler('chat')::search(['text' => $text]));
  })->middleware('auth');

  $router->get('/stats/{bot_id}', function($bot_id) {
    ogResponse::json(ogApp()->handler('chat')::getStats(['bot_id' => $bot_id]));
  })->middleware('auth');

  $router->delete('/by-client/{client_id}', function($client_id) {
    ogResponse::json(ogApp()->handler('chat')::deleteByClient(['client_id' => $client_id]));
  })->middleware('auth');

  // Estadísticas por día - GET /api/chat/stats/by-day?range=last_7_days
  $router->get('/stats/by-day', function() {
    $range = ogRequest::query('range', 'last_7_days');
    ogResponse::json(ogApp()->handler('chat')::getStatsByDay(['range' => $range]));
  })->middleware('auth');

  // Chats por producto - GET /api/chat/stats/by-product?range=last_7_days
  $router->get('/stats/by-product', function() {
    $range = ogRequest::query('range', 'last_7_days');
    ogResponse::json(ogApp()->handler('chat')::getStatsByProduct(['range' => $range]));
  });
});

// ============================================
// ENDPOINTS:
//
// UTILIDAD (sin auth en dev, con auth en prod):
// - GET    /api/chat/rebuild/{number}/{bot_id}
// - GET    /api/chat/show/{number}/{bot_id}
// - DELETE /api/chat/delete/{number}/{bot_id}
// - GET    /api/chat/validate/{number}/{bot_id}
// - GET    /api/chat/cleanup/old-chats?days=14&limit=100&dry_run=false
//
// PERSONALIZADAS (siempre auth):
// - GET    /api/chat/conversation/{bot_id}/{client_id}
// - GET    /api/chat/by-bot/{bot_id}
// - GET    /api/chat/by-client/{client_id}
// - GET    /api/chat/by-sale/{sale_id}
// - GET    /api/chat/search/{text}
// - GET    /api/chat/stats/{bot_id}
// - DELETE /api/chat/by-client/{client_id}
//
// CRUD (auto-registradas desde chat.json con auth):
// - GET    /api/chat
// - GET    /api/chat/{id}
// - POST   /api/chat
// - PUT    /api/chat/{id}
// - DELETE /api/chat/{id}
// ============================================