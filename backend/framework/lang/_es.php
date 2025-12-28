<?php
// framework/lang/es.php - Traducciones del FRAMEWORK
$langPath = __DIR__ . '/es/';

return [
  'api'        => require $langPath . 'api.php',
  'auth'       => require $langPath . 'auth.php',
  'core'       => require $langPath . 'core.php',
  'country'    => require $langPath . 'country.php',
  'helper'     => require $langPath . 'helper.php',
  'log'        => require $langPath . 'log.php',
  'middleware' => require $langPath . 'middleware.php',
  'session'    => require $langPath . 'session.php',
  'validation' => require $langPath . 'validation.php',
  
  // Servicios del framework
  'services'   => [
    'ai'       => file_exists($langPath . 'services/ai.php') ? require $langPath . 'services/ai.php' : [],
    'ogChatApi'  => file_exists($langPath . 'services/ogChatApi.php') ? require $langPath . 'services/ogChatApi.php' : [],
    'evolution'=> file_exists($langPath . 'services/evolution.php') ? require $langPath . 'services/evolution.php' : [],
    'webhook'  => file_exists($langPath . 'services/webhook.php') ? require $langPath . 'services/webhook.php' : [],
    'email'    => file_exists($langPath . 'services/email.php') ? require $langPath . 'services/email.php' : [],
    'storage'  => file_exists($langPath . 'services/storage.php') ? require $langPath . 'services/storage.php' : [],
  ],
];
