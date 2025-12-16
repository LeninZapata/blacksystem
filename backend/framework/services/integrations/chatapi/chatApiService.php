<?php
class chatApiService {

  private static $config = null;
  private static $botData = null;
  private static $providers = [];

  static function setConfig(array $botData) {
    self::$botData = $botData;
    self::$config = $botData['config']['apis']['chat'] ?? [];
    self::$providers = [];
  }

  static function send(string $to, string $message, string $media = ''): array {
    if (!self::$config) throw new Exception('chatApiService no configurado. Llama a setConfig() primero');

    $lastError = null;
    foreach (self::$config as $index => $apiConfig) {
      try {
        $provider = self::getProvider($apiConfig);
        $response = $provider->sendMessage($to, $message, $media);
        
        if ($response['success']) {
          $response['used_fallback'] = $index > 0;
          $response['attempt'] = $index + 1;
          return $response;
        }
        
        $lastError = $response['error'] ?? 'Unknown error';
      } catch (Exception $e) {
        $lastError = $e->getMessage();
        if ($index < count(self::$config) - 1) continue;
      }
    }

    log::error('chatApiService::send - Todos los proveedores fallaron', ['error' => $lastError], ['module' => 'chatapi']);
    return ['success' => false, 'error' => $lastError, 'all_providers_failed' => true];
  }

  static function sendPresence(string $to, string $presenceType, int $delay = 1200): array {
    if (!self::$config) return ['success' => true, 'silent' => true];

    foreach (self::$config as $apiConfig) {
      try {
        $provider = self::getProvider($apiConfig);
        $response = $provider->sendPresence($to, $presenceType, $delay);
        if ($response['success']) return $response;
      } catch (Exception $e) {
        continue;
      }
    }

    return ['success' => true, 'silent' => true];
  }

  static function sendArchive(string $chatNumber, string $lastMessageId = 'archive', bool $archive = true): array {
    if (!self::$config) throw new Exception('chatApiService no configurado');

    $results = [];
    $successCount = 0;

    foreach (self::$config as $apiConfig) {
      try {
        $provider = self::getProvider($apiConfig);
        $response = $provider->sendArchive($chatNumber, $lastMessageId, $archive);
        $results[] = $response;
        if ($response['success']) $successCount++;
      } catch (Exception $e) {
        $results[] = ['success' => false, 'error' => $e->getMessage()];
      }
    }

    return [
      'success' => $successCount > 0,
      'successful_providers' => $successCount,
      'total_providers' => count(self::$config),
      'results' => $results
    ];
  }

  private static function getProvider(array $apiConfig) {
    $type = $apiConfig['config']['type_value'] ?? null;
    if (!$type) throw new Exception('Tipo de API no especificado');

    $cacheKey = md5(json_encode($apiConfig));
    if (isset(self::$providers[$cacheKey])) return self::$providers[$cacheKey];

    $config = [
      'api_key' => $apiConfig['config']['credential_value'] ?? '',
      'instance' => $apiConfig['config']['instance'] ?? '',
      'base_url' => $apiConfig['config']['base_url'] ?? ''
    ];

    $provider = match($type) {
      'evolutionapi' => new evolutionProvider($config),
      'testing' => new testingProvider($config),
      default => throw new Exception("Proveedor '{$type}' no soportado")
    };

    self::$providers[$cacheKey] = $provider;
    return $provider;
  }
}