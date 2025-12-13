<?php
// AI Service - Orquestador
class ai {
  private static $instances = [];
  private static $defaultProvider = 'deepseek';

  static function provider($name = null) {
    $name = $name ?? self::$defaultProvider;
    
    if (!isset(self::$instances[$name])) {
      $class = ucfirst($name);
      $file = __DIR__ . "/integrations/ai/{$name}/{$name}.php";
      
      if (!file_exists($file)) {
        throw new Exception("AI provider '{$name}' not found");
      }
      
      require_once $file;
      self::$instances[$name] = new $class();
    }
    
    return self::$instances[$name];
  }

  static function chat($messages, $opts = []) {
    return self::provider()->chat($messages, $opts);
  }

  static function complete($prompt, $opts = []) {
    return self::provider()->complete($prompt, $opts);
  }

  static function setDefault($provider) {
    self::$defaultProvider = $provider;
  }
}