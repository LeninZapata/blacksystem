<?php

class FollowupHandler {

  private static $lastCalculatedDate = null;
  private static $logMeta = ['module' => 'FollowupHandler', 'layer' => 'app/resources'];

  // Variable estática para almacenar el user_id globalmente (NUEVO)
  private static $currentUserId = null;

  // Configuración de horarios (desde infoproduct-v2.php)
  private static $startHour = 8;  // 08:00 AM
  private static $endHour = 22;   // 22:00 (último permitido 21:59)

  // Configuración de variación aleatoria (desde infoproduct-v2.php)
  private static $minutesBefore = 15;  // Minutos a restar (máximo)
  private static $minutesAfter = 15;   // Minutos a sumar (máximo)

  // Establecer el user_id global (NUEVO)
  static function setUserId($userId) {
    self::$currentUserId = $userId;
  }

  // Obtener el user_id actual (NUEVO)
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

    // Prioridad 3: Obtener desde la tabla bots (si se proporciona botId)
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

    // Si no se puede resolver, lanzar error
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

  // Registrar followups desde venta
  static function registerFromSale($saleData, $followups, $botTimezone) {
    if (empty($followups)) {
      return ['success' => true, 'count' => 0];
    }

    // Resolver user_id automáticamente (NUEVO)
    $userId = self::resolveUserId($saleData['bot_id'] ?? null);

    self::$lastCalculatedDate = null;
    $count = 0;
    $botTz = new DateTimeZone($botTimezone);

    foreach ($followups as $fup) {
      $result = self::calculateFutureDate($fup, $botTimezone);
      $futureDate = $result['future_date'];
      $dateInBotTz = $result['date_in_bot_tz'];

      // Limitar mensaje a 20 caracteres
      $fullMessage = $fup['message'] ?? '';
     /* $shortMessage = mb_strlen($fullMessage) > 20
        ? mb_substr($fullMessage, 0, 20) . '...'
        : $fullMessage;*/

      $data = [
        'user_id' => $userId, // AGREGADO
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
        'future_date' => $futureDate,
        'processed' => 0,
        'status' => 1,
        'dc' => date('Y-m-d H:i:s'),
        'tc' => time()
      ];

      // Validar: debe tener texto O media (source_url)
      $hasText = !empty($fullMessage);
      $hasMedia = !empty($fup['url']);

      if (!$hasText && !$hasMedia) {
        ogLog::warning("registerFromSale - Followup sin texto ni media, omitido", [
          'tracking_id' => $fup['tracking_id'],
          'index' => $count
        ], self::$logMeta);
        continue;
      }

      ogDb::t('followups')->insert($data);
      $count++;

      // Actualizar lastCalculatedDate con la fecha en timezone del bot
      self::$lastCalculatedDate = $dateInBotTz;
    }

    return ['success' => true, 'count' => $count];
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

        // Guardar la fecha/hora anterior antes de modificar
        $previousDate = clone $baseDate;

        // Establecer la hora de "esta noche" en el día actual de $baseDate
        $baseDate->setTime((int)$hour, (int)$minute, 0);

        // Si la hora ya pasó respecto a la fecha anterior, mover al siguiente día
        if ($baseDate <= $previousDate) {
          $baseDate->modify('+1 day');
        }
        break;

      case 'fin_semana':
        $previousDate = clone $baseDate;
        $baseDate->modify('next sunday');
        list($hour, $minute) = explode(':', $timeHour);
        $baseDate->setTime((int)$hour, (int)$minute, 0);

        // Si el domingo calculado es anterior a la fecha previa, avanzar una semana más
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

        // Si la fecha calculada es anterior a la previa, mover a la siguiente quincena
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

        // Si la fecha calculada es anterior a la previa, mover al mes siguiente
        if ($baseDate <= $previousDate) {
          $baseDate->modify('+1 month');
        }
        break;

      default:
        break;
    }

    // Aplicar restricciones de horario (en timezone del bot)
    $dateBeforeRestrictions = clone $baseDate;
    $baseDate = self::applyTimeRestrictions($baseDate);

    // Aplicar variación aleatoria si NO es tipo inmediato (en timezone del bot)
    $shouldApplyVariation = !in_array($timeType, ['minuto', 'hora']);
    if ($shouldApplyVariation) {
      $baseDate = self::applyRandomMinutesVariation($baseDate);
    }

    // Guardar la fecha en botTz ANTES de convertir a Ecuador
    $dateInBotTz = $baseDate->format('Y-m-d H:i:s');

    // Determinar si debe convertirse a Ecuador
    // Para minuto/hora: solo convertir si se movió al día siguiente por restricciones
    $isImmediateType = in_array($timeType, ['minuto', 'hora']);
    $movedToNextDay = $baseDate->format('Y-m-d') > $dateBeforeRestrictions->format('Y-m-d');

    if (!$isImmediateType || $movedToNextDay) {
      $baseDate->setTimezone($ecuadorTz);
    }

    // Retornar array con ambas fechas
    return [
      'future_date' => $baseDate->format('Y-m-d H:i:s'),
      'date_in_bot_tz' => $dateInBotTz
    ];
  }

  // Aplicar restricciones de horario permitido
  private static function applyTimeRestrictions($dateTime) {
    $hour = (int)$dateTime->format('H');

    // Si es >= hora fin (ej: 22:00 o más) → Mover al día siguiente startHour
    if ($hour >= self::$endHour) {
      $dateTime->modify('+1 day');
      $dateTime->setTime(self::$startHour, 0, 0);
    }
    // Si es < hora inicio (ej: antes de 08:00) → Mover a startHour del mismo día
    elseif ($hour < self::$startHour) {
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
    $seenNumbers = []; // Para trackear números ya procesados

    // PASO 1: Obtener bots activos
    $activeBots = ogDb::t('bots')
      ->where('status', 1)
      ->get();

    if (empty($activeBots)) {
      return [
        'bots_config' => [],
        'followups' => []
      ];
    }

    // PASO 2: Por cada bot, cargar config y obtener followups pendientes
    foreach ($activeBots as $bot) {
      $botId = $bot['id'];
      $botNumber = $bot['number'];

      // Cargar configuración del bot desde archivo JSON
      ogApp()->loadHandler('bot');
      $botData = BotHandler::getDataFile($botNumber);
      if (!$botData) continue;

      $botsConfig[$botId] = $botData;

      // Obtener followups pendientes de este bot
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

          // Filtrar: Solo agregar el primer followup de cada número
          if (!isset($seenNumbers[$personNumber])) {
            // Agregar bot_number al followup
            $fup['bot_number'] = $botNumber;

            $allFollowups[] = $fup;
            $seenNumbers[$personNumber] = true;
          }
          // Si ya existe este número, ignorar este followup (quedarse con el primero)
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