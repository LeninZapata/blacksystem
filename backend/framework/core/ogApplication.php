<?php
// ogApplication - Maneja el ciclo de vida de la request
class ogApplication {
  private $router;

  public function __construct() {
    // Cargar ogRouter
    require_once OG_FRAMEWORK_PATH . '/core/ogRouter.php';
    $this->router = new ogRouter();

    // Guardar router en ogApp para acceder vía core()
    ogApp()->loaded['core_router'] = $this->router;

    // Cargar rutas del framework (compone rutas de framework + app)
    if (file_exists(OG_FRAMEWORK_PATH . '/routes/api.php')) {
      require_once OG_FRAMEWORK_PATH . '/routes/api.php';
    }
  }

  public function run() {
    // Capturar output previo
    $captured = ob_get_clean();
    if (!empty($captured) && OG_IS_DEV) {
      ogLog::warning('Output capturado antes del routing', ['output' => substr($captured, 0, 200)], ['module' => 'application', 'layer' => 'framework']);
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

    if (OG_IS_DEV) {
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
        'debug' => OG_IS_DEV ? substr($trimmed, 0, 500) : null
      ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } else {
      echo $trimmed;
    }
  }

  private function handlePhpWarning($output) {
    http_response_code(500);

    if (OG_IS_DEV) {
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