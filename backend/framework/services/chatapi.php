<?php
class chatapi {

  private static $config = null;
  private static $botData = null;
  private static $provider = null;  // Provider detectado
  private static $providers = [];   // Cache de instancias de providers

  // Configurar servicio con provider opcional
  static function setConfig(array $botData, string $provider = null) {
    self::$botData = $botData;
    self::$config = $botData['config']['apis']['chat'] ?? [];
    self::$provider = $provider; // Guardar provider si se especifica
    self::$providers = []; // Limpiar cache
  }

  // Obtener provider configurado
  static function getProvider() {
    return self::$provider;
  }

  static function send(string $to, string $message, string $media = ''): array {
    if (!self::$config) throw new Exception(__('core.service.not_configured', ['service' => self::class]));

    $lastError = null;
    foreach (self::$config as $index => $apiConfig) {
      try {
        $provider = self::getProviderInstance($apiConfig);
        $response = $provider->sendMessage($to, $message, $media);

        if ($response['success']) {
          $response['used_fallback'] = $index > 0;
          $response['attempt'] = $index + 1;
          $response['provider'] = self::$provider; // Incluir provider usado
          return $response;
        }

        $lastError = $response['error'] ?? 'Unknown error';
      } catch (Exception $e) {
        $lastError = $e->getMessage();
        if ($index < count(self::$config) - 1) continue;
      }
    }

    log::error('chatapi::send - Todos los proveedores fallaron', ['error' => $lastError], ['module' => 'chatapi']);
    return ['success' => false, 'error' => $lastError, 'all_providers_failed' => true];
  }

  static function sendPresence(string $to, string $presenceType, int $delay = 1200): array {
    if (!self::$config) return ['success' => true, 'silent' => true];

    foreach (self::$config as $apiConfig) {
      try {
        $provider = self::getProviderInstance($apiConfig);
        $response = $provider->sendPresence($to, $presenceType, $delay);
        if ($response['success']) return $response;
      } catch (Exception $e) {
        continue;
      }
    }

    return ['success' => true, 'silent' => true];
  }

  static function sendArchive(string $chatNumber, string $lastMessageId = 'archive', bool $archive = true): array {
    if (!self::$config) throw new Exception(__('core.service.not_configured_short', ['service' => self::class]));

    $results = [];
    $successCount = 0;

    foreach (self::$config as $apiConfig) {
      try {
        $provider = self::getProviderInstance($apiConfig);
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

  private static function getProviderInstance(array $apiConfig) {
    $type = $apiConfig['config']['type_value'] ?? null;
    if (!$type) throw new Exception(__('core.service.api_type_not_specified'));

    $cacheKey = md5(json_encode($apiConfig));
    if (isset(self::$providers[$cacheKey])) return self::$providers[$cacheKey];

    $config = [
      'api_key'  => $apiConfig['config']['credential_value'] ?? '',
      'instance' => $apiConfig['config']['instance'] ?? '',
      'base_url' => $apiConfig['config']['base_url'] ?? ''
    ];

    $provider = match($type) {
      'evolutionapi' => new evolutionProvider($config),
      'testing' => new testingProvider($config),
      default => throw new Exception(__('core.service.provider_not_supported', ['provider' => $type]))
    };

    self::$providers[$cacheKey] = $provider;
    return $provider;
  }
}