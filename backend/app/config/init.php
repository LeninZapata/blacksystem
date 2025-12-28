<?php
// APP/CONFIG/INIT.PHP - Define paths y carga framework SI NO ESTÁ CARGADO

// Calcular rutas base ANTES de cargar framework
if (!defined('BASE_PATH')) {
  define('BASE_PATH', realpath(dirname(dirname(dirname(__DIR__)))));
  define('BACKEND_PATH', BASE_PATH . '/backend');
  define('APP_PATH', BACKEND_PATH . '/app');
}

// Cargar framework SOLO si no está cargado
if (!class_exists('ogFramework')) {
  // Definir OG_FRAMEWORK_PATH solo si no existe
  if (!defined('OG_FRAMEWORK_PATH')) {
    define('OG_FRAMEWORK_PATH', FACTORY_BACKEND_PATH . '/framework');
  }
  // Cargar el framework completo
  require_once OG_FRAMEWORK_PATH . '/config/init.php';
}

// Cargar constantes de la aplicación
require_once __DIR__ . '/consts.php';