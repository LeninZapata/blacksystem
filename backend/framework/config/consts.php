<?php
// Constantes del FRAMEWORK (genéricas, reutilizables)

// Configuración de zona horaria
define('TIMEZONE', 'America/Guayaquil');
date_default_timezone_set(TIMEZONE);

// Constantes de tiempo (en segundos)
define('TIME_SECOND', 1);
define('TIME_MINUTE', 60);
define('TIME_HOUR', 3600);
define('TIME_DAY', 86400);
define('TIME_WEEK', 604800);
define('TIME_MONTH', 2592000);
define('TIME_YEAR', 31536000);

// Configuración de sesiones
define('SESSION_TTL', TIME_DAY * 1);
define('SESSION_TTL_MS', SESSION_TTL * 1000);

// Rutas del sistema (ya definidas en app/config/init.php, validamos)
if (!defined('BASE_PATH')) {
  throw new Exception('BASE_PATH must be defined before loading framework');
}

// Rutas complementarias
define('FRONTEND_PATH', BASE_PATH . '/public');
define('EXTENSIONS_PATH', BACKEND_PATH . '/extensions');
define('ROUTES_PATH', APP_PATH . '/routes');
define('STORAGE_PATH', APP_PATH . '/storage');
define('LOG_PATH', STORAGE_PATH . '/logs');
define('SHARED_PATH', BASE_PATH . '/shared');
define('SERVICES_PATH', FRAMEWORK_PATH . '/services');
define('API_URL', '/api');

// Configuración de la API
define('API_DEFAULT_TIMEOUT', 60);
define('API_MAX_PAYLOAD_SIZE', 1048576);

// Configuración de idioma
define('DEFAULT_LANG', 'es');