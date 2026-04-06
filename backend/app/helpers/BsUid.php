<?php
/**
 * BsUid — Utilidades para Business-Scoped User IDs de Meta (desde marzo 2026)
 *
 * Formato: {ISO-3166-alpha-2}.{alfanumérico}
 * Ejemplo: US.13491208655302741918
 *          EC.4830291748203948572
 *
 * El prefijo de 2 letras es el mismo código ISO que usa ogCountry,
 * lo que permite derivar país, moneda y timezone directamente del BSUID
 * cuando el número de teléfono no está disponible (username privacy).
 */
class BsUid {

  /**
   * Parsea un BSUID y retorna sus partes.
   *
   * @param string|null $bsuid
   * @return array|null ['country_code' => 'US', 'uid' => '13491208...'] o null si inválido
   */
  static function parse($bsuid) {
    if (empty($bsuid) || !is_string($bsuid)) return null;

    $dot = strpos($bsuid, '.');
    if ($dot === false) return null;

    $prefix = strtoupper(substr($bsuid, 0, $dot));
    $uid    = substr($bsuid, $dot + 1);

    if (strlen($prefix) !== 2 || empty($uid)) return null;

    return [
      'country_code' => $prefix,
      'uid'          => $uid
    ];
  }

  /**
   * Extrae y valida el código de país ISO desde un BSUID.
   * Verifica contra ogCountry para asegurar que el código sea conocido.
   *
   * @param string|null $bsuid   BSUID completo (ej: "US.13491208655302741918")
   * @param string|null $fallback Valor a retornar si no se puede extraer (default: null)
   * @return string|null  Código ISO de país (ej: "US", "EC") o $fallback
   */
  static function countryCode($bsuid, $fallback = null) {
    $parsed = self::parse($bsuid);
    if (!$parsed) return $fallback;

    $code = $parsed['country_code'];

    // Validar contra ogCountry — descarta prefijos que no sean países conocidos
    $country = ogApp()->helper('country');
    if (!$country::exists($code)) return $fallback;

    return $code;
  }

  /**
   * Retorna los datos completos del país desde un BSUID (delegando a ogCountry).
   *
   * @param string|null $bsuid
   * @return array|null  Array con name, currency, timezone, etc. o null
   */
  static function countryData($bsuid) {
    $code = self::countryCode($bsuid);
    if (!$code) return null;

    return ogApp()->helper('country')::get($code);
  }

  /**
   * Verifica si un BSUID tiene formato válido (prefijo 2 letras + punto + alfanumérico).
   *
   * @param mixed $bsuid
   * @return bool
   */
  static function isValid($bsuid) {
    return self::parse($bsuid) !== null;
  }
}
