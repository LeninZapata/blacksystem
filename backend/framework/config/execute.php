<?php
// Inicializar idioma
ogLang::load(OG_DEFAULT_LANG);

// Opción 2: Detectar desde header HTTP (descomenta para usar)
// $lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'es', 0, 2);
// ogLang::load($lang);

// Opción 3: Desde query param (descomenta para usar)
// $lang = $_GET['lang'] ?? OG_DEFAULT_LANG;
// ogLang::load($lang);

// Configurar logs
ogLog::setConfig([
  'format' => 'custom',
  'template' => '{year}/{month}/{day}/{module}.log',
  'level' => OG_IS_DEV ? 'debug' : 'info',
  'max_size' => 1048576,
  'enabled' => true
]);