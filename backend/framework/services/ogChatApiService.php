<?php
class ogChatApiService {
  private static $config = null;
  private static $botData = null;
  private static $provider = null;
  private static $providers = [];
  private static $logMeta = ['module' => 'ogChatApi', 'layer' => 'framework'];

  static function setConfig(array $botData, string $provider = null) {
    self::$botData = $botData;
    self::$config = $botData['config']['apis']['chat'] ?? [];
    self::$provider = $provider;
    self::$providers = [];
  }

  static function getProvider() {
    return self::$provider;
  }

  // Detectar provider desde webhook y normalizar
  static function detectAndNormalize($rawData) {
    $service = ogApp()->core('service');
    $provider = $service::detect('ogChatApi', $rawData);
    if (!$provider) return null;

    $normalized = $service::call('ogChatApi', $provider, 'Normalizer', 'normalize', $rawData);
    $standard = $service::call('ogChatApi', $provider, 'Normalizer', 'standardize', $normalized);

    return ['provider' => $provider, 'normalized' => $normalized, 'standard' => $standard];
  }

  static function send(string $to, string $message, string $media = ''): array {
    if (!self::$config) ogLog::throwError(__('services.ogChatApi.not_configured'), [], self::$logMeta);

    $lastError = null;
    foreach (self::$config as $index => $apiConfig) {
      try {
        $provider = self::getProviderInstance($apiConfig);
        $response = $provider->sendMessage($to, $message, $media);

        if ($response['success']) {
          $response['used_fallback'] = $index > 0;
          $response['attempt'] = $index + 1;
          $response['provider'] = self::$provider;
          return $response;
        }

        $lastError = $response['error'] ?? 'Unknown error';
      } catch (Exception $e) {
        $lastError = $e->getMessage();
        if ($index < count(self::$config) - 1) continue;
      }
    }

    ogLog::throwError(__('services.ogChatApi.all_providers_failed'), ['error' => $lastError, 'to' => $to], self::$logMeta);
  }

  static function sendPresence(string $to, string $presenceType, int $delay = 1200): array {
    if (!self::$config) return ['success' => true, 'silent' => true];

    foreach (self::$config as $apiConfig) {
      try {
        $provider = self::getProviderInstance($apiConfig);
        $response = $provider->sendPresence($to, $presenceType, $delay);
        if ($response['success']) return $response;
      } catch (Exception $e) {
        // Ignorar errores en presencia, solo log
        ogLog::warning('ogChatApi::sendPresence - Error enviando presencia', ['error' => $e->getMessage(), 'to' => $to, 'presence_type' => $presenceType], self::$logMeta);
        continue;
      }
    }

    return ['success' => true, 'silent' => true];
  }

  static function sendArchive(string $chatNumber, string $lastMessageId = 'archive', bool $archive = true): array {
    if (!self::$config) ogLog::throwError(__('services.ogChatApi.not_configured'), [], self::$logMeta);

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

    // Marcamos un warning si ningún provider tuvo éxito
    ogLog::warning('ogChatApi::sendArchive - Ningún provider tuvo éxito', ['chat_number' => $chatNumber, 'results' => $results], self::$logMeta);

    return [
      'success' => $successCount > 0,
      'successful_providers' => $successCount,
      'total_providers' => count(self::$config),
      'results' => $results
    ];
  }

  private static function getProviderInstance(array $apiConfig) {
    $type = $apiConfig['config']['type_value'] ?? null;
    if (!$type) ogLog::throwError(__('services.ogChatApi.api_type_required'), [], self::$logMeta);

    $cacheKey = md5(json_encode($apiConfig));
    if (isset(self::$providers[$cacheKey])) return self::$providers[$cacheKey];

    $config = [
      'api_key'  => $apiConfig['config']['credential_value'] ?? '',
      'instance' => $apiConfig['config']['instance'] ?? '',
      'base_url' => $apiConfig['config']['base_url'] ?? ''
    ];

    // Cargar provider bajo demanda según el tipo
    $provider = self::loadProvider($type, $config);

    self::$providers[$cacheKey] = $provider;
    return $provider;
  }

  private static function loadProvider(string $type, array $config) {
    // Mapeo de tipos a archivos de provider
    $providerMap = [
      'evolutionapi' => [
        'class' => 'evolutionProvider',
        'path' => OG_FRAMEWORK_PATH . '/services/integrations/ogChatApi/evolution/evolutionProvider.php'
      ],
      'testing' => [
        'class' => 'testingProvider',
        'path' => OG_FRAMEWORK_PATH . '/services/integrations/ogChatApi/testing/testingProvider.php'
      ]
    ];

    if (!isset($providerMap[$type])) {
      ogLog::throwError(__('services.ogChatApi.provider_not_supported', ['provider' => $type]), [], self::$logMeta);
    }

    $providerInfo = $providerMap[$type];
    $providerClass = $providerInfo['class'];
    $providerPath = $providerInfo['path'];

    // Cargar provider bajo demanda
    if (!class_exists($providerClass)) {
      // Cargar interface y base primero si no existen
      if (!interface_exists('chatApiProviderInterface')) {
        require_once OG_FRAMEWORK_PATH . '/services/integrations/ogChatApi/chatApiProviderInterface.php';
      }
      if (!class_exists('baseChatApiProvider')) {
        require_once OG_FRAMEWORK_PATH . '/services/integrations/ogChatApi/baseChatApiProvider.php';
      }

      // Cargar provider específico
      if (!file_exists($providerPath)) {
        ogLog::throwError(__('services.ogChatApi.provider_file_not_found', ['path' => $providerPath]), [], self::$logMeta);
      }

      require_once $providerPath;
    }

    if (!class_exists($providerClass)) {
      ogLog::throwError(__('services.ogChatApi.provider_class_not_found', ['class' => $providerClass]), [], self::$logMeta);
    }

    return new $providerClass($config);
  }
}