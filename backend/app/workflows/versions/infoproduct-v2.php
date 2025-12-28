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

  private $actionDispatcher;
  private $maxConversationDays = 2;
  private $bufferDelay = 3;
  private $appPath;  // Path dinámico del plugin

  // Configuración de followups
  private $followupStartHour = 8;
  private $followupEndHour = 22;
  private $followupMinutesBefore = 15;
  private $followupMinutesAfter = 15;

  public function __construct() {
    ogLog::debug("InfoproductV2Handler::__construct - INICIO", [], ['module' => 'infoproduct_v2']);

    // Guardar path del plugin como propiedad
    $this->appPath = ogApp()->getPath();

    $this->actionDispatcher = new ActionDispatcher();

    ogLog::debug("InfoproductV2Handler::__construct - ActionDispatcher creado", [], ['module' => 'infoproduct_v2']);

    // Cargar FollowupHandlers bajo demanda
    ogApp()->loadHandler('FollowupHandlers');
    
    // Configurar horarios y variación para followups
    FollowupHandlers::setAllowedHours($this->followupStartHour, $this->followupEndHour);
    FollowupHandlers::setMinutesVariation($this->followupMinutesBefore, $this->followupMinutesAfter);

    $this->registerActionHandlers();

    ogLog::debug("InfoproductV2Handler::__construct - Handlers registrados", [], ['module' => 'infoproduct_v2']);
  }

  private function registerActionHandlers() {
    ogLog::debug("InfoproductV2Handler::registerActionHandlers - INICIO", [], ['module' => 'infoproduct_v2']);

    $registry = $this->actionDispatcher->getRegistry();

    $registry->register('does_not_want_the_product', 'DoesNotWantProductAction');

    ogLog::debug("InfoproductV2Handler::registerActionHandlers - FIN", [], ['module' => 'infoproduct_v2']);
  }

  public function handle($webhook) {
    ogLog::info("InfoproductV2Handler::handle - INICIO", [], ['module' => 'infoproduct_v2']);

    $standard = $webhook['standard'] ?? [];
    ogLog::debug("InfoproductV2Handler::handle - Standard extraído", [
      'has_standard' => !empty($standard)
    ], ['module' => 'infoproduct_v2']);

    $bot = $standard['sender'] ?? [];
    $person = $standard['person'] ?? [];
    $message = $standard['message'] ?? [];
    $context = $standard['context'] ?? [];

    ogLog::debug("InfoproductV2Handler::handle - Datos extraídos", [
      'bot_number' => $bot['number'] ?? 'N/A',
      'person_number' => $person['number'] ?? 'N/A',
      'message_type' => $message['type'] ?? 'N/A'
    ], ['module' => 'infoproduct_v2']);

    $botNumber = $bot['number'] ?? null;

    if (!$botNumber) {
      ogLog::error("Bot number not found in webhook", [], ['module' => 'infoproduct_v2']);
      return;
    }

    ogLog::debug("InfoproductV2Handler::handle - Cargando bot data", [
      'bot_number' => $botNumber
    ], ['module' => 'infoproduct_v2']);

    // Cargar BotHandlers bajo demanda
    ogApp()->loadHandler('BotHandlers');
    $botData = BotHandlers::getDataFile($botNumber);

    if (!$botData) {
      ogLog::error("Bot data not found: {$botNumber}", [], ['module' => 'infoproduct_v2']);
      return;
    }

    ogLog::debug("InfoproductV2Handler::handle - Bot data cargado", [
      'bot_id' => $botData['id'] ?? 'N/A'
    ], ['module' => 'infoproduct_v2']);

    $bot = array_merge($bot, $botData);

    ogLog::debug("InfoproductV2Handler::handle - Clasificando mensaje", [], ['module' => 'infoproduct_v2']);

    $messageType = MessageClassifier::classify($message);

    ogLog::debug("InfoproductV2Handler::handle - Mensaje clasificado", [
      'type' => $messageType
    ], ['module' => 'infoproduct_v2']);

    ogLog::debug("InfoproductV2Handler::handle - Verificando conversación activa", [
      'number' => $person['number'],
      'bot_id' => $bot['id']
    ], ['module' => 'infoproduct_v2']);

    $hasConversation = ConversationValidator::quickCheck(
      $person['number'],
      $bot['id'],
      $this->maxConversationDays
    );

    ogLog::debug("InfoproductV2Handler::handle - Conversación verificada", [
      'has_conversation' => $hasConversation
    ], ['module' => 'infoproduct_v2']);

    // PRIORIDAD 1: Detectar welcome SIEMPRE (incluso con conversación activa)
    ogLog::debug("InfoproductV2Handler::handle - Detectando welcome", [], ['module' => 'infoproduct_v2']);
    $welcomeCheck = WelcomeValidator::detect($bot, $message, $context);

    ogLog::debug("InfoproductV2Handler::handle - Welcome detectado", [
      'is_welcome' => $welcomeCheck['is_welcome'],
      'product_id' => $welcomeCheck['product_id'] ?? null
    ], ['module' => 'infoproduct_v2']);

    if ($welcomeCheck['is_welcome']) {
      ogLog::info("InfoproductV2Handler::handle - Ejecutar welcome", [
        'product_id' => $welcomeCheck['product_id'],
        'has_active_conversation' => $hasConversation
      ], ['module' => 'infoproduct_v2']);

      $this->executeWelcome($bot, $person, $message, $context, $welcomeCheck);
      return;
    }

    // PRIORIDAD 2: Continuar conversación (si no es welcome)
    if ($hasConversation) {
      ogLog::info("InfoproductV2Handler::handle - Continuar conversación", [], ['module' => 'infoproduct_v2']);
      $this->continueConversation($bot, $person, $message, $messageType);
      return;
    }

    ogLog::info("No action taken - No conversation and not welcome", [
      'number' => $person['number']
    ], ['module' => 'infoproduct_v2']);
  }

  private function continueConversation($bot, $person, $message, $messageType) {
    ogLog::info("InfoproductV2Handler::continueConversation - INICIO", [], ['module' => 'infoproduct_v2']);

    $chatData = ConversationValidator::getChatData($person['number'], $bot['id']);

    // BYPASS del buffer para imágenes (respuesta inmediata)
    $isImage = strtoupper($messageType) === 'IMAGE';

    if ($isImage) {
      ogLog::info("InfoproductV2Handler::continueConversation - Imagen detectada, procesando SIN buffer", [
        'number' => $person['number'],
        'bypass_reason' => 'image_type'
      ], ['module' => 'infoproduct_v2']);

      $this->processImageMessages([$message], $bot, $person, $chatData);
      return;
    }

    // VALIDACIÓN: Rechazar documentos
    $isDocument = strtoupper($messageType) === 'DOCUMENT';

    if ($isDocument) {
      ogLog::info("InfoproductV2Handler::continueConversation - Documento detectado, rechazando", [
        'number' => $person['number'],
        'reject_reason' => 'document_not_supported'
      ], ['module' => 'infoproduct_v2']);

      $this->processDocumentMessages([$message], $bot, $person, $chatData);
      return;
    }

    // VALIDACIÓN: Procesar videos
    $isVideo = strtoupper($messageType) === 'VIDEO';

    if ($isVideo) {
      ogLog::info("InfoproductV2Handler::continueConversation - Video detectado", [
        'number' => $person['number']
      ], ['module' => 'infoproduct_v2']);

      $this->processVideoMessages([$message], $bot, $person, $chatData);
      return;
    }

    // VALIDACIÓN: Rechazar stickers
    $isSticker = strtoupper($messageType) === 'STICKER';

    if ($isSticker) {
      ogLog::info("InfoproductV2Handler::continueConversation - Sticker detectado, rechazando", [
        'number' => $person['number']
      ], ['module' => 'infoproduct_v2']);

      $this->processStickerMessages([$message], $bot, $person, $chatData);
      return;
    }

    // Para texto/audio: usar buffer normal (3 segundos)
    ogLog::debug("InfoproductV2Handler::continueConversation - Mensaje de texto/audio, usando buffer", [
      'type' => $messageType,
      'delay' => $this->bufferDelay
    ], ['module' => 'infoproduct_v2']);

    // Usar MessageBuffer para procesar mensajes con delay
    $buffer = new MessageBuffer($this->bufferDelay);
    $result = $buffer->process($person['number'], $bot['id'], $message);

    if (!$result) {
      ogLog::debug("InfoproductV2Handler::continueConversation - Buffer activo, esperando más mensajes", [], ['module' => 'infoproduct_v2']);
      return;
    }

    $messages = $result['messages'];
    $hasImage = MessageClassifier::hasImageInMessages($messages);

    ogLog::debug("InfoproductV2Handler::continueConversation - Buffer completado, procesando mensajes", [
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
    ogLog::info("InfoproductV2Handler::processImageMessages - INICIO", [], ['module' => 'infoproduct_v2']);

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
    ogLog::info("InfoproductV2Handler::processDocumentMessages - INICIO", [], ['module' => 'infoproduct_v2']);

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

    ogLog::info("InfoproductV2Handler::processDocumentMessages - Documento rechazado exitosamente", [
      'caption' => $result['caption'] ?? ''
    ], ['module' => 'infoproduct_v2']);
  }

  private function processVideoMessages($messages, $bot, $person, $chatData) {
    ogLog::info("InfoproductV2Handler::processVideoMessages - INICIO", [], ['module' => 'infoproduct_v2']);

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
      ogLog::info("InfoproductV2Handler::processVideoMessages - Video sin caption registrado, sin respuesta", [], ['module' => 'infoproduct_v2']);
      return;
    }

    // Si tiene caption → Procesar como texto con IA
    ogLog::info("InfoproductV2Handler::processVideoMessages - Video con caption, procesando con IA", [], ['module' => 'infoproduct_v2']);

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
    ogLog::info("InfoproductV2Handler::processStickerMessages - INICIO", [], ['module' => 'infoproduct_v2']);

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

    ogLog::info("InfoproductV2Handler::processStickerMessages - Sticker rechazado exitosamente", [], ['module' => 'infoproduct_v2']);
  }

  private function processTextMessages($messages, $bot, $person, $chatData) {
    ogLog::info("InfoproductV2Handler::processTextMessages - INICIO", [], ['module' => 'infoproduct_v2']);

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
    ogLog::info("InfoproductV2Handler::executeWelcome - INICIO", [
      'product_id' => $welcomeCheck['product_id']
    ], ['module' => 'infoproduct_v2']);

    require_once $this->appPath . '/workflows/infoproduct/strategies/ConversationStrategyInterface.php';
    require_once $this->appPath . '/workflows/infoproduct/strategies/WelcomeStrategy.php';

    $strategy = new WelcomeStrategy();
    $strategy->execute([
      'bot' => $bot,
      'person' => $person,
      'message' => $message,
      'context' => $context,
      'product_id' => $welcomeCheck['product_id']
    ]);

    ogLog::info("InfoproductV2Handler::executeWelcome - FIN", [], ['module' => 'infoproduct_v2']);
  }
}