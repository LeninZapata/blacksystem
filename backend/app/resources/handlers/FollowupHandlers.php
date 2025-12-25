<?php

class FollowupHandlers {

  private static $table = DB_TABLES['followups'];
  private static $lastCalculatedDate = null;
  
  // Configuración de horarios (desde infoproduct-v2.php)
  private static $startHour = 8;  // 08:00 AM
  private static $endHour = 22;   // 22:00 (último permitido 21:59)

  // Configuración de variación aleatoria (desde infoproduct-v2.php)
  private static $minutesBefore = 15;  // Minutos a restar (máximo)
  private static $minutesAfter = 15;   // Minutos a sumar (máximo)

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

    self::$lastCalculatedDate = null;
    $count = 0;
    $botTz = new DateTimeZone($botTimezone);

    foreach ($followups as $fup) {
      $futureDate = self::calculateFutureDate($fup, $botTimezone);

      // Limitar mensaje a 20 caracteres
      $fullMessage = $fup['message'] ?? '';
      $shortMessage = mb_strlen($fullMessage) > 20 
        ? mb_substr($fullMessage, 0, 20) . '...' 
        : $fullMessage;

      $data = [
        'sale_id' => $saleData['sale_id'],
        'product_id' => $saleData['product_id'],
        'client_id' => $saleData['client_id'],
        'bot_id' => $saleData['bot_id'],
        'context' => 'whatsapp',
        'number' => $saleData['number'],
        'name' => $fup['tracking_id'] ?? null,
        'instruction' => !empty($fup['instruction']) ? $fup['instruction'] : '',
        'text' => $shortMessage ?: null,
        'source_url' => $fup['url'] ?? null,
        'future_date' => $futureDate,
        'processed' => 0,
        'status' => 1,
        'dc' => date('Y-m-d H:i:s'),
        'tc' => time()
      ];

      db::table(self::$table)->insert($data);
      $count++;

      // Actualizar lastCalculatedDate en timezone del bot
      $isImmediateType = in_array($fup['time_type'] ?? 'minuto', ['minuto', 'hora']);
      if ($isImmediateType) {
        self::$lastCalculatedDate = $futureDate;
      } else {
        $ecuadorTz = new DateTimeZone('America/Guayaquil');
        $dt = new DateTime($futureDate, $ecuadorTz);
        $dt->setTimezone($botTz);
        self::$lastCalculatedDate = $dt->format('Y-m-d H:i:s');
      }
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
        $baseDate->setTime((int)$hour, (int)$minute, 0);
        $now = new DateTime('now', $botTz);
        if ($baseDate <= $now) {
          $baseDate->modify('+1 day');
        }
        break;

      case 'fin_semana':
        $baseDate->modify('next sunday');
        list($hour, $minute) = explode(':', $timeHour);
        $baseDate->setTime((int)$hour, (int)$minute, 0);
        break;

      case 'quincena_actual':
        $currentDay = (int)$baseDate->format('d');
        if ($currentDay <= 15) {
          $baseDate->setDate((int)$baseDate->format('Y'), (int)$baseDate->format('m'), 15);
        } else {
          $baseDate->modify('last day of this month');
        }
        list($hour, $minute) = explode(':', $timeHour);
        $baseDate->setTime((int)$hour, (int)$minute, 0);
        break;

      case 'inicio_mes_proximo':
        $baseDate->modify('first day of next month');
        $baseDate->modify('+1 day');
        list($hour, $minute) = explode(':', $timeHour);
        $baseDate->setTime((int)$hour, (int)$minute, 0);
        break;

      default:
        break;
    }

    // Aplicar restricciones de horario (en timezone del bot)
    $baseDate = self::applyTimeRestrictions($baseDate);

    // Aplicar variación aleatoria si NO es tipo inmediato (en timezone del bot)
    $shouldApplyVariation = !in_array($timeType, ['minuto', 'hora']);
    if ($shouldApplyVariation) {
      $baseDate = self::applyRandomMinutesVariation($baseDate);
    }

    // Convertir a hora Ecuador solo si es dia futuro
    $isImmediateType = in_array($timeType, ['minuto', 'hora']);
    if (!$isImmediateType) {
      $baseDate->setTimezone($ecuadorTz);
    }

    return $baseDate->format('Y-m-d H:i:s');
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

    // PASO 1: Obtener bots activos
    $activeBots = db::table(DB_TABLES['bots'])
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
      $botData = BotHandlers::getDataFile($botNumber);
      if (!$botData) continue;

      $botsConfig[$botId] = $botData;

      // Obtener followups pendientes de este bot
      $followups = db::table(self::$table)
        ->where('bot_id', $botId)
        ->where('processed', 0)
        ->where('status', 1)
        ->where('future_date', '<=', $now)
        ->orderBy('future_date', 'ASC')
        ->limit(50)
        ->get();

      if (!empty($followups)) {
        foreach ($followups as $fup) {
          $allFollowups[] = $fup;
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
    return db::table(self::$table)
      ->where('id', $id)
      ->update([
        'processed' => 1,
        'du' => date('Y-m-d H:i:s'),
        'tu' => time()
      ]);
  }

  // Cancelar followups por venta
  static function cancelBySale($saleId) {
    return db::table(self::$table)
      ->where('sale_id', $saleId)
      ->where('processed', 0)
      ->update([
        'processed' => 2,
        'du' => date('Y-m-d H:i:s'),
        'tu' => time()
      ]);
  }
}