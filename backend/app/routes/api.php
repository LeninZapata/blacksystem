<?php
// routes/api.php - Router híbrido: Rutas manuales + Auto-registro CRUD

$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);

// Normalizar path: remover slashes duplicados y prefijos
$path = preg_replace('#/+#', '/', $path);
// Soportar múltiples niveles de carpetas: /blacksystem/blacksystem/api/auth -> /api/auth
if (preg_match('#(/api/.*)$#', $path, $matches)) {
  $path = $matches[1];
}
$path = rtrim($path, '/');

// Extraer el módulo: /api/user -> user
$module = null;
if (preg_match('#^/api/([^/]+)#', $path, $matches)) {
  $module = $matches[1];
}

// PASO 1: Auto-registrar rutas CRUD desde JSON
if ($module) {
  $resourceFile = APP_PATH . "/resources/schemas/{$module}.json";  // ✅ CORREGIDO

  if (file_exists($resourceFile)) {

    $config = json_decode(file_get_contents($resourceFile), true);

    // Verificar si existe controller personalizado
    $controllerClass = ucfirst($module) . 'Controller';  // ✅ CORREGIDO: PascalCase
    $ctrl = class_exists($controllerClass)
    ? new $controllerClass()
    : new controller($module);

    $globalMw = $config['middleware'] ?? [];

    // Rutas CRUD estándar
    $crudRoutes = [
      'list'   => ['get',    "/api/{$module}",      'list'],
      'show'   => ['get',    "/api/{$module}/{id}", 'show'],
      'create' => ['post',   "/api/{$module}",      'create'],
      'update' => ['put',    "/api/{$module}/{id}", 'update'],
      'delete' => ['delete', "/api/{$module}/{id}", 'delete']
    ];

    foreach ($crudRoutes as $key => $routeData) {
      list($method, $routePath, $action) = $routeData;

      // Obtener configuración de la ruta desde JSON
      $routeConfig = $config['routes'][$key] ?? [];

      // Si la ruta no está habilitada en JSON, saltarla
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

// PASO 2: Cargar rutas manuales (custom routes)
$manualRoutes = ROUTES_PATH . '/apis/' . $module . '.php';
if ($module && file_exists($manualRoutes)) {
  require_once $manualRoutes;
}