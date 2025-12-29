<?php

class PaymentStrategy implements ConversationStrategyInterface {
  private $logMeta = ['module' => 'PaymentStrategy', 'layer' => 'app/workflows'];
  public function execute(array $context): array {
    $bot = $context['bot'];
    $person = $context['person'];
    $imageAnalysis = $context['image_analysis'];
    $chatData = $context['chat_data'];

    require_once ogApp()->getPath() . '/workflows/infoproduct/validators/PaymentProofValidator.php';
    $validation = PaymentProofValidator::validate($imageAnalysis);

    // ✅ PASO 1: Guardar mensaje de imagen siempre (DB + JSON)
    $this->saveImageMessage($imageAnalysis, $context);

    // ✅ PASO 2: Detectar recibo duplicado (sin venta activa)
    $currentSale = $chatData['full_chat']['current_sale'] ?? null;
    $isProofPayment = $imageAnalysis['metadata']['description']['is_proof_payment'] ?? false;

    if (!$currentSale && $isProofPayment) {
      ogLog::info("execute - Recibo duplicado detectado", [ 'number' => $person['number'], 'current_sale' => 'null', 'is_proof_payment' => true, 'action' => 'invocar_ia_para_responder' ], $this->logMeta);
      // ✅ Invocar IA para que responda (igual que ActiveConversationStrategy)
      return $this->handleDuplicateReceipt($context, $imageAnalysis);
    }

    // ✅ PASO 3: Detectar imagen sin venta pendiente (NO es recibo o recibo inválido)
    if (!$currentSale && !$isProofPayment) {
      ogLog::info("execute - Imagen enviada sin venta pendiente", [ 'number' => $person['number'], 'current_sale' => 'null', 'is_proof_payment' => false, 'ai_analysis' => $imageAnalysis['metadata']['description'] ?? null, 'action' => 'dejar_que_ia_interprete' ], $this->logMeta);

      // ✅ Dejar que la IA interprete la imagen (no es contexto de pago)
      return $this->handleImageWithoutSale($context, $imageAnalysis);
    }

    // ✅ PASO 4: Validar recibo (solo si hay venta activa)
    if (!$validation['is_valid']) {
      $errorMessage = "Lo siento, no pude validar el comprobante de pago. Por favor, envía una foto clara del recibo.";

      ogLog::info("execute - Recibo inválido (con venta activa)", [ 'number' => $person['number'], 'current_sale' => $currentSale['sale_id'] ?? null, 'errors' => $validation['errors'], 'ai_analysis' => $validation['data'] ] , $this->logMeta );  // ✅ JSON completo de la IA ], $this->logMeta);

      $chatapi = ogApp()->service('chatApi');
      $chatapi::send($person['number'], $errorMessage);

      ogApp()->loadHandler('chatHandlers');
      chatHandlers::register(
        $bot['id'],
        $bot['number'],
        $chatData['client_id'],
        $person['number'],
        $errorMessage,
        'B',
        'text',
        ['action' => 'invalid_receipt_format', 'errors' => $validation['errors']],
        $chatData['sale_id']
      );

      return [
        'success' => false,
        'error' => 'Invalid payment proof',
        'validation' => $validation
      ];
    }

    $paymentData = $validation['data'];
    $saleId = $chatData['sale_id'];

    // ✅ PASO 5: Procesar pago (solo si hay venta activa)
    ogLog::info("execute - Procesando pago válido", [ 'sale_id' => $saleId, 'amount' => $paymentData['amount'] ], $this->logMeta);

    // 1. Actualizar venta (billed_amount)
    $this->updateSale($paymentData, $saleId);

    // 2. Actualizar cliente (nombre, total_purchases, amount_spent)
    $this->updateClient($chatData['client_id'], $paymentData, $validation);

    // 3. Registrar sale_confirmed (Sistema)
    $this->registerSaleConfirmed($bot, $person, $chatData, $paymentData, $validation);

    // 4. Procesar pago
    $this->processPayment($paymentData, $saleId);

    // 5. Entregar producto
    $this->deliverProduct($chatData, $context);

    // 6. Procesar upsell (cancelar followups + registrar upsell si aplica)
    $this->processUpsell($chatData, $bot);

    return [
      'success' => true,
      'payment_data' => $paymentData,
      'sale_id' => $saleId
    ];
  }

  /**
   * ✅ NUEVO: Manejar imagen sin venta pendiente
   * Deja que la IA interprete la imagen en contexto conversacional
   */
  private function handleImageWithoutSale($context, $imageAnalysis) {
    $bot = $context['bot'];
    $person = $context['person'];
    $chatData = $context['chat_data'];

    ogLog::info("handleImageWithoutSale - Invocando IA", [ 'number' => $person['number'] ], $this->logMeta);

    // Construir texto para la IA con descripción de la imagen
    $resume = $imageAnalysis['metadata']['description']['resume'] ?? 'Imagen recibida';
    $aiText = "[image]: {$resume}";

    // Recargar chat actualizado (ya tiene el mensaje de la imagen guardado)
    ogApp()->loadHandler('chatHandlers');
    $chat = ChatHandlers::getChat($person['number'], $bot['id'], true);

    // Construir prompt igual que ActiveConversationStrategy
    $prompt = $this->buildPrompt($bot, $chat, $aiText);

    ogLog::info("handleImageWithoutSale - Prompt construido", $prompt, $this->logMeta);

    // Llamar a la IA
    $aiResponse = $this->callAI($prompt, $bot);

    if (!$aiResponse['success']) {
      ogLog::error("handleImageWithoutSale - Error en IA: {$aiResponse['error']}", ['number' => $person['number']], $this->logMeta);
      return [
        'success' => false,
        'error' => $aiResponse['error'] ?? 'AI call failed'
      ];
    }

    // ✅ LOG DE RESPUESTA DE LA IA
    ogLog::info("handleImageWithoutSale - Respuesta de IA recibida", [ 'response_length' => strlen($aiResponse['response']), 'response_preview' => substr($aiResponse['response'], 0, 200) . '...' ], $this->logMeta);

    // Parsear respuesta
    $parsedResponse = $this->parseResponse($aiResponse['response']);

    if (!$parsedResponse) {
      ogLog::error("handleImageWithoutSale - JSON inválido de IA", ['raw_response' => $aiResponse['response']], $this->logMeta);
      return [
        'success' => false,
        'error' => 'Invalid AI response'
      ];
    }

    // ✅ LOG DE RESPUESTA PARSEADA
    ogLog::info("handleImageWithoutSale - Respuesta parseada correctamente", [ 'message_length' => strlen($parsedResponse['message'] ?? ''), 'message_preview' => substr($parsedResponse['message'] ?? '', 0, 150), 'has_metadata' => isset($parsedResponse['metadata']), 'action' => $parsedResponse['metadata']['action'] ?? 'none' ], $this->logMeta);

    // Enviar mensaje al cliente
    $this->sendMessages($parsedResponse, $context);

    // Guardar respuesta del bot (DB + JSON)
    $this->saveBotMessages($parsedResponse, $context);

    ogLog::info("handleImageWithoutSale - Completado exitosamente", [], $this->logMeta);

    return [
      'success' => true,
      'image_without_sale' => true,
      'ai_response' => $parsedResponse
    ];
  }

  /**
   * ✅ NUEVO: Manejar recibo duplicado (sin venta activa)
   * Solo guarda en chat y hace que la IA responda
   */
  private function handleDuplicateReceipt($context, $imageAnalysis) {
    $bot = $context['bot'];
    $person = $context['person'];
    $chatData = $context['chat_data'];
    ogLog::info("handleDuplicateReceipt - Invocando IA", [ 'number' => $person['number'] ], $this->logMeta);

    // Construir texto para la IA
    $resume = $imageAnalysis['metadata']['description']['resume'] ?? 'Recibo de pago';
    $aiText = "[image]: {$resume}";

    // Recargar chat actualizado (ya tiene el mensaje de la imagen guardado)
    ogApp()->loadHandler('chatHandlers');
    $chat = ChatHandlers::getChat($person['number'], $bot['id'], true);

    // Construir prompt igual que ActiveConversationStrategy
    $prompt = $this->buildPrompt($bot, $chat, $aiText);

    ogLog::info("handleDuplicateReceipt - Prompt construido", [], $this->logMeta);

    // Llamar a la IA
    $aiResponse = $this->callAI($prompt, $bot);

    if (!$aiResponse['success']) {
      ogLog::error("handleDuplicateReceipt - Error en IA", [ 'error' => $aiResponse['error'] ?? 'unknown' ], $this->logMeta);

      return [
        'success' => false,
        'error' => $aiResponse['error'] ?? 'AI call failed'
      ];
    }

    // ✅ LOG DE RESPUESTA DE LA IA
    ogLog::info("handleDuplicateReceipt - Respuesta de IA recibida", [ 'response_length' => strlen($aiResponse['response']), 'response_preview' => substr($aiResponse['response'], 0, 200) . '...' ], $this->logMeta );

    // Parsear respuesta
    $parsedResponse = $this->parseResponse($aiResponse['response']);

    if (!$parsedResponse) {
      ogLog::error("handleDuplicateReceipt - JSON inválido de IA", [ 'raw_response' => $aiResponse['response'] ], $this->logMeta);

      return [
        'success' => false,
        'error' => 'Invalid AI response'
      ];
    }

    // ✅ LOG DE RESPUESTA PARSEADA
    ogLog::info("handleDuplicateReceipt - Respuesta parseada correctamente", [ 'message_length' => strlen($parsedResponse['message'] ?? ''), 'message_preview' => substr($parsedResponse['message'] ?? '', 0, 150), 'has_metadata' => isset($parsedResponse['metadata']), 'action' => $parsedResponse['metadata']['action'] ?? 'none' ], $this->logMeta);

    // Enviar mensaje al cliente
    $this->sendMessages($parsedResponse, $context);

    // Guardar respuesta del bot (DB + JSON)
    $this->saveBotMessages($parsedResponse, $context);

    ogLog::info("handleDuplicateReceipt - Completado exitosamente", [], $this->logMeta);

    return [
      'success' => true,
      'duplicate_receipt' => true,
      'ai_response' => $parsedResponse
    ];
  }

  private function saveImageMessage($imageAnalysis, $context) {
    $bot = $context['bot'];
    $person = $context['person'];
    $chatData = $context['chat_data'];

    // ✅ Guardar JSON completo del análisis
    $description = $imageAnalysis['metadata']['description'] ?? [];
    $messageJson = json_encode($description, JSON_UNESCAPED_UNICODE);

    ogApp()->loadHandler('chatHandlers');
    chatHandlers::register(
      $bot['id'],
      $bot['number'],
      $chatData['client_id'],
      $person['number'],
      $messageJson,  // ✅ JSON completo
      'P',
      'image',
      $imageAnalysis['metadata'],
      $chatData['sale_id']
    );

    chatHandlers::addMessage([
      'number' => $person['number'],
      'bot_id' => $bot['id'],
      'client_id' => $chatData['client_id'],
      'sale_id' => $chatData['sale_id'],
      'message' => $messageJson,  // ✅ JSON completo
      'format' => 'image',
      'metadata' => $imageAnalysis['metadata']
    ], 'P');
  }

  private function updateSale($paymentData, $saleId) {
    $billedAmount = $paymentData['amount'] ?? null;

    if ($billedAmount && $billedAmount > 0) {
      ogDb::table('sales')->where('id', $saleId)->update([
          'billed_amount' => $billedAmount,
          'du' => date('Y-m-d H:i:s'),
          'tu' => time()
        ]);
      ogLog::info("updateSale - Venta actualizada: billed_amount = {$billedAmount}", [ 'sale_id' => $saleId ], $this->logMeta);
    }
  }

  private function updateClient($clientId, $paymentData, $validation) {
    $client = ogDb::table('clients')->find($clientId);

    if (!$client) {
      ogLog::error("updateClient - Cliente no encontrado", [ 'client_id' => $clientId ], $this->logMeta);
      return false;
    }

    $updates = [
      'du' => date('Y-m-d H:i:s'),
      'tu' => time()
    ];

    // 1. Actualizar nombre si aplica (lógica flexible)
    $receiptName = $paymentData['name'] ?? null;
    $validName = $validation['valid_name'] ?? false;
    $wordCount = $receiptName ? str_word_count($receiptName) : 0;
    $hasValidName = $validName || ($wordCount >= 2);

    if ($hasValidName && $receiptName && !empty($receiptName)) {
      $currentName = $client['name'] ?? '';

      if (empty($currentName) || strlen($receiptName) > strlen($currentName)) {
        $updates['name'] = $receiptName;
        ogLog::info("updateClient - Nombre actualizado: '{$currentName}' → '{$receiptName}'", [ 'client_id' => $clientId, 'reason' => empty($currentName) ? 'empty_name' : 'longer_name' ], $this->logMeta);
      }
    }

    // 2. Incrementar total_purchases
    $updates['total_purchases'] = ($client['total_purchases'] ?? 0) + 1;

    // 3. Sumar amount_spent (usar billed_amount del recibo)
    $billedAmount = $paymentData['amount'] ?? 0;
    $updates['amount_spent'] = ($client['amount_spent'] ?? 0) + $billedAmount;

    // Ejecutar UPDATE en BD
    try {
      ogDb::table('clients')->where('id', $clientId)->update($updates);
      ogLog::info("updateClient - Cliente actualizado correctamente", [ 'client_id' => $clientId, 'name_updated' => isset($updates['name']), 'total_purchases' => $updates['total_purchases'], 'amount_spent' => $updates['amount_spent'] ], $this->logMeta);
      return true;

    } catch (Exception $e) {
      ogLog::error("updateClient - Error al actualizar", [ 'client_id' => $clientId, 'error' => $e->getMessage() ], $this->logMeta);
      return false;
    }
  }

  private function registerSaleConfirmed($bot, $person, $chatData, $paymentData, $validation) {
    $receiptData = $validation['data'];

    $message = "Venta confirmada - Recibo validado correctamente";
    $metadata = [
      'action' => 'sale_confirmed',
      'sale_id' => $chatData['sale_id'],
      'receipt_data' => [
        'amount_found' => $receiptData['amount'] ?? 0,
        'name_found' => $receiptData['name'] ?? '',
        'valid_name' => $validation['valid_name'] ?? false,
        'valid_amount' => $validation['valid_amount'] ?? false,
        'resume' => $receiptData['resume'] ?? ''
      ],
      'updates' => [
        'billed_amount_updated' => $receiptData['amount'] ?? 0,
        'client_name_updated' => ($validation['valid_name'] ?? false) && !empty($receiptData['name'] ?? '')
      ]
    ];

    // Guardar en DB
    ogApp()->loadHandler('chatHandlers');
    chatHandlers::register(
      $bot['id'],
      $bot['number'],
      $chatData['client_id'],
      $person['number'],
      $message,
      'S',
      'text',
      $metadata,
      $chatData['sale_id']
    );

    // Guardar en JSON
    chatHandlers::addMessage([
      'number' => $person['number'],
      'bot_id' => $bot['id'],
      'client_id' => $chatData['client_id'],
      'sale_id' => $chatData['sale_id'],
      'message' => $message,
      'format' => 'text',
      'metadata' => $metadata
    ], 'S');
  }

  private function processPayment($paymentData, $saleId) {
    ogApp()->loadHandler('saleHandlers');
    saleHandlers::updateStatus($saleId, 'completed');

    saleHandlers::registerPayment(
      $saleId,
      'RECEIPT_' . time(),
      'Recibo de pago',
      date('Y-m-d H:i:s')
    );
  }

  private function deliverProduct($chatData, $context) {
    $productId = $chatData['full_chat']['current_sale']['product_id'] ?? null;
    if ($productId) {
      require_once ogApp()->getPath() . '/workflows/infoproduct/actions/DeliverProductAction.php';
      DeliverProductAction::send($productId, $context);
    }
  }

  // ✅ MÉTODOS COMPARTIDOS CON ActiveConversationStrategy

  private function buildPrompt($bot, $chat, $aiText) {

    $promptFile = ogApp()->getPath()  . '/workflows/prompts/infoproduct/recibo.txt';

    if (!file_exists($promptFile)) {
      ogLog::throwError("buildPrompt - Prompt file not found: {$promptFile}", [], self::$logMeta);
    }

    $promptSystem = file_get_contents($promptFile);
    $botPrompt = !empty($bot['personality']) ? "\n\n---\n\n## Personalidad Especifica\n" . $bot['personality'] : '';
    $promptSystem .= $botPrompt;

    require_once ogApp()->getPath()  . '/workflows/core/builders/PromptBuilder.php';
    return PromptBuilder::buildWithCache($bot, $chat, $aiText, $promptSystem);
  }

  private function callAI($prompt, $bot) {
    try {
      $ai = ogApp()->service('ai');
      return $ai->getChatCompletion($prompt, $bot, []);
    } catch (Exception $e) {
      return [
        'success' => false,
        'error' => $e->getMessage()
      ];
    }
  }

  private function parseResponse($response) {
    $decoded = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
      return null;
    }

    return $decoded;
  }

  private function sendMessages($parsedResponse, $context) {
    $person = $context['person'];
    $message = $parsedResponse['message'] ?? '';
    $sourceUrl = $parsedResponse['source_url'] ?? '';

    if (!empty($message)) {
      $chatapi = ogApp()->service('chatApi');
      $chatapi::send($person['number'], $message, $sourceUrl);
    }
  }

  private function saveBotMessages($parsedResponse, $context) {
    $bot = $context['bot'];
    $person = $context['person'];
    $chatData = $context['chat_data'];

    $message = $parsedResponse['message'] ?? '';
    $metadata = $parsedResponse['metadata'] ?? null;

    ogApp()->loadHandler('chatHandlers');
    ChatHandlers::register(
      $bot['id'],
      $bot['number'],
      $chatData['client_id'],
      $person['number'],
      $message,
      'B',
      'text',
      $metadata,
      $chatData['sale_id']
    );

    ChatHandlers::addMessage([
      'number' => $person['number'],
      'bot_id' => $bot['id'],
      'client_id' => $chatData['client_id'],
      'sale_id' => $chatData['sale_id'],
      'message' => $message,
      'format' => 'text',
      'metadata' => $metadata
    ], 'B');
  }

  private function processUpsell($chatData, $bot) {
    $currentSale = $chatData['full_chat']['current_sale'] ?? null;
    if (!$currentSale) return;

    $saleData = [
      'sale_id' => $currentSale['sale_id'],
      'product_id' => $currentSale['product_id'],
      'client_id' => $chatData['client_id'],
      'bot_id' => $bot['id'],
      'number' => $chatData['full_chat']['number'],
      'origin' => $currentSale['origin'] ?? 'organic'
    ];

    $botTimezone = $bot['config']['timezone'] ?? 'America/Guayaquil';

    ogApp()->loadHandler('UpsellHandlers');
    UpsellHandlers::processAfterSale($saleData, $botTimezone);
  }
}