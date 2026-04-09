<?php
// webhook_test.php

$VERIFY_TOKEN = 'TU_VERIFY_TOKEN_AQUI'; // El mismo que tienes en WEBHOOK_META_VERIFY_TOKEN

// Verificación GET de Facebook
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $mode      = $_GET['hub_mode'] ?? null;
  $token     = $_GET['hub_verify_token'] ?? null;
  $challenge = $_GET['hub_challenge'] ?? null;

  // Si es verificación de Facebook
  if ($mode === 'subscribe' && $token === $VERIFY_TOKEN) {
    http_response_code(200);
    header('Content-Type: text/plain');
    echo $challenge;
    exit;
  }

  // Si es visita normal al navegador
  http_response_code(200);
  echo 'Webhook test activo ✅ - ' . date('Y-m-d H:i:s');
  exit;
}

// Si es POST, guardar lo que llegue
$raw = file_get_contents('php://input');
$headers = function_exists('getallheaders') ? getallheaders() : [];

$log = [
  'time'    => date('Y-m-d H:i:s'),
  'method'  => $_SERVER['REQUEST_METHOD'],
  'headers' => $headers,
  'body'    => $raw
];

file_put_contents(__DIR__ . '/webhook_test.log', json_encode($log, JSON_PRETTY_PRINT) . PHP_EOL . '---' . PHP_EOL, FILE_APPEND);

http_response_code(200);
echo 'OK';