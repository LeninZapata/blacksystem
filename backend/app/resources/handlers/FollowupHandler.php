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
      ogLog::info("registerFromSale - Facebook detectado, ajustando followups a 70h", [
        'total_followups' => count($calculatedFollowups)
      ], self::$logMeta);

      $calculatedFollowups = self::adjustFor70Hours($calculatedFollowups, $botTimezone);
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
        'name' => $fup['tracking_id'] ?? null,
        'instruction' => !empty($fup['instruction']) ? $fup['instruction'] : '',
        'text' => $fullMessage ?: null,
        'source_url' => $fup['url'] ?? null,
        'future_date' => $item['future_date'],
        'processed' => 0,
        'status' => 1,
        'dc' => date('Y-m-d H:i:s'),
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

    ogLog::debug("adjustFor70Hours - Analizando followups", [
      'now' => $now->format('Y-m-d H:i:s'),
      'max_time' => date('Y-m-d H:i:s', $maxTimestamp),
      'total_followups' => count($followups)
    ], self::$logMeta);

    // Identificar followups que pasan de 70h
    $needsAdjustment = false;
    $last24HoursStart = $maxTimestamp - (24 * 3600); // Últimas 24h = desde hora 46 hasta 70

    foreach ($followups as $item) {
      if ($item['future_timestamp'] > $maxTimestamp) {
        $needsAdjustment = true;
        ogLog::info("adjustFor70Hours - Followup excede 70h", [
          'index' => $item['index'],
          'original_date' => $item['future_date'],
          'hours_from_now' => round(($item['future_timestamp'] - $now->getTimestamp()) / 3600, 2)
        ], self::$logMeta);
        break;
      }
    }

    if (!$needsAdjustment) {
      ogLog::info("adjustFor70Hours - Todos los followups están dentro de 70h", [], self::$logMeta);
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
        ogLog::debug("adjustFor70Hours - Resolviendo colisión", [
          'index' => $item['index'],
          'moved_back_1h' => true
        ], self::$logMeta);
      }

      // Actualizar fecha
      $newDate = new DateTime('@' . $newTimestamp);
      $newDate->setTimezone($botTz);

      $item['future_date'] = $newDate->format('Y-m-d H:i:s');
      $item['future_timestamp'] = $newTimestamp;
      $item['date_in_bot_tz'] = $newDate->format('Y-m-d H:i:s');

      ogLog::info("adjustFor70Hours - Followup ajustado", [
        'index' => $item['index'],
        'original_date' => date('Y-m-d H:i:s', $timestamp),
        'new_date' => $item['future_date'],
        'hours_from_now' => round(($newTimestamp - $now->getTimestamp()) / 3600, 2)
      ], self::$logMeta);

      $adjusted[] = $item;
      $usedSlots[] = $newTimestamp;
    }

    return $adjusted;
  }

  // Calcular fecha futura según timezone del bot
  private static function calculateFutureDate($fup, $botTimezone) {
    $ecuadorTz = new DateTimeZone('America/Guayaquil');
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

    // Determinar si debe convertirse a Ecuador
    $isImmediateType = in_array($timeType, ['minuto', 'hora']);
    $movedToNextDay = $baseDate->format('Y-m-d') > $dateBeforeRestrictions->format('Y-m-d');

    if (!$isImmediateType || $movedToNextDay) {
      $baseDate->setTimezone($ecuadorTz);
    }

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
    $now = date('Y-m-d H:i:s');
    $botsConfig = [];
    $allFollowups = [];
    $seenNumbers = [];

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

      $followups = ogDb::t('followups')
        ->where('bot_id', $botId)
        ->where('processed', 0)
        ->where('status', 1)
        ->where('future_date', '<=', $now)
        ->orderBy('future_date', 'ASC')
        ->limit(50)
        ->get();

      if (!empty($followups)) {
        foreach ($followups as $fup) {
          $personNumber = $fup['number'];

          if (!isset($seenNumbers[$personNumber])) {
            $fup['bot_number'] = $botNumber;
            $allFollowups[] = $fup;
            $seenNumbers[$personNumber] = true;
          }
        }
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
        'du' => date('Y-m-d H:i:s'),
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
        'du' => date('Y-m-d H:i:s'),
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

      ogLog::debug("getBySale - Followups obtenidos", [
        'sale_id' => $saleId,
        'count' => count($followups)
      ], self::$logMeta);

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