<?php
// Helpers de sistema - Funciones para detectar entorno

/**
 * Detecta si la aplicación está corriendo en localhost
 * @return bool true si está en localhost, false si está en producción
 */
function isLocalhost() {
  $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
  return in_array($host, ['localhost', '127.0.0.1', '::1'])
    || strpos($host, 'localhost:') === 0
    || strpos($host, '127.0.0.1:') === 0;
}

/**
 * Detecta si la aplicación está en modo desarrollo
 * @return bool
 */
function isDev() {
  return isLocalhost() || (defined('IS_DEV') && IS_DEV);
}

/**
 * Detecta si la aplicación está en modo producción
 * @return bool
 */
function isProduction() {
  return !isDev();
}
