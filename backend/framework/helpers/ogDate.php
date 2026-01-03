<?php
// ogDate - Helper para manejo de fechas y rangos
class ogDate {

  // Obtener rango de fechas según período
  static function getDateRange($range) {
    $now = new DateTime();
    $start = clone $now;
    $end = clone $now;

    switch ($range) {
      case 'today':
        $start->setTime(0, 0, 0);
        $end->setTime(23, 59, 59);
        break;
      case 'yesterday':
        $start->modify('-1 day')->setTime(0, 0, 0);
        $end->modify('-1 day')->setTime(23, 59, 59);
        break;
      case 'last_7_days':
        $start->modify('-6 days')->setTime(0, 0, 0);
        break;
      case 'last_10_days':
        $start->modify('-9 days')->setTime(0, 0, 0);
        break;
      case 'last_15_days':
        $start->modify('-14 days')->setTime(0, 0, 0);
        break;
      case 'this_week':
        $start->modify('monday this week')->setTime(0, 0, 0);
        break;
      case 'this_month':
        $start->modify('first day of this month')->setTime(0, 0, 0);
        $end->modify('last day of this month')->setTime(23, 59, 59);
        break;
      case 'last_30_days':
        $start->modify('-29 days')->setTime(0, 0, 0);
        break;
      case 'last_month':
        $start->modify('first day of last month')->setTime(0, 0, 0);
        $end->modify('last day of last month')->setTime(23, 59, 59);
        break;
      default:
        return null;
    }

    return [
      'start' => $start->format('Y-m-d'),
      'end' => $end->format('Y-m-d')
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