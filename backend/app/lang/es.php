<?php
// app/lang/es.php - Orquestador de traducciones en español

$langPath = __DIR__ . '/es/';

return [
  'api'        => require $langPath . 'api.php',
  'auth'       => require $langPath . 'auth.php',
  'client'     => require $langPath . 'client.php',
  'core'       => require $langPath . 'core.php',
  'helper'     => require $langPath . 'helper.php',
  'log'        => require $langPath . 'log.php',
  'middleware' => require $langPath . 'middleware.php',
  'session'    => require $langPath . 'session.php',
  'user'       => require $langPath . 'user.php',
];