<?php
// app/lang/en.php - English translations orchestrator

$langPath = __DIR__ . '/en/';

return [
  'api'        => require $langPath . 'api.php',
  'auth'       => require $langPath . 'auth.php',
  'bot'        => require $langPath . 'bot.php',
  'client'     => require $langPath . 'client.php',
  'core'       => require $langPath . 'core.php',
  'helper'     => require $langPath . 'helper.php',
  'log'        => require $langPath . 'log.php',
  'middleware' => require $langPath . 'middleware.php',
  'session'    => require $langPath . 'session.php',
  'user'       => require $langPath . 'user.php',
];
