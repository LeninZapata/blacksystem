<?php
// routes/apis/chat.php

$router->group('/api/chat', function($router) {

  // Middleware condicional: sin auth en desarrollo, con auth en producción
  $devMiddleware = OG_IS_DEV ? [] : ['auth'];

  // ============================================
  // RUTAS DE UTILIDAD (desarrollo sin auth, producción con auth)
  // ============================================

  // Reconstruir chat JSON desde DB
  $router->get('/rebuild/{number}/{bot_id}', function($number, $bot_id) {
    ogLog::info("chat/rebuild - INICIO", ['number' => $number, 'bot_id' => $bot_id], ['module' => 'chat_api']);

    if (empty($number) || empty($bot_id)) ogResponse::error('Parámetros requeridos: number, bot_id', 400);

    try {
      $chat = ChatHandlers::rebuildFromDB($number, $bot_id);

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

    $chat = ChatHandlers::getChat($number, $bot_id, true);
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

    $chatFile = CHATS_INFOPRODUCT_PATH . '/chat_' . $number . '_bot_' . $bot_id . '.json';
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
    require_once APP_PATH . '/workflows/core/validators/ConversationValidator.php';

    $hasConversation = ConversationValidator::quickCheck($number, $bot_id, $maxDays);
    $chatData = ConversationValidator::getChatData($number, $bot_id, false);

    ogResponse::success([
      'has_conversation' => $hasConversation,
      'max_days' => $maxDays,
      'conversation_started' => $chatData['full_chat']['conversation_started'] ?? null,
      'current_sale' => $chatData['full_chat']['current_sale'] ?? null,
      'completed_sales' => $chatData['full_chat']['summary']['completed_sales'] ?? 0,
      'purchased_products' => $chatData['full_chat']['summary']['purchased_products'] ?? []
    ]);
  })->middleware($devMiddleware);

  // ============================================
  // RUTAS PERSONALIZADAS (siempre con auth)
  // ============================================

  $router->get('/conversation/{bot_id}/{client_id}', function($bot_id, $client_id) {
    $result = ChatHandlers::getConversation(['bot_id' => $bot_id, 'client_id' => $client_id]);
    ogResponse::json($result);
  })->middleware('auth');

  $router->get('/by-bot/{bot_id}', function($bot_id) {
    $result = ChatHandlers::getByBot(['bot_id' => $bot_id]);
    ogResponse::json($result);
  })->middleware('auth');

  $router->get('/by-client/{client_id}', function($client_id) {
    $result = ChatHandlers::getByClient(['client_id' => $client_id]);
    ogResponse::json($result);
  })->middleware('auth');

  $router->get('/by-sale/{sale_id}', function($sale_id) {
    $result = ChatHandlers::getBySale(['sale_id' => $sale_id]);
    ogResponse::json($result);
  })->middleware('auth');

  $router->get('/search/{text}', function($text) {
    $result = ChatHandlers::search(['text' => $text]);
    ogResponse::json($result);
  })->middleware('auth');

  $router->get('/stats/{bot_id}', function($bot_id) {
    $result = ChatHandlers::getStats(['bot_id' => $bot_id]);
    ogResponse::json($result);
  })->middleware('auth');

  $router->delete('/by-client/{client_id}', function($client_id) {
    $result = ChatHandlers::deleteByClient(['client_id' => $client_id]);
    ogResponse::json($result);
  })->middleware('auth');
});

// ============================================
// ENDPOINTS:
//
// UTILIDAD (sin auth en dev, con auth en prod):
// - GET    /api/chat/rebuild/{number}/{bot_id}
// - GET    /api/chat/show/{number}/{bot_id}
// - DELETE /api/chat/delete/{number}/{bot_id}
// - GET    /api/chat/validate/{number}/{bot_id}
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