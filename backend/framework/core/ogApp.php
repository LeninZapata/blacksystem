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

  // Cargar helper bajo demanda
  function helper($name) {
    $key = "helper_{$name}";

    if (isset($this->loaded[$key])) {
      return $this->loaded[$key];
    }

    // Buscar en framework primero, luego en app
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

  // Cargar service bajo demanda
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

  // Cargar core class bajo demanda
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

  // Cargar controller bajo demanda (sin instanciar)
  function loadController($name) {
    // Buscar en framework primero, luego en app
    $controllerFile = OG_FRAMEWORK_PATH . "/controllers/{$name}.php";
    if (!file_exists($controllerFile)) {
      $controllerFile = $this->pluginPath . "/resources/controllers/{$name}.php";
    }

    if (!file_exists($controllerFile)) {
      throw new Exception("Controller not found: {$name}");
    }

    require_once $controllerFile;

    if (!class_exists($name)) {
      throw new Exception("Controller class not found: {$name}");
    }

    return true;
  }

  // Cargar handler bajo demanda (sin instanciar)
  function loadHandler($name) {
    // Buscar en framework primero, luego en app
    $handlerFile = OG_FRAMEWORK_PATH . "/handlers/{$name}.php";
    if (!file_exists($handlerFile)) {
      // ogLog::debug("loadHandler - Handler not found in framework, trying app: {$name}", [$name], $this->logMeta);
      $handlerFile = $this->pluginPath . "/resources/handlers/{$name}.php";
    }

    if (!file_exists($handlerFile)) {
      ogLog::throwError("loadHandler - Handler not found in app: {$name}", [$name], $this->logMeta);
    }

    require_once $handlerFile;

    if (!class_exists($name)) {
      ogLog::throwError("loadHandler - Handler class not found: {$name}", [$name], ['module' => 'ogFramework', 'layer' => 'core/framework']);
    }

    return true;
  }

  // Cargar middleware bajo demanda (sin instanciar)
  function loadMiddleware($name) {
    // Buscar en framework primero, luego en app
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