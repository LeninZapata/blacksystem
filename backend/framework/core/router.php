<?php
// Router - Enrutador minimalista con middleware
class router {
  private $routes = [];
  private $prefix = '';
  private $groupMiddleware = [];

  // Middleware disponibles
  protected $middleware = [
    'auth' => 'authMiddleware',
    'json' => 'jsonMiddleware',
    'throttle' => 'throttleMiddleware'
  ];

  // Registrar rutas
  function get($path, $handler) { return $this->add('GET', $path, $handler); }
  function post($path, $handler) { return $this->add('POST', $path, $handler); }
  function put($path, $handler) { return $this->add('PUT', $path, $handler); }
  function delete($path, $handler) { return $this->add('DELETE', $path, $handler); }

  // Grupos
  function group($prefix, $callback) {
    $prev = $this->prefix;
    $prevMw = $this->groupMiddleware;
    $this->prefix = $prev . $prefix;
    $callback($this);
    $this->prefix = $prev;
    $this->groupMiddleware = $prevMw;
    return $this;
  }

  // Middleware de grupo
  function middleware($mw) {
    $this->groupMiddleware = array_merge($this->groupMiddleware, is_array($mw) ? $mw : [$mw]);
    return $this;
  }

  // Agregar ruta (soporta array de paths)
  private function add($method, $path, $handler) {
    $paths = is_array($path) ? $path : [$path];
    $route = null;

    foreach ($paths as $p) {
      $fullPath = $this->prefix . $p;
      $route = new route($method, $fullPath, $handler);
      if ($this->groupMiddleware) $route->middleware($this->groupMiddleware);
      $this->routes[$method][$fullPath] = $route;
    }

    return $route;
  }

  // Ejecutar
  function dispatch() {
    $method = $_SERVER['REQUEST_METHOD'];
    $path = $this->getPath();

    // Ruta exacta
    if (isset($this->routes[$method][$path])) {
      $this->exec($this->routes[$method][$path]);
      return;
    }

    // Ruta dinámica
    $this->matchDynamic($method, $path);
  }

  // Path actual - Normalizado sin prefijo de carpeta y sin slashes duplicados
  private function getPath() {
    $uri = $_SERVER['REQUEST_URI'];
    if (($pos = strpos($uri, '?')) !== false) $uri = substr($uri, 0, $pos);

    $path = parse_url($uri, PHP_URL_PATH);

    // Remover slashes duplicados: //api/user -> /api/user
    $path = preg_replace('#/+#', '/', $path);

    // Remover el prefijo de la carpeta del proyecto si existe
    // /factory-saas/api/system -> /api/system
    // /blacksystem/blacksystem/api/auth/login -> /api/auth/login
    if (preg_match('#(/api/.*)$#', $path, $matches)) {
      $path = $matches[1];
    }

    // Eliminar trailing slash (excepto si es solo "/")
    if ($path !== '/') {
      $path = rtrim($path, '/');
    }

    return $path;
  }

  // Match dinámico
  private function matchDynamic($method, $path) {
    if (!isset($this->routes[$method])) {
      $this->notFound('core.router.method_not_found', ['method' => $method]);
      return;
    }

    foreach ($this->routes[$method] as $route => $routeObj) {
      $pattern = preg_replace('/\{[^}]+\}/', '([^/]+)', $route);
      $pattern = '#^' . $pattern . '$#';
      if (preg_match($pattern, $path, $matches)) {
        array_shift($matches);
        $this->exec($routeObj, $matches);
        return;
      }
    }
    $this->notFound();
  }

  // Ejecutar handler con middleware
  private function exec($routeObj, $params = []) {
    // Si es un objeto Route, ejecutar middleware
    if ($routeObj instanceof route) {
      foreach ($routeObj->getMiddleware() as $mw) {
        if (!$this->runMiddleware($mw)) return; // Middleware bloqueó
      }
      $handler = $routeObj->getHandler();
    } else {
      $handler = $routeObj; // Retrocompatibilidad
    }

    // Ejecutar handler
    if (is_callable($handler)) {
      call_user_func_array($handler, $params);
      return;
    }
    if (is_array($handler) && count($handler) === 2) {
      call_user_func_array($handler, $params);
      return;
    }
    if (is_string($handler) && strpos($handler, '@') !== false) {
      list($class, $method) = explode('@', $handler);
      if (class_exists($class)) {
        call_user_func_array([new $class(), $method], $params);
        return;
      }
    }
    throw new Exception(__('core.router.invalid_handler'));
  }

  // Ejecutar middleware
  private function runMiddleware($mwName) {
    $parts = explode(':', $mwName);
    $name = $parts[0];
    $mwParams = isset($parts[1]) ? explode(',', $parts[1]) : [];

    if (!isset($this->middleware[$name])) {
      throw new Exception(__('core.router.middleware_not_found', ['middleware' => $name]));
    }

    $mwClass = $this->middleware[$name];
    $mwFile = __DIR__ . '/../middleware/' . $mwClass . '.php';

    if (!file_exists($mwFile)) {
      throw new Exception(__('core.router.middleware_file_not_found', ['middleware' => $mwClass]));
    }

    require_once $mwFile;

    if (!class_exists($mwClass)) {
      throw new Exception(__('core.router.middleware_class_not_found', ['middleware' => $mwClass]));
    }

    $mw = new $mwClass();

    // Solo llamar setParams si existe el método Y hay parámetros
    if (!empty($mwParams) && method_exists($mw, 'setParams')) {
      $mw->setParams($mwParams);
    }

    return $mw->handle(); // true = continuar, false = bloquear
  }

  // 404
  private function notFound($errorKey = 'core.router.not_found', $params = []) {
    $params['path'] = $this->getPath();
    response::json(['success' => false, 'error' => __($errorKey, $params)], 404);
  }
}

// Clase Route para manejar middleware por ruta
class route {
  private $method, $path, $handler, $middleware = [];

  function __construct($method, $path, $handler) {
    $this->method = $method;
    $this->path = $path;
    $this->handler = $handler;
  }

  // Agregar middleware a ruta específica
  function middleware($mw) {
    $this->middleware = array_merge($this->middleware, is_array($mw) ? $mw : [$mw]);
    return $this;
  }

  function getMiddleware() { return $this->middleware; }
  function getHandler() { return $this->handler; }
  function getMethod() { return $this->method; }
  function getPath() { return $this->path; }
}