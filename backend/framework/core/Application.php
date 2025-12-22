<?php
// Application - Maneja el ciclo de vida de la request
class Application {
  private $router;

  public function __construct() {
    // Cargar autoloader
    require_once FRAMEWORK_PATH . '/core/autoload.php';

    // Cargar router
    require_once FRAMEWORK_PATH . '/core/router.php';
    $this->router = new router();

    // Hacer $router accesible en el scope de routes/api.php
    $router = $this->router;

    // Cargar rutas de la aplicación
    if (file_exists(ROUTES_PATH . '/api.php')) {
      require_once ROUTES_PATH . '/api.php';
    }
  }

  public function run() {
    // Capturar output previo
    $captured = ob_get_clean();
    if (!empty($captured) && IS_DEV) {
      log::warning('Output capturado antes del routing', ['output' => substr($captured, 0, 200)], ['module' => 'application', 'layer' => 'framework']);
    }

    // Reiniciar buffer para la respuesta
    ob_start();

    try {
      $this->router->dispatch();
    } catch (Exception $e) {
      $this->handleException($e);
      exit;
    }

    $this->sendResponse();
  }

  private function handleException($e) {
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
      echo json_encode([
        'success' => false,
        'error' => __('api.server_error', ['message' => $e->getMessage()])
      ], JSON_UNESCAPED_UNICODE);
    }
  }

  private function sendResponse() {
    $output = ob_get_clean();

    if (empty($output)) {
      echo json_encode(['success' => true]);
      return;
    }

    $trimmed = trim($output);

    // Detectar HTML/warnings de PHP
    if (preg_match('/<br\s*\/?>|<b>|<\/b>/', $trimmed)) {
      $this->handlePhpWarning($trimmed);
      return;
    }

    // Validar JSON
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
  }

  private function handlePhpWarning($output) {
    http_response_code(500);

    if (IS_DEV) {
      echo json_encode([
        'success' => false,
        'error' => __('api.php_warning_detected'),
        'output_captured' => $output
      ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } else {
      echo json_encode([
        'success' => false,
        'error' => __('api.server_error')
      ], JSON_UNESCAPED_UNICODE);
    }
  }
} 