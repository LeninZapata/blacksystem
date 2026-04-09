<?php

class FollowupHandler {

  private static $lastCalculatedDate = null;
  private static $logMeta = ['module' => 'FollowupHandler', 'layer' => 'app/resources'];

  // Variable estática para almacenar el user_id globalmente
  private static $currentUserId = null;

  // Configuración de horarios
  private static $startHour = 8;  // 08:00 AM
  private static $endHour = 22;   // 22:00 (último permitido 21:59)

  // Configuración de variación aleatoria
  private static $minutesBefore = 15;
  private static $minutesAfter = 15;

  // Establecer el user_id global
  static function setUserId($userId) {
    self::$currentUserId = $userId;
  }

  // Obtener el user_id actual
  static function getUserId() {
    return self::$currentUserId;
  }

  // Resolver user_id automáticamente
  private static function resolveUserId($botId = null) {
    // Prioridad 1: user_id seteado en FollowupHandler
    if (self::$currentUserId !== null) {
      return self::$currentUserId;
    }

    // Prioridad 2: user_id desde ChatHandler (compartido)
    ogApp()->loadHandler('chat');
    $chatUserId = ChatHandler::getUserId();
    if ($chatUserId !== null) {
      self::$currentUserId = $chatUserId;
      return $chatUserId;
    }

    // Prioridad 3: Obtener desde la tabla bots
    if ($botId !== null) {
      try {
        $bot = ogDb::t('bots')
          ->where('id', $botId)
          ->first();

        if ($bot && isset($bot['user_id'])) {
          self::$currentUserId = $bot['user_id'];
          return $bot['user_id'];
        }
      } catch (Exception $e) {
        ogLog::error("FollowupHandler - Error obteniendo user_id desde bot", [
          'bot_id' => $botId,
          'error' => $e->getMessage()
        ], self::$logMeta);
      }
    }

    ogLog::throwError("FollowupHandler - No se pudo resolver user_id", [
      'bot_id' => $botId
    ], self::$logMeta);
  }

  // Configurar horarios permitidos
  static function setAllowedHours($startHour, $endHour) {
    self::$startHour = $startHour;
    self::$endHour = $endHour;
  }

  // Configurar variación de minutos
  static function setMinutesVariation($minutesBefore, $minutesAfter) {
    self::$minutesBefore = $minutesBefore;
    self::$minutesAfter = $minutesAfter;
  }

  // Detectar si el bot usa WhatsApp Cloud API (Facebook)
  private static function usesFacebookProvider($botData) {
    $chatApis = $botData['config']['apis']['chat'] ?? [];
    
    foreach ($chatApis as $api) {
      $typeValue = $api['config']['type_value'] ?? '';
      if ($typeValue === 'whatsapp-cloud-api') {
        return true;
      }
    }
    
    return false;
  }

  // Registrar followups desde venta
  static function registerFromSale($saleData, $followups, $botTimezone, $botData = null) {
    if (empty($followups)) {
      return ['success' => true, 'count' => 0];
    }

    // Resolver user_id automáticamente
    $userId = self::resolveUserId($saleData['bot_id'] ?? null);

    self::$lastCalculatedDate = null;
    $botTz = new DateTimeZone($botTimezone);

    // PASO 1: Calcular todas las fechas futuras normalmente
    $calculatedFollowups = [];
    foreach ($followups as $index => $fup) {
      $result = self::calculateFutureDate($fup, $botTimezone);
      $futureDate = $result['future_date'];
      $dateInBotTz = $result['date_in_bot_tz'];

      $calculatedFollowups[] = [
        'index' => $index,
        'config' => $fup,
        'future_date' => $futureDate,
        'date_in_bot_tz' => $dateInBotTz,
        'future_timestamp' => strtotime($futureDate)
      ];

      self::$lastCalculatedDate = $dateInBotTz;
    }

    // PASO 2: Si usa Facebook, ajustar las últimas 24 horas dentro de 70h
    if ($botData && self::usesFacebookProvider($botData)) {

      $calculatedFollowups = self::adjustFor70Hours($calculatedFollowups, $botTimezone);
    }

    // Obtener fecha máxima de envío:
    // Prioridad 1: viene calculada desde el llamador (open_chat aún no existe en BD)
    // Prioridad 2: consultar client_bot_meta si ya existe
    $maxSendAt = $saleData['max_send_at'] ?? null;

    if ($maxSendAt === null) {
      $openChatMeta = ogDb::raw(
        "SELECT meta_value FROM client_bot_meta WHERE client_id = ? AND bot_id = ? AND meta_key = 'open_chat' ORDER BY meta_value DESC LIMIT 1",
        [$saleData['client_id'], $saleData['bot_id']]
      );
      $maxSendAt = $openChatMeta[0]['meta_value'] ?? null;
    }

    // PASO 3: Insertar followups en BD
    $count = 0;
    foreach ($calculatedFollowups as $item) {
      $fup = $item['config'];
      $fullMessage = $fup['message'] ?? '';

      $data = [
        'user_id' => $userId,
        'sale_id' => $saleData['sale_id'],
        'product_id' => $saleData['product_id'],
        'client_id' => $saleData['client_id'],
        'bot_id' => $saleData['bot_id'],
        'context' => 'whatsapp',
        'number' => $saleData['number'],
        'bsuid'  => $saleData['bsuid'] ?? null,
        'name' => $fup['tracking_id'] ?? null,
        'instruction' => !empty($fup['instruction']) ? $fup['instruction'] : '',
        'text' => $fullMessage ?: null,
        'source_url' => $fup['url'] ?? null,
        'args' => !empty($fup['buttons']) || !empty($fup['footer'])
          ? json_encode(['buttons' => $fup['buttons'] ?? [], 'footer' => $fup['footer'] ?? ''])
          : null,
        'future_date' => $item['future_date'],
        'max_send_at' => $maxSendAt,
        'processed' => 0,
        'status' => 1,
        'dc' => gmdate('Y-m-d H:i:s'),
        'tc' => time()
      ];

      // Validar: debe tener texto O media
      $hasText = !empty($fullMessage);
      $hasMedia = !empty($fup['url']);

      if (!$hasText && !$hasMedia) {
        ogLog::warning("registerFromSale - Followup sin texto ni media, omitido", [
          'tracking_id' => $fup['tracking_id'],
          'index' => $item['index']
        ], self::$logMeta);
        continue;
      }

      ogDb::t('followups')->insert($data);
      $count++;
    }

    return ['success' => true, 'count' => $count];
  }

  // Ajustar followups para que estén dentro de 70 horas
  private static function adjustFor70Hours($followups, $botTimezone) {
    if (empty($followups)) return $followups;

    $botTz = new DateTimeZone($botTimezone);
    $now = new DateTime('now', $botTz);
    $maxTimestamp = $now->getTimestamp() + (70 * 3600); // 70 horas desde ahora

    // Identificar followups que pasan de 70h
    $needsAdjustment = false;
    $last24HoursStart = $maxTimestamp - (24 * 3600); // Últimas 24h = desde hora 46 hasta 70

    foreach ($followups as $item) {
      if ($item['future_timestamp'] > $maxTimestamp) {
        $needsAdjustment = true;
        break;
      }
    }

    if (!$needsAdjustment) {
      return $followups;
    }

    // Ajustar: comprimir los que están en las últimas 24h hacia atrás
    $adjusted = [];
    $usedSlots = []; // Para evitar colisiones

    foreach ($followups as $item) {
      $timestamp = $item['future_timestamp'];

      // Si está dentro de las primeras 46h, dejar como está
      if ($timestamp <= $last24HoursStart) {
        $adjusted[] = $item;
        $usedSlots[] = $timestamp;
        continue;
      }

      // Si está en las últimas 24h o se pasa de 70h, ajustar
      if ($timestamp > $maxTimestamp) {
        // Mover hacia atrás al límite de 70h
        $newTimestamp = $maxTimestamp;
      } else {
        $newTimestamp = $timestamp;
      }

      // Resolver colisiones: si ya existe un followup en ese minuto, mover 1h atrás
      while (in_array($newTimestamp, $usedSlots)) {
        $newTimestamp -= 3600; // 1 hora atrás
      }

      // Actualizar fecha — guardar future_date en UTC para consistencia con el CRON
      $newDate = new DateTime('@' . $newTimestamp); // DateTime desde unix timestamp siempre es UTC
      $newDateBotTz = clone $newDate;
      $newDateBotTz->setTimezone($botTz);

      $item['future_date'] = $newDate->format('Y-m-d H:i:s'); // UTC
      $item['future_timestamp'] = $newTimestamp;
      $item['date_in_bot_tz'] = $newDateBotTz->format('Y-m-d H:i:s'); // para logs

      $adjusted[] = $item;
      $usedSlots[] = $newTimestamp;
    }

    return $adjusted;
  }

  // Calcular fecha futura según timezone del bot
  private static function calculateFutureDate($fup, $botTimezone) {
    $utcTz = new DateTimeZone('UTC');
    $botTz = new DateTimeZone($botTimezone);

    // Fecha base según si ya calculamos alguna anterior
    if (self::$lastCalculatedDate) {
      $baseDate = new DateTime(self::$lastCalculatedDate, $botTz);
    } else {
      $baseDate = new DateTime('now', $botTz);
    }

    $timeType = $fup['time_type'] ?? 'minuto';
    $timeValue = (int)($fup['time_value'] ?? 1);
    $timeHour = $fup['hour'] ?? $fup['time_hour'] ?? '12:00';

    switch ($timeType) {
      case 'minuto':
        $baseDate->modify("+{$timeValue} minutes");
        break;

      case 'hora':
        $baseDate->modify("+{$timeValue} hours");
        break;

      case 'dia':
        $baseDate->modify("+{$timeValue} days");
        list($hour, $minute) = explode(':', $timeHour);
        $baseDate->setTime((int)$hour, (int)$minute, 0);
        break;

      case 'esta_noche':
        $defaultHour = $timeHour !== '00:00' ? $timeHour : '20:00';
        list($hour, $minute) = explode(':', $defaultHour);

        $previousDate = clone $baseDate;
        $baseDate->setTime((int)$hour, (int)$minute, 0);

        if ($baseDate <= $previousDate) {
          $baseDate->modify('+1 day');
        }
        break;

      case 'fin_semana':
        $previousDate = clone $baseDate;
        $baseDate->modify('next sunday');
        list($hour, $minute) = explode(':', $timeHour);
        $baseDate->setTime((int)$hour, (int)$minute, 0);

        if ($baseDate <= $previousDate) {
          $baseDate->modify('+7 days');
        }
        break;

      case 'quincena_actual':
        $previousDate = clone $baseDate;
        $currentDay = (int)$baseDate->format('d');
        if ($currentDay <= 15) {
          $baseDate->setDate((int)$baseDate->format('Y'), (int)$baseDate->format('m'), 15);
        } else {
          $baseDate->modify('last day of this month');
        }
        list($hour, $minute) = explode(':', $timeHour);
        $baseDate->setTime((int)$hour, (int)$minute, 0);

        if ($baseDate <= $previousDate) {
          if ($currentDay <= 15) {
            $baseDate->modify('last day of this month');
          } else {
            $baseDate->modify('first day of next month');
            $baseDate->setDate((int)$baseDate->format('Y'), (int)$baseDate->format('m'), 15);
          }
          $baseDate->setTime((int)$hour, (int)$minute, 0);
        }
        break;

      case 'inicio_mes_proximo':
        $previousDate = clone $baseDate;
        $baseDate->modify('first day of next month');
        $baseDate->modify('+1 day');
        list($hour, $minute) = explode(':', $timeHour);
        $baseDate->setTime((int)$hour, (int)$minute, 0);

        if ($baseDate <= $previousDate) {
          $baseDate->modify('+1 month');
        }
        break;

      default:
        break;
    }

    // Aplicar restricciones de horario
    $dateBeforeRestrictions = clone $baseDate;
    $baseDate = self::applyTimeRestrictions($baseDate);

    // Aplicar variación aleatoria si NO es tipo inmediato
    $shouldApplyVariation = !in_array($timeType, ['minuto', 'hora']);
    if ($shouldApplyVariation) {
      $baseDate = self::applyRandomMinutesVariation($baseDate);
    }

    $dateInBotTz = $baseDate->format('Y-m-d H:i:s');

    // Convertir siempre a UTC antes de guardar en DB.
    // El CRON compara con date('Y-m-d H:i:s') que ahora también es UTC.
    // Siempre convertir a UTC antes de guardar en DB
    $baseDate->setTimezone($utcTz);

    return [
      'future_date' => $baseDate->format('Y-m-d H:i:s'),
      'date_in_bot_tz' => $dateInBotTz
    ];
  }

  // Aplicar restricciones de horario permitido
  private static function applyTimeRestrictions($dateTime) {
    $hour = (int)$dateTime->format('H');

    if ($hour >= self::$endHour) {
      $dateTime->modify('+1 day');
      $dateTime->setTime(self::$startHour, 0, 0);
    } elseif ($hour < self::$startHour) {
      $dateTime->setTime(self::$startHour, 0, 0);
    }

    return $dateTime;
  }

  // Aplicar variación aleatoria de minutos
  private static function applyRandomMinutesVariation($dateTime) {
    $minVariation = -self::$minutesBefore;
    $maxVariation = self::$minutesAfter;
    $randomMinutes = rand($minVariation, $maxVariation);

    if ($randomMinutes > 0) {
      $dateTime->modify("+{$randomMinutes} minutes");
    } elseif ($randomMinutes < 0) {
      $absMinutes = abs($randomMinutes);
      $dateTime->modify("-{$absMinutes} minutes");
    }

    return $dateTime;
  }

  // Obtener followups pendientes organizados por bot
  static function getPending() {
    $now = gmdate('Y-m-d H:i:s');
    $botsConfig = [];
    $allFollowups = [];
    $seenNumbers = [];

    // ID único para esta ejecución del cron
    $workerId = uniqid('w_', true);

    // Liberar huérfanos: reclamados hace más de 15 min sin procesar
    // (proceso que murió a mitad sin marcar processed=1)
    $orphanCutoff = gmdate('Y-m-d H:i:s', time() - 900);
    ogDb::raw(
      "UPDATE followups SET claimed=0, worker_id=NULL, claimed_at=NULL
       WHERE claimed=1 AND processed=0 AND claimed_at < ?",
      [$orphanCutoff]
    );

    $activeBots = ogDb::t('bots')
      ->where('status', 1)
      ->get();

    if (empty($activeBots)) {
      return [
        'bots_config' => [],
        'followups' => []
      ];
    }

    foreach ($activeBots as $bot) {
      $botId = $bot['id'];
      $botNumber = $bot['number'];

      ogApp()->loadHandler('bot');
      $botData = BotHandler::getDataFile($botNumber);
      if (!$botData) continue;

      $botsConfig[$botId] = $botData;

      // UPDATE atómico: solo este worker puede reclamar estos registros.
      // Si otro cron llega al mismo tiempo, su UPDATE no verá estos
      // porque claimed=0 ya no aplica para los que este worker reclamó.
      ogDb::raw(
        "UPDATE followups SET claimed=1, worker_id=?, claimed_at=?
         WHERE bot_id=? AND processed=0 AND claimed=0 AND status=1 AND future_date<=?
         LIMIT 40",
        [$workerId, $now, $botId, $now]
      );

      // Traer únicamente los que este worker reclamó
      $followups = ogDb::t('followups')
        ->where('bot_id', $botId)
        ->where('worker_id', $workerId)
        ->orderBy('future_date', 'ASC')
        ->get();

      if (empty($followups)) continue;

      $toRelease = [];

      foreach ($followups as $fup) {
        $personNumber = $fup['number'] ?? $fup['bsuid']; // Phase 3: fallback a bsuid

        // Validar ventana de conversación: si max_send_at expiró, cancelar y omitir
        if (!empty($fup['max_send_at']) && $fup['max_send_at'] < $now) {
          ogDb::t('followups')->where('id', $fup['id'])->update([
            'processed' => 2,
            'du' => $now,
            'tu' => time()
          ]);
          continue;
        }

        if (!isset($seenNumbers[$personNumber])) {
          $fup['bot_number'] = $botNumber;
          $allFollowups[] = $fup;
          $seenNumbers[$personNumber] = true;
        } else {
          // Ya hay un followup de esta persona en este ciclo.
          // Liberar para que el próximo cron lo procese normalmente.
          $toRelease[] = (int)$fup['id'];
        }
      }

      // Liberar los filtrados por seenNumbers de forma inmediata
      if (!empty($toRelease)) {
        $ids = implode(',', $toRelease);
        ogDb::raw(
          "UPDATE followups SET claimed=0, worker_id=NULL, claimed_at=NULL WHERE id IN ({$ids})"
        );
      }
    }

    return [
      'bots_config' => $botsConfig,
      'followups' => $allFollowups
    ];
  }

  // Marcar como procesado
  static function markProcessed($id) {
    return ogDb::t('followups')
      ->where('id', $id)
      ->update([
        'processed' => 1,
        'du' => gmdate('Y-m-d H:i:s'),
        'tu' => time()
      ]);
  }

  // Cancelar followups por venta
  static function cancelBySale($saleId) {
    return ogDb::t('followups')
      ->where('sale_id', $saleId)
      ->where('processed', 0)
      ->update([
        'processed' => 2,
        'du' => gmdate('Y-m-d H:i:s'),
        'tu' => time()
      ]);
  }

  // Obtener followup por ID
  static function getById($followupId) {
    try {
      $followup = ogDb::t('followups')
        ->where('id', $followupId)
        ->first();

      return $followup;

    } catch (Exception $e) {
      ogLog::error("getById - Error", [
        'followup_id' => $followupId,
        'error' => $e->getMessage()
      ], self::$logMeta);

      return null;
    }
  }

  // Obtener followups por sale_id
  static function getBySale($saleId, $includeProcessed = false) {
    try {
      $query = ogDb::t('followups')
        ->where('sale_id', $saleId);

      if (!$includeProcessed) {
        $query->where('processed', 0);
      }

      $followups = $query->orderBy('future_date', 'ASC')->get();

      return $followups;

    } catch (Exception $e) {
      ogLog::error("getBySale - Error", [
        'sale_id' => $saleId,
        'error' => $e->getMessage()
      ], self::$logMeta);

      return [];
    }
  }

}