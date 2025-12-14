<?php
class country {
  
  private static $countries = [
    // AMÉRICA DEL SUR
    'AR' => ['name' => 'Argentina', 'region' => 'america', 'currency' => 'ARS', 'timezone' => 'America/Argentina/Buenos_Aires', 'offset' => 'UTC-3'],
    'BO' => ['name' => 'Bolivia', 'region' => 'america', 'currency' => 'BOB', 'timezone' => 'America/La_Paz', 'offset' => 'UTC-4'],
    'BR' => ['name' => 'Brasil', 'region' => 'america', 'currency' => 'BRL', 'timezone' => 'America/Sao_Paulo', 'offset' => 'UTC-3'],
    'CL' => ['name' => 'Chile', 'region' => 'america', 'currency' => 'CLP', 'timezone' => 'America/Santiago', 'offset' => 'UTC-3'],
    'CO' => ['name' => 'Colombia', 'region' => 'america', 'currency' => 'COP', 'timezone' => 'America/Bogota', 'offset' => 'UTC-5'],
    'EC' => ['name' => 'Ecuador', 'region' => 'america', 'currency' => 'USD', 'timezone' => 'America/Guayaquil', 'offset' => 'UTC-5'],
    'GY' => ['name' => 'Guyana', 'region' => 'america', 'currency' => 'GYD', 'timezone' => 'America/Guyana', 'offset' => 'UTC-4'],
    'PE' => ['name' => 'Perú', 'region' => 'america', 'currency' => 'PEN', 'timezone' => 'America/Lima', 'offset' => 'UTC-5'],
    'PY' => ['name' => 'Paraguay', 'region' => 'america', 'currency' => 'PYG', 'timezone' => 'America/Asuncion', 'offset' => 'UTC-4'],
    'SR' => ['name' => 'Surinam', 'region' => 'america', 'currency' => 'SRD', 'timezone' => 'America/Paramaribo', 'offset' => 'UTC-3'],
    'UY' => ['name' => 'Uruguay', 'region' => 'america', 'currency' => 'UYU', 'timezone' => 'America/Montevideo', 'offset' => 'UTC-3'],
    'VE' => ['name' => 'Venezuela', 'region' => 'america', 'currency' => 'VES', 'timezone' => 'America/Caracas', 'offset' => 'UTC-4'],

    // AMÉRICA CENTRAL Y CARIBE
    'BZ' => ['name' => 'Belice', 'region' => 'america', 'currency' => 'BZD', 'timezone' => 'America/Belize', 'offset' => 'UTC-6'],
    'CR' => ['name' => 'Costa Rica', 'region' => 'america', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'offset' => 'UTC-6'],
    'SV' => ['name' => 'El Salvador', 'region' => 'america', 'currency' => 'USD', 'timezone' => 'America/El_Salvador', 'offset' => 'UTC-6'],
    'GT' => ['name' => 'Guatemala', 'region' => 'america', 'currency' => 'GTQ', 'timezone' => 'America/Guatemala', 'offset' => 'UTC-6'],
    'HN' => ['name' => 'Honduras', 'region' => 'america', 'currency' => 'HNL', 'timezone' => 'America/Tegucigalpa', 'offset' => 'UTC-6'],
    'NI' => ['name' => 'Nicaragua', 'region' => 'america', 'currency' => 'NIO', 'timezone' => 'America/Managua', 'offset' => 'UTC-6'],
    'PA' => ['name' => 'Panamá', 'region' => 'america', 'currency' => 'PAB', 'timezone' => 'America/Panama', 'offset' => 'UTC-5'],
    'CU' => ['name' => 'Cuba', 'region' => 'america', 'currency' => 'CUP', 'timezone' => 'America/Havana', 'offset' => 'UTC-5'],
    'DO' => ['name' => 'República Dominicana', 'region' => 'america', 'currency' => 'DOP', 'timezone' => 'America/Santo_Domingo', 'offset' => 'UTC-4'],
    'HT' => ['name' => 'Haití', 'region' => 'america', 'currency' => 'HTG', 'timezone' => 'America/Port-au-Prince', 'offset' => 'UTC-5'],
    'JM' => ['name' => 'Jamaica', 'region' => 'america', 'currency' => 'JMD', 'timezone' => 'America/Jamaica', 'offset' => 'UTC-5'],
    'TT' => ['name' => 'Trinidad y Tobago', 'region' => 'america', 'currency' => 'TTD', 'timezone' => 'America/Port_of_Spain', 'offset' => 'UTC-4'],
    'PR' => ['name' => 'Puerto Rico', 'region' => 'america', 'currency' => 'USD', 'timezone' => 'America/Puerto_Rico', 'offset' => 'UTC-4'],

    // AMÉRICA DEL NORTE
    'US' => ['name' => 'Estados Unidos', 'region' => 'america', 'currency' => 'USD', 'timezone' => 'America/New_York', 'offset' => 'UTC-5'],
    'CA' => ['name' => 'Canadá', 'region' => 'america', 'currency' => 'CAD', 'timezone' => 'America/Toronto', 'offset' => 'UTC-5'],
    'MX' => ['name' => 'México', 'region' => 'america', 'currency' => 'MXN', 'timezone' => 'America/Mexico_City', 'offset' => 'UTC-6'],

    // EUROPA OCCIDENTAL
    'ES' => ['name' => 'España', 'region' => 'europa', 'currency' => 'EUR', 'timezone' => 'Europe/Madrid', 'offset' => 'UTC+1'],
    'FR' => ['name' => 'Francia', 'region' => 'europa', 'currency' => 'EUR', 'timezone' => 'Europe/Paris', 'offset' => 'UTC+1'],
    'DE' => ['name' => 'Alemania', 'region' => 'europa', 'currency' => 'EUR', 'timezone' => 'Europe/Berlin', 'offset' => 'UTC+1'],
    'IT' => ['name' => 'Italia', 'region' => 'europa', 'currency' => 'EUR', 'timezone' => 'Europe/Rome', 'offset' => 'UTC+1'],
    'PT' => ['name' => 'Portugal', 'region' => 'europa', 'currency' => 'EUR', 'timezone' => 'Europe/Lisbon', 'offset' => 'UTC+0'],
    'NL' => ['name' => 'Países Bajos', 'region' => 'europa', 'currency' => 'EUR', 'timezone' => 'Europe/Amsterdam', 'offset' => 'UTC+1'],
    'BE' => ['name' => 'Bélgica', 'region' => 'europa', 'currency' => 'EUR', 'timezone' => 'Europe/Brussels', 'offset' => 'UTC+1'],
    'AT' => ['name' => 'Austria', 'region' => 'europa', 'currency' => 'EUR', 'timezone' => 'Europe/Vienna', 'offset' => 'UTC+1'],
    'GR' => ['name' => 'Grecia', 'region' => 'europa', 'currency' => 'EUR', 'timezone' => 'Europe/Athens', 'offset' => 'UTC+2'],
    'IE' => ['name' => 'Irlanda', 'region' => 'europa', 'currency' => 'EUR', 'timezone' => 'Europe/Dublin', 'offset' => 'UTC+0'],
    'FI' => ['name' => 'Finlandia', 'region' => 'europa', 'currency' => 'EUR', 'timezone' => 'Europe/Helsinki', 'offset' => 'UTC+2'],
    'GB' => ['name' => 'Reino Unido', 'region' => 'europa', 'currency' => 'GBP', 'timezone' => 'Europe/London', 'offset' => 'UTC+0'],
    'CH' => ['name' => 'Suiza', 'region' => 'europa', 'currency' => 'CHF', 'timezone' => 'Europe/Zurich', 'offset' => 'UTC+1'],
    'NO' => ['name' => 'Noruega', 'region' => 'europa', 'currency' => 'NOK', 'timezone' => 'Europe/Oslo', 'offset' => 'UTC+1'],
    'SE' => ['name' => 'Suecia', 'region' => 'europa', 'currency' => 'SEK', 'timezone' => 'Europe/Stockholm', 'offset' => 'UTC+1'],
    'DK' => ['name' => 'Dinamarca', 'region' => 'europa', 'currency' => 'DKK', 'timezone' => 'Europe/Copenhagen', 'offset' => 'UTC+1'],
    'IS' => ['name' => 'Islandia', 'region' => 'europa', 'currency' => 'ISK', 'timezone' => 'Atlantic/Reykjavik', 'offset' => 'UTC+0'],
    'PL' => ['name' => 'Polonia', 'region' => 'europa', 'currency' => 'PLN', 'timezone' => 'Europe/Warsaw', 'offset' => 'UTC+1'],
    'CZ' => ['name' => 'República Checa', 'region' => 'europa', 'currency' => 'CZK', 'timezone' => 'Europe/Prague', 'offset' => 'UTC+1'],
    'HU' => ['name' => 'Hungría', 'region' => 'europa', 'currency' => 'HUF', 'timezone' => 'Europe/Budapest', 'offset' => 'UTC+1'],
    'RO' => ['name' => 'Rumania', 'region' => 'europa', 'currency' => 'RON', 'timezone' => 'Europe/Bucharest', 'offset' => 'UTC+2'],
    'BG' => ['name' => 'Bulgaria', 'region' => 'europa', 'currency' => 'BGN', 'timezone' => 'Europe/Sofia', 'offset' => 'UTC+2'],
    'HR' => ['name' => 'Croacia', 'region' => 'europa', 'currency' => 'EUR', 'timezone' => 'Europe/Zagreb', 'offset' => 'UTC+1'],
    'RS' => ['name' => 'Serbia', 'region' => 'europa', 'currency' => 'RSD', 'timezone' => 'Europe/Belgrade', 'offset' => 'UTC+1'],
    'UA' => ['name' => 'Ucrania', 'region' => 'europa', 'currency' => 'UAH', 'timezone' => 'Europe/Kiev', 'offset' => 'UTC+2']
  ];

  public static function get($code) {
    $code = strtoupper($code);
    return self::$countries[$code] ?? null;
  }

  public static function all() {
    return self::$countries;
  }

  public static function exists($code) {
    return isset(self::$countries[strtoupper($code)]);
  }

  public static function now($code, $format = 'Y-m-d H:i:s') {
    $country = self::get($code);
    if (!$country) return null;

    try {
      $tz = new DateTimeZone($country['timezone']);
      $dt = new DateTime('now', $tz);
      return $dt->format($format);
    } catch (Exception $e) {
      return null;
    }
  }

  public static function convert($datetime, $fromCode, $toCode) {
    $from = self::get($fromCode);
    $to = self::get($toCode);

    if (!$from || !$to) return null;

    try {
      $fromTZ = new DateTimeZone($from['timezone']);
      $toTZ = new DateTimeZone($to['timezone']);
      $dt = new DateTime($datetime, $fromTZ);
      $dt->setTimezone($toTZ);
      return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
      return null;
    }
  }
}

// Ejemplos de uso:
// $ec = country::get('EC'); // ['name'=>'Ecuador', 'region'=>'america', 'currency'=>'USD', 'timezone'=>'America/Guayaquil', 'offset'=>'UTC-5']
// $all = country::all(); // Array con todos los países
// $exists = country::exists('EC'); // true
// $hora = country::now('EC'); // '2025-12-14 15:30:45'
// $horaCustom = country::now('EC', 'H:i'); // '15:30'
// $converted = country::convert('2025-12-14 10:00:00', 'EC', 'ES'); // '2025-12-14 16:00:00' (hora en España)
