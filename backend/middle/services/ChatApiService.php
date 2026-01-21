<?php
class chatApiService {
  private static $config = null;
  private static $botData = null;
  private static $provider = null;
  private static $providers = [];
  private static $logMeta = ['module' => 'ogChatApi', 'layer' => 'middle/framework'];

  static function setConfig(array $botData, string $provider = null) {
    self::$botData = $botData;
    self::$config = $botData['config']['apis']['chat'] ?? [];
    self::$provider = $provider;
    self::$providers = [];
  }

  // Método para filtrar config por provider específico
  static function setProvider(string $providerType) {
    if (!self::$config) {
      ogLog::warning('setProvider - No hay config cargada', ['provider_type' => $providerType], self::$logMeta);
      return;
    }

    $filtered = array_filter(self::$config, function($api) use ($providerType) {
      return ($api['config']['type_value'] ?? '') === $providerType;
    });

    self::$config = array_values($filtered);
    self::$provider = $providerType;

    ogLog::info('setProvider - Provider configurado', [
      'provider' => $providerType,
      'total_apis' => count(self::$config)
    ], self::$logMeta);
  }

  static function getProvider() {
    return self::$provider;
  }

  static function getBotConfig() {
    return self::$botData;
  }

  // Detectar provider desde webhook y normalizar
  static function detectAndNormalize($rawData) {
    // Extraer primer elemento si viene como array
    $data = is_array($rawData) && isset($rawData[0]) ? $rawData[0] : $rawData;
    ogLog::info('webhook desde evolution', $data, self::$logMeta);

    // Detectar provider basado en estructura del webhook
    $provider = null;

    // Evolution API - Formato directo (producción)
    if (isset($data['event']) && isset($data['instance']) && isset($data['sender'])) {
      $provider = 'evolutionapi';
    }
    // Evolution API - Formato envuelto en body (desarrollo/n8n)
    elseif (isset($data['body']['event']) && isset($data['body']['instance'])) {
      $provider = 'evolutionapi';
    }
    // Facebook/WhatsApp Cloud API
    elseif (isset($data['object']) && $data['object'] === 'whatsapp_business_account') {
      $provider = 'whatsapp-cloud-api';
    }

    if (!$provider) {
      ogLog::warning('detectAndNormalize - Provider no detectado', ['data_keys' => array_keys($data)], self::$logMeta);
      return null;
    }

    ogLog::info('detectAndNormalize - Provider detectado', ['provider' => $provider], self::$logMeta);

    // Normalizar según provider
    $normalized = self::normalize($provider, $rawData);
    $standard = self::standardize($provider, $normalized);

    return [
      'provider' => $provider,
      'normalized' => $normalized,
      'standard' => $standard
    ];
  }

  // Normalizar webhook según provider
  private static function normalize($provider, $rawData) {
    $middlePath = ogApp()->helper('cache')::memoryGet('path_middle');
    $basePath = "{$middlePath}/services/integrations/chatApi";

    switch ($provider) {
      case 'evolutionapi':
        $normalizerPath = "{$basePath}/evolution/evolutionNormalizer.php";
        if (!file_exists($normalizerPath)) {
          ogLog::throwError('Normalizer no encontrado: ' . $normalizerPath, [], self::$logMeta);
        }
        require_once $normalizerPath;
        return evolutionNormalizer::normalize($rawData);

      case 'whatsapp-cloud-api':
        $normalizerPath = "{$basePath}/facebook/facebookNormalizer.php";
        if (!file_exists($normalizerPath)) {
          ogLog::throwError('Normalizer no encontrado: ' . $normalizerPath, [], self::$logMeta);
        }
        require_once $normalizerPath;
        return facebookNormalizer::normalize($rawData);

      default:
        ogLog::throwError('Provider no soportado para normalización: ' . $provider, [], self::$logMeta);
    }
  }

  // Estandarizar datos normalizados
  private static function standardize($provider, $normalized) {
    $middlePath = ogApp()->helper('cache')::memoryGet('path_middle');
    $basePath = "{$middlePath}/services/integrations/chatApi";

    switch ($provider) {
      case 'evolutionapi':
        $normalizerPath = "{$basePath}/evolution/evolutionNormalizer.php";
        if (!class_exists('evolutionNormalizer')) {
          require_once $normalizerPath;
        }
        return evolutionNormalizer::standardize($normalized);

      case 'whatsapp-cloud-api':
        $normalizerPath = "{$basePath}/facebook/facebookNormalizer.php";
        if (!class_exists('facebookNormalizer')) {
          require_once $normalizerPath;
        }
        return facebookNormalizer::standardize($normalized);

      default:
        ogLog::throwError('Provider no soportado para estandarización: ' . $provider, [], self::$logMeta);
    }
  }

  static function send(string $to, string $message, string $media = ''): array {
    if (!self::$config) ogLog::throwError(__('services.ogChatApi.not_configured'), [], self::$logMeta);

    $lastError = null;
    foreach (self::$config as $index => $apiConfig) {
      try {
        $provider = self::getProviderInstance($apiConfig);
        $response = $provider->sendMessage($to, ogApp()->helper('bsStr')::decodeMessagePatterns($message), $media);

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
      'api_key'  => $apiConfig['config']['credential_value'] ?? $apiConfig['config']['access_token'] ?? '',
      'instance' => $apiConfig['config']['instance'] ?? '',
      'base_url' => $apiConfig['config']['base_url'] ?? '',
      // Campos específicos de Facebook
      /*'phone_number_id' => $apiConfig['config']['phone_number_id'] ?? '',
      'business_account_id' => $apiConfig['config']['business_account_id'] ?? '',
      'access_token' => $apiConfig['config']['access_token'] ?? ''*/
    ];

    ogLog::info('getProviderInstance - Config extraído', ['type' => $type, 'config' => $config, 'apiConfig' => $apiConfig], self::$logMeta);

    // Cargar provider bajo demanda según el tipo
    $provider = self::loadProvider($type, $config);
    
    ogLog::info('getProviderInstance - Provider cargado', ['provider_type' => $type, 'provider_class' => get_class($provider)], self::$logMeta);

    self::$providers[$cacheKey] = $provider;
    return $provider;
  }

  private static function loadProvider(string $type, array $config) {
    $middlePath = ogApp()->helper('cache')::memoryGet('path_middle');
    $basePath = "{$middlePath}/services/integrations/chatApi";

    // ✅ Mapeo de tipos a providers (agregado Facebook)
    $providerMap = [
      'evolutionapi' => [
        'class' => 'evolutionProvider',
        'folder' => 'evolution'
      ],
      'whatsapp-cloud-api' => [
        'class' => 'facebookProvider',
        'folder' => 'facebook'
      ],
      'testing' => [
        'class' => 'testingProvider',
        'folder' => 'testing'
      ]
    ];

    if (!isset($providerMap[$type])) {
      ogLog::throwError( 'loadProvider - ' . __('services.ogChatApi.provider_not_supported', ['provider' => $type]), [], self::$logMeta);
    }

    $providerInfo = $providerMap[$type];
    $providerClass = $providerInfo['class'];
    $providerFolder = $providerInfo['folder'];
    $providerPath = "{$basePath}/{$providerFolder}/{$providerClass}.php";

    // Validar y cargar archivos bajo demanda
    // Cargar interface
    if (!interface_exists('chatApiProviderInterface')) {
      $interfacePath = "{$basePath}/chatApiProviderInterface.php";
      if (!file_exists($interfacePath)) {
        ogLog::throwError( 'loadProvider - ' . __('services.ogChatApi.interface_file_not_found', ['path' => $interfacePath]), [], self::$logMeta);
      }
      require_once $interfacePath;
    }

    // Cargar base
    if (!class_exists('baseChatApiProvider')) {
      $baseClassPath = "{$basePath}/baseChatApiProvider.php";
      if (!file_exists($baseClassPath)) {
        ogLog::throwError( 'loadProvider - ' . __('services.ogChatApi.base_class_file_not_found', ['path' => $baseClassPath]), [], self::$logMeta);
      }
      require_once $baseClassPath;
    }

    // Cargar provider específico
    if (!file_exists($providerPath)) {
      ogLog::throwError( 'loadProvider - ' . __('services.ogChatApi.provider_file_not_found', ['path' => $providerPath]), [], self::$logMeta);
    }
    require_once $providerPath;

    // Verificar que la clase existe después de cargar
    if (!class_exists($providerClass)) {
      ogLog::throwError( 'loadProvider - ' . __('services.ogChatApi.provider_class_not_found', ['class' => $providerClass]), [], self::$logMeta);
    }

    ogLog::info('loadProvider - Creando instancia del provider', ['class' => $providerClass, 'config_keys' => array_keys($config)], self::$logMeta);

    $instance = new $providerClass($config);
    
    ogLog::info('loadProvider - Instancia creada', ['class' => $providerClass, 'instance_class' => get_class($instance)], self::$logMeta);

    return $instance;
  }
}