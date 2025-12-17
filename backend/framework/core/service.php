<?php
// Helper para acceder a servicios de integración
class service {

  // Método principal - SIEMPRE semántico
  // Uso: service::integration('chatapi', 'detect', $rawData)
  // Uso: service::integration('chatapi', 'normalize', $data)
  static function integration($category, $method, ...$args) {
    
    // Validar parámetros
    if (!is_string($method) || empty($method)) {
      throw new Exception("service::integration - El segundo parámetro debe ser el nombre del método (ej: 'detect', 'normalize')");
    }
    
    // Método especial: 'detect' - Detecta provider y retorna serviceHelper
    if ($method === 'detect') {
      $rawData = $args[0] ?? null;
      if (!$rawData) {
        throw new Exception("service::integration - Se requieren datos para detectar provider");
      }
      
      $provider = self::detectProvider($category, $rawData);
      
      if (!$provider) {
        throw new Exception("service::integration - No se pudo detectar el provider para categoría '{$category}'");
      }
      
      // Retornar helper con provider detectado
      return new serviceHelper($category, $provider, $rawData);
    }
    
    // Otros métodos: llamar directamente
    return self::callMethod($category, $method, $args);
  }

  // Llamar método directamente en un servicio
  private static function callMethod($category, $method, $args) {
    // TODO: Implementar para otros métodos si se necesita
    throw new Exception("service::integration - Método '{$method}' no implementado. Usa 'detect' o la clase directa del servicio.");
  }

  // Detectar provider según los datos
  private static function detectProvider($category, $rawData) {
    $basePath = SERVICES_PATH . "/integrations/{$category}";

    if (!is_dir($basePath)) {
      return null;
    }

    // Buscar todos los providers en la categoría
    $providers = array_diff(scandir($basePath), ['.', '..']);

    foreach ($providers as $provider) {
      $providerPath = $basePath . '/' . $provider;

      if (!is_dir($providerPath)) continue;

      // Intentar cargar el Normalizer del provider
      $normalizerFile = $providerPath . '/' . strtolower($provider) . 'Normalizer.php';

      if (file_exists($normalizerFile)) {
        require_once $normalizerFile;

        $normalizerClass = $provider . 'Normalizer';

        // Si tiene método detect y retorna true, es este provider
        if (class_exists($normalizerClass) && method_exists($normalizerClass, 'detect')) {
          if ($normalizerClass::detect($rawData)) {
            return $provider;
          }
        }
      }
    }

    return null;
  }
}

// Helper class para métodos encadenados
class serviceHelper {
  private $category;
  private $provider;
  private $rawData;
  private $normalizedData = null;

  function __construct($category, $provider, $rawData) {
    $this->category = $category;
    $this->provider = $provider;
    $this->rawData = $rawData;
  }

  // Normalizar datos
  function normalize() {
    if ($this->normalizedData !== null) {
      return $this->normalizedData;
    }

    $this->normalizedData = $this->callMethod('normalize', $this->rawData);
    return $this->normalizedData;
  }

  // Extraer sender
  function extractSender() {
    $normalized = $this->normalize();
    return $this->callMethod('extractSender', $normalized);
  }

  // Extraer mensaje
  function extractMessage() {
    $normalized = $this->normalize();
    return $this->callMethod('extractMessage', $normalized);
  }

  // Extraer contexto
  function extractContext() {
    $normalized = $this->normalize();
    return $this->callMethod('extractContext', $normalized);
  }

  // Obtener provider detectado
  function getProvider() {
    return $this->provider;
  }

  // Obtener datos normalizados (alias de normalize)
  function getData() {
    return $this->normalize();
  }

  // Llamar método en el Normalizer del provider
  private function callMethod($method, ...$args) {
    $basePath = SERVICES_PATH . "/integrations/{$this->category}/{$this->provider}";

    // Determinar tipo de clase según método
    $classType = $this->getClassType($method);
    $className = $this->provider . $classType;
    $classFile = $basePath . '/' . strtolower($this->provider) . $classType . '.php';

    if (!file_exists($classFile)) {
      throw new Exception("service::integration - Archivo no encontrado: {$classFile}");
    }

    require_once $classFile;

    if (!class_exists($className)) {
      throw new Exception("service::integration - Clase no encontrada: {$className}");
    }

    if (!method_exists($className, $method)) {
      throw new Exception("service::integration - Método '{$method}' no existe en {$className}");
    }

    return call_user_func_array([$className, $method], $args);
  }

  // Determinar tipo de clase según método
  private function getClassType($method) {
    $normalizerMethods = ['normalize', 'detect', 'extractSender', 'extractMessage', 'extractContext'];
    $validatorMethods = ['validate'];
    $providerMethods = ['send', 'sendMessage', 'sendPresence', 'sendArchive'];

    if (in_array($method, $normalizerMethods)) {
      return 'Normalizer';
    }

    if (in_array($method, $validatorMethods)) {
      return 'Validator';
    }

    if (in_array($method, $providerMethods)) {
      return 'Provider';
    }

    return 'Provider'; // Default
  }
}