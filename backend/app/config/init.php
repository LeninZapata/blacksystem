<?php
// APP/CONFIG/INIT.PHP - Define paths y carga framework SI NO ESTÁ CARGADO

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