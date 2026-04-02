<?php
class ogCountry {
  
  // Mapa de prefijos telefónicos → código de país ISO
  // Orden: 4 dígitos primero (NANP especiales), luego 3, luego 2, luego 1
  private static $callingCodes = [
    // NANP +1: área codes de Canadá (4 dígitos = "1" + area code)
    '1204' => 'CA', '1226' => 'CA', '1236' => 'CA', '1249' => 'CA',
    '1250' => 'CA', '1263' => 'CA', '1289' => 'CA', '1306' => 'CA',
    '1343' => 'CA', '1365' => 'CA', '1367' => 'CA', '1368' => 'CA',
    '1382' => 'CA', '1387' => 'CA', '1403' => 'CA', '1416' => 'CA',
    '1418' => 'CA', '1431' => 'CA', '1437' => 'CA', '1438' => 'CA',
    '1450' => 'CA', '1468' => 'CA', '1474' => 'CA', '1506' => 'CA',
    '1514' => 'CA', '1519' => 'CA', '1548' => 'CA', '1579' => 'CA',
    '1581' => 'CA', '1584' => 'CA', '1587' => 'CA', '1604' => 'CA',
    '1613' => 'CA', '1639' => 'CA', '1647' => 'CA', '1672' => 'CA',
    '1683' => 'CA', '1705' => 'CA', '1709' => 'CA', '1742' => 'CA',
    '1753' => 'CA', '1778' => 'CA', '1782' => 'CA', '1807' => 'CA',
    '1819' => 'CA', '1825' => 'CA', '1867' => 'CA', '1873' => 'CA',
    '1902' => 'CA', '1905' => 'CA', '1942' => 'CA',
    // NANP +1: Caribe (4 dígitos)
    '1809' => 'DO', '1829' => 'DO', '1849' => 'DO',
    '1876' => 'JM', '1868' => 'TT',
    '1787' => 'PR', '1939' => 'PR',
    // 3 dígitos
    '501' => 'BZ', '502' => 'GT', '503' => 'SV', '504' => 'HN',
    '505' => 'NI', '506' => 'CR', '507' => 'PA', '509' => 'HT',
    '591' => 'BO', '592' => 'GY', '593' => 'EC', '595' => 'PY',
    '597' => 'SR', '598' => 'UY',
    '351' => 'PT', '353' => 'IE', '354' => 'IS', '358' => 'FI',
    '359' => 'BG', '380' => 'UA', '381' => 'RS', '385' => 'HR',
    '420' => 'CZ',
    // 2 dígitos
    '51' => 'PE', '52' => 'MX', '53' => 'CU', '54' => 'AR',
    '55' => 'BR', '56' => 'CL', '57' => 'CO', '58' => 'VE',
    '30' => 'GR', '31' => 'NL', '32' => 'BE', '33' => 'FR',
    '34' => 'ES', '36' => 'HU', '39' => 'IT', '40' => 'RO',
    '41' => 'CH', '43' => 'AT', '44' => 'GB', '45' => 'DK',
    '46' => 'SE', '47' => 'NO', '48' => 'PL', '49' => 'DE',
    // 1 dígito — fallback NANP (US cubre lo que no sea CA ni Caribe)
    '1' => 'US',
  ];

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

  /**
   * Detecta el código de país ISO a partir de un número de teléfono internacional.
   * El número debe estar en formato completo sin '+' (ej: 593985631990 → 'EC').
   * Retorna null si no se puede identificar el país.
   */
  public static function fromPhone($number) {
    $number = preg_replace('/\D/', '', (string)$number);
    if (empty($number)) return null;

    foreach ([4, 3, 2, 1] as $len) {
      $prefix = substr($number, 0, $len);
      if (isset(self::$callingCodes[$prefix])) {
        return self::$callingCodes[$prefix];
      }
    }

    return null;
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
// $ec = ogCountry::get('EC'); // ['name'=>'Ecuador', 'region'=>'america', 'currency'=>'USD', 'timezone'=>'America/Guayaquil', 'offset'=>'UTC-5']
// $all = ogCountry::all(); // Array con todos los países
// $exists = ogCountry::exists('EC'); // true
// $hora = ogCountry::now('EC'); // '2025-12-14 15:30:45'
// $horaCustom = ogCountry::now('EC', 'H:i'); // '15:30'
// $converted = ogCountry::convert('2025-12-14 10:00:00', 'EC', 'ES'); // '2025-12-14 16:00:00' (hora en España)
