<?php
class ogService {
  private static $instances = [];
  private static $logMeta = ['module' => 'service', 'layer' => 'framework'];

  // Método principal - Envoltura optimizada para acceso rápido a servicios
  static function integration($category) {
    // Cache de instancias
    if (isset(self::$instances[$category])) {
      return self::$instances[$category];
    }

    // Cargar servicio directamente (sin pasar por autoload completo)
    $serviceFile = SERVICES_PATH . "/{$category}.php";

    if (!file_exists($serviceFile)) {
      ogLog::throwError(__('core.service.not_found', ['category' => $category]), [], self::$logMeta);
    }

    require_once $serviceFile;

    // Crear instancia y cachear
    self::$instances[$category] = new $category();
    return self::$instances[$category];
  }

  // Detectar provider de una categoría (solo si tiene Normalizer con método detect)
  static function detect($category, $rawData) {
    $basePath = SERVICES_PATH . "/integrations/{$category}";
    if (!is_dir($basePath)) return null;

    $providers = array_diff(scandir($basePath), ['.', '..']);

    foreach ($providers as $provider) {
      $providerPath = $basePath . '/' . $provider;
      if (!is_dir($providerPath)) continue;

      $normalizerClass = $provider . 'Normalizer';
      $normalizerFile = $providerPath . '/' . $normalizerClass . '.php';

      // Cargar clase bajo demanda si existe el archivo
      if (file_exists($normalizerFile)) {
        require_once $normalizerFile;
        
        // Ahora sí verificar si existe y tiene el método detect
        if (class_exists($normalizerClass) && method_exists($normalizerClass, 'detect')) {
          if ($normalizerClass::detect($rawData)) return $provider;
        }
      }
    }

    return null;
  }

  // Llamar método estático en clase de un provider
  static function call($category, $provider, $className, $method, ...$args) {
    $class = $provider . $className;

    // Cargar clase bajo demanda si no existe
    if (!class_exists($class)) {
      $basePath = SERVICES_PATH . "/integrations/{$category}/{$provider}";
      $classFile = $basePath . '/' . $class . '.php';
      
      if (file_exists($classFile)) {
        require_once $classFile;
      }
    }

    if (!class_exists($class)) {
      ogLog::throwError(__('core.service.class_not_found', ['class' => $class]), [], self::$logMeta);
    }

    if (!method_exists($class, $method)) {
      ogLog::throwError(__('core.service.method_not_found', ['class' => $class, 'method' => $method]), [], self::$logMeta);
    }

    return call_user_func_array([$class, $method], $args);
  }
}