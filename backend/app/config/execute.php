<?php
// Configurar error reporting según entorno
if (OG_IS_DEV || TRUE ) {
  error_reporting(E_ALL);
  ini_set('display_errors', '1');
  ini_set('log_errors', '1');
} else {
  error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
  ini_set('display_errors', '0');
  ini_set('log_errors', '0');
}

// Configuración de ejecución de la aplicación

// Cargar y configurar base de datos
$dbConfig = require __DIR__ . '/database.php';
ogDb::setConfig($dbConfig);

// Cargar tablas del sistema
$tables = require __DIR__ . '/tables.php';
ogDb::setTables($tables);

// Aquí puedes agregar más configuraciones específicas de la app
// como servicios, integraciones, etc.
