<?php
// API.PHP - Entry Point
// ⚠️ IMPORTANTE: ob_start() DEBE ser lo primero para capturar warnings/errors de PHP
ob_start();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  exit();
}

$path = __DIR__;
require_once $path . '/framework/core/autoload.php';
require_once $path . '/app/config/consts.php';

if (IS_DEV) {
  error_reporting(E_ALL);
  ini_set('display_errors', '0');
  ini_set('log_errors', '1');
} else {
  error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
  ini_set('display_errors', '0');
  ini_set('log_errors', '0');
}

require_once $path . '/framework/core/router.php';

$router = new router();

require_once $path . '/app/routes/api.php';

// Capturar cualquier output no deseado hasta aquí
$captured = ob_get_clean();
if (!empty($captured) && IS_DEV) {
  error_log("⚠️ API Warning: Output captured before routing: " . substr($captured, 0, 200));
}

// Reiniciar buffer para la respuesta JSON
ob_start();

try {
  $router->dispatch();
} catch (Exception $e) {
  if (ob_get_length()) ob_get_clean();
  http_response_code(500);

  if (IS_DEV) {
    echo json_encode([
      'success' => false,
      'error' => $e->getMessage(),
      'file' => $e->getFile(),
      'line' => $e->getLine(),
      'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 5)
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  } else {
    echo json_encode(['success' => false, 'error' => __('api.server_error')], JSON_UNESCAPED_UNICODE);
  }
  exit;
}

$output = ob_get_clean();

// Verificar si el output es JSON válido
if (!empty($output)) {
  $trimmed = trim($output);

  // Detectar si contiene HTML/warnings de PHP
  if (preg_match('/<br\s*\/?>|<b>|<\/b>/', $trimmed)) {
    if (IS_DEV) {
      // En dev, mostrar el error completo
      http_response_code(500);
      echo json_encode([
        'success' => false,
        'error' => __('api.php_warning_detected'),
        'output_captured' => $trimmed
      ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } else {
      // En prod, error genérico
      http_response_code(500);
      echo json_encode([
        'success' => false,
        'error' => __('api.server_error')
      ], JSON_UNESCAPED_UNICODE);
    }
    exit;
  }

  // Intentar validar JSON
  $decoded = json_decode($trimmed);

  if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(500);
    echo json_encode([
      'success' => false,
      'error' => __('api.invalid_json_response'),
      'json_error' => json_last_error_msg(),
      'debug' => IS_DEV ? substr($trimmed, 0, 500) : null
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  } else {
    echo $trimmed;
  }
} else {
  echo json_encode(['success' => true]);
}