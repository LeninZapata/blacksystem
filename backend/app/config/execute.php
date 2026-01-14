<?php
// Configuración de ejecución de la aplicación

// Cargar y configurar base de datos
$dbConfig = require __DIR__ . '/database.php';
ogDb::setConfig($dbConfig);

// Cargar tablas y guardar en memoria cache
$tables = require __DIR__ . '/tables.php';
ogCache::memorySet('db_tables', $tables);

// Aquí puedes agregar más configuraciones específicas de la app
// como servicios, integraciones, etc.
