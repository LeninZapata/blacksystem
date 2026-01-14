<?php
// framework/routes/api.php - Compositor de rutas (framework + middle + app)

// Obtener router desde ogApp
$router = ogApp()->core('router');

// $pluginName viene del scope de bootstrap.php
if (!isset($pluginName)) {
  $pluginName = 'default';
}

$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);

// Normalizar path
$path = preg_replace('#/+#', '/', $path);
if (preg_match('#(/api/.*)$#', $path, $matches)) {
  $path = $matches[1];
}
$path = rtrim($path, '/');

// Extraer módulo
$module = null;
if (preg_match('#^/api/([^/]+)#', $path, $matches)) {
  $module = $matches[1];
}

// IMPORTANTE: Reescribir URL si tiene guiones (para que router matchee)
$urlRewritten = false;
if ($module && strpos($module, '-') !== false) {
  $moduleCamelCase = ogApp()->helper('str')::toCamelCase($module);

  // Reescribir $_SERVER['REQUEST_URI'] internamente
  $originalUri = $_SERVER['REQUEST_URI'];
  $_SERVER['REQUEST_URI'] = str_replace("/api/{$module}", "/api/{$moduleCamelCase}", $_SERVER['REQUEST_URI']);

  $urlRewritten = true;

  ogLog::warning("Ruta con kebab-case detectada (reescrita a camelCase)", [
    'url_original' => $originalUri,
    'url_reescrita' => $_SERVER['REQUEST_URI'],
    'module_original' => $module,
    'module_convertido' => $moduleCamelCase,
    'recomendacion' => "Usar URL /api/{$moduleCamelCase} directamente"
  ], ['module' => 'ogApi', 'layer' => 'framework/routes']);

  // Actualizar $module y $path para el resto del código
  $module = $moduleCamelCase;
  $path = str_replace("/api/{$matches[1]}", "/api/{$moduleCamelCase}", $path);
}

// PASO 1: Auto-registrar rutas CRUD desde JSON
if ($module) {
  $moduleCamelCase = $module; // Ya fue convertido arriba si tenía guiones

  // Buscar schema en: app → middle → framework
  $resourceFile = ogApp($pluginName)->getPath() . "/resources/schemas/{$moduleCamelCase}.json";
  if (!file_exists($resourceFile)) {
    $resourceFile = OG_FRAMEWORK_PATH . "/../middle/resources/schemas/{$moduleCamelCase}.json";
  }
  if (!file_exists($resourceFile)) {
    $resourceFile = OG_FRAMEWORK_PATH . "/resources/schemas/{$moduleCamelCase}.json";
  }

  if (file_exists($resourceFile)) {
    $config = json_decode(file_get_contents($resourceFile), true);

    // Verificar si existe controller personalizado
    $controllerClass = ucfirst($moduleCamelCase) . 'Controller';

    // Intentar cargar controller personalizado (app → middle → framework)
    if (!class_exists($controllerClass)) {
      try {
        ogApp()->loadController($moduleCamelCase);
      } catch (Exception $e) {
        ogLog::warning("Controller personalizado no encontrado: {$controllerClass}", ['module' => $moduleCamelCase, 'path' => $resourceFile], ['module' => 'ogApi', 'layer' => 'framework/routes']);
      }
    }

    // Instanciar controller (personalizado o genérico)
    $ctrl = class_exists($controllerClass)
      ? new $controllerClass()
      : new ogController($moduleCamelCase);

    $globalMw = $config['middleware'] ?? [];

    // Rutas CRUD siempre en camelCase
    $crudRoutes = [
      'list'   => ['get',    "/api/{$moduleCamelCase}",      'list'],
      'show'   => ['get',    "/api/{$moduleCamelCase}/{id}", 'show'],
      'create' => ['post',   "/api/{$moduleCamelCase}",      'create'],
      'update' => ['put',    "/api/{$moduleCamelCase}/{id}", 'update'],
      'delete' => ['delete', "/api/{$moduleCamelCase}/{id}", 'delete']
    ];

    foreach ($crudRoutes as $key => $routeData) {
      list($method, $routePath, $action) = $routeData;

      $routeConfig = $config['routes'][$key] ?? [];

      // Si la ruta no está habilitada, saltarla
      if (isset($routeConfig['enabled']) && $routeConfig['enabled'] === false) {
        continue;
      }

      $routeMw = array_merge($globalMw, $routeConfig['middleware'] ?? []);

      // Registrar ruta CRUD
      $route = $router->$method($routePath, [$ctrl, $action]);

      if (!empty($routeMw)) {
        $route->middleware($routeMw);
      }
    }
  }
}

// PASO 2: Cargar rutas manuales del FRAMEWORK
if ($module) {
  $moduleCamelCase = $module; // Ya convertido si tenía guiones

  $frameworkRoutes = OG_FRAMEWORK_PATH . '/routes/' . $moduleCamelCase . '.php';
  if (file_exists($frameworkRoutes)) {
    require_once $frameworkRoutes;
  }
}

// PASO 3: Cargar rutas manuales de MIDDLE
if ($module) {
  $moduleCamelCase = $module;

  $middleRoutes = OG_FRAMEWORK_PATH . '/../middle/routes/' . $moduleCamelCase . '.php';
  if (file_exists($middleRoutes)) {
    require_once $middleRoutes;
  }
}

// PASO 4: Cargar rutas manuales de APP del plugin actual
if ($module) {
  $moduleCamelCase = $module; // Ya convertido si tenía guiones

  $pluginPath = ogApp($pluginName)->getPath();
  $appRoutes = $pluginPath . '/routes/' . $moduleCamelCase . '.php';

  if (file_exists($appRoutes)) {
    require_once $appRoutes;
  }
}