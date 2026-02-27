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
    ogLog::info("__construct - Inicio", [], $this->logMeta);

    $this->appPath = ogApp()->getPath();
    $this->actionDispatcher = new ActionDispatcher();

    ogApp()->loadHandler('followup');
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
    ogLog::info("handle - Inicio", [],  $this->logMeta);

    // FILTRO: Ignorar eventos de tipo "presence"
    $event = $webhook['normalized']['body']['event'] ?? null;

    if ($event === 'presence.update' || stripos($event, 'presence') !== false) {
      ogLog::info("handle - Evento presence detectado, ignorando", [ 'event' => $event ], $this->logMeta);
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
      ogLog::info("handle - Mensaje propio (fromMe=true), ignorando", [
        'number' => $person['number'] ?? 'N/A',
        'message_type' => $message['type'] ?? 'unknown'
      ], $this->logMeta);
      return;
    }

    // ✅ 1. DETECTAR ORIGEN DEL WEBHOOK
    $webhookProvider = $standard['webhook']['provider'] ?? 'evolution';
    $webhookSource = $standard['webhook']['source'] ?? 'unknown';

    ogLog::info("handle - Webhook recibido", [
      'webhook_provider' => $webhookProvider,
      'webhook_source' => $webhookSource,
      'number' => $person['number'] ?? 'N/A',
      'message_type' => $message['type'] ?? 'unknown'
    ], $this->logMeta);

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

    // ★ FIX: Establecer timezone del bot para toda la ejecución del webhook
    $botTimezone = $bot['config']['timezone'] ?? 'America/Guayaquil';
    date_default_timezone_set($botTimezone);
    ogLog::info("handle - Timezone establecida", ['timezone' => $botTimezone], $this->logMeta);

    // ESTABLECER user_id GLOBALMENTE EN ChatHandler
    $userId = $bot['user_id'] ?? null;
    if ($userId) {
      ogApp()->handler('chat')::setUserId($userId);
      ogApp()->handler('followup')::setUserId($userId);
      ogLog::info("handle - user_id establecido globalmente", [ 'user_id' => $userId, 'bot_id' => $bot['id'] ], $this->logMeta);
    } else {
      ogLog::warning("handle - Bot sin user_id", [ 'bot_id' => $bot['id'] ?? 'N/A', 'bot_number' => $botNumber ], $this->logMeta);
    }

    $bot['prompt_recibo'] = $this->prompt_recibo;
    $bot['prompt_reccibo_imagen'] = $this->prompt_recibo_imagen;
    ogLog::info("handle - Bot data cargado", [ 'bot_id' => $bot['id'] ?? 'N/A' ], $this->logMeta );

    $messageType = MessageClassifier::classify($message);
    ogLog::info("handle - Mensaje clasificado", [ 'type' => $messageType, 'person number' => $person['number'] ?? 'N/A' ], $this->logMeta);

    $hasConversation = ConversationValidator::quickCheck( $person['number'], $bot['id'], $this->maxConversationDays );
    ogLog::info("handle - Conversación activa verificada", [ 'has_conversation' => $hasConversation['exists'] ?? false, 'messages_count' => isset($hasConversation['chat']['messages']) ? count($hasConversation['chat']['messages']) : null ], $this->logMeta);

    // ✅ 2. DETECTAR WELCOME
    ogLog::info("handle - Detectando welcome", [], $this->logMeta);
    $welcomeCheck = WelcomeValidator::detect($bot, $message, $context, $hasConversation['chat'] ?? null);
    ogLog::info("handle - Resultado de welcome detection", [ 'welcome_check' => $welcomeCheck ], $this->logMeta);

    if ( ($welcomeCheck['is_welcome'] && !$hasConversation['exists']) || ($welcomeCheck['is_welcome_diff_product'] && $hasConversation['exists']) ) {
      // Provider real del bot (para filtrar el webhook — siempre del config)
      $botProvider      = $bot['config']['apis']['chat'][0]['config']['type_value'] ?? self::PROVIDER_EVOLUTION;
      // Provider de envío (puede ser forzado para testing)
      $selectedProvider = $this->forcedProvider ?? $botProvider;

      ogLog::info("handle - Welcome detectado", [
        'product_id' => $welcomeCheck['product_id'],
        'bot_provider' => $botProvider,
        'send_provider' => $selectedProvider,
        'has_active_conversation' => $hasConversation['exists']
      ], $this->logMeta);

      // Verificar si debe procesarse según el provider REAL del bot (no el forzado)
      if (!$this->shouldProcessWebhook($webhookProvider, $botProvider)) {
        ogLog::info("handle - Welcome descartado (webhook incorrecto)", [
          'webhook_source' => $webhookSource,
          'bot_provider' => $botProvider
        ], $this->logMeta);
        return;
      }

      // Configurar provider de envío (aquí sí puede ser el forzado)
      $this->configureChatProvider($bot, $selectedProvider);

      $this->executeWelcome($bot, $person, $message, $context, $welcomeCheck);
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

      ogLog::info("handle - No es welcome ➞ Continuar conversación", [
        'bot_provider'  => $correctProvider,
        'send_provider' => $sendProvider,
        'webhook_provider' => $webhookProvider
      ], $this->logMeta);

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
      ogLog::info("continueConversation - Reaction detectada, rechazando", [
        'number' => $person['number']
      ], ['module' => 'infoproduct_v2']);

      $this->processReactionMessages([$message], $bot, $person, $chatData);
      return;
    }

    // BUFFER: Acumular mensajes antes de procesar
    $requiresBuffering = MessageClassifier::requiresBuffering($messageType);

    if (!$requiresBuffering) {
      ogLog::info("continueConversation - Mensaje no requiere buffering, procesando directamente", [
        'message_type' => $messageType,
        'number' => $person['number']
      ], ['module' => 'infoproduct_v2']);

      $this->processTextMessages([$message], $bot, $person, $chatData);
      return;
    }

    $buffer = new MessageBuffer($this->bufferDelay);
    // NOTA: cleanOld() removido - causaba bloqueo con múltiples webhooks simultáneos
    $result = $buffer->process($person['number'], $bot['id'], $message);

    if ($result === null) {
      ogLog::info("continueConversation - Mensaje agregado a buffer, esperando más", [], ['module' => 'infoproduct_v2']);
      return;
    }

    ogLog::info("continueConversation - Buffer completado, procesando mensajes", [
      'total_messages' => $result['count']
    ], ['module' => 'infoproduct_v2']);

    $this->processTextMessages($result['messages'], $bot, $person, $chatData);
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
    ogLog::info("forceWelcome - INICIO", [
      'bot_id'     => $bot['id'],
      'product_id' => $productId,
      'phone'      => $person['number'],
    ], $this->logMeta);

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
    $botTimezone = $bot['config']['timezone'] ?? 'America/Guayaquil';
    date_default_timezone_set($botTimezone);
    $bot['prompt_recibo']         = $this->prompt_recibo;
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

    ogLog::info("forceWelcome - FIN", [
      'bot_id'     => $bot['id'],
      'product_id' => $productId,
      'phone'      => $person['number'],
    ], $this->logMeta);
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

    ogLog::info("selectChatProvider - Provider resuelto", [
      'bot_provider'    => $botProvider,
      'forced_provider' => $this->forcedProvider,
      'bot_id'          => $bot['id'] ?? null
    ], $this->logMeta);

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

    if (!$shouldProcess) {
      ogLog::info("shouldProcessWebhook - Webhook descartado", [
        'webhook_provider' => $webhookProvider,
        'correct_provider' => $correctProvider,
        'reason' => 'provider_mismatch'
      ], $this->logMeta);
    }

    return $shouldProcess;
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

    ogLog::info("configureChatProvider - ChatAPI configurado", [
      'provider' => $provider,
      'bot_id' => $bot['id'] ?? null
    ], $this->logMeta);
  }
}