<?php
// Constantes del FRAMEWORK (genéricas, reutilizables)

// Configuración de zona horaria
define('OG_TIMEZONE', 'America/Guayaquil');
date_default_timezone_set(OG_TIMEZONE);

// Constantes de tiempo (en segundos)
define('OG_TIME_SECOND', 1);
define('OG_TIME_MINUTE', 60);
define('OG_TIME_HOUR', 3600);
define('OG_TIME_DAY', 86400);
define('OG_TIME_WEEK', 604800);
define('OG_TIME_MONTH', 2592000);
define('OG_TIME_YEAR', 31536000);

// Configuración de sesiones
define('OG_SESSION_TTL', OG_TIME_DAY * 1);
define('OG_SESSION_TTL_MS', OG_SESSION_TTL * 1000);

// Rutas del sistema (ya definidas en app/config/init.php, validamos)
if (!defined('BASE_PATH')) {
  throw new Exception('BASE_PATH must be defined before loading framework');
}

// Rutas complementarias
define('EXTENSIONS_PATH', APP_PATH . '/extensions');  // ← CAMBIADO: ahora en app/
define('ROUTES_PATH', APP_PATH . '/routes');
define('STORAGE_PATH', APP_PATH . '/storage');
define('LOG_PATH', STORAGE_PATH . '/logs');
define('SHARED_PATH', BASE_PATH . '/shared');
define('SERVICES_PATH', OG_FRAMEWORK_PATH . '/services');

// Configuración de la API
define('OG_API_DEFAULT_TIMEOUT', 60);
define('OG_API_MAX_PAYLOAD_SIZE', 1048576);

// Configuración de idioma
define('OG_DEFAULT_LANG', 'es');