<?php

// Cargar dependencias core (usando ogApp()->getPath() directamente aquí porque es antes de la clase)
$_APP_PATH = ogApp()->getPath();

require_once $_APP_PATH . '/workflows/core/events/ActionDispatcher.php';
require_once $_APP_PATH . '/workflows/core/events/ActionRegistry.php';
require_once $_APP_PATH . '/workflows/core/events/ActionHandler.php';
require_once $_APP_PATH . '/workflows/core/support/MessageClassifier.php';
require_once $_APP_PATH . '/workflows/core/support/MessageBuffer.php';
require_once $_APP_PATH . '/workflows/core/validators/ConversationValidator.php';
require_once $_APP_PATH . '/workflows/core/validators/WelcomeValidator.php';

// Cargar action handlers
require_once $_APP_PATH . '/workflows/infoproduct/actions/DoesNotWantProductAction.php';

class InfoproductV2Handler {

  private $logMeta = ['module' => 'InfoproductV2Handler', 'layer' => 'app/workflows'];

  private $actionDispatcher;
  private $maxConversationDays = 2;
  private $bufferDelay = OG_IS_DEV ? 3 : 7;
  private $appPath;  // Path dinámico del plugin

  // Prompts personalizados
  private $prompt_recibo = 'recibo.txt';
  private $prompt_recibo_imagen = 'recibo-img.txt';

  // Configuración de followups
  private $followupStartHour = 8;
  private $followupEndHour = 22;
  private $followupMinutesBefore = 15;
  private $followupMinutesAfter = 15;

  public function __construct() {
    ogLog::info("__construct - Inicio", [], $this->logMeta);

    // Guardar path del plugin como propiedad
    $this->appPath = ogApp()->getPath();
    $this->actionDispatcher = new ActionDispatcher();

    // Cargar FollowupHandler bajo demanda
    ogApp()->loadHandler('followup');

    // Configurar horarios y variación para followups
    FollowupHandler::setAllowedHours($this->followupStartHour, $this->followupEndHour);
    FollowupHandler::setMinutesVariation($this->followupMinutesBefore, $this->followupMinutesAfter);
    $this->registerActionHandlers();

    ogLog::info("__construct - ActionHandlers registrados", [], $this->logMeta);
  }

  private function registerActionHandlers() {
    $registry = $this->actionDispatcher->getRegistry();
    $registry->register('does_not_want_the_product', 'DoesNotWantProductAction');
  }

  public function handle($webhook) {
    ogLog::info("handle - Inicio", $webhook,  $this->logMeta);exit;
    $standard = $webhook['standard'] ?? [];
    $bot = $standard['sender'] ?? [];
    $person = $standard['person'] ?? [];
    $message = $standard['message'] ?? [];
    $context = $standard['context'] ?? [];
    $botNumber = $bot['number'] ?? null;

    if (!$botNumber) {
      ogLog::throwError("handle - Bot number missing in webhook", $bot ?? null, $this->logMeta);
    }

    // Cargar BotHandler bajo demanda
    ogApp()->loadHandler('bot');
    $botData = BotHandler::getDataFile($botNumber);

    if (!$botData) {
      ogLog::throwError("handle - data not found: {$botNumber}", [], $this->logMeta);
    }

    $bot = array_merge($bot, $botData);
    ogApp()->helper('cache')::memorySet('current_bot', $bot);

    // ESTABLECER user_id GLOBALMENTE EN ChatHandler
    $userId = $bot['user_id'] ?? null;
    if ($userId) {
      ogApp()->handler('chat')::setUserId($userId);
      ogApp()->handler('followup')::setUserId($userId);
      ogLog::info("handle - user_id establecido globalmente", [ 'user_id' => $userId, 'bot_id' => $bot['id'] ], $this->logMeta);
    } else {
      ogLog::warning("handle - Bot sin user_id", [ 'bot_id' => $bot['id'] ?? 'N/A', 'bot_number' => $botNumber ], $this->logMeta);
    }

    // Agregar prompts personalizados al array $bot
    $bot['prompt_recibo'] = $this->prompt_recibo;
    $bot['prompt_reccibo_imagen'] = $this->prompt_recibo_imagen;
    ogLog::info("handle - Bot data cargado", [ 'bot_id' => $bot['id'] ?? 'N/A' ], $this->logMeta );

    $messageType = MessageClassifier::classify($message);
    ogLog::info("handle - Mensaje clasificado", [ 'type' => $messageType, 'person number' => $person['number'] ?? 'N/A' ], $this->logMeta);

    $hasConversation = ConversationValidator::quickCheck( $person['number'], $bot['id'], $this->maxConversationDays );
    // $context['chat'] = $hasConversation['chat'];
    ogLog::info("handle - Conversación activa verificada", [ 'has_conversation' => $hasConversation ], $this->logMeta);

    // PRIORIDAD 1: Detectar welcome SIEMPRE (incluso con conversación activa)
    ogLog::info("handle - Detectando welcome", [], $this->logMeta);
    $welcomeCheck = WelcomeValidator::detect($bot, $message, $context);
    ogLog::info("handle - Resultado de welcome detection", [ 'welcome_check' => $welcomeCheck ], $this->logMeta);

    if ( ($welcomeCheck['is_welcome'] && !$hasConversation['exists']) || ($welcomeCheck['is_welcome_diff_product'] && $hasConversation['exists']) ) {
      ogLog::info("handle - Welcome detectado ➜ Ejecutar welcome", [ 'product_id' => $welcomeCheck['product_id'], 'has_active_conversation' => $hasConversation ], $this->logMeta);
      $this->executeWelcome($bot, $person, $message, $context, $welcomeCheck);
      return;
    }

    // PRIORIDAD 2: Continuar conversación (si no es welcome)
    if ( $hasConversation['exists'] ?? false ) {
      ogLog::info("handle - No es welcome ➜ Continuar conversación", [], $this->logMeta);
      $this->continueConversation($bot, $person, $message, $messageType);
      return;
    }

    ogLog::info("No hacer nada, no hay conversacion iniciada y no es un welcome", [
      'number' => $person['number']
    ], ['module' => 'infoproduct_v2']);
  }

  private function continueConversation($bot, $person, $message, $messageType) {
    ogLog::info("continueConversation - INICIO", [], ['module' => 'infoproduct_v2']);

    $chatData = ConversationValidator::getChatData($person['number'], $bot['id']);

    // BYPASS del buffer para imágenes (respuesta inmediata)
    $isImage = strtoupper($messageType) === 'IMAGE';

    if ($isImage) {
      ogLog::info("continueConversation - Imagen detectada, procesando SIN buffer", [
        'number' => $person['number'],
        'bypass_reason' => 'image_type'
      ], ['module' => 'infoproduct_v2']);

      $this->processImageMessages([$message], $bot, $person, $chatData);
      return;
    }

    // VALIDACIÓN: Rechazar documentos
    $isDocument = strtoupper($messageType) === 'DOCUMENT';

    if ($isDocument) {
      ogLog::info("continueConversation - Documento detectado, rechazando", [
        'number' => $person['number'],
        'reject_reason' => 'document_not_supported'
      ], ['module' => 'infoproduct_v2']);

      $this->processDocumentMessages([$message], $bot, $person, $chatData);
      return;
    }

    // VALIDACIÓN: Procesar videos
    $isVideo = strtoupper($messageType) === 'VIDEO';

    if ($isVideo) {
      ogLog::info("continueConversation - Video detectado", [
        'number' => $person['number']
      ], ['module' => 'infoproduct_v2']);

      $this->processVideoMessages([$message], $bot, $person, $chatData);
      return;
    }

    // VALIDACIÓN: Rechazar stickers
    $isSticker = strtoupper($messageType) === 'STICKER';

    if ($isSticker) {
      ogLog::info("continueConversation - Sticker detectado, rechazando", [
        'number' => $person['number']
      ], ['module' => 'infoproduct_v2']);

      $this->processStickerMessages([$message], $bot, $person, $chatData);
      return;
    }

    // VALIDACIÓN: Ignorar reactions
    $isReaction = strtoupper($messageType) === 'REACTION';

    if ($isReaction) {
      ogLog::info("continueConversation - Reaction detectada, ignorando", [
        'number' => $person['number']
      ], ['module' => 'infoproduct_v2']);

      $this->processReactionMessages([$message], $bot, $person, $chatData);
      return;
    }

    // Para texto/audio: usar buffer normal (3 segundos)
    ogLog::info("continueConversation - Mensaje de texto/audio, usando buffer", [
      'type' => $messageType,
      'delay' => $this->bufferDelay
    ], ['module' => 'infoproduct_v2']);

    // Usar MessageBuffer para procesar mensajes con delay
    $buffer = new MessageBuffer($this->bufferDelay);
    $result = $buffer->process($person['number'], $bot['id'], $message);

    if (!$result) {
      ogLog::info("continueConversation - Buffer activo, esperando más mensajes", [], ['module' => 'infoproduct_v2']);
      return;
    }

    $messages = $result['messages'];
    $hasImage = MessageClassifier::hasImageInMessages($messages);

    ogLog::info("continueConversation - Buffer completado, procesando mensajes", [
      'has_image' => $hasImage,
      'message_count' => count($messages)
    ], ['module' => 'infoproduct_v2']);

    if ($hasImage) {
      $this->processImageMessages($messages, $bot, $person, $chatData);
    } else {
      $this->processTextMessages($messages, $bot, $person, $chatData);
    }
  }

  private function processImageMessages($messages, $bot, $person, $chatData) {
    ogLog::info("processImageMessages - INICIO", [], ['module' => 'infoproduct_v2']);

    require_once $this->appPath . '/workflows/infoproduct/processors/MessageProcessorInterface.php';
    require_once $this->appPath . '/workflows/infoproduct/processors/ImageMessageProcessor.php';
    require_once $this->appPath . '/workflows/infoproduct/strategies/ConversationStrategyInterface.php';
    require_once $this->appPath . '/workflows/infoproduct/strategies/PaymentStrategy.php';

    $processor = new ImageMessageProcessor();
    $result = $processor->process($messages, [
      'bot' => $bot,
      'person' => $person,
      'chat_data' => $chatData
    ]);

    if (!$result['success']) {
      ogLog::error("Image processing failed", [
        'error' => $result['error'] ?? 'Unknown'
      ], ['module' => 'infoproduct_v2']);
      return;
    }

    $strategy = new PaymentStrategy();
    $strategy->execute([
      'bot' => $bot,
      'person' => $person,
      'image_analysis' => $result['analysis'],
      'chat_data' => $chatData
    ]);
  }

  private function processDocumentMessages($messages, $bot, $person, $chatData) {
    ogLog::info("processDocumentMessages - INICIO", [], ['module' => 'infoproduct_v2']);

    require_once $this->appPath . '/workflows/infoproduct/processors/MessageProcessorInterface.php';
    require_once $this->appPath . '/workflows/infoproduct/processors/DocumentMessageProcessor.php';

    $processor = new DocumentMessageProcessor();
    $result = $processor->process($messages, [
      'bot' => $bot,
      'person' => $person,
      'chat_data' => $chatData
    ]);

    if (!$result['success']) {
      ogLog::error("Document processing failed", [
        'error' => $result['error'] ?? 'Unknown'
      ], ['module' => 'infoproduct_v2']);
      return;
    }

    ogLog::info("processDocumentMessages - Documento rechazado exitosamente", [
      'caption' => $result['caption'] ?? ''
    ], ['module' => 'infoproduct_v2']);
  }

  private function processVideoMessages($messages, $bot, $person, $chatData) {
    ogLog::info("processVideoMessages - INICIO", [], ['module' => 'infoproduct_v2']);

    require_once $this->appPath . '/workflows/infoproduct/processors/MessageProcessorInterface.php';
    require_once $this->appPath . '/workflows/infoproduct/processors/VideoMessageProcessor.php';

    $processor = new VideoMessageProcessor();
    $result = $processor->process($messages, [
      'bot' => $bot,
      'person' => $person,
      'chat_data' => $chatData
    ]);

    if (!$result['success']) {
      ogLog::error("Video processing failed", [
        'error' => $result['error'] ?? 'Unknown'
      ], ['module' => 'infoproduct_v2']);
      return;
    }

    // Si el video NO tiene caption → No responder
    if (isset($result['no_response']) && $result['no_response']) {
      ogLog::info("processVideoMessages - Video sin caption registrado, sin respuesta", [], ['module' => 'infoproduct_v2']);
      return;
    }

    // Si tiene caption → Procesar como texto con IA
    ogLog::info("processVideoMessages - Video con caption, procesando con IA", [], ['module' => 'infoproduct_v2']);

    require_once $this->appPath . '/workflows/infoproduct/strategies/ConversationStrategyInterface.php';
    require_once $this->appPath . '/workflows/infoproduct/strategies/ActiveConversationStrategy.php';

    $strategy = new ActiveConversationStrategy();
    $strategyResult = $strategy->execute([
      'bot' => $bot,
      'person' => $person,
      'processed_data' => $result,
      'chat_data' => $chatData
    ]);

    if ($strategyResult['success'] && isset($strategyResult['ai_response']['metadata']['action'])) {
      $action = $strategyResult['ai_response']['metadata']['action'];

      $this->actionDispatcher->dispatch($action, [
        'bot' => $bot,
        'person' => $person,
        'ai_response' => $strategyResult['ai_response'],
        'chat_data' => $chatData,
        'metadata' => $strategyResult['ai_response']['metadata']
      ]);
    }
  }

  private function processStickerMessages($messages, $bot, $person, $chatData) {
    ogLog::info("processStickerMessages - INICIO", [], ['module' => 'infoproduct_v2']);

    require_once $this->appPath . '/workflows/infoproduct/processors/MessageProcessorInterface.php';
    require_once $this->appPath . '/workflows/infoproduct/processors/StickerMessageProcessor.php';

    $processor = new StickerMessageProcessor();
    $result = $processor->process($messages, [
      'bot' => $bot,
      'person' => $person,
      'chat_data' => $chatData
    ]);

    if (!$result['success']) {
      ogLog::error("Sticker processing failed", [
        'error' => $result['error'] ?? 'Unknown'
      ], ['module' => 'infoproduct_v2']);
      return;
    }

    ogLog::info("processStickerMessages - Sticker rechazado exitosamente", [], ['module' => 'infoproduct_v2']);
  }

  private function processReactionMessages($messages, $bot, $person, $chatData) {
    ogLog::info("processReactionMessages - INICIO", [], ['module' => 'infoproduct_v2']);

    require_once $this->appPath . '/workflows/infoproduct/processors/MessageProcessorInterface.php';
    require_once $this->appPath . '/workflows/infoproduct/processors/ReactionMessageProcessor.php';

    $processor = new ReactionMessageProcessor();
    $result = $processor->process($messages, [
      'bot' => $bot,
      'person' => $person,
      'chat_data' => $chatData
    ]);

    if (!$result['success']) {
      ogLog::error("Reaction processing failed", [
        'error' => $result['error'] ?? 'Unknown'
      ], ['module' => 'infoproduct_v2']);
      return;
    }

    ogLog::info("processReactionMessages - Reaction ignorada exitosamente", [], ['module' => 'infoproduct_v2']);
  }

  private function processTextMessages($messages, $bot, $person, $chatData) {
    ogLog::info("processTextMessages - INICIO", [], ['module' => 'infoproduct_v2']);

    require_once $this->appPath . '/workflows/infoproduct/processors/MessageProcessorInterface.php';
    require_once $this->appPath . '/workflows/infoproduct/processors/TextMessageProcessor.php';
    require_once $this->appPath . '/workflows/infoproduct/strategies/ConversationStrategyInterface.php';
    require_once $this->appPath . '/workflows/infoproduct/strategies/ActiveConversationStrategy.php';

    $processor = new TextMessageProcessor();
    $processedData = $processor->process($messages, [
      'bot' => $bot,
      'person' => $person,
      'chat_data' => $chatData
    ]);

    $strategy = new ActiveConversationStrategy();
    $result = $strategy->execute([
      'bot' => $bot,
      'person' => $person,
      'processed_data' => $processedData,
      'chat_data' => $chatData
    ]);

    if ($result['success'] && isset($result['ai_response']['metadata']['action'])) {
      $action = $result['ai_response']['metadata']['action'];

      $this->actionDispatcher->dispatch($action, [
        'bot' => $bot,
        'person' => $person,
        'ai_response' => $result['ai_response'],
        'chat_data' => $chatData,
        'metadata' => $result['ai_response']['metadata']
      ]);
    }
  }

  private function executeWelcome($bot, $person, $message, $context, $welcomeCheck) {
    require_once $this->appPath . '/workflows/infoproduct/strategies/ConversationStrategyInterface.php';
    require_once $this->appPath . '/workflows/infoproduct/strategies/WelcomeStrategy.php';

    $strategy = new WelcomeStrategy();
    $strategy->execute([ 'bot' => $bot, 'person' => $person, 'message' => $message, 'context' => $context, 'product_id' => $welcomeCheck['product_id'] ]);
    ogLog::info("executeWelcome - FIN", [], $this->logMeta);
  }
}