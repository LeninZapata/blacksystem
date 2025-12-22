<?php
class extensionLoader {
  private static $loaded = [];
  private static $extensionPath = EXTENSIONS_PATH;

  // Cargar SOLO un extension específico
  static function load($extensionName, $router) {
    // Evitar cargar dos veces
    if (isset(self::$loaded[$extensionName])) {
      return true;
    }

    $extensionPath = self::$extensionPath . '/' . $extensionName;
    $configFile = $extensionPath . '/extension.json';

    // Verificar que existe
    if (!file_exists($configFile)) {
      response::error(__('core.extension.not_found', ['extension' => $extensionName]), 404);
      return false;
    }

    $config = json_decode(file_get_contents($configFile), true);

    // Verificar que está habilitado
    if (!($config['enabled'] ?? false)) {
      response::error(__('core.extension.disabled', ['extension' => $extensionName]), 403);
      return false;
    }

    // Verificar que tiene backend
    if (!($config['backend'] ?? false)) {
      response::error(__('core.extension.no_backend', ['extension' => $extensionName]), 400);
      return false;
    }

    // Autoload de clases del extension
    spl_autoload_register(function($class) use ($extensionPath, $extensionName) {
      // Controllers
      $file = $extensionPath . '/controllers/' . $class . '.php';
      if (file_exists($file)) {
        require_once $file;
        return;
      }

      // Services
      $file = $extensionPath . '/services/' . $class . '.php';
      if (file_exists($file)) {
        require_once $file;
        return;
      }

      // Models
      $file = $extensionPath . '/models/' . $class . '.php';
      if (file_exists($file)) {
        require_once $file;
        return;
      }
    });

    // Cargar rutas del extension
    $routesFile = $extensionPath . '/routes/routes.php';
    if (file_exists($routesFile)) {
      $prefix = $config['routes_prefix'] ?? "/extension/{$extensionName}";

      $router->group($prefix, function($router) use ($routesFile) {
        $routesFn = require $routesFile;
        if (is_callable($routesFn)) {
          $routesFn($router);
        }
      });
    }

    self::$loaded[$extensionName] = $config;

    return true;
  }

  // Solo si realmente necesitas cargar todos (ejemplo: panel admin)
  static function loadAll($router) {
    $extensionsDir = self::$extensionPath;

    if (!is_dir($extensionsDir)) return;

    foreach (scandir($extensionsDir) as $extension) {
      if ($extension === '.' || $extension === '..') continue;
      self::load($extension, $router);
    }
  }

  // Verificar si un extension está cargado
  static function isLoaded($extensionName) {
    return isset(self::$loaded[$extensionName]);
  }

  // Obtener lista de extensiones disponibles (sin cargarlos)
  static function getAvailable() {
    $extensionsDir = self::$extensionPath;
    $extensions = [];

    if (!is_dir($extensionsDir)) return $extensions;

    foreach (scandir($extensionsDir) as $extension) {
      if ($extension === '.' || $extension === '..') continue;

      $configFile = $extensionsDir . '/' . $extension . '/extension.json';
      if (file_exists($configFile)) {
        $config = json_decode(file_get_contents($configFile), true);
        $extensions[$extension] = $config;
      }
    }

    return $extensions;
  }

  // Obtener config de un extension (sin cargarlo)
  static function getConfig($extensionName) {
    if (isset(self::$loaded[$extensionName])) {
      return self::$loaded[$extensionName];
    }

    $configFile = self::$extensionPath . '/' . $extensionName . '/extension.json';
    if (file_exists($configFile)) {
      return json_decode(file_get_contents($configFile), true);
    }

    return null;
  }
}