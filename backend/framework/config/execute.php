<?php
// Inicializar idioma
ogLang::load(OG_DEFAULT_LANG);

// Opción 2: Detectar desde header HTTP (descomenta para usar)
// $lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'es', 0, 2);
// ogLang::load($lang);

// Opción 3: Desde query param (descomenta para usar)
// $lang = $_GET['lang'] ?? OG_DEFAULT_LANG;
// ogLang::load($lang);

// Configurar error reporting según entorno
if (OG_IS_DEV) {
  error_reporting(E_ALL);
  ini_set('display_errors', '1');
  ini_set('log_errors', '1');
} else {
  error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
  ini_set('display_errors', '0');
  ini_set('log_errors', '0');
}

// Configurar logs
ogLog::setConfig([
  'format' => 'custom',
  'template' => '{year}/{month}/{day}/{module}.log',
  'level' => OG_IS_DEV ? 'debug' : 'info',
  'max_size' => 1048576,
  'enabled' => true
]);