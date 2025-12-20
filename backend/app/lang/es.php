<?php
// app/lang/es.php - Orquestador de traducciones en español

$langPath = __DIR__ . '/es/';

return [
  'api'        => require $langPath . 'api.php',
  'auth'       => require $langPath . 'auth.php',
  'bot'        => require $langPath . 'bot.php',
  'chat'       => require $langPath . 'chat.php',
  'client'     => require $langPath . 'client.php',
  'core'       => require $langPath . 'core.php',
  'country'    => require $langPath . 'country.php',
  'credential' => require $langPath . 'credential.php',
  'helper'     => require $langPath . 'helper.php',
  'log'        => require $langPath . 'log.php',
  'middleware' => require $langPath . 'middleware.php',
  'product'    => require $langPath . 'product.php',
  'sale'       => require $langPath . 'sale.php',
  'session'    => require $langPath . 'session.php',
  'user'       => require $langPath . 'user.php',
  'workFlow'   => require $langPath . 'workFlow.php',

  // Servicios
  'services'   => [
    'chatapi'  => require $langPath . 'services/chatapi.php',
    'evolution'=> require $langPath . 'services/evolution.php',
    'webhook'  => require $langPath . 'services/webhook.php',
  ]
];