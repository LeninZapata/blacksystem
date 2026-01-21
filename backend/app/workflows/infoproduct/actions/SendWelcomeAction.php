<?php

class SendWelcomeAction {

  private static $logMeta = ['module' => 'SendWelcomeAction', 'layer' => 'app/workflows'];

  static function send($dataSale) {
    $bot = $dataSale['bot'];
    $person = $dataSale['person'];
    $product = $dataSale['product'];
    $productId = $dataSale['product_id'];
    $from = $person['number'];

    self::cancelPendingSaleFollowups($from, $bot['id'], $productId);

    ogApp()->loadHandler('product');
    $messages = ProductHandler::getMessagesFile('welcome', $productId);

    if (!$messages) {
      ogLog::error("send - No existe mensaje de bienvenida", ['product_id' => $productId], self::$logMeta);
      return ['success' => false, 'error' => 'welcome_file_not_found'];
    }

    if (empty($messages)) {
      ogLog::error("send - No hay mensajes configurados", ['product_id' => $productId], self::$logMeta);
      return ['success' => false, 'error' => 'no_messages_configured'];
    }

    $totalMessages = count($messages);
    $messagesSent = 0;
    $messagesFailed = 0;
    $failedMessages = [];
    $clientId = null;
    $saleId = null;

    // Calcular plan simplificado
    $delayPlan = self::calculateSimplePlan($messages, $bot);

    ogLog::info("send - Plan de delays calculado", $delayPlan, self::$logMeta);

    // Sleep inicial crudo
    sleep(1);

    // Delay inicial
    if ($delayPlan['initial']['ms'] > 0) {
      ogLog::debug("send - Aplicando delay inicial", [
        'delay_data' => $delayPlan['initial']
      ], self::$logMeta);

      self::executeDelay($from, $delayPlan['initial'], $bot);
    }

    $chatapi = ogApp()->service('chatApi');
    $welcomeStartTime = microtime(true);

    foreach ($messages as $index => $msg) {
      $text = $msg['message'] ?? '';
      $url = !empty($msg['url']) && $msg['type'] != 'text' ? $msg['url'] : '';

      // Delay antes del mensaje
      $msgDelay = $delayPlan['messages'][$index];

      ogLog::debug("send - Aplicando delay mensaje", [
        'message_index' => $index + 1,
        'delay_data' => $msgDelay
      ], self::$logMeta);

      if ($msgDelay['ms'] > 0) {
        self::executeDelay($from, $msgDelay, $bot);
      }

      // Enviar mensaje con manejo de errores
      ogLog::debug("send - Enviando mensaje", [
        'message_index' => $index + 1,
        'preview' => substr($text, 0, 30)
      ], self::$logMeta);

      try {
        $result = $chatapi::send($from, $text, $url);
        
        if ($result['success']) {
          $messagesSent++;
          
          // Crear venta después del primer mensaje exitoso
          if ($messagesSent === 1) {
            $saleResult = CreateSaleAction::create($dataSale);

            ogLog::success("send - Venta para crear", [
              'sale_id' => $saleResult['sale_id'],
              'client_id' => $saleResult['client_id']
            ], self::$logMeta);
            if ($saleResult['success']) {
              $clientId = $saleResult['client_id'];
              $saleId = $saleResult['sale_id'];

              $followups = ProductHandler::getMessagesFile('follow', $productId);
              if (!empty($followups)) {
                ogApp()->loadHandler('followup');
                FollowupHandler::registerFromSale([
                  'sale_id' => $saleId,
                  'product_id' => $productId,
                  'client_id' => $clientId,
                  'bot_id' => $bot['id'],
                  'number' => $from
                ], $followups, $bot['config']['timezone'] ?? 'America/Guayaquil', $bot);
              }
            }
          }
        } else {
          // Mensaje falló con response success=false
          $messagesFailed++;
          $failedMessages[] = [
            'index' => $index + 1,
            'preview' => substr($text, 0, 30),
            'error' => $result['error'] ?? 'Unknown error'
          ];
          
          ogLog::warning("send - Mensaje falló, continuando con siguientes", [
            'message_index' => $index + 1,
            'error' => $result['error'] ?? 'Unknown'
          ], self::$logMeta);
        }
      } catch (Exception $e) {
        // Excepción al enviar mensaje
        $messagesFailed++;
        $failedMessages[] = [
          'index' => $index + 1,
          'preview' => substr($text, 0, 30),
          'error' => $e->getMessage()
        ];
        
        ogLog::error("send - Excepción al enviar mensaje, continuando con siguientes", [
          'message_index' => $index + 1,
          'error' => $e->getMessage()
        ], self::$logMeta);
      }
    }

    $totalDuration = microtime(true) - $welcomeStartTime;

    if ($messagesFailed > 0) {
      ogLog::warning("send - Welcome completado con errores", [
        'messages_sent' => $messagesSent,
        'messages_failed' => $messagesFailed,
        'failed_messages' => $failedMessages,
        'total_duration_seconds' => round($totalDuration, 2),
        'sale_id' => $saleId
      ], self::$logMeta);
    } else {
      ogLog::success("send - Welcome completado exitosamente", [
        'messages_sent' => $messagesSent,
        'total_duration_seconds' => round($totalDuration, 2),
        'sale_id' => $saleId
      ], self::$logMeta);
    }

    return [
      'success' => $messagesSent > 0, // Éxito si al menos 1 mensaje se envió
      'total_messages' => $totalMessages,
      'messages_sent' => $messagesSent,
      'messages_failed' => $messagesFailed,
      'failed_messages' => $failedMessages,
      'client_id' => $clientId,
      'sale_id' => $saleId,
      'duration_seconds' => round($totalDuration, 2),
      'error' => $messagesSent === 0 ? 'Ningún mensaje se envió exitosamente' : null
    ];
  }

  // Calcular plan simplificado
  private static function calculateSimplePlan($messages, $bot) {
    $plan = ['initial' => null, 'messages' => []];

    // Delay inicial: fijo, sin variación
    $initialDelay = (int)($bot['config']['welcome_initial_delay'] ?? 2);
    $plan['initial'] = ['ms' => $initialDelay * 1000];

    // Delays de mensajes: con variación ±2s
    foreach ($messages as $index => $msg) {
      $configuredDelay = (int)($msg['delay'] ?? 3);
      $plan['messages'][$index] = self::calculateSimpleDelay($configuredDelay);
    }

    return $plan;
  }

  // Calcular delay simple: variación ±2s, 1 pulse
  private static function calculateSimpleDelay($seconds) {
    if ($seconds <= 0) {
      return ['ms' => 0];
    }

    // Variación ±2 segundos
    $variation = rand(-2, 2);
    $realSeconds = max(1, $seconds + $variation);

    return ['ms' => $realSeconds * 1000];
  }

  // Ejecutar delay con 1 pulse (Evolution + sleep maneja todo)
  private static function executeDelay($to, $delayData, $bot) {
    $ms = $delayData['ms'];

    if ($ms <= 0) return;

    $chatapi = ogApp()->service('chatApi');
    $provider = $chatapi::getProvider();

    ogLog::debug("executeDelay - Ejecutando", [
      'ms' => $ms,
      'provider' => $provider
    ], self::$logMeta);

    // Para APIs sin typing, reducir tiempo
    if ($provider !== 'evolutionapi') {
      $reduction = (int)($bot['config']['no_typing_delay_reduction'] ?? 1);
      $seconds = max(1, ($ms / 1000) - $reduction);
      sleep($seconds);
      return;
    }

    // Evolution API: 1 solo sendPresence (ya incluye sleep interno)
    try {
      $chatapi::sendPresence($to, 'composing', $ms);
      ogLog::debug("executeDelay - Completado", [], self::$logMeta);
    } catch (Exception $e) {
      ogLog::error("executeDelay - Error en sendPresence", [
        'error' => $e->getMessage()
      ], self::$logMeta);
      sleep($ms / 1000);
    }
  }

  private static function cancelPendingSaleFollowups($number, $botId, $newProductId) {
    try {
      $pendingSales = ogDb::t('sales')
        ->where('number', $number)
        ->where('bot_id', $botId)
        ->whereNotIn('process_status', ['sale_confirmed', 'cancelled', 'refunded'])
        ->where('product_id', '!=', $newProductId)
        ->get();

      if (empty($pendingSales)) return;

      ogApp()->loadHandler('followup');
      $totalCancelled = 0;

      foreach ($pendingSales as $sale) {
        $affected = FollowupHandler::cancelBySale($sale['id']);
        $totalCancelled += $affected;
      }

      if ($totalCancelled > 0) {
        ogLog::success("cancelPendingSaleFollowups - Followups cancelados", [
          'total_cancelled' => $totalCancelled
        ], self::$logMeta);
      }
    } catch (Exception $e) {
      ogLog::error("cancelPendingSaleFollowups - Error", [
        'error' => $e->getMessage()
      ], self::$logMeta);
    }
  }
}