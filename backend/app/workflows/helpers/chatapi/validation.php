<?php
// workflows/helpers/chatapi/validation.php
// Helper para validaciones de entrada del usuario

class workflowValidation {
  
  /**
   * Validar si el texto es una opción válida
   */
  static function isValidOption($text, array $validOptions) {
    return in_array($text, $validOptions);
  }
  
  /**
   * Validar si es un comando
   */
  static function isCommand($text) {
    $commands = ['menu', 'catalogo', 'pedidos', 'soporte', 'ayuda', 'hola', 'start'];
    return in_array(strtolower($text), $commands);
  }
  
  /**
   * Validar si es una confirmación (si/no)
   */
  static function isConfirmation($text) {
    $text = strtolower(trim($text));
    return in_array($text, ['si', 'sí', 'yes', 'ok']);
  }
  
  /**
   * Validar si es una negación
   */
  static function isDenial($text) {
    $text = strtolower(trim($text));
    return in_array($text, ['no', 'nop', 'nope', 'cancelar']);
  }
  
  /**
   * Validar si es un número de teléfono válido
   */
  static function isValidPhone($text) {
    // Remover espacios y caracteres especiales
    $cleaned = preg_replace('/[^0-9]/', '', $text);
    
    // Debe tener entre 10 y 15 dígitos
    return strlen($cleaned) >= 10 && strlen($cleaned) <= 15;
  }
  
  /**
   * Validar si es un email válido
   */
  static function isValidEmail($text) {
    return filter_var($text, FILTER_VALIDATE_EMAIL) !== false;
  }
  
  /**
   * Validar si es un número válido
   */
  static function isNumber($text) {
    return is_numeric($text);
  }
  
  /**
   * Validar si es un número entero positivo
   */
  static function isPositiveInteger($text) {
    return ctype_digit($text) && (int)$text > 0;
  }
  
  /**
   * Validar longitud mínima
   */
  static function minLength($text, $min) {
    return mb_strlen($text) >= $min;
  }
  
  /**
   * Validar longitud máxima
   */
  static function maxLength($text, $max) {
    return mb_strlen($text) <= $max;
  }
  
  /**
   * Extraer número de un texto (ej: "opción 1" -> 1)
   */
  static function extractNumber($text) {
    preg_match('/\d+/', $text, $matches);
    return $matches[0] ?? null;
  }
  
  /**
   * Validar si contiene una palabra clave
   */
  static function contains($text, $keyword) {
    return stripos($text, $keyword) !== false;
  }
  
  /**
   * Limpiar texto (remover emojis, espacios extras)
   */
  static function clean($text) {
    // Remover emojis
    $text = preg_replace('/[\x{1F600}-\x{1F64F}]/u', '', $text);
    $text = preg_replace('/[\x{1F300}-\x{1F5FF}]/u', '', $text);
    $text = preg_replace('/[\x{1F680}-\x{1F6FF}]/u', '', $text);
    $text = preg_replace('/[\x{2600}-\x{26FF}]/u', '', $text);
    $text = preg_replace('/[\x{2700}-\x{27BF}]/u', '', $text);
    
    // Remover espacios extras
    $text = preg_replace('/\s+/', ' ', $text);
    
    return trim($text);
  }
}