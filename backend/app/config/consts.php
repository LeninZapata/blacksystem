<?php
// Constantes de la APLICACIÓN (específicas de este proyecto)

// Cargar configuración de base de datos
$dbConfig = require __DIR__ . '/database.php';
define('DB_HOST', $dbConfig['host']);
define('DB_NAME', $dbConfig['name']);
define('DB_USER', $dbConfig['user']);
define('DB_PASS', $dbConfig['pass']);
define('DB_CHARSET', $dbConfig['charset']);

// Cargar nombres de tablas
$tables = require __DIR__ . '/tables.php';
define('DB_TABLES', $tables);

define('JSON_STORAGE_PATH', STORAGE_PATH . '/json');

// Rutas de almacenamiento de bots
define('BOTS_STORAGE_PATH', JSON_STORAGE_PATH . '/bots');
define('BOTS_DATA_PATH', BOTS_STORAGE_PATH . '/data');
define('BOTS_INFOPRODUCT_PATH', BOTS_STORAGE_PATH . '/infoproduct');
define('BOTS_INFOPRODUCT_MESSAGES_PATH', BOTS_INFOPRODUCT_PATH . '/messages');
define('BOTS_INFOPRODUCT_RAPID_PATH', BOTS_INFOPRODUCT_PATH . '/rapid');
define('BOTS_INFOPRODUCT_CHAT_PATH', BOTS_INFOPRODUCT_PATH . '/chats');

// Rutas de almacenamiento de chats
define('CHATS_STORAGE_PATH', JSON_STORAGE_PATH . '/chats');
define('CHATS_INFOPRODUCT_PATH', CHATS_STORAGE_PATH . '/infoproduct');
define('CHATS_BUFFER_PATH', CHATS_STORAGE_PATH . '/buffer');


// Aquí puedes agregar más constantes específicas del proyecto
// define('APP_NAME', 'Factory SaaS');
// define('APP_VERSION', '1.0.0');
