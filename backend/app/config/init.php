<?php
// Calcular rutas base ANTES de cargar framework
if (!defined('BASE_PATH')) {
  define('BASE_PATH', realpath(dirname(dirname(dirname(__DIR__)))));
  define('BACKEND_PATH', BASE_PATH . '/backend');
  define('FRAMEWORK_PATH', BACKEND_PATH . '/framework');
  define('APP_PATH', BACKEND_PATH . '/app');
}

// Cargar el framework
require_once FRAMEWORK_PATH . '/config/init.php';

// Cargar constantes de la aplicación
require_once __DIR__ . '/consts.php';