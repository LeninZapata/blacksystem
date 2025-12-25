<?php
class service {
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
      log::throwError(__('core.service.not_found', ['category' => $category]), [], self::$logMeta);
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

      // Autoload maneja la carga
      if (class_exists($normalizerClass) && method_exists($normalizerClass, 'detect')) {
        if ($normalizerClass::detect($rawData)) return $provider;
      }
    }

    return null;
  }

  // Llamar método estático en clase de un provider
  static function call($category, $provider, $className, $method, ...$args) {
    $class = $provider . $className;

    if (!class_exists($class)) {
      log::throwError(__('core.service.class_not_found', ['class' => $class]), [], self::$logMeta);
    }

    if (!method_exists($class, $method)) {
      log::throwError(__('core.service.method_not_found', ['class' => $class, 'method' => $method]), [], self::$logMeta);
    }

    return call_user_func_array([$class, $method], $args);
  }
}