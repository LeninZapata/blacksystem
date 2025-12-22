<?php
// Helpers de sistema - Funciones para detectar entorno

// Detecta si la aplicación está corriendo en localhost
function isLocalhost() {
  $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
  return in_array($host, ['localhost', '127.0.0.1', '::1'])
    || strpos($host, 'localhost:') === 0
    || strpos($host, '127.0.0.1:') === 0;
}

// Detecta si la aplicación está en modo desarrollo
function isDev() {
  return isLocalhost() || (defined('IS_DEV') && IS_DEV);
}

// Detecta si la aplicación está en modo producción
function isProduction() {
  return !isDev();
}