<?php
class workflowMessage {

  // Verificar si existe conversación activa (archivo JSON existe y está dentro del rango de días)
  static function hasActiveConversation($number, $botId, $maxDays = 1) {
    $chat = chatHandlers::getChat($number, $botId, true);

    if ($chat === false) {
      return false;
    }

    // Obtener fecha de inicio de conversación
    $conversationStarted = $chat['conversation_started'] ?? null;

    if (!$conversationStarted) {
      return false;
    }

    // Calcular diferencia de días
    $startDate = strtotime($conversationStarted);
    $currentDate = time();
    $daysDiff = floor(($currentDate - $startDate) / 86400);

    // Retornar true solo si está dentro del rango de días
    return $daysDiff < $maxDays;
  }

  // Obtener datos del chat (client_id, sale_id)
  static function getChatData($number, $botId) {
    $chat = chatHandlers::getChat($number, $botId, true);
    
    if (!$chat) {
      return ['client_id' => null, 'sale_id' => 0];
    }

    return [
      'client_id' => $chat['client_id'] ?? null,
      'sale_id' => $chat['current_sale']['sale_id'] ?? 0
    ];
  }

  // Agregar mensaje de venta iniciada al chat JSON y BD
  static function addStartSaleMessage($args) {
    $bot = $args['bot'];
    $person = $args['person'];
    $product = $args['product'];
    $clientId = $args['client_id'];
    $saleId = $args['sale_id'];

    $message = 'Nueva venta iniciada: ' . $product['name'];
    $metadata = [
      'action' => 'start_sale',
      'sale_id' => $saleId,
      'product_id' => $product['id'],
      'product_name' => $product['name'],
      'price' => $product['price'],
      'description' => $product['description'] ?? '',
      'instructions' => $product['config']['instructions'] ?? '',
      'templates' => $product['config']['messages']['templates'] ?? []
    ];

    // Registrar en BD primero
    $dbResult = chatHandlers::register(
      $bot['id'],
      $bot['number'],
      $clientId,
      $person['number'],
      $message,
      'S', // Sistema
      'text',
      $metadata,
      $saleId
    );

    // Si se registró en BD exitosamente, agregar a JSON
    if ($dbResult['success']) {
      $chatData = [
        'number' => $person['number'],
        'bot_id' => $bot['id'],
        'client_id' => $clientId,
        'sale_id' => $saleId,
        'product_id' => $product['id'],
        'product_name' => $product['name'],
        'message' => $message,
        'format' => 'text',
        'metadata' => $metadata
      ];

      return chatHandlers::addMessage($chatData, 'start_sale');
    }

    return false;
  }

  // Guardar conversación (mensaje del usuario) en BD y JSON
  static function saveConversation($args) {
    $bot = $args['bot'];
    $person = $args['person'];
    $message = $args['message'];
    $clientId = $args['client_id'];
    $saleId = $args['sale_id'] ?? 0;

    $text = $message['text'];
    $type = $message['type'];

    // Registrar en BD primero
    $dbResult = chatHandlers::register(
      $bot['id'],
      $bot['number'],
      $clientId,
      $person['number'],
      $text,
      'P', // Prospecto
      $type,
      null,
      $saleId
    );

    // Si se registró en BD exitosamente, agregar a JSON
    if ($dbResult['success']) {
      $chatData = [
        'number' => $person['number'],
        'bot_id' => $bot['id'],
        'client_id' => $clientId,
        'sale_id' => $saleId,
        'message' => $text,
        'format' => $type,
        'metadata' => null
      ];

      return chatHandlers::addMessage($chatData, 'p');
    }

    return false;
  }

  // Resolver mensaje (guardar y procesar lógica de conversación)
  static function resolve($args) {
    $bot = $args['bot'];
    $person = $args['person'];
    $message = $args['message'];
    $clientId = $args['client_id'];
    $saleId = $args['sale_id'];

    // 1. Procesar mensaje en buffer (acumular por X segundos)
    require_once APP_PATH . '/workflows/helpers/conversation/messageBuffer.php';
    $buffer = new messageBuffer(1);
    $result = $buffer->process($person['number'], $bot['id'], $message);

    if ($result === null) {
      log::debug('Mensaje agregado a buffer - Esperando más mensajes', [], ['module' => 'conversation']);
      return true;
    }

    // 2. Buffer completado - Interpretar todos los mensajes acumulados
    $messages = $result['messages'];
    $interpretedMessages = [];
    $rawMessages = [];

    log::info('Interpretando ' . count($messages) . ' mensajes acumulados', [], ['module' => 'conversation']);

    if(count($messages) > 0){
      require_once APP_PATH . '/workflows/helpers/conversation/messageInterpreter.php';
      require_once APP_PATH . '/workflows/helpers/conversation/promptBuilder.php';
    }

    foreach ($messages as $msg) {
      $rawMessages[] = $msg;
      $interpreted = messageInterpreter::interpret($msg, $bot);
      $interpretedMessages[] = $interpreted;
      log::debug('Mensaje interpretado', ['type' => $interpreted['type'], 'text_preview' => substr($interpreted['text'], 0, 50)], ['module' => 'conversation']);
    }

    // 3. Construir texto final para IA
    $aiText = self::buildAiText($interpretedMessages);

    log::success('Mensajes listos para IA', ['total' => count($messages), 'ai_text_length' => strlen($aiText)], ['module' => 'conversation']);

    // 4. Obtener chat completo para contexto
    $chat = chatHandlers::getChat($person['number'], $bot['id']);

    // 5. Archivo de prompt (quemado aquí - fácil de cambiar)
    $promcptFile = APP_PATH . '/workflows/prompts/infoproduct/recibo.txt';
    if (!file_exists($promcptFile)) {
      log::error('Archivo de prompt no encontrado', ['file' => $promcptFile], ['module' => 'conversation']);
      throw new Exception("Archivo de prompt no encontrado: {$promcptFile}"); // hay que usar throw para que el workflow capture el error
    } $promcptFile = file_get_contents($promcptFile);

    // 6. Prompt adicional del bot (se concatena al principal)
    $botPrompt = ! empty( $bot['personality'] ) ? "\n\n---\n\n## Personalidad Especifica\n" . $bot['personality'] : '';

    // 6.1 Prompt static + prompt bot
    $promptSystem = $promcptFile . $botPrompt;

    log::info('Usando prompt:', ['file' => basename($promptFile), 'has_bot_prompt' => !empty($botPrompt)], ['module' => 'conversation']);

    // 7. Construir prompt con caché ephemeral
    $prompt = promptBuilder::buildWithCache($bot, $chat, $aiText, $promptSystem);

    log::info('Prompt con caché construido', ['bloques' => count($prompt)], ['module' => 'conversation']);

    // 8. Guardar todos los mensajes en BD/JSON
    foreach ($rawMessages as $index => $msg) {
      self::saveConversation([
        'bot' => $bot,
        'person' => $person,
        'message' => $msg,
        'client_id' => $clientId,
        'sale_id' => $saleId
      ]);
    }

    // 9. Enviar a IA con prompt cacheado
    log::info('Enviando prompt a IA', ['bloques' => count($prompt)], ['module' => 'conversation']);

    try {
      $ai = service::integration('ai');
      $aiResponse = $ai->getChatCompletion($prompt, $bot, []);
      echo '<pre>$aiResponse:'; var_dump($aiResponse); echo '</pre>';exit;

      if ($aiResponse['success']) {
        log::success('Respuesta de IA recibida', [
          'provider' => $aiResponse['provider'] ?? 'unknown',
          'model' => $aiResponse['model'] ?? 'unknown',
          'tokens_used' => $aiResponse['tokens_used'] ?? 0,
          'cache_efficiency' => $aiResponse['usage']['cache_efficiency'] ?? '0%',
          'response_length' => strlen($aiResponse['response'] ?? '')
        ], ['module' => 'conversation']);

        log::info('=== RESPUESTA DE LA IA ===', ['response' => $aiResponse['response']], ['module' => 'conversation']);

        // TODO: Procesar respuesta JSON y enviar mensaje al cliente
      } else {
        log::error('Error en respuesta de IA', ['error' => $aiResponse['error'] ?? 'Unknown'], ['module' => 'conversation']);
      }
    } catch (Exception $e) {
      log::error('Excepción al llamar IA', ['error' => $e->getMessage()], ['module' => 'conversation']);
    }

    return true;
  }

  // Construir texto para IA desde mensajes interpretados
  private static function buildAiText($interpretedMessages) {
    $lines = [];

    foreach ($interpretedMessages as $msg) {
      $type = $msg['type'];
      $text = $msg['text'];
      $lines[] = "[{$type}]: {$text}";
    }

    return implode("\n", $lines);
  }
}