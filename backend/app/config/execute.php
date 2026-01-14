<?php
// Configuración de ejecución de la aplicación

// Cargar y configurar base de datos
$dbConfig = require __DIR__ . '/database.php';
ogDb::setConfig($dbConfig);

// Cargar tablas del sistema
$tables = require __DIR__ . '/tables.php';
ogDb::setTables($tables);

// Aquí puedes agregar más configuraciones específicas de la app
// como servicios, integraciones, etc.
