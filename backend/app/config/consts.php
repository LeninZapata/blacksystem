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


// Aquí puedes agregar más constantes específicas del proyecto
// define('APP_NAME', 'Factory SaaS');
// define('APP_VERSION', '1.0.0');