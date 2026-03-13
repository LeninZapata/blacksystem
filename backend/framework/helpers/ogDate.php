<?php
// ogDate - Helper para manejo de fechas y rangos
class ogDate {

  // Leer timezone del usuario desde el header X-User-Timezone (enviado por api.js)
  static function getUserTimezone() {
    $tz = $_SERVER['HTTP_X_USER_TIMEZONE'] ?? 'UTC';
    try {
      new DateTimeZone($tz);
      return $tz;
    } catch (\Exception $e) {
      return 'UTC';
    }
  }

  // Convertir una fecha local (YYYY-MM-DD) a rango UTC para consultas en BD.
  // Retorna: ['start', 'end' (ambos UTC), 'offset_hours', 'offset_sec']
  static function localDateToUtcRange($date, $timezone = 'UTC') {
    try {
      $tz  = new DateTimeZone($timezone);
      $utc = new DateTimeZone('UTC');
      $offsetSec = $tz->getOffset(new DateTime('now', $utc));
      $start = (new DateTime($date . ' 00:00:00', $tz))->setTimezone($utc);
      $end   = (new DateTime($date . ' 23:59:59', $tz))->setTimezone($utc);
      return [
        'start'        => $start->format('Y-m-d H:i:s'),
        'end'          => $end->format('Y-m-d H:i:s'),
        'offset_hours' => $offsetSec / 3600,
        'offset_sec'   => $offsetSec,
      ];
    } catch (\Exception $e) {
      return [
        'start'        => $date . ' 00:00:00',
        'end'          => $date . ' 23:59:59',
        'offset_hours' => 0,
        'offset_sec'   => 0,
      ];
    }
  }

  // Obtener rango de fechas según período, en UTC, calculado en la timezone del usuario.
  // $timezone = resultado de getUserTimezone()
  static function getDateRange($range, $timezone = 'UTC') {
    try {
      $tz = new DateTimeZone($timezone);
    } catch (\Exception $e) {
      $tz = new DateTimeZone('UTC');
    }
    $utc   = new DateTimeZone('UTC');
    $now   = new DateTime('now', $tz);
    $start = clone $now;
    $end   = clone $now;

    switch ($range) {
      case 'today':
        $start->setTime(0, 0, 0);
        $end->setTime(23, 59, 59);
        break;
      case 'yesterday':
        $start->modify('-1 day')->setTime(0, 0, 0);
        $end->modify('-1 day')->setTime(23, 59, 59);
        break;
      case 'yesterday_today':
        $start->modify('-1 day')->setTime(0, 0, 0);
        $end->setTime(23, 59, 59);
        break;
      case 'last_3_days':
        $start->modify('-3 days')->setTime(0, 0, 0);
        $end->modify('-1 day')->setTime(23, 59, 59);
        break;
      case 'last_7_days':
        $start->modify('-7 days')->setTime(0, 0, 0);
        $end->modify('-1 day')->setTime(23, 59, 59);
        break;
      case 'last_10_days':
        $start->modify('-10 days')->setTime(0, 0, 0);
        $end->modify('-1 day')->setTime(23, 59, 59);
        break;
      case 'last_15_days':
        $start->modify('-15 days')->setTime(0, 0, 0);
        $end->modify('-1 day')->setTime(23, 59, 59);
        break;
      case 'this_week':
        $start->modify('monday this week')->setTime(0, 0, 0);
        $end->setTime(23, 59, 59);
        break;
      case 'this_month':
        $start->modify('first day of this month')->setTime(0, 0, 0);
        $end->modify('last day of this month')->setTime(23, 59, 59);
        break;
      case 'last_30_days':
        $start->modify('-30 days')->setTime(0, 0, 0);
        $end->modify('-1 day')->setTime(23, 59, 59);
        break;
      case 'last_month':
        $start->modify('first day of last month')->setTime(0, 0, 0);
        $end->modify('last day of last month')->setTime(23, 59, 59);
        break;
      default:
        return null;
    }

    return [
      'start' => $start->setTimezone($utc)->format('Y-m-d H:i:s'),
      'end'   => $end->setTimezone($utc)->format('Y-m-d H:i:s'),
    ];
  }

  // Formatear fecha en español
  static function formatEs($date, $format = 'd/m/Y') {
    if (is_string($date)) {
      $date = new DateTime($date);
    }
    return $date->format($format);
  }

  // Diferencia en días entre dos fechas
  static function diffDays($dateFrom, $dateTo = null) {
    if (!$dateTo) $dateTo = date('Y-m-d');

    $from = new DateTime($dateFrom);
    $to = new DateTime($dateTo);

    return $from->diff($to)->days;
  }

  // Agregar días a una fecha
  static function addDays($date, $days) {
    $dateObj = new DateTime($date);
    $dateObj->modify("+{$days} days");
    return $dateObj->format('Y-m-d');
  }

  // Restar días a una fecha
  static function subDays($date, $days) {
    $dateObj = new DateTime($date);
    $dateObj->modify("-{$days} days");
    return $dateObj->format('Y-m-d');
  }

  // Verificar si una fecha es hoy
  static function isToday($date) {
    return date('Y-m-d', strtotime($date)) === date('Y-m-d');
  }

  // Verificar si una fecha es ayer
  static function isYesterday($date) {
    return date('Y-m-d', strtotime($date)) === date('Y-m-d', strtotime('-1 day'));
  }

  // Obtener timestamp
  static function timestamp($date = null) {
    if (!$date) return time();
    return strtotime($date);
  }

  // Formatear fecha para MySQL
  static function toMysql($date = null) {
    if (!$date) return date('Y-m-d H:i:s');
    return date('Y-m-d H:i:s', strtotime($date));
  }

  // Formatear solo fecha para MySQL
  static function toMysqlDate($date = null) {
    if (!$date) return date('Y-m-d');
    return date('Y-m-d', strtotime($date));
  }
}