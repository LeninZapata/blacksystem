<?php
class workflowMessage {

  // Verificar si existe conversación activa (archivo JSON existe y está dentro del rango de días)
  static function hasActiveConversation($number, $botId, $maxDays = 1) {
    $chat = ChatHandlers::getChat($number, $botId, true);

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
    $chat = ChatHandlers::getChat($number, $botId, true);
    
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
      'instructions' => $product['config']['prompt'] ?? '',
      // 'templates' => $product['config']['messages']['templates'] ?? []
    ];

    // Registrar en BD primero
    $dbResult = ChatHandlers::register(
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
        'bot_mode' => $bot['mode'],
        'client_id' => $clientId,
        'sale_id' => $saleId,
        'product_id' => $product['id'],
        'product_name' => $product['name'],
        'message' => $message,
        'format' => 'text',
        'metadata' => $metadata
      ];

      return ChatHandlers::addMessage($chatData, 'start_sale');
    }

    return false;
  }

  // Guardar conversación (mensaje del usuario) en BD y JSON
  static function saveConversation($args, $message_type = 'P') {
    $bot = $args['bot'];
    $person = $args['person'];
    $message = $args['message'];
    $clientId = $args['client_id'];
    $saleId = $args['sale_id'] ?? 0;

    $text = $message['text'];
    $type = $message['type'];

    // Registrar en BD primero
    $dbResult = ChatHandlers::register(
      $bot['id'],
      $bot['number'],
      $clientId,
      $person['number'],
      $text,
      $message_type, // Prospecto, Bot , Sistema
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

      return ChatHandlers::addMessage($chatData, $message_type);
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
      $msg['from'] = $person['number']; // Asegurar que el campo 'from' esté presente
      $rawMessages[] = $msg;
      $interpreted = messageInterpreter::interpret($msg, $bot);

      if( $interpreted['type'] === 'image' ){
        // Si es imagen lo vamos a resolver aparte
        log::info('Mensaje de tipo imagen detectado - Procesando análisis de imagen', [], ['module' => 'conversation']);
        self::resolveImageMessage($person, $bot, $msg, $interpreted, $clientId, $saleId );
        return;
      }

      $interpretedMessages[] = $interpreted;
      log::debug('Mensaje interpretado', ['type' => $interpreted['type'], 'text_preview' => substr($interpreted['text'], 0, 50)], ['module' => 'conversation']);
    }

    // 2.1 Si entre los mensajes tengo un tipo de mensaje tipo "image" entonces no continuar a IA
    // necesitamos saber si es un recibo valido de pago y con eso validar el pago y enviar el producto
    log::info('=== RESPUESTA DE LA IA DE IMAGEN ===', ['response' => $aiResponse['response']], ['module' => 'conversation']);
    

    // 3. Construir texto final para IA
    $aiText = self::buildAiText($interpretedMessages);

    log::success('Mensajes listos para IA', ['total' => count($messages), 'ai_text_length' => strlen($aiText)], ['module' => 'conversation']);

    // 4. Obtener chat completo para contexto
    $chat = ChatHandlers::getChat($person['number'], $bot['id']);

    // 5. Archivo de prompt (quemado aquí - fácil de cambiar)
    // TODO: hacer que este archivo sea traido del bot
    $promptFile = APP_PATH . '/workflows/prompts/infoproduct/recibo.txt';
    if (!file_exists($promptFile)) {
      log::error('Archivo de prompt no encontrado', ['file' => $promptFile], ['module' => 'conversation']);
      throw new Exception("Archivo de prompt no encontrado: {$promptFile}"); // hay que usar throw para que el workflow capture el error
    } $promptFile = file_get_contents($promptFile);

    // 6. Prompt adicional del bot (se concatena al principal)
    $botPrompt = ! empty( $bot['personality'] ) ? "\n\n---\n\n## Personalidad Especifica\n" . $bot['personality'] : '';

    // 6.1 Prompt static + prompt bot
    $promptSystem = $promptFile . $botPrompt;

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

  private static function resolveImageMessage($person, $bot, $message, $interpreted, $clientId, $saleId ){ {
    if( $interpreted['success'] === false ){
      log::error('Error interpretando imagen', ['text' => $interpreted['text']], ['module' => 'conversation']);
      $message_error = "Lo siento, no pude procesar la imagen correctamente. Por favor, envíala nuevamente.";
      chatapi::send($message['from'] ?? null, $message_error);

      // Guardamos el mensaje del cliente
      self::saveConversation(['bot' => $bot, 'person' => $person, 'message' => $message, 'client_id' => $clientId, 'sale_id' =>  $saleId, 'P']);

      // Guardamos el mensaje de error en la conversación
      self::saveConversation(['bot' => $bot, 'person' => $person, 'message' => $message_error, 'client_id' => $clientId, 'sale_id' =>  $saleId, 'B']);
      return;
    }

    // Aquí puedes agregar la lógica para procesar la descripción de la imagen
    // Por ejemplo, verificar si es un recibo válido, enviar confirmaciones, etc.
  }
}