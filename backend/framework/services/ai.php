<?php
class ai {

  // Chat completion con fallback automático
  public function getChatCompletion($prompt, $bot, $options = []) {
    try {
      $aiServices = $this->getServicesForTask($bot, 'conversation');
      if (empty($aiServices)) throw new Exception(__('services.ai.no_services_available'));

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
          log::debug("[" . $provider->getProviderName() . "] Error en chat completion", ['error' => $lastError], ['module' => 'ai']);
          if ($attemptNumber < count($aiServices)) continue;
        }
      }

      throw new Exception(__('services.ai.all_services_failed', ['error' => $lastError]));

    } catch (Exception $e) {
      log::error('Error en chat completion', ['error' => $e->getMessage()], ['module' => 'ai']);
      return ['success' => false, 'error' => $e->getMessage(), 'services_tried' => $attemptNumber ?? 0];
    }
  }

  // Transcribir audio con fallback
  public function transcribeAudio($audioUrl, $bot) {
    try {
      $aiServices = $this->getServicesForTask($bot, 'audio');
      if (empty($aiServices)) throw new Exception(__('services.ai.no_services_for_task', ['task' => 'audio']));

      $attemptNumber = 0;
      $lastError = null;

      foreach ($aiServices as $serviceConfig) {
        $attemptNumber++;
        try {
          $provider = $this->createProvider($serviceConfig);
          $result = $provider->transcribeAudio($audioUrl);
          if ($result['success']) return $result;

        } catch (Exception $e) {
          $lastError = $e->getMessage();
          if ($attemptNumber < count($aiServices)) continue;
        }
      }

      throw new Exception(__('services.ai.all_services_failed', ['error' => $lastError]));

    } catch (Exception $e) {
      log::error('Error transcribiendo audio', ['error' => $e->getMessage()], ['module' => 'ai']);
      return ['success' => false, 'error' => $e->getMessage()];
    }
  }

  // Analizar imagen con fallback
  public function analyzeImage($imageDataUri, $instruction, $bot) {
    try {
      $aiServices = $this->getServicesForTask($bot, 'image');
      if (empty($aiServices)) throw new Exception(__('services.ai.no_services_for_task', ['task' => 'image']));

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

      throw new Exception(__('services.ai.all_services_failed', ['error' => $lastError]));

    } catch (Exception $e) {
      log::error('Error analizando imagen', ['error' => $e->getMessage()], ['module' => 'ai']);
      return ['success' => false, 'error' => $e->getMessage()];
    }
  }

  // Crear instancia del proveedor
  private function createProvider($serviceConfig) {
    $config = $serviceConfig['config'] ?? [];
    $slug = strtolower($config['type_value'] ?? '');

    $providerMap = [
      'deepseek' => 'deepSeekProvider',
      'openai' => 'openAiProvider',
      'gpt' => 'openAiProvider'
    ];

    $providerClass = $providerMap[$slug] ?? null;
    if (!$providerClass) throw new Exception(__('services.ai.provider_not_supported', ['provider' => $slug]));

    // Autoload carga la clase automáticamente
    if (!class_exists($providerClass)) {
      throw new Exception(__('services.ai.class_not_found', ['class' => $providerClass]));
    }

    return new $providerClass($config);
  }

  // Obtener servicios para una tarea
  private function getServicesForTask($bot, $task) {
    $services = $bot['config']['apis']['ai'][$task] ?? [];
    if (empty($services)) log::warning("No hay servicios para: {$task}", [], ['module' => 'ai']);
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