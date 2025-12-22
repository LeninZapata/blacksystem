<?php
spl_autoload_register(function ($class) {
  // Clases que mantienen case original (PascalCase) para compatibilidad Linux
  static $caseSensitiveClasses = ['Application'];
  
  $classLower = in_array($class, $caseSensitiveClasses) ? $class : strtolower($class);

  // Mapa estático SOLO de clases core que se usan en CADA request
  static $classMap = [
    // Core (framework) - Se usa siempre
    'Application' => FRAMEWORK_PATH . '/core/Application.php',
    'controller' => FRAMEWORK_PATH . '/core/controller.php',
    'router' => FRAMEWORK_PATH . '/core/router.php',
    'resource' => FRAMEWORK_PATH . '/core/resource.php',
    'extensionloader' => FRAMEWORK_PATH . '/core/extensionLoader.php',
    'service' => FRAMEWORK_PATH . '/core/service.php',
    'route' => FRAMEWORK_PATH . '/core/router.php',

    // Helpers críticos - Se usan en casi todos los requests
    'request' => FRAMEWORK_PATH . '/helpers/request.php',
    'response' => FRAMEWORK_PATH . '/helpers/response.php',
    'db' => FRAMEWORK_PATH . '/helpers/db.php',
  ];

  if (isset($classMap[$classLower])) {
    require_once $classMap[$classLower];
    return;
  }

  // Auto-discovery dinámico (lazy loading)

  // 1. Helpers (framework) - Carga bajo demanda
  $helperFile = FRAMEWORK_PATH . '/helpers/' . $classLower . '.php';
  if (file_exists($helperFile)) {
    require_once $helperFile;
    return;
  }

  // 2. Core (framework)
  $coreFile = FRAMEWORK_PATH . '/core/' . $class . '.php';
  if (file_exists($coreFile)) {
    require_once $coreFile;
    return;
  }

  // 3. Middleware (framework) - Carga bajo demanda
  $mwFile = FRAMEWORK_PATH . '/middleware/' . $class . '.php';
  if (file_exists($mwFile)) {
    require_once $mwFile;
    return;
  }

  // 4. Controllers (app) - Carga bajo demanda
  $controllerFile = APP_PATH . '/resources/controllers/' . $class . '.php';
  if (file_exists($controllerFile)) {
    require_once $controllerFile;
    return;
  }

  // 5. Handlers (app) - Carga bajo demanda
  $handlerFile = APP_PATH . '/resources/handlers/' . $class . '.php';
  if (file_exists($handlerFile)) {
    require_once $handlerFile;
    return;
  }

  // 6. Traits (framework) - Carga bajo demanda
  $traitFile = FRAMEWORK_PATH . '/traits/' . $class . '.php';
  if (file_exists($traitFile)) {
    require_once $traitFile;
    return;
  }

  // 7. Services - Auto-discovery con patrón inteligente
  if (tryLoadService($class, $classLower)) {
    return;
  }

  // Si llegamos aquí, la clase no fue encontrada
  handleClassNotFound($class);
});

// Función helper para cargar servicios dinámicamente
function tryLoadService($class, $classLower) {
  // Buscar servicios principales en /services/ (chatApiService, aiService, etc.)
  $serviceFile = SERVICES_PATH . '/' . $classLower . '.php';
  if (file_exists($serviceFile)) {
    require_once $serviceFile;
    return true;
  }

  // Buscar en subcarpeta /integrations/ (chatApiService dentro de chatapi/)
  $categories = ['chatapi', 'ai', 'email', 'storage', 'payment'];
  foreach ($categories as $category) {
    $categoryServiceFile = SERVICES_PATH . "/integrations/{$category}/{$classLower}.php";
    if (file_exists($categoryServiceFile)) {
      require_once $categoryServiceFile;
      return true;
    }
  }

  // Detectar sufijo (Provider, Normalizer, Validator, Service)
  $suffixes = ['provider', 'normalizer', 'validator', 'service'];

  foreach ($suffixes as $suffix) {
    if (str_ends_with($classLower, $suffix)) {
      $providerName = substr($classLower, 0, -strlen($suffix));

      // Buscar en todas las categorías de integración
      foreach ($categories as $category) {
        // Patrón 1: /integrations/{category}/{provider}/{class}.php
        $file = SERVICES_PATH . "/integrations/{$category}/{$providerName}/{$classLower}.php";
        if (file_exists($file)) {
          loadServiceDependencies($category, $providerName);
          require_once $file;
          return true;
        }

        // Patrón 2: /integrations/{category}/{provider}/{Provider}{Suffix}.php
        $file = SERVICES_PATH . "/integrations/{$category}/{$providerName}/{$class}.php";
        if (file_exists($file)) {
          loadServiceDependencies($category, $providerName);
          require_once $file;
          return true;
        }
      }
    }
  }

  return false;
}

// Cargar dependencias de servicios (interfaces y clases base)
function loadServiceDependencies($category, $provider) {
  static $loaded = [];
  $key = "{$category}:{$provider}";

  if (isset($loaded[$key])) return;

  // Cargar interface si existe
  $interfaceFile = SERVICES_PATH . "/integrations/{$category}/" . $category . "ProviderInterface.php";
  if (file_exists($interfaceFile)) {
    require_once $interfaceFile;
  }

  // Cargar clase base si existe
  $baseFile = SERVICES_PATH . "/integrations/{$category}/base" . ucfirst($category) . "Provider.php";
  if (file_exists($baseFile)) {
    require_once $baseFile;
  }

  $loaded[$key] = true;
}

// Manejar clase no encontrada
function handleClassNotFound($class) {
  $isApi = isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/api/') !== false;
  $isJson = isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false;

  // Log del error (log y lang ya están cargados en consts.php)
  log::error(__('core.autoload.class_not_found', ['class' => $class]), [
    'class' => $class,
    'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
    'is_api' => $isApi
  ], ['module' => 'autoload']);

  // Si es API o JSON request, responder en JSON
  if ($isApi || $isJson) {
    if (class_exists('response', false)) {
      response::error(__('core.autoload.class_not_found', ['class' => $class]), 500);
    } else {
      http_response_code(500);
      header('Content-Type: application/json');
      echo json_encode([
        'success' => false,
        'error' => __('core.autoload.class_not_found', ['class' => $class]),
        'class' => $class
      ], JSON_UNESCAPED_UNICODE);
      exit;
    }
  } else {
    // Respuesta HTML para peticiones web
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');

    if (defined('IS_DEV') && IS_DEV) {
      echo "<!DOCTYPE html>
<html>
<head>
  <title>" . __('core.autoload.error_title') . "</title>
  <style>
    body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
    .error-box { background: white; padding: 20px; border-radius: 5px; border-left: 4px solid #e74c3c; }
    h1 { color: #e74c3c; margin: 0 0 10px 0; }
    code { background: #f8f9fa; padding: 2px 6px; border-radius: 3px; }
  </style>
</head>
<body>
  <div class='error-box'>
    <h1>" . __('core.autoload.error_title') . "</h1>
    <p>" . __('core.autoload.class_not_found_message', ['class' => $class]) . "</p>
    <p><strong>URI:</strong> " . htmlspecialchars($_SERVER['REQUEST_URI'] ?? 'unknown') . "</p>
  </div>
</body>
</html>";
    } else {
      echo "<!DOCTYPE html>
<html>
<head>
  <title>" . __('core.autoload.server_error_title') . "</title>
  <style>
    body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
    .error-box { background: white; padding: 20px; border-radius: 5px; border-left: 4px solid #e74c3c; }
    h1 { color: #e74c3c; }
  </style>
</head>
<body>
  <div class='error-box'>
    <h1>" . __('core.autoload.server_error_title') . "</h1>
    <p>" . __('core.autoload.server_error_message') . "</p>
  </div>
</body>
</html>";
    }
    exit;
  }
}