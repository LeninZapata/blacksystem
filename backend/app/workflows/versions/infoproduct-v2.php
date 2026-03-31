<?php

// Cargar dependencias core (usando ogApp()->getPath() directamente aquí porque es antes de la clase)
$path = ogApp()->getPath();

require_once $path . '/workflows/core/events/ActionDispatcher.php';
require_once $path . '/workflows/core/events/ActionRegistry.php';
require_once $path . '/workflows/core/events/ActionHandler.php';
require_once $path . '/workflows/core/support/MessageClassifier.php';
require_once $path . '/workflows/core/support/MessageBuffer.php';
require_once $path . '/workflows/core/validators/ConversationValidator.php';
require_once $path . '/workflows/core/validators/WelcomeValidator.php';

// Cargar action handlers
require_once $path . '/workflows/infoproduct/actions/DoesNotWantProductAction.php';
require_once $path . '/workflows/infoproduct/actions/DeliveredProductActionHandler.php';

class InfoproductV2Handler {

  private $logMeta = ['module' => 'InfoproductV2Handler', 'layer' => 'app/workflows'];

  // ========================================
  // ✅ CONFIGURACIÓN DE PROVIDERS
  // ========================================

  /**
   * Provider forzado para testing
   *
   * Opciones:
   * - null: Modo AUTOMÁTICO (calcula según horas desde conversation_started)
   * - 'evolutionapi': Forzar uso de Evolution API
   * - 'whatsapp-cloud-api': Forzar uso de WhatsApp Cloud API (Facebook)
   *
   * EJEMPLOS:
   * - Testing Evolution: private $forcedProvider = 'evolutionapi';
   * - Testing Facebook: private $forcedProvider = 'whatsapp-cloud-api';
   * - Producción: private $forcedProvider = null;
   */
  private $forcedProvider = null; // null = usa config del bot (producción). Ver constantes PROVIDER_* arriba.

  /**
   * Límite de horas para usar WhatsApp Cloud API (Facebook)
   * Solo aplica si $forcedProvider = null
   */
  private const HOURS_LIMIT_FACEBOOK = 72;
  private const PROVIDER_FACEBOOK = 'whatsapp-cloud-api';
  private const PROVIDER_EVOLUTION = 'evolutionapi';

  // ========================================
  // Variables existentes
  // ========================================
  private $actionDispatcher;
  private $maxConversationDays = 2;
  private $bufferDelay = OG_IS_DEV ? 3 : 9;
  private $appPath;

  // Prompts personalizados
  private $prompt_recibo = 'recibo.txt';
  private $prompt_recibo_imagen = 'recibo-img.txt';

  // Configuración de followups
  private $followupStartHour = 8;
  private $followupEndHour = 22;
  private $followupMinutesBefore = 15;
  private $followupMinutesAfter = 15;

  public function __construct() {
    $this->appPath = ogApp()->getPath();
    $this->actionDispatcher = new ActionDispatcher();

    ogApp()->loadHandler('followup');
    FollowupHandler::setAllowedHours($this->followupStartHour, $this->followupEndHour);
    FollowupHandler::setMinutesVariation($this->followupMinutesBefore, $this->followupMinutesAfter);
    $this->registerActionHandlers();
  }

  private function registerActionHandlers() {
    $registry = $this->actionDispatcher->getRegistry();
    $registry->register('does_not_want_the_product', 'DoesNotWantProductAction');
    $registry->register('delivered_product', 'DeliveredProductActionHandler');
  }

  public function handle($webhook) {
    // FILTRO: Ignorar eventos de tipo "presence"
    $event = $webhook['normalized']['body']['event'] ?? null;

    if ($event && ($event === 'presence.update' || stripos($event, 'presence') !== false)) {
      return;
    }

    $standard = $webhook['standard'] ?? [];
    $bot = $standard['sender'] ?? [];
    $person = $standard['person'] ?? [];
    $message = $standard['message'] ?? [];
    $context = $standard['context'] ?? [];
    $botNumber = $bot['number'] ?? null;

    // FILTRO: Ignorar mensajes enviados por el propio bot/agente (fromMe=true)
    if ($person['is_me'] ?? false) {
      return;
    }

    // ✅ 1. DETECTAR ORIGEN DEL WEBHOOK
    $webhookProvider = $standard['webhook']['provider'] ?? 'evolution';
    $webhookSource = $standard['webhook']['source'] ?? 'unknown';

    if (!$botNumber) {
      ogLog::throwError("handle - Bot number missing in webhook", $bot ?? null, $this->logMeta);
    }

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
    } else {
      ogLog::warning("handle - Bot sin user_id", [ 'bot_id' => $bot['id'] ?? 'N/A', 'bot_number' => $botNumber ], $this->logMeta);
    }

    $bot['prompt_recibo'] = ($bot['mode'] ?? 'R') === 'C' ? 'checkout.txt' : $this->prompt_recibo;
    $bot['prompt_reccibo_imagen'] = $this->prompt_recibo_imagen;

    $messageType = MessageClassifier::classify($message);
    $clientKey = $this->clientKey($person);
    $hasConversation = ConversationValidator::quickCheck( $clientKey, $bot['id'], $this->maxConversationDays );

    // ✅ 2. DETECTAR WELCOME
    $welcomeCheck = WelcomeValidator::detect($bot, $message, $context, $hasConversation['chat'] ?? null);

    if ( ($welcomeCheck['is_welcome'] && !$hasConversation['exists']) || ($welcomeCheck['is_welcome_diff_product'] && $hasConversation['exists']) ) {
      // Provider real del bot (para filtrar el webhook — siempre del config)
      $botProvider      = $bot['config']['apis']['chat'][0]['config']['type_value'] ?? self::PROVIDER_EVOLUTION;
      // Provider de envío (puede ser forzado para testing)
      $selectedProvider = $this->forcedProvider ?? $botProvider;

      // Verificar si debe procesarse según el provider REAL del bot (no el forzado)
      if (!$this->shouldProcessWebhook($webhookProvider, $botProvider)) {
        return;
      }

      // Configurar provider de envío (aquí sí puede ser el forzado)
      $this->configureChatProvider($bot, $selectedProvider);

      $welcomeResult = $this->executeWelcome($bot, $person, $message, $context, $welcomeCheck);

      return;
    }

    // ✅ 4. CONTINUAR CONVERSACIÓN (con selección dinámica de provider)
    if ( $hasConversation['exists'] ?? false ) {
      $chat = $hasConversation['chat'];

      // Determinar provider real del bot (para filtro de webhook)
      $correctProvider = $this->selectChatProvider($chat, $bot);
      // Provider de envio: puede ser forzado para testing
      $sendProvider = $this->forcedProvider ?? $correctProvider;

      // Verificar si debe procesarse este webhook (siempre con provider real del bot)
      if (!$this->shouldProcessWebhook($webhookProvider, $correctProvider)) {
        return; // Descartar webhook duplicado
      }

      // Configurar servicio con el provider de envio
      $this->configureChatProvider($bot, $sendProvider);

      $this->continueConversation($bot, $person, $message, $messageType);
      return;
    }
  }

  private function continueConversation($bot, $person, $message, $messageType) {

    $chatData = ConversationValidator::getChatData($this->clientKey($person), $bot['id']);

    // Si la respuesta automática está desactivada para este cliente+bot,
    // registrar el mensaje entrante pero no responder
    if (!empty($chatData['bot_response_disabled'])) {
      $clientId = $chatData['client_id'] ?? null;
      $saleId   = (int)($chatData['current_sale']['sale_id'] ?? 0);

      if ($clientId) {
        $msgText = $message['text'] ?? '';
        $formatLabels = [
          'IMAGE'    => ['label' => '[Imagen recibida]',    'format' => 'image'],
          'DOCUMENT' => ['label' => '[Documento recibido]', 'format' => 'document'],
          'VIDEO'    => ['label' => '[Video recibido]',     'format' => 'video'],
          'AUDIO'    => ['label' => '[Audio recibido]',     'format' => 'audio'],
          'STICKER'  => ['label' => '[Sticker recibido]',   'format' => 'sticker'],
        ];
        $typeKey = strtoupper($messageType);
        if (empty($msgText) && isset($formatLabels[$typeKey])) {
          $msgText = $formatLabels[$typeKey]['label'];
        }
        $msgFormat = $formatLabels[$typeKey]['format'] ?? 'text';

        ChatHandler::register(
          $bot['id'], $bot['number'],
          $clientId, $person['number'],
          $msgText, 'P', $msgFormat,
          null, $saleId, false
        );
        ChatHandler::addMessage([
          'number'    => $person['number'],
          'bot_id'    => $bot['id'],
          'client_id' => $clientId,
          'sale_id'   => $saleId,
          'message'   => $msgText,
          'format'    => $msgFormat,
          'metadata'  => null
        ], 'P');
      }
      return;
    }

    // BYPASS del buffer para imágenes (respuesta inmediata)
    $isImage = strtoupper($messageType) === 'IMAGE';

    if ($isImage) {
      $this->processImageMessages([$message], $bot, $person, $chatData);
      return;
    }

    // VALIDACIÓN: Rechazar documentos
    $isDocument = strtoupper($messageType) === 'DOCUMENT';

    if ($isDocument) {
      $this->processDocumentMessages([$message], $bot, $person, $chatData);
      return;
    }

    // VALIDACIÓN: Procesar videos
    $isVideo = strtoupper($messageType) === 'VIDEO';

    if ($isVideo) {
      $this->processVideoMessages([$message], $bot, $person, $chatData);
      return;
    }

    // VALIDACIÓN: Rechazar stickers
    $isSticker = strtoupper($messageType) === 'STICKER';

    if ($isSticker) {
      $this->processStickerMessages([$message], $bot, $person, $chatData);
      return;
    }

    // VALIDACIÓN: Ignorar reactions
    $isReaction = strtoupper($messageType) === 'REACTION';

    if ($isReaction) {
      $this->processReactionMessages([$message], $bot, $person, $chatData);
      return;
    }

    // INTERACTIVE: botón de WhatsApp pulsado → buscar plantilla quick_reply
    $isInteractive = strtoupper($messageType) === 'INTERACTIVE';

    if ($isInteractive) {
      require_once $this->appPath . '/workflows/infoproduct/handlers/QuickReplyHandler.php';
      $buttonText = QuickReplyHandler::extractInteractiveText($message);

      if ($buttonText !== '') {
        if (!$this->handleQuickReply($buttonText, $bot, $person, $chatData)) {
          // Sin coincidencia de plantilla → procesar el texto del botón con IA
          $this->processTextMessages(
            [['type' => 'TEXT', 'text' => $buttonText]],
            $bot, $person, $chatData
          );
        }
      }
      return;
    }

    // BUFFER: Acumular mensajes antes de procesar
    $requiresBuffering = MessageClassifier::requiresBuffering($messageType);

    if (!$requiresBuffering) {
      if (!$this->handleQuickReply($message['text'] ?? '', $bot, $person, $chatData)) {
        $this->processTextMessages([$message], $bot, $person, $chatData);
      }
      return;
    }

    $buffer = new MessageBuffer($this->bufferDelay);
    // NOTA: cleanOld() removido - causaba bloqueo con múltiples webhooks simultáneos
    $result = $buffer->process($this->clientKey($person), $bot['id'], $message);

    if ($result === null) {
      return;
    }

    $combinedText = trim(implode(' ', array_map(function($m) {
      return trim($m['text'] ?? '');
    }, $result['messages'])));

    if (!$this->handleQuickReply($combinedText, $bot, $person, $chatData)) {
      $this->processTextMessages($result['messages'], $bot, $person, $chatData);
    }
  }

  /**
   * Verifica si el texto coincide con algún trigger de quick_reply_templates del chat.
   * Si hay coincidencia, envía la plantilla y retorna true.
   * Si no, retorna false para que el flujo continúe hacia la IA.
   */
  private function handleQuickReply(string $text, array $bot, array $person, array $chatData): bool {
    if ($text === '') return false;

    require_once $this->appPath . '/workflows/infoproduct/handlers/QuickReplyHandler.php';

    $match = QuickReplyHandler::findMatch($text, $chatData);
    if (!$match) return false;

    $clientId = $chatData['client_id'] ?? null;
    $saleId   = (int)($chatData['current_sale']['sale_id'] ?? 0);

    ogApp()->loadHandler('chat');

    // Registrar mensaje del cliente (botón pulsado o texto del trigger)
    if ($clientId) {
      ChatHandler::register($bot['id'], $bot['number'], $clientId, $person['number'], $text, 'P', 'text', null, $saleId, false);
      ChatHandler::addMessage([
        'number'    => $person['number'],
        'bot_id'    => $bot['id'],
        'client_id' => $clientId,
        'sale_id'   => $saleId,
        'message'   => $text,
        'format'    => 'text',
        'metadata'  => null
      ], 'P');
    }

    // Enviar la plantilla
    QuickReplyHandler::send($match, $person['number'], $bot);

    // Registrar respuesta de la plantilla (bot)
    if ($clientId) {
      $templateMessage = $match['message'] ?? '';
      $metadata = [
        'action'         => 'quick_reply',
        'template_id'    => $match['template_id'] ?? '',
        'template_type'  => $match['template_type'] ?? 'quick_reply'
      ];
      ChatHandler::register($bot['id'], $bot['number'], $clientId, $person['number'], $templateMessage, 'S', 'text', $metadata, $saleId, true);
      ChatHandler::addMessage([
        'number'    => $person['number'],
        'bot_id'    => $bot['id'],
        'client_id' => $clientId,
        'sale_id'   => $saleId,
        'message'   => $templateMessage,
        'format'    => 'text',
        'metadata'  => $metadata
      ], 'S');
    }

    return true;
  }

  private function processImageMessages($messages, $bot, $person, $chatData) {

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

  }

  private function processVideoMessages($messages, $bot, $person, $chatData) {

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
      return;
    }

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

  }

  private function processReactionMessages($messages, $bot, $person, $chatData) {

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

  }

  private function processTextMessages($messages, $bot, $person, $chatData) {

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
    require_once $this->appPath . '/workflows/infoproduct/strategies/ChatWindowStrategy.php';
    require_once $this->appPath . '/workflows/infoproduct/strategies/WelcomeStrategy.php';

    $strategy = new WelcomeStrategy();
    return $strategy->execute([ 'bot' => $bot, 'person' => $person, 'message' => $message, 'context' => $context, 'product_id' => $welcomeCheck['product_id'] ]);
  }

  // ========================================
  // ✅ BIENVENIDA FORZADA (sin webhook real)
  // ========================================

  /**
   * Ejecuta el flujo de bienvenida directamente, sin pasar por detección
   * de anuncios ni validación de webhook. Se llama desde el endpoint
   * POST /api/product/force-welcome.
   *
   * @param array $bot       Datos del bot cargados desde DB
   * @param array $person    ['number' => '593...', 'name' => 'unknown', 'platform' => 'forced']
   * @param int   $productId ID del producto al que dar la bienvenida
   */
  public function forceWelcome(array $bot, array $person, int $productId): void {

    // ── Cargar bot completo desde JSON (con credenciales resueltas) ────────
    // Igual que handle() hace con BotHandler::getDataFile($botNumber)
    $botNumber = $bot['number'] ?? null;
    if (!$botNumber) {
      ogLog::throwError("forceWelcome - Bot sin número", $bot, $this->logMeta);
    }

    ogApp()->loadHandler('bot');
    $botData = BotHandler::getDataFile($botNumber);

    if (!$botData) {
      ogLog::throwError("forceWelcome - data file no encontrado: {$botNumber}", [], $this->logMeta);
    }

    // Merge igual que handle(): datos DB + datos JSON (con credenciales resueltas)
    $bot = array_merge($bot, $botData);

    // Establecer user_id globalmente
    $userId = $bot['user_id'] ?? null;
    if ($userId) {
      ogApp()->handler('chat')::setUserId($userId);
      ogApp()->handler('followup')::setUserId($userId);
    }

    ogApp()->helper('cache')::memorySet('current_bot', $bot);
    $bot['prompt_recibo']         = ($bot['mode'] ?? 'R') === 'C' ? 'checkout.txt' : $this->prompt_recibo;
    $bot['prompt_reccibo_imagen'] = $this->prompt_recibo_imagen;

    // Configurar provider (usa evolutionapi por defecto igual que $forcedProvider)
    $this->configureChatProvider($bot, $this->forcedProvider ?? self::PROVIDER_EVOLUTION);

    // Construir welcomeCheck mínimo con el product_id dado
    $welcomeCheck = [
      'is_welcome'            => true,
      'product_id'            => $productId,
      'source'                => 'forced',
      'is_welcome_diff_product' => false,
    ];

    // Contexto marcado como bienvenida forzada (CreateSaleAction lo usa)
    $context = ['force_welcome' => 1];

    // Mensaje mínimo (WelcomeStrategy solo lo pasa a handleNewProductWelcome que no lo usa)
    $message = ['type' => 'TEXT', 'text' => ''];

    $this->executeWelcome($bot, $person, $message, $context, $welcomeCheck);

  }

  // ========================================
  // ✅ MÉTODOS DE SELECCIÓN DE PROVIDER
  // ========================================

  /**
   * Seleccionar provider de ChatAPI según configuración del bot
   * Devuelve siempre el provider real del bot (para filtro de webhook).
   * El forcedProvider se aplica solo al configurar el envio en el caller.
   */
  private function selectChatProvider($chat, $bot) {
    // Provider real del bot desde config (para filtro de webhook)
    $botProvider = $bot['config']['apis']['chat'][0]['config']['type_value'] ?? self::PROVIDER_EVOLUTION;


    // NOTA: Para WhatsApp Cloud API existe ventana de conversación gratuita:
    //   - Apertura por bienvenida/anuncio (welcome): 72 horas
    //   - Apertura por mensaje del cliente (entrante): 24 horas
    // La ventana se gestiona en la tabla client_bot_meta (clave: open_chat).
    // Si la ventana expiró, chatApiService::send() lanzará error;
    // en ese caso se debe usar una plantilla (template message) de WhatsApp.
    // Evolution API no tiene límite de tiempo.

    return $botProvider;
  }

  /**
   * Verificar si debe procesarse este webhook según el provider
   */
  private function shouldProcessWebhook($webhookProvider, $correctProvider) {
    $shouldProcess = $webhookProvider === $correctProvider;

    return $shouldProcess;
  }

  /**
   * Clave de identificación del cliente para chat/buffer/validator.
   * Cuando el número está disponible (caso normal) → el número.
   * Cuando está vacío (username privacy activo desde marzo 2026) → prefijo bsuid_.
   */
  private function clientKey(array $person): string {
    return $person['number'] ?: ('bsuid_' . ($person['bsuid'] ?? ''));
  }

  /**
   * Configurar el servicio de ChatAPI con el provider correcto
   */
  private function configureChatProvider($bot, $provider) {
    $chatapi = ogApp()->service('chatApi');

    // Configurar el servicio con el bot completo
    $chatapi::setConfig($bot);

    // Filtrar solo el provider específico
    $chatapi::setProvider($provider);

  }
}