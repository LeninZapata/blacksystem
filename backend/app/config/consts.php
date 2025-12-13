<?php
// Constantes de configuración
define('IS_DEV', true);

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

// Configuración de base de datos
define('DB_HOST', 'localhost');
define('DB_NAME', 'factory-saas');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Rutas del sistema
define('BASE_PATH', realpath(dirname(dirname(dirname(__DIR__)))));
define('BACKEND_PATH', BASE_PATH . '/backend');
define('FRAMEWORK_PATH', BACKEND_PATH . '/framework');
define('APP_PATH', BACKEND_PATH . '/app');
define('FRONTEND_PATH', BASE_PATH . '/frontend');
define('EXTENSIONS_PATH', BACKEND_PATH . '/extensions');
define('ROUTES_PATH', APP_PATH . '/routes');
define('STORAGE_PATH', APP_PATH . '/storage');
define('LOG_PATH', STORAGE_PATH . '/logs');
define('API_BASE_URL', '/api');

// Configuración de la API
define('API_DEFAULT_TIMEOUT', 60);
define('API_MAX_PAYLOAD_SIZE', 1048576);

// Configuración de idioma
define('DEFAULT_LANG', 'es');

// Cargar helper de idioma
require_once FRAMEWORK_PATH . '/helpers/lang.php';

// Inicializar idioma
// Opción 1: Idioma fijo
lang::load(DEFAULT_LANG);

// Opción 2: Detectar desde header HTTP (descomenta para usar)
// $lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'es', 0, 2);
// lang::load($lang);

// Opción 3: Desde query param (descomenta para usar)
// $lang = $_GET['lang'] ?? DEFAULT_LANG;
// lang::load($lang);

// Ejecuta otros archivos de configuración
require_once APP_PATH . '/config/debug.php';