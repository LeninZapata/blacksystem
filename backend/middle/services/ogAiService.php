<?php
class ogAiService {

  private static $logMeta = ['module' => 'ai', 'layer' => 'framework/services'];

  // Chat completion con fallback automático
  public function getChatCompletion($prompt, $bot, $options = []) {
    try {
      $aiServices = $this->getServicesForTask($bot, 'conversation');
      if (empty($aiServices)) ogLog::throwError(__('services.ai.no_services_available'), [], self::$logMeta);

      $attemptNumber = 0;
      $lastError = null;

      foreach ($aiServices as $serviceConfig) {
        $attemptNumber++;
        try {
          $provider = $this->createProvider($serviceConfig);
          $response = $provider->chatCompletion($prompt, $options);

          if ($response['success']) {
            $response['attempt_number'] = $attemptNumber;
            $response['total_services_available'] = count($aiServices);
            $response['used_fallback'] = $attemptNumber > 1;
            return $response;
          }

        } catch (Exception $e) {
          $lastError = $e->getMessage();
          if ($attemptNumber < count($aiServices)) continue;
        }
      }

      ogLog::throwError( 'getChatCompletion - ' . __('services.ai.all_services_failed', ['error' => $lastError]), ['services_tried' => $attemptNumber], self::$logMeta);

    } catch (Exception $e) {
      ogLog::error('getChatCompletion - Error en chat completion', ['error' => $e->getMessage()], self::$logMeta);
      return ['success' => false, 'error' => $e->getMessage(), 'services_tried' => $attemptNumber ?? 0];
    }
  }

  // Transcribir audio con fallback
  public function transcribeAudio($audioUrl, $bot) {
    try {
      $aiServices = $this->getServicesForTask($bot, 'audio');
      if (empty($aiServices)) ogLog::throwError(__('services.ai.no_services_for_task', ['task' => 'audio']), [], self::$logMeta);

      $attemptNumber = 0;
      $lastError = null;

      foreach ($aiServices as $serviceConfig) {
        $attemptNumber++;
        try {
          $provider = $this->createProvider($serviceConfig);
          $result = $provider->transcribeAudio($audioUrl);
          ogLog::info("transcribeAudio - resultado de la transcripcion", $result, self::$logMeta);
          if ($result['success']) return $result;

        } catch (Exception $e) {
          $lastError = $e->getMessage();
          if ($attemptNumber < count($aiServices)) continue;
        }
      }

      ogLog::throwError(__('services.ai.all_services_failed', ['error' => $lastError]), ['services_tried' => $attemptNumber, 'task' => 'audio'], self::$logMeta);

    } catch (Exception $e) {
      ogLog::error('Error transcribiendo audio', ['error' => $e->getMessage()], self::$logMeta);
      return ['success' => false, 'error' => $e->getMessage()];
    }
  }

  // Analizar imagen con fallback
  public function analyzeImage($imageDataUri, $instruction, $bot) {
    try {
      $aiServices = $this->getServicesForTask($bot, 'image');
      if (empty($aiServices)) ogLog::throwError(__('services.ai.no_services_for_task', ['task' => 'image']), [], self::$logMeta);

      $attemptNumber = 0;
      $lastError = null;

      foreach ($aiServices as $serviceConfig) {
        $attemptNumber++;
        try {
          $provider = $this->createProvider($serviceConfig);
          $result = $provider->analyzeImage($imageDataUri, $instruction);
          if ($result['success']) return $result;

        } catch (Exception $e) {
          $lastError = $e->getMessage();
          if ($attemptNumber < count($aiServices)) continue;
        }
      }

      ogLog::throwError(__('services.ai.all_services_failed', ['error' => $lastError]), ['services_tried' => $attemptNumber, 'task' => 'image'], self::$logMeta);

    } catch (Exception $e) {
      ogLog::error('Error analizando imagen', ['error' => $e->getMessage()], self::$logMeta);
      return ['success' => false, 'error' => $e->getMessage()];
    }
  }

  // Crear instancia del proveedor con carga bajo demanda
  private function createProvider($serviceConfig) {
    $config = $serviceConfig['config'] ?? [];
    $slug = strtolower($config['type_value'] ?? '');

    // Mapeo de slugs a providers
    $providerMap = [
      'deepseek' => [
        'class' => 'deepSeekProvider',
        'path' => OG_FRAMEWORK_PATH . '/services/integrations/ogAi/deepseek/deepSeekProvider.php'
      ],
      'openai' => [
        'class' => 'openAiProvider',
        'path' => OG_FRAMEWORK_PATH . '/services/integrations/ogAi/openai/openAiProvider.php'
      ],
      'gpt' => [
        'class' => 'openAiProvider',
        'path' => OG_FRAMEWORK_PATH . '/services/integrations/ogAi/openai/openAiProvider.php'
      ]
    ];

    if (!isset($providerMap[$slug])) {
      ogLog::throwError('createProvider - ' . __('services.ai.provider_not_supported', ['provider' => $slug]), [], self::$logMeta);
    }

    $providerInfo = $providerMap[$slug];
    $providerClass = $providerInfo['class'];
    $providerPath = $providerInfo['path'];

    // Validar y cargar archivos bajo demanda
    // Cargar interface
    if (!interface_exists('aiProviderInterface')) {
      $interfacePath = OG_FRAMEWORK_PATH . '/services/integrations/ogAi/aiProviderInterface.php';
      if (!file_exists($interfacePath)) {
        ogLog::throwError('createProvider - Interface file not found: ' . $interfacePath, [], self::$logMeta);
      }
      require_once $interfacePath;
    }

    // Cargar base
    if (!class_exists('baseAIProvider')) {
      $basePath = OG_FRAMEWORK_PATH . '/services/integrations/ogAi/baseAIProvider.php';
      if (!file_exists($basePath)) {
        ogLog::throwError('createProvider - Base class file not found: ' . $basePath, [], self::$logMeta);
      }
      require_once $basePath;
    }

    // Cargar provider específico
    if (!file_exists($providerPath)) {
      ogLog::throwError('createProvider - ' . __('services.ai.provider_file_not_found', ['path' => $providerPath]), [], self::$logMeta);
    }
    require_once $providerPath;

    // Verificar que la clase existe después de cargar
    if (!class_exists($providerClass)) {
      ogLog::throwError('createProvider - ' . __('services.ai.class_not_found', ['class' => $providerClass]), [], self::$logMeta);
    }

    return new $providerClass($config);
  }

  // Obtener servicios para una tarea
  private function getServicesForTask($bot, $task) {
    $services = $bot['config']['apis']['ai'][$task] ?? [];
    if (empty($services)) ogLog::warning("No hay servicios para: {$task}", [], self::$logMeta);
    return $services;
  }

  // Estadísticas de uso
  public function getUsageStats($response) {
    return [
      'prompt_tokens' => $response['usage']['prompt_tokens'] ?? 0,
      'completion_tokens' => $response['usage']['completion_tokens'] ?? 0,
      'total_tokens' => $response['usage']['total_tokens'] ?? 0,
      'cache_hit_tokens' => $response['usage']['prompt_cache_hit_tokens'] ?? 0,
      'cache_efficiency' => $response['usage']['cache_efficiency'] ?? '0%',
      'estimated_cost' => $this->calculateCost($response)
    ];
  }

  // Calcular costo estimado
  private function calculateCost($response) {
    $totalTokens = $response['usage']['total_tokens'] ?? 0;
    $provider = $response['provider'] ?? 'unknown';

    $costPerMilTokens = [
      'deepseek' => 0.0002,
      'openai' => 0.002,
      'gpt-4o-mini' => 0.00015
    ];

    $rate = $costPerMilTokens[$provider] ?? 0.002;
    return ($totalTokens / 1000) * $rate;
  }
}