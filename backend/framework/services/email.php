<?php
// Email Service - Orquestador
class email {
  private static $instances = [];
  private static $defaultProvider = 'plusemail';

  static function provider($name = null) {
    $name = $name ?? self::$defaultProvider;
    
    if (!isset(self::$instances[$name])) {
      $class = ucfirst($name);
      $file = __DIR__ . "/integrations/email/{$name}/{$name}.php";
      
      if (!file_exists($file)) {
        throw new Exception("Email provider '{$name}' not found");
      }
      
      require_once $file;
      self::$instances[$name] = new $class();
    }
    
    return self::$instances[$name];
  }

  static function send($to, $subject, $body, $opts = []) {
    return self::provider()->send($to, $subject, $body, $opts);
  }

  static function setDefault($provider) {
    self::$defaultProvider = $provider;
  }
}