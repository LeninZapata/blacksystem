<?php
// ogFramework - Singleton principal del framework
class ogFramework {
  private static $instances = [];
  private $pluginName;
  private $pluginPath;
  private $isWordPress;
  private $prefix;
  private $logMeta = ['module' => 'ogFramework', 'layer' => 'core/framework'];
  public $loaded = [];

  private function __construct($pluginName = 'default', $pluginPath = null, $isWP = false, $prefix = null) {
    $this->pluginName = $pluginName;

    if (!$pluginPath) {
      throw new Exception("Plugin path is required for ogApp initialization");
    }

    $this->pluginPath = $pluginPath;
    $this->isWordPress = $isWP;
    $this->prefix = $prefix;
  }

  static function instance($pluginName = 'default', $pluginPath = null, $isWP = false, $prefix = null) {
    if (!isset(self::$instances[$pluginName])) {
      self::$instances[$pluginName] = new self($pluginName, $pluginPath, $isWP, $prefix);
    }
    return self::$instances[$pluginName];
  }

  /**
   * Método central de búsqueda de archivos
   */
  private function findResource($name, $type, $suffix = '', $subFolder = '') {
    $parts = explode('/', $name);
    $fileName = array_pop($parts);
    $subPath = !empty($parts) ? implode('/', $parts) . '/' : '';

    // Determinar orden de búsqueda según WordPress
    $searchOrder = $this->isWordPress && $this->prefix
      ? ['prefix', 'none', 'og']
      : ['none', 'prefix', 'og'];

    // Capas de búsqueda (rutas limpias sin ..)
    $middlePath = dirname(OG_FRAMEWORK_PATH) . '/middle';

    $layers = [
      'app' => $this->pluginPath . ($subFolder ? "/resources/{$subFolder}/" : "/{$type}s/"),
      'middle' => $middlePath . ($subFolder ? "/resources/{$subFolder}/" : "/{$type}s/"),
      'framework' => OG_FRAMEWORK_PATH . ($subFolder ? "/resources/{$subFolder}/" : "/{$type}s/")
    ];

    foreach ($layers as $layer => $basePath) {
      foreach ($searchOrder as $prefixType) {
        // Framework solo usa prefijo "og"
        if ($layer === 'framework' && $prefixType !== 'og') continue;

        // Construir nombre de clase
        $className = $this->buildClassName($fileName, $suffix, $prefixType);

        // Si buildClassName retorna null (prefijo vacío), saltar
        if ($className === null) continue;

        $file = $basePath . $subPath . $className . '.php';

        if (file_exists($file)) {
          return ['file' => $file, 'class' => $className];
        }
      }
    }

    return null;
  }

  /**
   * Construir nombre de clase según tipo de prefijo
   */
  private function buildClassName($name, $suffix, $prefixType) {
    $baseName = ucfirst($name);

    switch ($prefixType) {
      case 'prefix':
        // Si no hay prefijo configurado, retornar null para saltar
        if (!$this->prefix) return null;
        return $this->prefix . $baseName . $suffix;

      case 'og':
        return 'og' . $baseName . $suffix;

      case 'none':
      default:
        return $baseName . $suffix;
    }
  }

  // Cargar helper bajo demanda (con referencia)
  function helper($name) {
    $key = "helper_{$name}";
    if (isset($this->loaded[$key])) {
      return $this->loaded[$key];
    }

    $result = $this->findResource($name, 'helper');
    if (!$result) {
      throw new Exception("Helper not found: {$name}");
    }

    require_once $result['file'];
    if (!class_exists($result['class'])) {
      throw new Exception("Helper class not found: {$result['class']}");
    }

    $this->loaded[$key] = new $result['class']();
    return $this->loaded[$key];
  }

  // Cargar helper bajo demanda (sin instanciar)
  function loadHelper($name) {
    $result = $this->findResource($name, 'helper');
    if (!$result) {
      throw new Exception("Helper not found: {$name}");
    }

    require_once $result['file'];
    if (!class_exists($result['class'])) {
      throw new Exception("Helper class not found: {$result['class']}");
    }

    return true;
  }

  // Cargar service bajo demanda (con referencia)
  function service($name) {
    $key = "service_{$name}";
    if (isset($this->loaded[$key])) {
      return $this->loaded[$key];
    }

    $result = $this->findResource($name, 'service', 'Service');
    if (!$result) {
      throw new Exception("Service not found: {$name}");
    }

    require_once $result['file'];
    if (!class_exists($result['class'])) {
      throw new Exception("Service class not found: {$result['class']}");
    }

    $this->loaded[$key] = new $result['class']();
    return $this->loaded[$key];
  }

  // Cargar service bajo demanda (sin instanciar)
  function loadService($name) {
    $result = $this->findResource($name, 'service', 'Service');
    if (!$result) {
      throw new Exception("Service not found: {$name}");
    }

    require_once $result['file'];
    if (!class_exists($result['class'])) {
      throw new Exception("Service class not found: {$result['class']}");
    }

    return true;
  }

  // Cargar controller bajo demanda (con referencia)
  function controller($name) {
    $key = "controller_{$name}";
    if (isset($this->loaded[$key])) {
      return $this->loaded[$key];
    }

    $result = $this->findResource($name, 'controller', 'Controller', 'controllers');
    if (!$result) {
      throw new Exception("Controller not found: {$name}");
    }

    require_once $result['file'];
    if (!class_exists($result['class'])) {
      throw new Exception("Controller class not found: {$result['class']}");
    }

    $this->loaded[$key] = new $result['class']();
    return $this->loaded[$key];
  }

  // Cargar controller bajo demanda (sin instanciar)
  function loadController($name) {
    $result = $this->findResource($name, 'controller', 'Controller', 'controllers');
    if (!$result) {
      throw new Exception("Controller not found: {$name}");
    }

    require_once $result['file'];
    if (!class_exists($result['class'])) {
      throw new Exception("Controller class not found: {$result['class']}");
    }

    return true;
  }

  // Cargar handler bajo demanda (con referencia)
  function handler($name) {
    $key = "handler_{$name}";
    if (isset($this->loaded[$key])) {
      return $this->loaded[$key];
    }

    $result = $this->findResource($name, 'handler', 'Handler', 'handlers');
    if (!$result) {
      throw new Exception("Handler not found: {$name}");
    }

    require_once $result['file'];
    if (!class_exists($result['class'])) {
      throw new Exception("Handler class not found: {$result['class']}");
    }

    $this->loaded[$key] = new $result['class']();
    return $this->loaded[$key];
  }

  // Cargar handler bajo demanda (sin instanciar)
  function loadHandler($name) {
    $result = $this->findResource($name, 'handler', 'Handler', 'handlers');
    if (!$result) {
      throw new Exception("Handler not found: {$name}");
    }

    require_once $result['file'];
    if (!class_exists($result['class'])) {
      throw new Exception("Handler class not found: {$result['class']}");
    }

    return true;
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

    // Core SIEMPRE con prefijo "og" en framework
    $className = "og" . ucfirst($fileName);
    $coreFile = OG_FRAMEWORK_PATH . "/core/{$subPath}{$className}.php";

    if (!file_exists($coreFile)) {
      throw new Exception("Core class not found: {$name}");
    }

    require_once $coreFile;
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

    $className = "og" . ucfirst($fileName);
    $coreFile = OG_FRAMEWORK_PATH . "/core/{$subPath}{$className}.php";

    if (!file_exists($coreFile)) {
      throw new Exception("Core class not found: {$name}");
    }

    require_once $coreFile;
    if (!class_exists($className)) {
      throw new Exception("Core class not found: {$className}");
    }

    return true;
  }

  // Cargar middleware bajo demanda (sin instanciar)
  function loadMiddleware($name) {
    // Middleware no usa sistema de prefijos, busca directo
    $middlePath = dirname(OG_FRAMEWORK_PATH) . '/middle';

    $layers = [
      $this->pluginPath . "/middleware/{$name}.php",
      $middlePath . "/middleware/{$name}.php",
      OG_FRAMEWORK_PATH . "/middleware/{$name}.php"
    ];

    foreach ($layers as $mwFile) {
      if (file_exists($mwFile)) {
        require_once $mwFile;
        if (!class_exists($name)) {
          throw new Exception("Middleware class not found: {$name}");
        }
        return true;
      }
    }

    throw new Exception("Middleware not found: {$name}");
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
    // Rutas predefinidas
    $routes = [
      'base' => dirname(dirname($this->pluginPath)),
      'backend' => dirname($this->pluginPath),
      'app' => $this->pluginPath,
      'storage' => $this->pluginPath . '/storage',
      'logs' => $this->pluginPath . '/storage/logs',
      'cache' => $this->pluginPath . '/storage/cache',
      'sessions' => $this->pluginPath . '/storage/sessions',
      'shared' => dirname(dirname($this->pluginPath)) . '/shared',
    ];

    // Si subPath es una ruta predefinida, devolverla
    if (isset($routes[$subPath])) {
      return $routes[$subPath];
    }

    // Si no, concatenar con pluginPath
    return $this->pluginPath . ($subPath ? '/' . ltrim($subPath, '/') : '');
  }

  function getName() {
    return $this->pluginName;
  }

  function isWordPress() {
    return $this->isWordPress;
  }

  function getPrefix() {
    return $this->prefix;
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
function ogApp($pluginName = 'default', $pluginPath = null, $isWP = false, $prefix = null) {
  return ogFramework::instance($pluginName, $pluginPath, $isWP, $prefix);
}