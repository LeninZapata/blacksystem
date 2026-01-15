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
    $clientId = null;
    $saleId = null;

    // Calcular plan completo de delays aleatorios
    $delayPlan = self::calculateDelayPlan($messages, $bot);

    ogLog::info("send - Plan de delays calculado", [
      'total_messages' => $totalMessages,
      'initial' => [
        'configured' => self::getInitialDelay($bot),
        'real' => $delayPlan['initial']['delay_seconds'],
        'pulses' => $delayPlan['initial']['typing_pulses']
      ],
      'messages' => array_map(function($index) use ($messages, $delayPlan) {
        return [
          'message_index' => $index + 1,
          'configured' => (int)($messages[$index]['delay'] ?? 0),
          'real' => $delayPlan['messages'][$index]['delay_seconds'],
          'pulses' => $delayPlan['messages'][$index]['typing_pulses']
        ];
      }, array_keys($delayPlan['messages']))
    ], self::$logMeta);

    // Sleep inicial crudo (2s sin typing)
    sleep(2);

    // Delay inicial con typing
    if ($delayPlan['initial']['delay_seconds'] > 0) {
      self::executeTypingPlan($from, $delayPlan['initial'], $bot);
    }

    $chatapi = ogApp()->service('chatApi');
    $welcomeStartTime = microtime(true);

    foreach ($messages as $index => $msg) {
      $text = $msg['message'] ?? '';
      $url = !empty($msg['url']) && $msg['type'] != 'text' ? $msg['url'] : '';

      // Aplicar delay ANTES de enviar este mensaje
      $delayData = $delayPlan['messages'][$index];

      if ($delayData['delay_seconds'] > 0) {

        $delayStartTime = microtime(true);
        self::executeTypingPlan($from, $delayData, $bot);
        $delayActual = microtime(true) - $delayStartTime;

      }

      // Enviar mensaje
      $messageStartTime = microtime(true);
      $result = $chatapi::send($from, $text, $url);
      $messageSendDuration = microtime(true) - $messageStartTime;
      $messagesSent++;
      $elapsedSinceStart = microtime(true) - $welcomeStartTime;

      // Crear venta después del primer mensaje
      if ($messagesSent === 1 && $result['success']) {
        $saleResult = CreateSaleAction::create($dataSale);

        if ($saleResult['success']) {
          ogLog::success("send - Venta creada", [ 'sale_id' => $saleResult['sale_id'], 'client_id' => $saleResult['client_id'] ], self::$logMeta);
          $clientId = $saleResult['client_id'];
          $saleId = $saleResult['sale_id'];

          // Registrar followups
          $followups = ProductHandler::getMessagesFile('follow', $productId);
          if (!empty($followups)) {
            ogApp()->loadHandler('followup');
            FollowupHandler::registerFromSale([
              'sale_id' => $saleId,
              'product_id' => $productId,
              'client_id' => $clientId,
              'bot_id' => $bot['id'],
              'number' => $from
            ], $followups, $bot['config']['timezone'] ?? 'America/Guayaquil');
          }
        }
      }
    }

    $totalDuration = microtime(true) - $welcomeStartTime;
    ogLog::success("send - Welcome completado", [ 'messages_sent' => $messagesSent, 'total_duration_seconds' => round($totalDuration, 2), 'sale_id' => $saleId ], self::$logMeta);

    return [
      'success' => true,
      'total_messages' => $totalMessages,
      'messages_sent' => $messagesSent,
      'client_id' => $clientId,
      'sale_id' => $saleId,
      'duration_seconds' => round($totalDuration, 2),
      'error' => null
    ];
  }

  // Calcular plan completo de delays con variación aleatoria
  private static function calculateDelayPlan($messages, $bot) {
    $plan = ['initial' => null, 'messages' => []];

    // Delay inicial
    $initialDelay = self::getInitialDelay($bot);
    $plan['initial'] = self::calculateDelayData($initialDelay, $bot);

    // Delay por cada mensaje (el delay se aplica ANTES de enviar ese mensaje)
    foreach ($messages as $index => $msg) {
      $configuredDelay = isset($msg['delay']) ? (int)$msg['delay'] : 3;
      $plan['messages'][$index] = self::calculateDelayData($configuredDelay, $bot);
    }

    return $plan;
  }

  // Calcular delay con variación del 20% y número de pulsos aleatorios
  private static function calculateDelayData($configuredDelay, $bot) {
    if ($configuredDelay <= 0) {
      return [
        'delay_seconds' => 0,
        'typing_pulses' => 0,
        'pulse_durations' => [],
        'pause_durations' => []
      ];
    }

    // Variación del 20% (puede ser -20% a +20%)
    $variation = rand(-20, 20) / 100;
    $realDelay = $configuredDelay * (1 + $variation);
    $realDelay = max(0.5, $realDelay);

    $minDelay = self::getMinTypingDelay($bot);

    // Si es menor al mínimo, no hay typing
    if ($realDelay < $minDelay) {
      return [
        'delay_seconds' => round($realDelay, 2),
        'typing_pulses' => 0,
        'pulse_durations' => [],
        'pause_durations' => []
      ];
    }

    // Calcular número de pulsos (máximo 5 por mensaje)
    // Garantizar múltiples pulsos para delays largos
    if ($realDelay <= 4) {
      $numPulses = 1; // Delays cortos: 1 pulso
    } else if ($realDelay <= 8) {
      $numPulses = rand(2, 3); // Delays medianos: 2-3 pulsos
    } else if ($realDelay <= 15) {
      $numPulses = rand(3, 4); // Delays largos: 3-4 pulsos
    } else {
      $numPulses = rand(4, 5); // Delays muy largos: 4-5 pulsos
    }

    // Distribuir el tiempo entre pulsos y pausas
    // 60% para typing, 40% para pausas
    $timeForTyping = $realDelay * 0.6;
    $timeForPauses = $realDelay * 0.4;

    // Generar duraciones de pulsos respetando el tiempo total
    $pulseDurations = [];
    $pauseDurations = [];

    // Distribuir tiempo de typing entre pulsos
    $avgPulseTime = $timeForTyping / $numPulses;
    $remainingTyping = $timeForTyping;

    for ($i = 0; $i < $numPulses; $i++) {
      if ($i === $numPulses - 1) {
        // Último pulso: usar tiempo restante (mínimo 1.5s, máximo 3.5s)
        $pulse = max(1.5, min(3.5, $remainingTyping));
        $pulseDurations[] = round($pulse, 2);
      } else {
        // Pulso aleatorio alrededor del promedio (±30%)
        $minPulse = max(1.5, $avgPulseTime * 0.7);
        $maxPulse = min(3.5, $avgPulseTime * 1.3);

        // Asegurar que quede suficiente tiempo para los pulsos restantes
        $minRequired = ($numPulses - $i - 1) * 1.5;
        $maxPulse = min($maxPulse, $remainingTyping - $minRequired);

        $minPulseCents = max(150, (int)round($minPulse * 100));
        $maxPulseCents = max($minPulseCents, (int)round($maxPulse * 100));
        $pulse = rand($minPulseCents, $maxPulseCents) / 100;
        $pulseDurations[] = round($pulse, 2);
        $remainingTyping -= $pulse;
      }
    }

    // Distribuir tiempo de pausas entre pulsos (excepto después del último)
    if ($numPulses > 1) {
      $numPauses = $numPulses - 1;
      $avgPauseTime = $timeForPauses / $numPauses;
      $remainingPauses = $timeForPauses;

      for ($i = 0; $i < $numPauses; $i++) {
        if ($i === $numPauses - 1) {
          // Última pausa: usar tiempo restante (mínimo 1.5s, máximo 3.5s)
          $pause = max(1.5, min(3.5, $remainingPauses));
          $pauseDurations[] = round($pause, 2);
        } else {
          // Pausa aleatoria alrededor del promedio (±30%)
          $minPause = max(1.5, $avgPauseTime * 0.7);
          $maxPause = min(3.5, $avgPauseTime * 1.3);

          // Asegurar que quede suficiente tiempo para las pausas restantes
          $minRequired = ($numPauses - $i - 1) * 1.5;
          $maxPause = min($maxPause, $remainingPauses - $minRequired);

          $minPauseCents = max(150, (int)round($minPause * 100));
          $maxPauseCents = max($minPauseCents, (int)round($maxPause * 100));
          $pause = rand($minPauseCents, $maxPauseCents) / 100;
          $pauseDurations[] = round($pause, 2);
          $remainingPauses -= $pause;
        }
      }
    }

    // El delay real es el configurado (con variación)
    return [
      'delay_seconds' => round($realDelay, 2),
      'typing_pulses' => $numPulses,
      'pulse_durations' => $pulseDurations,
      'pause_durations' => $pauseDurations
    ];
  }

  // Ejecutar el plan de typing con pausas entre pulsos
  private static function executeTypingPlan($to, $delayData, $bot) {
    $delaySeconds = $delayData['delay_seconds'];
    $typingPulses = $delayData['typing_pulses'];
    $pulseDurations = $delayData['pulse_durations'];
    $pauseDurations = $delayData['pause_durations'] ?? [];

    if ($delaySeconds <= 0) return;

    // Si no hay typing, solo sleep
    if ($typingPulses === 0) {
      usleep((int)($delaySeconds * 1000000));
      return;
    }

    $chatapi = ogApp()->service('chatApi');
    $provider = $chatapi::getProvider();

    foreach ($pulseDurations as $index => $pulseSec) {
      $pulseMs = (int)($pulseSec * 1000);

      // Enviar typing indicator
      try {
        if ($provider === 'evolutionapi') {
          $chatapi::sendPresence($to, 'composing', $pulseMs);
        } else {
          usleep((int)($pulseSec * 1000000));
        }
      } catch (Exception $e) {
        usleep((int)($pulseSec * 1000000));
      }

      // Pausa entre pulsos (excepto después del último)
      if ($index < $typingPulses - 1 && isset($pauseDurations[$index])) {
        $pauseSec = $pauseDurations[$index];

        usleep((int)($pauseSec * 1000000)); // Microsegundos
      }
    }

  }

  private static function getInitialDelay($bot) {
    return (int)($bot['config']['welcome_initial_delay'] ?? 2);
  }

  private static function getMinTypingDelay($bot) {
    return (float)($bot['config']['min_typing_delay'] ?? 1.4);
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