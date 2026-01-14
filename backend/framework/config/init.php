<?php
// FRAMEWORK/CONFIG/INIT.PHP - Inicialización del framework
// Cargar configuración de entorno
require_once __DIR__ . '/environment.php';

// Cargar constantes del framework
require_once __DIR__ . '/consts.php';

// Cargar helpers críticos
require_once __DIR__ . '/requires.php';

// Ejecutar scripts de inicialización
require_once __DIR__ . '/execute.php';