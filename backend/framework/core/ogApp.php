<?php
// ogFramework - Singleton principal del framework
class ogFramework {
  private static $instances = [];
  private $pluginName;
  private $pluginPath;
  private $isWordPress;
  private $logMeta = ['module' => 'ogFramework', 'layer' => 'core/framework'];
  public $loaded = [];

  private function __construct($pluginName = 'default', $pluginPath = null, $isWordPress = false) {
    $this->pluginName = $pluginName;

    if (!$pluginPath) {
      throw new Exception("Plugin path is required for ogApp initialization");
    }

    $this->pluginPath = $pluginPath;
    $this->isWordPress = $isWordPress;
  }

  static function instance($pluginName = 'default', $pluginPath = null, $isWordPress = false) {
    if (!isset(self::$instances[$pluginName])) {
      self::$instances[$pluginName] = new self($pluginName, $pluginPath, $isWordPress);
    }
    return self::$instances[$pluginName];
  }

  // Cargar helper bajo demanda (con referencia)
  function helper($name) {
    $key = "helper_{$name}";

    if (isset($this->loaded[$key])) {
      return $this->loaded[$key];
    }

    // Extraer ruta y nombre del archivo
    $parts = explode('/', $name);
    $fileName = array_pop($parts);
    $subPath = !empty($parts) ? implode('/', $parts) . '/' : '';

    $helperFile = OG_FRAMEWORK_PATH . "/helpers/{$subPath}og" . ucfirst($fileName) . ".php";
    if (!file_exists($helperFile)) {
      $helperFile = $this->pluginPath . "/helpers/{$subPath}og" . ucfirst($fileName) . ".php";
    }

    if (!file_exists($helperFile)) {
      throw new Exception("Helper not found: {$name}");
    }

    require_once $helperFile;
    $className = "og" . ucfirst($fileName);

    if (!class_exists($className)) {
      throw new Exception("Helper class not found: {$className}");
    }

    $this->loaded[$key] = new $className();
    return $this->loaded[$key];
  }

  // Cargar helper bajo demanda (sin instanciar)
  function loadHelper($name) {
    $parts = explode('/', $name);
    $fileName = array_pop($parts);
    $subPath = !empty($parts) ? implode('/', $parts) . '/' : '';

    $helperFile = OG_FRAMEWORK_PATH . "/helpers/{$subPath}og" . ucfirst($fileName) . ".php";
    if (!file_exists($helperFile)) {
      $helperFile = $this->pluginPath . "/helpers/{$subPath}og" . ucfirst($fileName) . ".php";
    }

    if (!file_exists($helperFile)) {
      throw new Exception("Helper not found: {$name}");
    }

    require_once $helperFile;
    $className = "og" . ucfirst($fileName);

    if (!class_exists($className)) {
      throw new Exception("Helper class not found: {$className}");
    }

    return true;
  }

  // Cargar service bajo demanda (con referencia)
  function service($name) {
    $key = "service_{$name}";

    if (isset($this->loaded[$key])) {
      return $this->loaded[$key];
    }

    $parts = explode('/', $name);
    $fileName = array_pop($parts);
    $subPath = !empty($parts) ? implode('/', $parts) . '/' : '';

    // 1. Buscar en framework con prefijo og
    $fwClassName = "og" . ucfirst($fileName) . "Service";
    $fwFile = OG_FRAMEWORK_PATH . "/services/{$subPath}og" . ucfirst($fileName) . "Service.php";
    if (file_exists($fwFile)) {
      require_once $fwFile;
      if (!class_exists($fwClassName)) {
        throw new Exception("Service class not found: {$fwClassName}");
      }
      $this->loaded[$key] = new $fwClassName();
      return $this->loaded[$key];
    }

    // 2. Buscar en app sin prefijo og
    $appClassName = ucfirst($fileName) . "Service";
    $appFile = $this->pluginPath . "/services/{$subPath}" . $appClassName . ".php";
    if (file_exists($appFile)) {
      require_once $appFile;
      if (!class_exists($appClassName)) {
        throw new Exception("Service class not found: {$appClassName}");
      }
      $this->loaded[$key] = new $appClassName();
      return $this->loaded[$key];
    }

    throw new Exception("Service not found: {$name}");
  }

  // Cargar service bajo demanda (sin instanciar)
  function loadService($name) {
    $parts = explode('/', $name);
    $fileName = array_pop($parts);
    $subPath = !empty($parts) ? implode('/', $parts) . '/' : '';

    // 1. Buscar en framework con prefijo og
    $fwClassName = "og" . ucfirst($fileName) . "Service";
    $fwFile = OG_FRAMEWORK_PATH . "/services/{$subPath}og" . ucfirst($fileName) . "Service.php";
    if (file_exists($fwFile)) {
      require_once $fwFile;
      if (!class_exists($fwClassName)) {
        throw new Exception("Service class not found: {$fwClassName}");
      }
      return true;
    }

    // 2. Buscar en app sin prefijo og
    $appClassName = ucfirst($fileName) . "Service";
    $appFile = $this->pluginPath . "/services/{$subPath}" . $appClassName . ".php";
    if (file_exists($appFile)) {
      require_once $appFile;
      if (!class_exists($appClassName)) {
        throw new Exception("Service class not found: {$appClassName}");
      }
      return true;
    }

    throw new Exception("Service not found: {$name}");
  }

  // Cargar core class bajo demanda (con referencia)
  function core($name) {
    $key = "core_{$name}";

    if (isset($this->loaded[$key])) {
      return $this->loaded[$key];
    }

    if ($name === 'router' && !isset($this->loaded[$key])) {
      throw new Exception("Router not initialized yet");
    }

    $parts = explode('/', $name);
    $fileName = array_pop($parts);
    $subPath = !empty($parts) ? implode('/', $parts) . '/' : '';

    $coreFile = OG_FRAMEWORK_PATH . "/core/{$subPath}og" . ucfirst($fileName) . ".php";

    if (!file_exists($coreFile)) {
      throw new Exception("Core class not found: {$name}");
    }

    require_once $coreFile;
    $className = "og" . ucfirst($fileName);

    if (!class_exists($className)) {
      throw new Exception("Core class not found: {$className}");
    }

    $this->loaded[$key] = new $className();
    return $this->loaded[$key];
  }

  // Cargar core class bajo demanda (sin instanciar)
  function loadCore($name) {
    $parts = explode('/', $name);
    $fileName = array_pop($parts);
    $subPath = !empty($parts) ? implode('/', $parts) . '/' : '';

    $coreFile = OG_FRAMEWORK_PATH . "/core/{$subPath}og" . ucfirst($fileName) . ".php";

    if (!file_exists($coreFile)) {
      throw new Exception("Core class not found: {$name}");
    }

    require_once $coreFile;
    $className = "og" . ucfirst($fileName);

    if (!class_exists($className)) {
      throw new Exception("Core class not found: {$className}");
    }

    return true;
  }

  // Cargar controller bajo demanda (con referencia)
  function controller($name) {
    $key = "controller_{$name}";
    if (isset($this->loaded[$key])) {
      return $this->loaded[$key];
    }

    $parts = explode('/', $name);
    $fileName = array_pop($parts);
    $subPath = !empty($parts) ? implode('/', $parts) . '/' : '';

    // 1. Buscar en framework/controllers/{subPath}og{Name}Controller.php
    $fwClassName = "og" . ucfirst($fileName) . "Controller";
    $fwFile = OG_FRAMEWORK_PATH . "/resources/controllers/{$subPath}og" . ucfirst($fileName) . "Controller.php";
    if (file_exists($fwFile)) {
      require_once $fwFile;
      if (!class_exists($fwClassName)) {
        throw new Exception("Controller class not found: {$fwClassName}");
      }
      $this->loaded[$key] = new $fwClassName();
      return $this->loaded[$key];
    }

    // 2. Buscar en app/resources/controllers/{subPath}{Name}Controller.php
    $appClassName = ucfirst($fileName) . "Controller";
    $appFile = $this->pluginPath . "/resources/controllers/{$subPath}" . $appClassName . ".php";
    if (file_exists($appFile)) {
      require_once $appFile;
      if (!class_exists($appClassName)) {
        throw new Exception("Controller class not found: {$appClassName}");
      }
      $this->loaded[$key] = new $appClassName();
      return $this->loaded[$key];
    }

    throw new Exception("Controller not found: {$name}");
  }

  // Cargar controller bajo demanda (sin instanciar)
  function loadController($name) {
    $parts = explode('/', $name);
    $fileName = array_pop($parts);
    $subPath = !empty($parts) ? implode('/', $parts) . '/' : '';

    $uname = ucfirst($fileName) . "Controller";
    $controllerFile = OG_FRAMEWORK_PATH . "/resources/controllers/{$subPath}{$uname}.php";
    if (!file_exists($controllerFile)) {
      $controllerFile = $this->pluginPath . "/resources/controllers/{$subPath}{$uname}.php";
    }

    if (!file_exists($controllerFile)) {
      throw new Exception("Controller not found: {$name}");
    }

    require_once $controllerFile;

    if (!class_exists($uname)) {
      throw new Exception("Controller class not found: {$name}");
    }

    return true;
  }

  // Cargar handler bajo demanda (con referencia)
  function handler($name) {
    $key = "handler_{$name}";
    if (isset($this->loaded[$key])) {
      return $this->loaded[$key];
    }

    $parts = explode('/', $name);
    $fileName = array_pop($parts);
    $subPath = !empty($parts) ? implode('/', $parts) . '/' : '';

    // 1. Buscar en framework/handlers/{subPath}og{Name}Handler.php
    $fwClassName = "og" . ucfirst($fileName) . "Handler";
    $fwFile = OG_FRAMEWORK_PATH . "/handlers/{$subPath}og" . ucfirst($fileName) . "Handler.php";
    if (file_exists($fwFile)) {
      require_once $fwFile;
      if (!class_exists($fwClassName)) {
        ogLog::throwError("handler - Handler class not found: {$fwClassName}", [$name], $this->logMeta);
      }
      $this->loaded[$key] = new $fwClassName();
      return $this->loaded[$key];
    }

    // 2. Buscar en app/resources/handlers/{subPath}{Name}Handler.php
    $appClassName = ucfirst($fileName) . "Handler";
    $appFile = $this->pluginPath . "/resources/handlers/{$subPath}" . $appClassName . ".php";
    if (file_exists($appFile)) {
      require_once $appFile;
      if (!class_exists($appClassName)) {
        ogLog::throwError("handler - Handler class not found: {$appClassName}", [$name], $this->logMeta);
      }
      $this->loaded[$key] = new $appClassName();
      return $this->loaded[$key];
    }

    ogLog::throwError("handler - Handler not found in app: {$name}", [$name], $this->logMeta);
  }

  // Cargar handler bajo demanda (sin instanciar)
  function loadHandler($name) {
    $parts = explode('/', $name);
    $fileName = array_pop($parts);
    $subPath = !empty($parts) ? implode('/', $parts) . '/' : '';

    $uname = ucfirst($fileName) . "Handler";
    $handlerFile = OG_FRAMEWORK_PATH . "/resources/handlers/{$subPath}{$uname}.php";
    if (!file_exists($handlerFile)) {
      $handlerFile = $this->pluginPath . "/resources/handlers/{$subPath}{$uname}.php";
    }

    if (!file_exists($handlerFile)) {
      ogLog::throwError("loadHandler - Handler not found in app: {$name}", ['name' => $name, 'name to search' => $uname], $this->logMeta);
    }

    require_once $handlerFile;

    if (!class_exists($uname)) {
      ogLog::throwError("loadHandler - Handler class not found: {$name}", ['name' => $name, 'name to search' => $uname], $this->logMeta);
    }

    return true;
  }

  // Cargar middleware bajo demanda (sin instanciar)
  function loadMiddleware($name) {
    $mwFile = OG_FRAMEWORK_PATH . "/middleware/{$name}.php";
    if (!file_exists($mwFile)) {
      $mwFile = $this->pluginPath . "/middleware/{$name}.php";
    }

    if (!file_exists($mwFile)) {
      throw new Exception("Middleware not found: {$name}");
    }

    require_once $mwFile;

    if (!class_exists($name)) {
      throw new Exception("Middleware class not found: {$name}");
    }

    return true;
  }

  // Acceso rápido a DB
  function db() {
    if (!class_exists('ogDb')) {
      require_once OG_FRAMEWORK_PATH . '/helpers/ogDb.php';
    }
    return ogDb::table(...func_get_args());
  }

  // Obtener config del plugin
  function getConfig($key = null, $default = null) {
    $configFile = $this->pluginPath . "/config/consts.php";

    if (!file_exists($configFile)) {
      return $default;
    }

    if (!isset($this->loaded['config'])) {
      $this->loaded['config'] = include $configFile;
    }

    if ($key === null) {
      return $this->loaded['config'];
    }

    return $this->loaded['config'][$key] ?? $default;
  }

  function getPath($subPath = '') {
    return $this->pluginPath . ($subPath ? '/' . ltrim($subPath, '/') : '');
  }

  function getName() {
    return $this->pluginName;
  }

  function isWordPress() {
    return $this->isWordPress;
  }

  function isLoaded($type, $name) {
    $key = "{$type}_{$name}";
    return isset($this->loaded[$key]);
  }

  function getLoaded() {
    return array_keys($this->loaded);
  }

  function unload($type, $name) {
    $key = "{$type}_{$name}";
    if (isset($this->loaded[$key])) {
      unset($this->loaded[$key]);
      return true;
    }
    return false;
  }

  function getApplication() {
    if (!class_exists('ogApplication')) {
      require_once OG_FRAMEWORK_PATH . '/core/ogApplication.php';
    }
    return new ogApplication();
  }

  static function getAllInstances() {
    return self::$instances;
  }

  private function __clone() {}

  public function __wakeup() {
    throw new Exception("Cannot unserialize singleton");
  }
}

// Alias global para acceso más corto
function ogApp($pluginName = 'default', $pluginPath = null, $isWordPress = false) {
  return ogFramework::instance($pluginName, $pluginPath, $isWordPress);
}