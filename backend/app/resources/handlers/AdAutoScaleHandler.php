<?php
class AdAutoScaleHandler {
  private static $logMeta = ['module' => 'AdAutoScaleHandler', 'layer' => 'app/resources'];

  // Ejecutar todas las reglas activas (CRON)
  static function executeRules($params = []) {
    try {
      ogLog::info('executeRules - Iniciando ejecución de reglas', [], self::$logMeta);

      $userId = $params['user_id'] ?? ($GLOBALS['auth_user_id'] ?? null);

      $service = ogApp()->service('AdAutoScale');
      $result = $service->processRules($userId);

      if ($result['success']) {
        ogLog::success('executeRules - Ejecución completada', [
          'rules_processed' => $result['rules_processed'],
          'actions_executed' => $result['actions_executed'],
          'execution_time' => $result['execution_time']
        ], self::$logMeta);
      }

      return $result;

    } catch (Exception $e) {
      ogLog::error('executeRules - Error', ['error' => $e->getMessage()], self::$logMeta);
      return [
        'success' => false,
        'error' => $e->getMessage()
      ];
    }
  }

  // Ejecutar una regla específica (testing)
  static function executeRule($params) {
    $ruleId = $params['rule_id'] ?? ogRequest::query('rule_id');

    if (!$ruleId) {
      return [
        'success' => false,
        'error' => 'rule_id requerido'
      ];
    }

    try {
      $rule = ogDb::t('ad_auto_scale')->find($ruleId);
      if (!$rule) {
        return [
          'success' => false,
          'error' => 'Regla no encontrada'
        ];
      }

      $service = ogApp()->service('AdAutoScale');

      // Usar reflexión para acceder al método privado (solo para testing)
      $reflection = new ReflectionClass($service);
      $method = $reflection->getMethod('processRule');
      $method->setAccessible(true);

      $result = $method->invoke($service, $rule);

      ogLog::info('executeRule - Regla ejecutada', [
        'rule_id' => $ruleId,
        'result' => $result
      ], self::$logMeta);

      return $result;

    } catch (Exception $e) {
      ogLog::error('executeRule - Error', [
        'rule_id' => $ruleId,
        'error' => $e->getMessage()
      ], self::$logMeta);

      return [
        'success' => false,
        'error' => $e->getMessage()
      ];
    }
  }



  /**
   * Resetear presupuestos diarios automáticamente
   * Se ejecuta cada hora via CRON
   * Agrupa activos por timezone para optimizar el proceso
   */
  static function resetDailyBudgets($params = []) {
    $startTime = microtime(true);
    ogLog::info('resetDailyBudgets - Iniciando proceso', [], self::$logMeta);

    // 1. Obtener todos los activos que tienen auto_reset activo
    $assets = ogDb::t('product_ad_assets')
      ->where('is_active', 1)
      ->where('auto_reset_budget', 1)
      ->where('status', 1)
      ->whereNotNull('credential_id')
      ->whereNotNull('base_daily_budget')
      ->get();

    if (empty($assets)) {
      ogLog::warning('resetDailyBudgets - No hay activos con auto_reset activo', [], self::$logMeta);
      return [
        'success' => true,
        'resets_executed' => 0,
        'message' => 'No hay activos con auto_reset activo'
      ];
    }

    // 2. Agrupar activos por timezone
    $assetsByTimezone = [];
    foreach ($assets as $asset) {
      $timezone = $asset['timezone'] ?? 'America/Guayaquil';
      if (!isset($assetsByTimezone[$timezone])) {
        $assetsByTimezone[$timezone] = [];
      }
      $assetsByTimezone[$timezone][] = $asset;
    }

    ogLog::info('resetDailyBudgets - Activos agrupados por timezone', [
      'total_assets' => count($assets),
      'timezones_count' => count($assetsByTimezone),
      'timezones' => array_keys($assetsByTimezone)
    ], self::$logMeta);

    $resetCount = 0;
    $skipped = 0;
    $errors = [];

    // 3. Procesar cada timezone
    foreach ($assetsByTimezone as $timezone => $timezoneAssets) {
      try {
        // Calcular hora actual en este timezone
        $currentTime = new DateTime('now', new DateTimeZone($timezone));
        $currentHour = $currentTime->format('H:i:s');
        $currentDate = $currentTime->format('Y-m-d');

        ogLog::debug('resetDailyBudgets - Procesando timezone', [
          'timezone' => $timezone,
          'assets_count' => count($timezoneAssets),
          'current_time' => $currentTime->format('Y-m-d H:i:s'),
          'current_hour' => $currentHour,
          'current_date' => $currentDate
        ], self::$logMeta);

        foreach ($timezoneAssets as $asset) {
          // Validar si ya se reseteó hoy
          if ($asset['last_reset_date'] === $currentDate) {
            ogLog::debug('resetDailyBudgets - Ya se reseteó hoy', [
              'asset_id' => $asset['id'],
              'last_reset_date' => $asset['last_reset_date']
            ], self::$logMeta);
            $skipped++;
            continue;
          }

          // Validar que tenga base_daily_budget configurado
          if (!$asset['base_daily_budget'] || $asset['base_daily_budget'] <= 0) {
            ogLog::warning('resetDailyBudgets - Activo sin base_daily_budget', [
              'asset_id' => $asset['id'],
              'ad_asset_id' => $asset['ad_asset_id']
            ], self::$logMeta);
            $skipped++;
            continue;
          }

          // Verificar si ya es hora de resetear
          $resetTime = $asset['reset_time'] ?? '00:00:00';
          
          if ($currentHour >= $resetTime) {
            ogLog::debug('resetDailyBudgets - Reseteando activo', [
              'asset_id' => $asset['id'],
              'ad_asset_id' => $asset['ad_asset_id'],
              'timezone' => $timezone,
              'reset_time' => $resetTime,
              'current_hour' => $currentHour
            ], self::$logMeta);

            $result = self::resetAssetBudget($asset, $timezone, $currentDate);
            
            if ($result['success']) {
              $resetCount++;
            } else {
              $errors[] = [
                'asset_id' => $asset['id'],
                'ad_asset_id' => $asset['ad_asset_id'],
                'error' => $result['error']
              ];
            }
          } else {
            ogLog::debug('resetDailyBudgets - Aún no es hora de resetear', [
              'asset_id' => $asset['id'],
              'reset_time' => $resetTime,
              'current_hour' => $currentHour
            ], self::$logMeta);
          }
        }

      } catch (Exception $e) {
        ogLog::error('resetDailyBudgets - Error procesando timezone', [
          'timezone' => $timezone,
          'error' => $e->getMessage()
        ], self::$logMeta);
        $errors[] = [
          'timezone' => $timezone,
          'error' => $e->getMessage()
        ];
      }
    }

    $executionTime = round((microtime(true) - $startTime) * 1000);

    ogLog::success('resetDailyBudgets - Proceso completado', [
      'resets_executed' => $resetCount,
      'skipped' => $skipped,
      'errors_count' => count($errors),
      'execution_time_ms' => $executionTime
    ], self::$logMeta);

    return [
      'success' => true,
      'resets_executed' => $resetCount,
      'skipped' => $skipped,
      'errors' => $errors,
      'execution_time_ms' => $executionTime
    ];
  }


  /**
   * Resetear presupuesto de un activo específico
   */
  private static function resetAssetBudget($asset, $timezone, $currentDate) {
    $startTime = microtime(true);

    try {
      // 1. Obtener credencial
      $credential = ogDb::t('credentials')->find($asset['credential_id']);
      
      if (!$credential) {
        return [
          'success' => false,
          'error' => "Credencial no encontrada: {$asset['credential_id']}"
        ];
      }

      // Parsear config de la credencial
      $credConfig = is_string($credential['config']) ? json_decode($credential['config'], true) : $credential['config'];
      $credConfig['credential_value'] = $credConfig['credential_value'] ?? $credConfig['access_token'] ?? '';

      // 2. Cargar provider (igual que en adjustBudget)
      $platform = strtolower($asset['ad_platform']);
      $providerClass = ucfirst($platform) . 'AdProvider';
      
      $basePath = ogCache::memoryGet('path_middle') . '/services/integrations/ads';
      
      if (!interface_exists('adProviderInterface')) {
        require_once "{$basePath}/adProviderInterface.php";
      }
      if (!class_exists('baseAdProvider')) {
        require_once "{$basePath}/baseAdProvider.php";
      }
      
      $providerFile = "{$basePath}/{$platform}/{$platform}AdProvider.php";
      if (!file_exists($providerFile)) {
        return ['success' => false, 'error' => "Provider no encontrado: {$platform}"];
      }
      
      require_once $providerFile;
      if (!class_exists($providerClass)) {
        return ['success' => false, 'error' => "Clase provider no encontrada: {$providerClass}"];
      }

      $provider = new $providerClass($credConfig);

      // 3. Obtener presupuesto actual de la plataforma
      $currentBudgetData = $provider->getBudget($asset['ad_asset_id'], $asset['ad_asset_type']);

      if (!$currentBudgetData['success']) {
        return [
          'success' => false,
          'error' => $currentBudgetData['error'] ?? 'Error obteniendo presupuesto actual'
        ];
      }

      $budgetBefore = $currentBudgetData['budget'] ?? 0;
      $budgetAfter = (float)$asset['base_daily_budget'];

      // 4. Actualizar presupuesto en la plataforma
      $updateResult = $provider->updateBudget(
        $asset['ad_asset_id'], 
        $asset['ad_asset_type'], 
        $budgetAfter, 
        'daily'
      );

      if (!$updateResult['success']) {
        return [
          'success' => false,
          'error' => $updateResult['error'] ?? 'Error actualizando presupuesto'
        ];
      }

      $executionTime = round((microtime(true) - $startTime) * 1000);

      // 5. Guardar en historial
      ogDb::t('ad_budget_resets')->insert([
        'user_id' => $asset['user_id'],
        'product_ad_asset_id' => $asset['id'],
        'ad_asset_id' => $asset['ad_asset_id'],
        'budget_before' => $budgetBefore,
        'budget_after' => $budgetAfter,
        'reset_date' => $currentDate,
        'reset_at' => date('Y-m-d H:i:s'),
        'timezone' => $timezone,
        'execution_time_ms' => $executionTime,
        'status' => 'success',
        'tc' => time()
      ]);

      // 6. Actualizar last_reset_date en product_ad_assets
      ogDb::t('product_ad_assets')
        ->where('id', $asset['id'])
        ->update(['last_reset_date' => $currentDate]);

      ogLog::success('resetAssetBudget - Presupuesto reseteado', [
        'asset_id' => $asset['id'],
        'ad_asset_id' => $asset['ad_asset_id'],
        'timezone' => $timezone,
        'budget_before' => $budgetBefore,
        'budget_after' => $budgetAfter,
        'execution_time_ms' => $executionTime
      ], self::$logMeta);

      return ['success' => true];

    } catch (Exception $e) {
      ogLog::error('resetAssetBudget - Error', [
        'asset_id' => $asset['id'],
        'ad_asset_id' => $asset['ad_asset_id'],
        'error' => $e->getMessage()
      ], self::$logMeta);

      // Guardar error en historial
      try {
        ogDb::t('ad_budget_resets')->insert([
          'user_id' => $asset['user_id'],
          'product_ad_asset_id' => $asset['id'],
          'ad_asset_id' => $asset['ad_asset_id'],
          'budget_before' => 0,
          'budget_after' => $asset['base_daily_budget'],
          'reset_date' => $currentDate,
          'reset_at' => date('Y-m-d H:i:s'),
          'timezone' => $timezone,
          'execution_time_ms' => round((microtime(true) - $startTime) * 1000),
          'status' => 'failed',
          'error_message' => $e->getMessage(),
          'tc' => time()
        ]);
      } catch (Exception $logError) {
        ogLog::error('resetAssetBudget - Error guardando en historial', [
          'error' => $logError->getMessage()
        ], self::$logMeta);
      }

      return [
        'success' => false,
        'error' => $e->getMessage()
      ];
    }
  }

  /**
   * Ajustar presupuesto manualmente (incremento/decremento)
   * Guarda en ad_auto_scale_history con execution_source='manual'
   */
  static function adjustBudget($params = []) {
    $startTime = microtime(true);
    $params = $params ?? ogRequest::data();
    ogLog::info('adjustBudget - Iniciando ajuste manual', $params, self::$logMeta);

    try {
      // Validar parámetros
      $requiredParams = ['product_ad_asset_id', 'ad_asset_id', 'ad_asset_type', 'budget_before', 'budget_after'];
      foreach ($requiredParams as $param) {
        if (!isset($params[$param]) || $params[$param] === '') {
          return ['success' => false, 'error' => "Parámetro requerido: {$param}"];
        }
      }

      $productAdAssetId = $params['product_ad_asset_id'];
      $adAssetId = $params['ad_asset_id'];
      $adAssetType = $params['ad_asset_type'];
      $budgetBefore = (float)$params['budget_before'];
      $budgetAfter = (float)$params['budget_after'];
      $adjustmentAmount = isset($params['adjustment_amount']) ? (float)$params['adjustment_amount'] : ($budgetAfter - $budgetBefore);
      $reason = $params['reason'] ?? '';

      if ($budgetAfter <= 0) {
        return ['success' => false, 'error' => 'El nuevo presupuesto debe ser mayor a $0'];
      }

      // Obtener activo
      $asset = ogDb::t('product_ad_assets')->find($productAdAssetId);
      if (!$asset) {
        return ['success' => false, 'error' => "Activo no encontrado: {$productAdAssetId}"];
      }

      // Obtener credencial
      $credential = ogDb::t('credentials')->find($asset['credential_id']);
      if (!$credential) {
        return ['success' => false, 'error' => "Credencial no encontrada: {$asset['credential_id']}"]; 
      }

      $credConfig = is_string($credential['config']) ? json_decode($credential['config'], true) : $credential['config'];
      $credConfig['credential_value'] = $credConfig['credential_value'] ?? $credConfig['access_token'] ?? '';

      // Cargar provider
      $platform = strtolower($asset['ad_platform']);
      $providerClass = ucfirst($platform) . 'AdProvider';
      
      $basePath = ogCache::memoryGet('path_middle') . '/services/integrations/ads';
      
      if (!interface_exists('adProviderInterface')) {
        require_once "{$basePath}/adProviderInterface.php";
      }
      if (!class_exists('baseAdProvider')) {
        require_once "{$basePath}/baseAdProvider.php";
      }
      
      $providerFile = "{$basePath}/{$platform}/{$platform}AdProvider.php";
      if (!file_exists($providerFile)) {
        return ['success' => false, 'error' => "Provider no encontrado: {$platform}"];
      }
      
      require_once $providerFile;
      if (!class_exists($providerClass)) {
        return ['success' => false, 'error' => "Clase provider no encontrada: {$providerClass}"];
      }

      $provider = new $providerClass($credConfig);

      // Actualizar presupuesto
      $updateResult = $provider->updateBudget($adAssetId, $adAssetType, $budgetAfter, 'daily');

      if (!$updateResult['success']) {
        return ['success' => false, 'error' => $updateResult['error'] ?? 'Error actualizando presupuesto'];
      }

      $executionTime = round((microtime(true) - $startTime) * 1000);

      // Guardar en historial - USAR COLUMNAS CORRECTAS
      ogDb::t('ad_auto_scale_history')->insert([
        'rule_id' => 0, // 0 para ajustes manuales (NOT NULL pero sin regla)
        'user_id' => $asset['user_id'],
        'ad_assets_id' => $productAdAssetId, // ← COLUMNA CORRECTA
        'product_id' => $asset['product_id'],
        'ad_platform' => $asset['ad_platform'],
        'ad_asset_type' => $adAssetType,
        'metrics_snapshot' => json_encode([
          'budget_before' => $budgetBefore,
          'budget_after' => $budgetAfter,
          'adjustment_amount' => $adjustmentAmount,
          'reason' => $reason
        ]),
        'time_range' => 'manual',
        'conditions_met' => 1, // Manual siempre "cumple"
        'conditions_logic' => null,
        'conditions_result' => null,
        'action_executed' => 1,
        'action_type' => $adjustmentAmount > 0 ? 'increase_budget' : 'decrease_budget',
        'action_result' => json_encode([
          'success' => true,
          'platform_response' => $updateResult
        ]),
        'execution_source' => 'manual',
        'execution_time_ms' => $executionTime,
        'error_message' => null,
        'executed_at' => date('Y-m-d H:i:s'),
        'dc' => date('Y-m-d H:i:s'),
        'tc' => time()
      ]);

      ogLog::success('adjustBudget - Presupuesto ajustado', [
        'asset_id' => $adAssetId,
        'budget_before' => $budgetBefore,
        'budget_after' => $budgetAfter,
        'execution_time_ms' => $executionTime
      ], self::$logMeta);

      return [
        'success' => true,
        'budget_before' => $budgetBefore,
        'budget_after' => $budgetAfter,
        'adjustment_amount' => $adjustmentAmount,
        'execution_time_ms' => $executionTime
      ];

    } catch (Exception $e) {
      ogLog::error('adjustBudget - Error', ['error' => $e->getMessage()], self::$logMeta);
      return ['success' => false, 'error' => $e->getMessage()];
    }
  }

}