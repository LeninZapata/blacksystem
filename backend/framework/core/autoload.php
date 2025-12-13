<?php
spl_autoload_register(function ($class) {
  $classLower = strtolower($class);

  // Mapa estático de clases más utilizadas
  static $classMap = [
    // Helpers (framework)
    'db' => FRAMEWORK_PATH . '/helpers/db.php',
    'log' => FRAMEWORK_PATH . '/helpers/log.php',
    'logreader' => FRAMEWORK_PATH . '/helpers/logReader.php',
    'lang' => FRAMEWORK_PATH . '/helpers/lang.php',
    'request' => FRAMEWORK_PATH . '/helpers/request.php',
    'response' => FRAMEWORK_PATH . '/helpers/response.php',
    'utils' => FRAMEWORK_PATH . '/helpers/utils.php',
    'validation' => FRAMEWORK_PATH . '/helpers/validation.php',
    'sessioncleanup' => FRAMEWORK_PATH . '/helpers/sessionCleanup.php',
    'routediscovery' => FRAMEWORK_PATH . '/helpers/routeDiscovery.php',

    // Core (framework)
    'controller' => FRAMEWORK_PATH . '/core/controller.php',
    'router' => FRAMEWORK_PATH . '/core/router.php',
    'resource' => FRAMEWORK_PATH . '/core/resource.php',
    'extensionloader' => FRAMEWORK_PATH . '/core/extensionLoader.php',

    // Middleware (framework)
    'authmiddleware' => FRAMEWORK_PATH . '/middleware/authMiddleware.php',
    'corsmiddleware' => FRAMEWORK_PATH . '/middleware/corsMiddleware.php',
    'jsonmiddleware' => FRAMEWORK_PATH . '/middleware/jsonMiddleware.php',
    'logmiddleware' => FRAMEWORK_PATH . '/middleware/logMiddleware.php',
    'throttlemiddleware' => FRAMEWORK_PATH . '/middleware/throttleMiddleware.php',

    // Controllers (app)
    'usercontroller' => APP_PATH . '/resources/controllers/userController.php',

    // Handlers (app)
    'userhandlers' => APP_PATH . '/resources/handlers/userHandlers.php',
    'clienthandlers' => APP_PATH . '/resources/handlers/clientHandlers.php',
  ];

  if (isset($classMap[$classLower])) {
    require_once $classMap[$classLower];
    return;
  }

  // Búsqueda dinámica (fallback)

  // Helpers (framework)
  $helperFile = FRAMEWORK_PATH . '/helpers/' . $classLower . '.php';
  if (file_exists($helperFile)) {
    require_once $helperFile;
    return;
  }

  // Core (framework)
  $coreFile = FRAMEWORK_PATH . '/core/' . $class . '.php';
  if (file_exists($coreFile)) {
    require_once $coreFile;
    return;
  }

  // Controllers personalizados
  $controllerFile = APP_PATH . '/resources/controllers/' . $class . '.php';
  if (file_exists($controllerFile)) {
    require_once $controllerFile;
    return;
  }

  // Handlers personalizados
  $handlerFile = APP_PATH . '/resources/handlers/' . $class . '.php';
  if (file_exists($handlerFile)) {
    require_once $handlerFile;
    return;
  }

  // Middleware (framework)
  $mwFile = FRAMEWORK_PATH . '/middleware/' . $class . '.php';
  if (file_exists($mwFile)) {
    require_once $mwFile;
    return;
  }

  // Services principales
  $serviceFile = FRAMEWORK_PATH . '/services/' . $classLower . '.php';
  if (file_exists($serviceFile)) {
    require_once $serviceFile;
    return;
  }

  // Integrations
  $servicesDir = FRAMEWORK_PATH . '/services/integrations/';
  if (is_dir($servicesDir)) {
    foreach (scandir($servicesDir) as $category) {
      if ($category === '.' || $category === '..') continue;

      $integrationFile = $servicesDir . $category . '/' . $classLower . '/' . $classLower . '.php';
      if (file_exists($integrationFile)) {
        require_once $integrationFile;
        return;
      }
    }
  }
});