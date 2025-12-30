<?php
// ogFramework - Singleton principal del framework
class ogFramework {
  private static $instances = [];
  private $pluginName;
  private $pluginPath;
  private $isWordPress;
  private $logMeta = ['module' => 'ogFramework', 'layer' => 'core/framework'];
  public $loaded = [];  // Public para que ogApplication pueda guardar router

  private function __construct($pluginName = 'default', $pluginPath = null, $isWordPress = false) {
    $this->pluginName = $pluginName;

    // No usar APP_PATH como fallback - debe pasarse explícitamente
    if (!$pluginPath) {
      throw new Exception("Plugin path is required for ogApp initialization");
    }

    $this->pluginPath = $pluginPath;
    $this->isWordPress = $isWordPress;
  }

  // Obtener instancia (singleton por plugin)
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

    $helperFile = OG_FRAMEWORK_PATH . "/helpers/og" . ucfirst($name) . ".php";
    if (!file_exists($helperFile)) {
      $helperFile = $this->pluginPath . "/helpers/og" . ucfirst($name) . ".php";
    }

    if (!file_exists($helperFile)) {
      throw new Exception("Helper not found: {$name}");
    }

    require_once $helperFile;
    $className = "og" . ucfirst($name);

    if (!class_exists($className)) {
      throw new Exception("Helper class not found: {$className}");
    }

    $this->loaded[$key] = new $className();
    return $this->loaded[$key];
  }

  // Cargar helper bajo demanda (sin instanciar)
  function loadHelper($name) {
    $helperFile = OG_FRAMEWORK_PATH . "/helpers/og" . ucfirst($name) . ".php";
    if (!file_exists($helperFile)) {
      $helperFile = $this->pluginPath . "/helpers/og" . ucfirst($name) . ".php";
    }

    if (!file_exists($helperFile)) {
      throw new Exception("Helper not found: {$name}");
    }

    require_once $helperFile;
    $className = "og" . ucfirst($name);

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

    $serviceFile = OG_FRAMEWORK_PATH . "/services/og" . ucfirst($name) . "Service.php";
    if (!file_exists($serviceFile)) {
      $serviceFile = $this->pluginPath . "/services/og" . ucfirst($name) . "Service.php";
    }

    if (!file_exists($serviceFile)) {
      throw new Exception("Service not found: {$name}");
    }

    require_once $serviceFile;
    $className = "og" . ucfirst($name) . "Service";

    if (!class_exists($className)) {
      throw new Exception("Service class not found: {$className}");
    }

    $this->loaded[$key] = new $className();
    return $this->loaded[$key];
  }

  // Cargar service bajo demanda (sin instanciar)
  function loadService($name) {
    $serviceFile = OG_FRAMEWORK_PATH . "/services/og" . ucfirst($name) . "Service.php";
    if (!file_exists($serviceFile)) {
      $serviceFile = $this->pluginPath . "/services/og" . ucfirst($name) . "Service.php";
    }

    if (!file_exists($serviceFile)) {
      throw new Exception("Service not found: {$name}");
    }

    require_once $serviceFile;
    $className = "og" . ucfirst($name) . "Service";

    if (!class_exists($className)) {
      throw new Exception("Service class not found: {$className}");
    }

    return true;
  }

  // Cargar core class bajo demanda (con referencia)
  function core($name) {
    $key = "core_{$name}";

    if (isset($this->loaded[$key])) {
      return $this->loaded[$key];
    }

    // Router ya fue guardado por ogApplication
    if ($name === 'router' && !isset($this->loaded[$key])) {
      throw new Exception("Router not initialized yet");
    }

    $coreFile = OG_FRAMEWORK_PATH . "/core/og" . ucfirst($name) . ".php";

    if (!file_exists($coreFile)) {
      throw new Exception("Core class not found: {$name}");
    }

    require_once $coreFile;
    $className = "og" . ucfirst($name);

    if (!class_exists($className)) {
      throw new Exception("Core class not found: {$className}");
    }

    $this->loaded[$key] = new $className();
    return $this->loaded[$key];
  }

  // Cargar core class bajo demanda (sin instanciar)
  function loadCore($name) {
    $coreFile = OG_FRAMEWORK_PATH . "/core/og" . ucfirst($name) . ".php";

    if (!file_exists($coreFile)) {
      throw new Exception("Core class not found: {$name}");
    }

    require_once $coreFile;
    $className = "og" . ucfirst($name);

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

    // 1. Buscar en framework/controllers/og{Name}Controller.php
    $fwClassName = "og" . ucfirst($name) . "Controller";
    $fwFile = OG_FRAMEWORK_PATH . "/controllers/og" . ucfirst($name) . "Controller.php";
    if (file_exists($fwFile)) {
      require_once $fwFile;
      if (!class_exists($fwClassName)) {
        throw new Exception("Controller class not found: {$fwClassName}");
      }
      $this->loaded[$key] = new $fwClassName();
      return $this->loaded[$key];
    }

    // 2. Buscar en app/resources/controllers/{Name}Controller.php
    $appClassName = ucfirst($name) . "Controller";
    $appFile = $this->pluginPath . "/resources/controllers/" . $appClassName . ".php";
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
    $uname = ucfirst($name) . "Controller";
    $controllerFile = OG_FRAMEWORK_PATH . "/controllers/{$uname}.php";
    if (!file_exists($controllerFile)) {
      $controllerFile = $this->pluginPath . "/resources/controllers/{$uname}.php";
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

    // 1. Buscar en framework/core/og{Name}Handler.php
    $fwClassName = "og" . ucfirst($name) . "Handler";
    $fwFile = OG_FRAMEWORK_PATH . "/core/og" . ucfirst($name) . "Handler.php";
    if (file_exists($fwFile)) {
      require_once $fwFile;
      if (!class_exists($fwClassName)) {
        ogLog::throwError("handler - Handler class not found: {$fwClassName}", [$name], $this->logMeta);
      }
      $this->loaded[$key] = new $fwClassName();
      return $this->loaded[$key];
    }

    // 2. Buscar en app/resources/handlers/{Name}Handler.php
    $appClassName = ucfirst($name) . "Handler";
    $appFile = $this->pluginPath . "/resources/handlers/" . $appClassName . ".php";
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
    $uname = ucfirst($name) . "Handler";
    $handlerFile = OG_FRAMEWORK_PATH . "/handlers/{$uname}.php";
    if (!file_exists($handlerFile)) {
      $handlerFile = $this->pluginPath . "/resources/handlers/{$uname}.php";
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

  // Obtener path del plugin
  function getPath($subPath = '') {
    return $this->pluginPath . ($subPath ? '/' . ltrim($subPath, '/') : '');
  }

  // Obtener nombre del plugin
  function getName() {
    return $this->pluginName;
  }

  // Verificar si es WordPress
  function isWordPress() {
    return $this->isWordPress;
  }

  // Verificar si un componente está cargado
  function isLoaded($type, $name) {
    $key = "{$type}_{$name}";
    return isset($this->loaded[$key]);
  }

  // Obtener lista de componentes cargados
  function getLoaded() {
    return array_keys($this->loaded);
  }

  // Limpiar cache de un componente
  function unload($type, $name) {
    $key = "{$type}_{$name}";
    if (isset($this->loaded[$key])) {
      unset($this->loaded[$key]);
      return true;
    }
    return false;
  }

  // Obtener instancia de ogApplication para ejecutar el framework
  function getApplication() {
    if (!class_exists('ogApplication')) {
      require_once OG_FRAMEWORK_PATH . '/core/ogApplication.php';
    }
    return new ogApplication();
  }

  // Obtener todas las instancias registradas (útil para debug)
  static function getAllInstances() {
    return self::$instances;
  }

  // Prevenir clonación
  private function __clone() {}

  // Prevenir unserialize
  public function __wakeup() {
    throw new Exception("Cannot unserialize singleton");
  }
}

// Alias global para acceso más corto
function ogApp($pluginName = 'default', $pluginPath = null, $isWordPress = false) {
  return ogFramework::instance($pluginName, $pluginPath, $isWordPress);
}