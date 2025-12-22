<?php

// Cargar dependencias core
require_once APP_PATH . '/workflows/core/events/ActionDispatcher.php';
require_once APP_PATH . '/workflows/core/events/ActionRegistry.php';
require_once APP_PATH . '/workflows/core/events/ActionHandler.php';
require_once APP_PATH . '/workflows/core/support/MessageClassifier.php';
require_once APP_PATH . '/workflows/core/validators/ConversationValidator.php';
require_once APP_PATH . '/workflows/core/validators/WelcomeValidator.php';

class InfoproductV2Handler {

  private $actionDispatcher;
  private $maxConversationDays = 2;
  private $bufferDelay = 3;

  public function __construct() {
    log::debug("InfoproductV2Handler::__construct - INICIO", [], ['module' => 'infoproduct_v2']);

    $this->actionDispatcher = new ActionDispatcher();

    log::debug("InfoproductV2Handler::__construct - ActionDispatcher creado", [], ['module' => 'infoproduct_v2']);

    $this->registerActionHandlers();

    log::debug("InfoproductV2Handler::__construct - Handlers registrados", [], ['module' => 'infoproduct_v2']);
  }

  private function registerActionHandlers() {
    log::debug("InfoproductV2Handler::registerActionHandlers - INICIO", [], ['module' => 'infoproduct_v2']);

    $registry = $this->actionDispatcher->getRegistry();

    $registry->register('sale_confirmed', 'SaleConfirmedHandler');
    $registry->register('delivered_product', 'DeliveredProductHandler');
    $registry->register('payment_method_template', 'PaymentMethodTemplateHandler');

    log::debug("InfoproductV2Handler::registerActionHandlers - FIN", [], ['module' => 'infoproduct_v2']);
  }

  public function handle($webhook) {
    log::info("InfoproductV2Handler::handle - INICIO", [], ['module' => 'infoproduct_v2']);

    $standard = $webhook['standard'] ?? [];
    log::debug("InfoproductV2Handler::handle - Standard extraído", [
      'has_standard' => !empty($standard)
    ], ['module' => 'infoproduct_v2']);

    $bot = $standard['sender'] ?? [];
    $person = $standard['person'] ?? [];
    $message = $standard['message'] ?? [];
    $context = $standard['context'] ?? [];

    log::debug("InfoproductV2Handler::handle - Datos extraídos", [
      'bot_number' => $bot['number'] ?? 'N/A',
      'person_number' => $person['number'] ?? 'N/A',
      'message_type' => $message['type'] ?? 'N/A'
    ], ['module' => 'infoproduct_v2']);

    $botNumber = $bot['number'] ?? null;

    if (!$botNumber) {
      log::error("Bot number not found in webhook", [], ['module' => 'infoproduct_v2']);
      return;
    }

    log::debug("InfoproductV2Handler::handle - Cargando bot data", [
      'bot_number' => $botNumber
    ], ['module' => 'infoproduct_v2']);

    $botData = BotHandlers::getDataFile($botNumber);

    if (!$botData) {
      log::error("Bot data not found: {$botNumber}", [], ['module' => 'infoproduct_v2']);
      return;
    }

    log::debug("InfoproductV2Handler::handle - Bot data cargado", [
      'bot_id' => $botData['id'] ?? 'N/A'
    ], ['module' => 'infoproduct_v2']);

    $bot = array_merge($bot, $botData);

    log::debug("InfoproductV2Handler::handle - Clasificando mensaje", [], ['module' => 'infoproduct_v2']);

    $messageType = MessageClassifier::classify($message);

    log::debug("InfoproductV2Handler::handle - Mensaje clasificado", [
      'type' => $messageType
    ], ['module' => 'infoproduct_v2']);

    log::debug("InfoproductV2Handler::handle - Verificando conversación activa", [
      'number' => $person['number'],
      'bot_id' => $bot['id']
    ], ['module' => 'infoproduct_v2']);

    $hasConversation = ConversationValidator::quickCheck(
      $person['number'],
      $bot['id'],
      $this->maxConversationDays
    );

    log::debug("InfoproductV2Handler::handle - Conversación verificada", [
      'has_conversation' => $hasConversation
    ], ['module' => 'infoproduct_v2']);

    if ($hasConversation) {
      log::info("InfoproductV2Handler::handle - Continuar conversación", [], ['module' => 'infoproduct_v2']);
      $this->continueConversation($bot, $person, $message, $messageType);
      return;
    }

    log::debug("InfoproductV2Handler::handle - Detectando welcome", [], ['module' => 'infoproduct_v2']);

    $welcomeCheck = WelcomeValidator::detect($bot, $message, $context);

    log::debug("InfoproductV2Handler::handle - Welcome detectado", [
      'is_welcome' => $welcomeCheck['is_welcome'],
      'product_id' => $welcomeCheck['product_id'] ?? null
    ], ['module' => 'infoproduct_v2']);

    if ($welcomeCheck['is_welcome']) {
      log::info("InfoproductV2Handler::handle - Ejecutar welcome", [
        'product_id' => $welcomeCheck['product_id']
      ], ['module' => 'infoproduct_v2']);

      $this->executeWelcome($bot, $person, $message, $context, $welcomeCheck);
      return;
    }

    log::info("No action taken - No conversation and not welcome", [
      'number' => $person['number']
    ], ['module' => 'infoproduct_v2']);
  }

  private function continueConversation($bot, $person, $message, $messageType) {
    log::info("InfoproductV2Handler::continueConversation - INICIO", [], ['module' => 'infoproduct_v2']);

    $chatData = ConversationValidator::getChatData($person['number'], $bot['id']);

    require_once APP_PATH . '/workflows/core/support/MessageBuffer.php';  // ← minúscula
    $buffer = new MessageBuffer($this->bufferDelay);

    $result = $buffer->process(
      $person['number'],
      $bot['id'],
      $message  // ← SIN el parámetro $shouldBuffer
    );

    if ($result === null) {
      log::debug("InfoproductV2Handler::continueConversation - Esperando buffer", [], ['module' => 'infoproduct_v2']);
      return;
    }

    $messages = $result['messages'];
    $hasImage = MessageClassifier::hasImageInMessages($messages);

    log::debug("InfoproductV2Handler::continueConversation - Procesando mensajes", [
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
    log::info("InfoproductV2Handler::processImageMessages - INICIO", [], ['module' => 'infoproduct_v2']);

    require_once APP_PATH . '/workflows/infoproduct/processors/MessageProcessorInterface.php';
    require_once APP_PATH . '/workflows/infoproduct/processors/ImageMessageProcessor.php';
    require_once APP_PATH . '/workflows/infoproduct/strategies/ConversationStrategyInterface.php';
    require_once APP_PATH . '/workflows/infoproduct/strategies/PaymentStrategy.php';

    $processor = new ImageMessageProcessor();
    $result = $processor->process($messages, [
      'bot' => $bot,
      'person' => $person,
      'chat_data' => $chatData
    ]);

    if (!$result['success']) {
      log::error("Image processing failed", [
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

  private function processTextMessages($messages, $bot, $person, $chatData) {
    log::info("InfoproductV2Handler::processTextMessages - INICIO", [], ['module' => 'infoproduct_v2']);

    require_once APP_PATH . '/workflows/infoproduct/processors/MessageProcessorInterface.php';
    require_once APP_PATH . '/workflows/infoproduct/processors/TextMessageProcessor.php';
    require_once APP_PATH . '/workflows/infoproduct/strategies/ConversationStrategyInterface.php';
    require_once APP_PATH . '/workflows/infoproduct/strategies/ActiveConversationStrategy.php';

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
        'chat_data' => $chatData
      ]);
    }
  }

  private function executeWelcome($bot, $person, $message, $context, $welcomeCheck) {
    log::info("InfoproductV2Handler::executeWelcome - INICIO", [
      'product_id' => $welcomeCheck['product_id']
    ], ['module' => 'infoproduct_v2']);

    require_once APP_PATH . '/workflows/infoproduct/strategies/ConversationStrategyInterface.php';
    require_once APP_PATH . '/workflows/infoproduct/strategies/WelcomeStrategy.php';

    $strategy = new WelcomeStrategy();
    $strategy->execute([
      'bot' => $bot,
      'person' => $person,
      'message' => $message,
      'context' => $context,
      'product_id' => $welcomeCheck['product_id']
    ]);

    log::info("InfoproductV2Handler::executeWelcome - FIN", [], ['module' => 'infoproduct_v2']);
  }
}