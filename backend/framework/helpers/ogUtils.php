<?php
// Utils - Utilidades generales
class ogUtils {
  // Obtener valor de array con default
  static function get($arr, $key, $default = null) {
    return $arr[$key] ?? $default;
  }

  // UUID v4
  static function uuid() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
      mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
      mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
      mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
  }

  // Token aleatorio
  static function token($length = 32) {
    return bin2hex(random_bytes($length / 2));
  }

  // Formatear moneda
  static function money($amount, $currency = '$') {
    return $currency . number_format($amount, 2, '.', ',');
  }

  // Tiempo transcurrido
  static function timeAgo($datetime) {
    $ts = is_numeric($datetime) ? $datetime : strtotime($datetime);
    $diff = time() - $ts;
    if ($diff < 60) return 'hace unos segundos';
    if ($diff < 3600) {
      $mins = floor($diff / 60);
      return "hace $mins " . ($mins == 1 ? 'minuto' : 'minutos');
    }
    if ($diff < 86400) {
      $hrs = floor($diff / 3600);
      return "hace $hrs " . ($hrs == 1 ? 'hora' : 'horas');
    }
    if ($diff < 604800) {
      $days = floor($diff / 86400);
      return "hace $days " . ($days == 1 ? 'día' : 'días');
    }
    return date('d/m/Y', $ts);
  }

  // Slug URL-friendly
  static function slug($text) {
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
    $text = preg_replace('~[^-\w]+~', '', $text);
    $text = trim($text, '-');
    $text = preg_replace('~-+~', '-', $text);
    return strtolower($text) ?: 'n-a';
  }

  // Truncar texto
  static function truncate($text, $len = 100, $suffix = '...') {
    return strlen($text) <= $len ? $text : substr($text, 0, $len) . $suffix;
  }

  // Formatear bytes
  static function bytes($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    for ($i = 0; $bytes > 1024; $i++) $bytes /= 1024;
    return round($bytes, 2) . ' ' . $units[$i];
  }
}