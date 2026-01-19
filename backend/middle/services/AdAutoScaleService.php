<?php
class AdAutoScaleService {
  private static $logMeta = ['module' => 'AdAutoScaleService', 'layer' => 'middle/services'];

  // Procesar todas las reglas activas
  function processRules($userId = null) {
    try {
      $startTime = microtime(true);

      // Obtener reglas activas
      $query = ogDb::t('ad_auto_scale')->where('status', 1);
      if ($userId) {
        $query = $query->where('user_id', $userId);
      }
      $rules = $query->get();

      if (empty($rules)) {
        ogLog::info('processRules - No hay reglas activas', ['user_id' => $userId], self::$logMeta);
        return [
          'success' => true,
          'message' => 'No hay reglas activas',
          'rules_processed' => 0,
          'actions_executed' => 0
        ];
      }

      $rulesProcessed = 0;
      $actionsExecuted = 0;
      $errors = [];
      $results = [];

      // Procesar cada regla
      foreach ($rules as $rule) {
        $ruleResult = $this->processRule($rule);
        $results[] = $ruleResult;

        if ($ruleResult['success']) {
          $rulesProcessed++;
          if ($ruleResult['action_executed']) {
            $actionsExecuted++;
          }
        } else {
          $errors[] = $ruleResult;
        }
      }

      $executionTime = round(microtime(true) - $startTime, 2);

      ogLog::info('processRules - Ejecución completada', [
        'rules_processed' => $rulesProcessed,
        'actions_executed' => $actionsExecuted,
        'errors' => count($errors),
        'execution_time' => $executionTime
      ], self::$logMeta);

      return [
        'success' => true,
        'rules_processed' => $rulesProcessed,
        'actions_executed' => $actionsExecuted,
        'errors' => count($errors),
        'results' => $results,
        'execution_time' => $executionTime
      ];

    } catch (Exception $e) {
      ogLog::error('processRules - Error', ['error' => $e->getMessage()], self::$logMeta);
      return [
        'success' => false,
        'error' => $e->getMessage()
      ];
    }
  }

  // Procesar una regla individual
  private function processRule($rule) {
    try {
      $ruleId = $rule['id'];
      $ruleName = $rule['name'];
      $assetId = $rule['ad_assets_id'];
      $userId = $rule['user_id']; // ✅ Ya viene en la regla
      $config = is_string($rule['config']) ? json_decode($rule['config'], true) : $rule['config'];

      ogLog::debug('processRule - Procesando regla', [
        'rule_id' => $ruleId,
        'rule_name' => $ruleName,
        'asset_id' => $assetId
      ], self::$logMeta);

      // Obtener info del activo
      $asset = ogDb::t('product_ad_assets')->find($assetId);
      if (!$asset) {
        return [
          'success' => false,
          'rule_id' => $ruleId,
          'rule_name' => $ruleName,
          'error' => 'Activo publicitario no encontrado'
        ];
      }

      // Obtener métricas del activo
      $metricsData = $this->getAssetMetrics($asset, $config);
      if (!$metricsData['success']) {
        // Guardar en historial incluso si falla
        $this->saveToHistory([
          'rule_id' => $ruleId,
          'user_id' => $userId,
          'ad_assets_id' => $asset['id'],
          'product_id' => $asset['product_id'],
          'ad_platform' => $asset['ad_platform'],
          'ad_asset_type' => $asset['ad_asset_type'],
          'metrics_snapshot' => json_encode([]),
          'time_range' => 'unknown',
          'conditions_met' => 0,
          'conditions_logic' => $config['conditions_logic'] ?? 'and_or_and',
          'action_executed' => 0,
          'action_type' => null,
          'action_result' => json_encode(['error' => $metricsData['error']]),
          'execution_source' => 'cron',
          'error_message' => $metricsData['error']
        ]);

        return [
          'success' => false,
          'rule_id' => $ruleId,
          'rule_name' => $ruleName,
          'error' => $metricsData['error']
        ];
      }

      // Evaluar condiciones con ogLogic
      $conditionsMet = $this->evaluateConditions($config, $metricsData['metrics']);

      ogLog::debug('processRule - Condiciones evaluadas', [
        'rule_id' => $ruleId,
        'conditions_met' => $conditionsMet,
        'metrics' => $metricsData['metrics']
      ], self::$logMeta);

      // Si no cumple condiciones, no ejecutar acciones
      if (!$conditionsMet) {
        // Guardar en historial
        $this->saveToHistory([
          'rule_id' => $ruleId,
          'user_id' => $userId,
          'ad_assets_id' => $asset['id'],
          'product_id' => $asset['product_id'],
          'ad_platform' => $asset['ad_platform'],
          'ad_asset_type' => $asset['ad_asset_type'],
          'metrics_snapshot' => json_encode($metricsData['metrics']),
          'time_range' => $metricsData['time_range'],
          'conditions_met' => 0,
          'conditions_logic' => $config['conditions_logic'] ?? 'and_or_and',
          'action_executed' => 0,
          'action_type' => null,
          'action_result' => json_encode([]),
          'execution_source' => 'cron'
        ]);

        return [
          'success' => true,
          'rule_id' => $ruleId,
          'rule_name' => $ruleName,
          'conditions_met' => false,
          'action_executed' => false,
          'message' => 'Condiciones no cumplidas'
        ];
      }

      // Ejecutar acciones
      $actionResults = $this->executeActions($asset, $config['actions'] ?? [], $metricsData['metrics']);

      // Guardar en historial
      $this->saveToHistory([
        'rule_id' => $ruleId,
        'user_id' => $userId,
        'ad_assets_id' => $asset['id'],
        'product_id' => $asset['product_id'],
        'ad_platform' => $asset['ad_platform'],
        'ad_asset_type' => $asset['ad_asset_type'],
        'metrics_snapshot' => json_encode($metricsData['metrics']),
        'time_range' => $metricsData['time_range'],
        'conditions_met' => 1,
        'conditions_logic' => $config['conditions_logic'] ?? 'and_or_and',
        'action_executed' => $actionResults['executed'] ? 1 : 0,
        'action_type' => isset($actionResults['results'][0]) ? $actionResults['results'][0]['action'] : null,
        'action_result' => json_encode($actionResults['results'][0] ?? []),
        'execution_source' => 'cron'
      ]);

      return [
        'success' => true,
        'rule_id' => $ruleId,
        'rule_name' => $ruleName,
        'conditions_met' => true,
        'action_executed' => $actionResults['executed'],
        'actions_results' => $actionResults['results'],
        'metrics' => $metricsData['metrics']
      ];

    } catch (Exception $e) {
      ogLog::error('processRule - Error', [
        'rule_id' => $rule['id'] ?? null,
        'error' => $e->getMessage()
      ], self::$logMeta);

      return [
        'success' => false,
        'rule_id' => $rule['id'] ?? null,
        'error' => $e->getMessage()
      ];
    }
  }

  // Guardar en historial
  private function saveToHistory($data) {
    try {
      $data['executed_at'] = date('Y-m-d H:i:s');
      $data['dc'] = date('Y-m-d H:i:s');
      $data['tc'] = time();
      
      ogDb::t('ad_auto_scale_history')->insert($data);
      
      ogLog::debug('saveToHistory - Registro guardado', [
        'rule_id' => $data['rule_id'],
        'action_executed' => $data['action_executed']
      ], self::$logMeta);
      
    } catch (Exception $e) {
      ogLog::error('saveToHistory - Error', ['error' => $e->getMessage()], self::$logMeta);
    }
  }

  // Obtener métricas del activo (PRIVADO - solo para AdAutoScaleService)
  private function getAssetMetrics($asset, $config) {
    try {
      $productId = $asset['product_id'];
      $assetId = $asset['ad_asset_id'];

      // Determinar rango de fechas más común en las condiciones
      $timeRange = $this->getMostCommonTimeRange($config);
      $dates = $this->getDateRangeFromTimeRange($timeRange);

      // Obtener métricas de ads
      $adMetrics = $this->getAdMetrics($asset, $dates['from'], $dates['to']);

      // VALIDACIÓN 1: Verificar que haya datos en el período
      if ($adMetrics['spend'] <= 0 && $adMetrics['results'] <= 0 && $adMetrics['impressions'] <= 0) {
        return [
          'success' => false,
          'error' => "Sin datos de métricas para el rango {$timeRange} ({$dates['from']} - {$dates['to']})"
        ];
      }

      // VALIDACIÓN 2: Mínimo de actividad (al menos 2 resultados/mensajes)
      if ($adMetrics['results'] < 2) {
        return [
          'success' => false,
          'error' => "Actividad insuficiente: {$adMetrics['results']} resultados (mínimo requerido: 2)"
        ];
      }

      // Calcular ROAS personalizado
      $roas = $this->calculateROAS($productId, $assetId, $dates['from'], $dates['to'], $adMetrics['spend']);

      // Calcular métricas derivadas
      $metrics = [
        'roas' => $roas,
        'cost_per_result' => $adMetrics['results'] > 0 ? $adMetrics['spend'] / $adMetrics['results'] : 0,
        'frequency' => $adMetrics['reach'] > 0 ? $adMetrics['impressions'] / $adMetrics['reach'] : 0,
        'spend' => $adMetrics['spend'],
        'results' => $adMetrics['results'],
        'impressions' => $adMetrics['impressions'],
        'reach' => $adMetrics['reach'],
        'clicks' => $adMetrics['clicks'],
        'ctr' => $adMetrics['ctr'],
        'cpc' => $adMetrics['cpc'],
        'cpm' => $adMetrics['cpm']
      ];

      // Redondear
      $metrics['roas'] = round($metrics['roas'], 2);
      $metrics['cost_per_result'] = round($metrics['cost_per_result'], 2);
      $metrics['frequency'] = round($metrics['frequency'], 2);

      return [
        'success' => true,
        'metrics' => $metrics,
        'time_range' => $timeRange,
        'date_from' => $dates['from'],
        'date_to' => $dates['to']
      ];

    } catch (Exception $e) {
      ogLog::error('getAssetMetrics - Error', ['error' => $e->getMessage()], self::$logMeta);
      return [
        'success' => false,
        'error' => $e->getMessage()
      ];
    }
  }

  // Obtener métricas de ads (histórico + hoy)
  private function getAdMetrics($asset, $dateFrom, $dateTo) {
    $assetId = $asset['ad_asset_id'];
    $productId = $asset['product_id'];
    $today = date('Y-m-d');

    $metrics = [
      'spend' => 0,
      'impressions' => 0,
      'reach' => 0,
      'clicks' => 0,
      'results' => 0,
      'ctr' => 0,
      'cpc' => 0,
      'cpm' => 0
    ];

    // HISTÓRICO: días anteriores a hoy (ad_metrics_daily)
    if ($dateFrom < $today) {
      $historicTo = ($dateTo < $today) ? $dateTo : date('Y-m-d', strtotime($today . ' -1 day'));

      $sqlHistoric = "
        SELECT
          SUM(spend) as spend,
          SUM(impressions) as impressions,
          SUM(reach) as reach,
          SUM(clicks) as clicks,
          SUM(results) as results
        FROM ad_metrics_daily
        WHERE product_id = ?
          AND ad_asset_id = ?
          AND metric_date >= ?
          AND metric_date <= ?
      ";

      $historic = ogDb::raw($sqlHistoric, [$productId, $assetId, $dateFrom, $historicTo]);
      if (!empty($historic)) {
        $row = $historic[0];
        $metrics['spend'] += (float)($row['spend'] ?? 0);
        $metrics['impressions'] += (int)($row['impressions'] ?? 0);
        $metrics['reach'] += (int)($row['reach'] ?? 0);
        $metrics['clicks'] += (int)($row['clicks'] ?? 0);
        $metrics['results'] += (int)($row['results'] ?? 0);
      }
    }

    // HOY: datos actuales (ad_metrics_hourly con is_latest = 1)
    if ($dateTo >= $today) {
      $sqlToday = "
        SELECT
          spend,
          impressions,
          reach,
          clicks,
          results,
          ctr,
          cpc,
          cpm
        FROM ad_metrics_hourly
        WHERE product_id = ?
          AND ad_asset_id = ?
          AND query_date = ?
          AND is_latest = 1
      ";

      $todayData = ogDb::raw($sqlToday, [$productId, $assetId, $today]);
      if (!empty($todayData)) {
        $row = $todayData[0];
        $metrics['spend'] += (float)($row['spend'] ?? 0);
        $metrics['impressions'] += (int)($row['impressions'] ?? 0);
        $metrics['reach'] += (int)($row['reach'] ?? 0);
        $metrics['clicks'] += (int)($row['clicks'] ?? 0);
        $metrics['results'] += (int)($row['results'] ?? 0);
      }
    }

    // Recalcular métricas derivadas
    $metrics['ctr'] = $metrics['impressions'] > 0 ? ($metrics['clicks'] / $metrics['impressions']) * 100 : 0;
    $metrics['cpc'] = $metrics['clicks'] > 0 ? $metrics['spend'] / $metrics['clicks'] : 0;
    $metrics['cpm'] = $metrics['impressions'] > 0 ? ($metrics['spend'] / $metrics['impressions']) * 1000 : 0;

    $metrics['ctr'] = round($metrics['ctr'], 2);
    $metrics['cpc'] = round($metrics['cpc'], 2);
    $metrics['cpm'] = round($metrics['cpm'], 2);

    return $metrics;
  }

  // Calcular ROAS personalizado
  private function calculateROAS($productId, $assetId, $dateFrom, $dateTo, $adSpend) {
    if ($adSpend <= 0) return 0;

    // Sumar SOLO ventas confirmadas del producto en el período
    $sql = "
      SELECT SUM(amount) as total_sales
      FROM sales
      WHERE product_id = ?
        AND payment_date >= ?
        AND payment_date <= ?
        AND process_status = 'sale_confirmed'
        AND status = 1
    ";

    $result = ogDb::raw($sql, [$productId, $dateFrom, $dateTo . ' 23:59:59']);
    $totalSales = !empty($result) ? (float)($result[0]['total_sales'] ?? 0) : 0;

    return $totalSales / $adSpend;
  }

  // Evaluar condiciones con ogLogic
  private function evaluateConditions($config, $metricsData) {
    $conditionGroups = $config['condition_groups'] ?? [];
    if (empty($conditionGroups)) {
      ogLog::warning('evaluateConditions - No hay grupos de condiciones', [], self::$logMeta);
      return false;
    }

    $conditionsLogic = $config['conditions_logic'] ?? 'and_or_and';

    // Construir lógica ogLogic según el tipo
    if ($conditionsLogic === 'and_or_and') {
      // (AND) or (AND) - Cada grupo es AND interno, grupos unidos por OR
      $orGroups = [];
      foreach ($conditionGroups as $group) {
        $andConditions = $this->buildGroupConditions($group['conditions'] ?? []);
        if (!empty($andConditions)) {
          $orGroups[] = ['and' => $andConditions];
        }
      }
      $logic = ['or' => $orGroups];

    } elseif ($conditionsLogic === 'or_and_or') {
      // (OR) and (OR) - Cada grupo es OR interno, grupos unidos por AND
      $andGroups = [];
      foreach ($conditionGroups as $group) {
        $orConditions = $this->buildGroupConditions($group['conditions'] ?? []);
        if (!empty($orConditions)) {
          $andGroups[] = ['or' => $orConditions];
        }
      }
      $logic = ['and' => $andGroups];

    } else {
      ogLog::error('evaluateConditions - Lógica desconocida', ['logic' => $conditionsLogic], self::$logMeta);
      return false;
    }

    // Evaluar con ogLogic
    return ogApp()->helper('logic')::apply($logic, $metricsData);
  }

  // Construir condiciones de un grupo
  private function buildGroupConditions($conditions) {
    $logicConditions = [];

    foreach ($conditions as $condition) {
      $metric = $condition['metric'] ?? null;
      $operator = $condition['operator'] ?? null;
      $value = $condition['value'] ?? null;

      if (!$metric || !$operator || $value === null) continue;

      // Construir condición ogLogic
      $logicConditions[] = [
        $operator => [
          ['var' => $metric],
          (float)$value
        ]
      ];
    }

    return $logicConditions;
  }

  // Ejecutar acciones
  private function executeActions($asset, $actions, $metrics) {
    $results = [];
    $executed = false;

    foreach ($actions as $action) {
      $actionType = $action['action_type'] ?? null;
      if (!$actionType) continue;

      $result = $this->executeAction($asset, $action, $metrics);
      $results[] = $result;

      if ($result['success']) {
        $executed = true;
      }
    }

    return [
      'executed' => $executed,
      'results' => $results
    ];
  }

  // Ejecutar una acción
  private function executeAction($asset, $action, $metrics) {
    $actionType = $action['action_type'];
    $platform = $asset['ad_platform'];
    $assetId = $asset['ad_asset_id'];
    $assetType = $asset['ad_asset_type'];

    try {
      switch ($actionType) {
        case 'increase_budget':
        case 'decrease_budget':
          return $this->adjustBudget($asset, $action, $actionType);

        case 'pause':
          return $this->pauseAsset($asset);

        default:
          return [
            'success' => false,
            'action' => $actionType,
            'error' => 'Acción no soportada'
          ];
      }

    } catch (Exception $e) {
      ogLog::error('executeAction - Error', [
        'action' => $actionType,
        'asset_id' => $assetId,
        'error' => $e->getMessage()
      ], self::$logMeta);

      return [
        'success' => false,
        'action' => $actionType,
        'error' => $e->getMessage()
      ];
    }
  }

  // Ajustar presupuesto
  private function adjustBudget($asset, $action, $actionType) {
    $platform = $asset['ad_platform'];
    $assetId = $asset['ad_asset_id'];
    $userId = $asset['user_id'];

    // Obtener presupuesto actual del provider
    $provider = $this->getAdProvider($platform, $userId);
    if (!$provider) {
      return [
        'success' => false,
        'action' => $actionType,
        'error' => "Provider no disponible para {$platform}"
      ];
    }

    $currentBudget = $provider->getBudget($assetId, $asset['ad_asset_type']);
    if (!$currentBudget['success']) {
      return [
        'success' => false,
        'action' => $actionType,
        'error' => 'No se pudo obtener presupuesto actual'
      ];
    }

    $budget = $currentBudget['budget'];
    $changeBy = (float)($action['change_by'] ?? 0);
    $changeType = $action['change_type'] ?? 'percent';
    $untilLimit = (float)($action['until_limit'] ?? 0);

    // Calcular nuevo presupuesto
    if ($changeType === 'percent') {
      $change = $budget * ($changeBy / 100);
    } else {
      $change = $changeBy;
    }

    $newBudget = $actionType === 'increase_budget'
      ? $budget + $change
      : $budget - $change;

    // Aplicar límites
    if ($actionType === 'increase_budget') {
      $newBudget = min($newBudget, $untilLimit);
    } else {
      $newBudget = max($newBudget, $untilLimit);
    }

    // Si no hay cambio, no hacer nada
    if (abs($newBudget - $budget) < 0.01) {
      return [
        'success' => true,
        'action' => $actionType,
        'message' => 'Presupuesto ya en el límite',
        'budget_before' => $budget,
        'budget_after' => $newBudget,
        'changed' => false
      ];
    }

    // Actualizar presupuesto
    $updateResult = $provider->updateBudget($assetId, $asset['ad_asset_type'], $newBudget, $action['budget_type'] ?? 'daily');

    if (!$updateResult['success']) {
      return [
        'success' => false,
        'action' => $actionType,
        'error' => $updateResult['error'] ?? 'Error al actualizar presupuesto'
      ];
    }

    ogLog::success('adjustBudget - Presupuesto actualizado', [
      'asset_id' => $assetId,
      'platform' => $platform,
      'action' => $actionType,
      'budget_before' => $budget,
      'budget_after' => $newBudget,
      'change' => $newBudget - $budget
    ], self::$logMeta);

    return [
      'success' => true,
      'action' => $actionType,
      'budget_before' => $budget,
      'budget_after' => $newBudget,
      'change' => round($newBudget - $budget, 2),
      'changed' => true
    ];
  }

  // Pausar activo
  private function pauseAsset($asset) {
    $platform = $asset['ad_platform'];
    $assetId = $asset['ad_asset_id'];
    $userId = $asset['user_id'];

    $provider = $this->getAdProvider($platform, $userId);
    if (!$provider) {
      return [
        'success' => false,
        'action' => 'pause',
        'error' => "Provider no disponible para {$platform}"
      ];
    }

    $result = $provider->pauseAsset($assetId, $asset['ad_asset_type']);

    if ($result['success']) {
      ogLog::success('pauseAsset - Activo pausado', [
        'asset_id' => $assetId,
        'platform' => $platform
      ], self::$logMeta);
    }

    return $result;
  }

  // Obtener provider de ads
  private function getAdProvider($platform, $userId) {
    $basePath = ogCache::memoryGet('path_middle') . '/services/integrations/ads';

    $providerMap = [
      'facebook' => ['class' => 'facebookAdProvider', 'folder' => 'facebook'],
      'google' => ['class' => 'googleAdProvider', 'folder' => 'google'],
      'tiktok' => ['class' => 'tiktokAdProvider', 'folder' => 'tiktok']
    ];

    $platform = strtolower($platform);
    if (!isset($providerMap[$platform])) {
      ogLog::error('getAdProvider - Plataforma no soportada', ['platform' => $platform], self::$logMeta);
      return null;
    }

    $providerInfo = $providerMap[$platform];
    $providerClass = $providerInfo['class'];
    $providerFolder = $providerInfo['folder'];

    // Cargar archivos necesarios
    if (!interface_exists('adProviderInterface')) {
      require_once "{$basePath}/adProviderInterface.php";
    }
    if (!class_exists('baseAdProvider')) {
      require_once "{$basePath}/baseAdProvider.php";
    }
    if (!class_exists($providerClass)) {
      require_once "{$basePath}/{$providerFolder}/{$providerClass}.php";
    }

    // Obtener credencial
    $credential = $this->getCredentialForPlatform($platform, $userId);
    if (!$credential) return null;

    return new $providerClass($credential);
  }

  // Obtener credencial
  private function getCredentialForPlatform($platform, $userId) {
    $typeValue = "ad-{$platform}";

    $credentials = ogDb::t('credentials')
      ->where('user_id', $userId)
      ->where('type', 'ad')
      ->where('status', 1)
      ->get();

    if (empty($credentials)) {
      ogLog::error('getCredentialForPlatform - No hay credenciales', [
        'platform' => $platform,
        'user_id' => $userId
      ], self::$logMeta);
      return null;
    }

    foreach ($credentials as $credential) {
      $config = is_string($credential['config']) ? json_decode($credential['config'], true) : $credential['config'];
      if (isset($config['type_value']) && $config['type_value'] === $typeValue) {
        $config['credential_value'] = $config['credential_value'] ?? $config['access_token'] ?? '';
        return $config;
      }
    }

    return null;
  }

  // Obtener rango más común de tiempo
  private function getMostCommonTimeRange($config) {
    $timeRanges = [];
    $conditionGroups = $config['condition_groups'] ?? [];

    foreach ($conditionGroups as $group) {
      $conditions = $group['conditions'] ?? [];
      foreach ($conditions as $condition) {
        $timeRange = $condition['time_range'] ?? null;
        if ($timeRange) {
          $timeRanges[] = $timeRange;
        }
      }
    }

    if (empty($timeRanges)) return 'today';

    // Retornar el más común (o el primero)
    $counts = array_count_values($timeRanges);
    arsort($counts);
    return array_key_first($counts);
  }

  // Convertir time_range a fechas
  private function getDateRangeFromTimeRange($timeRange) {
    $today = date('Y-m-d');

    switch ($timeRange) {
      case 'today':
        return ['from' => $today, 'to' => $today];

      case 'yesterday':
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        return ['from' => $yesterday, 'to' => $yesterday];

      case 'last_3d':
        return ['from' => date('Y-m-d', strtotime('-3 days')), 'to' => $today];

      case 'last_7d':
        return ['from' => date('Y-m-d', strtotime('-7 days')), 'to' => $today];

      case 'last_14d':
        return ['from' => date('Y-m-d', strtotime('-14 days')), 'to' => $today];

      case 'last_30d':
        return ['from' => date('Y-m-d', strtotime('-30 days')), 'to' => $today];

      case 'lifetime':
        return ['from' => '2020-01-01', 'to' => $today];

      default:
        return ['from' => $today, 'to' => $today];
    }
  }
}