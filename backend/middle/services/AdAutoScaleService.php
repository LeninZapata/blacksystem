<?php
class AdAutoScaleService {
  private static $logMeta = ['module' => 'AdAutoScaleService', 'layer' => 'middle/services'];

  // Procesar todas las reglas activas
  public function processRule($rule) {
    try {
      $ruleId = $rule['id'];
      $ruleName = $rule['name'];
      $userId = $rule['user_id'];
      $assetId = $rule['ad_assets_id'];

      ogLog::info('processRule - Iniciando', [
        'rule_id' => $ruleId,
        'rule_name' => $ruleName
      ], self::$logMeta);

      // Obtener activo publicitario
      $asset = ogDb::t('product_ad_assets')
        ->where('id', $assetId)
        ->where('is_active', 1)
        ->where('status', 1)
        ->first();

      if (!$asset) {
        ogLog::warning('processRule - Activo no encontrado o inactivo', [
          'asset_id' => $assetId
        ], self::$logMeta);

        return [
          'success' => false,
          'rule_id' => $ruleId,
          'error' => 'Activo no encontrado o inactivo'
        ];
      }

      // Decodificar configuración
      $config = is_string($rule['config'])
        ? json_decode($rule['config'], true)
        : $rule['config'];

      // Obtener bloques de condiciones (solo V2)
      $conditionBlocks = $config['condition_blocks'] ?? [];

      if (empty($conditionBlocks)) {
        ogLog::warning('processRule - Sin bloques de condiciones', [
          'rule_id' => $ruleId
        ], self::$logMeta);

        return [
          'success' => false,
          'rule_id' => $ruleId,
          'error' => 'Regla sin bloques de condiciones'
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
          'conditions_result' => null, // ← No hay condiciones evaluadas
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

      // Evaluar bloques secuencialmente - guardar TODOS los resultados
      $blockExecuted = false;
      $blockExecutedIndex = null;
      $blockExecutedName = null;
      $actionResults = null;
      $blocksEvaluated = [];

      // Iterar bloques hasta que UNO cumpla (break)
      foreach ($conditionBlocks as $idx => $block) {
        $blockName = $block['block_name'] ?? "Bloque " . ($idx + 1);
        
        ogLog::debug('processRule - Evaluando bloque', [
          'rule_id' => $ruleId,
          'block_index' => $idx,
          'block_name' => $blockName
        ], self::$logMeta);

        // Evaluar condiciones del bloque
        $conditionsEval = $this->evaluateConditions($metricsData, $block);

        // Guardar evaluación de este bloque
        $blocksEvaluated[] = [
          'index' => $idx,
          'name' => $blockName,
          'met' => $conditionsEval['met'],
          'evaluation' => $conditionsEval['full_evaluation'] ?? []
        ];

        if ($conditionsEval['met']) {
          // Bloque cumplió - ejecutar sus acciones
          $blockExecutedIndex = $idx;
          $blockExecutedName = $blockName;
          $blockExecuted = true;

          ogLog::info('processRule - Bloque cumplió condiciones', [
            'rule_id' => $ruleId,
            'block_index' => $blockExecutedIndex,
            'block_name' => $blockExecutedName
          ], self::$logMeta);

          // Ejecutar acciones del bloque
          $actionResults = $this->executeActions($asset, $block['actions'] ?? [], $metricsData);

          break; // Solo el PRIMER bloque que cumpla
        }
      }

      // Marcar bloques no evaluados como null (después del break)
      if ($blockExecuted) {
        for ($i = $blockExecutedIndex + 1; $i < count($conditionBlocks); $i++) {
          $blocksEvaluated[] = [
            'index' => $i,
            'name' => $conditionBlocks[$i]['block_name'] ?? "Bloque " . ($i + 1),
            'met' => null,
            'evaluation' => null
          ];
        }
      }

      ogLog::debug('processRule - Evaluación completa', [
        'rule_id' => $ruleId,
        'block_executed' => $blockExecuted,
        'block_index' => $blockExecutedIndex,
        'blocks_evaluated_count' => count($blocksEvaluated)
      ], self::$logMeta);

      // Si no cumplió ningún bloque
      if (!$blockExecuted) {
        // Guardar en historial SIN bloque ejecutado
        $this->saveToHistory([
          'rule_id' => $ruleId,
          'user_id' => $userId,
          'ad_assets_id' => $asset['id'],
          'product_id' => $asset['product_id'],
          'ad_platform' => $asset['ad_platform'],
          'ad_asset_type' => $asset['ad_asset_type'],
          'metrics_snapshot' => json_encode($metricsData['metrics']),
          'time_range' => implode(',', $metricsData['time_ranges']),
          'conditions_met' => 0,
          'conditions_logic' => 'none',
          'conditions_result' => json_encode([
            'result' => false,
            'message' => 'Ningún bloque cumplió condiciones',
            'blocks_evaluated' => $blocksEvaluated
          ], JSON_UNESCAPED_UNICODE),
          'action_executed' => 0,
          'action_type' => null,
          'action_result' => json_encode(['message' => 'Condiciones no cumplidas']),
          'execution_source' => 'cron'
        ]);

        return [
          'success' => true,
          'rule_id' => $ruleId,
          'rule_name' => $ruleName,
          'conditions_met' => false,
          'action_executed' => false,
          'message' => 'Condiciones no cumplidas',
          'blocks_evaluated' => $blocksEvaluated,
          'metrics' => $metricsData['metrics']
        ];
      }

      // SÍ cumplió un bloque - Guardar con información completa
      $this->saveToHistory([
        'rule_id' => $ruleId,
        'user_id' => $userId,
        'ad_assets_id' => $asset['id'],
        'product_id' => $asset['product_id'],
        'ad_platform' => $asset['ad_platform'],
        'ad_asset_type' => $asset['ad_asset_type'],
        'ad_asset_id' => $asset['ad_asset_id'],
        'metrics_snapshot' => json_encode($metricsData['metrics']),
        'time_range' => implode(',', $metricsData['time_ranges']),
        'conditions_met' => 1,
        'conditions_logic' => $blocksEvaluated[$blockExecutedIndex]['evaluation']['logic_type'] ?? 'unknown',
        'conditions_result' => json_encode([
          'result' => true,
          'blocks_evaluated' => $blocksEvaluated,
          'block_executed' => [
            'index' => $blockExecutedIndex,
            'name' => $blockExecutedName
          ]
        ], JSON_UNESCAPED_UNICODE),
        'action_executed' => $actionResults['executed'] ? 1 : 0,
        'action_type' => isset($actionResults['results'][0]) ? $actionResults['results'][0]['action'] : null,
        'action_result' => json_encode($actionResults['results'][0] ?? []),
        'success' => isset($actionResults['results'][0]['success']) ? ($actionResults['results'][0]['success'] ? 1 : 0) : 0,
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

  
  // Procesar todas las reglas activas (llamado por CRON HORARIO - solo "today")
  public function processRules($userId = null) {
    $startTime = microtime(true);
    
    ogLog::info('processRules - Iniciando (HORARIO - solo today)', ['user_id' => $userId], self::$logMeta);

    try {
      // Query de reglas activas
      $query = ogDb::t('ad_auto_scale')
        ->where('is_active', 1)
        ->where('status', 1);

      // Filtrar por usuario si se especifica
      if ($userId) {
        $query = $query->where('user_id', $userId);
      }

      $allRules = $query->get();
      
      // Filtrar solo reglas con time_range = "today"
      $rules = [];
      foreach ($allRules as $rule) {
        $config = is_string($rule['config']) ? json_decode($rule['config'], true) : $rule['config'];
        if (!$this->isHistoricalRule($config)) {
          $rules[] = $rule;
        }
      }

      if (empty($rules)) {
        ogLog::info('processRules - No hay reglas "today" para ejecutar', [], self::$logMeta);
        return [
          'success' => true,
          'rules_processed' => 0,
          'actions_executed' => 0,
          'execution_time' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
        ];
      }

      $rulesProcessed = 0;
      $actionsExecuted = 0;
      $results = [];

      // Procesar cada regla
      foreach ($rules as $rule) {
        $result = $this->processRule($rule);
        
        $rulesProcessed++;
        
        if ($result['success'] && ($result['action_executed'] ?? false)) {
          $actionsExecuted++;
        }

        $results[] = [
          'rule_id' => $rule['id'],
          'rule_name' => $rule['name'],
          'success' => $result['success'],
          'action_executed' => $result['action_executed'] ?? false,
          'message' => $result['message'] ?? ($result['error'] ?? 'OK'),
          'blocks_evaluated' => $result['blocks_evaluated'] ?? null,
          'metrics' => $result['metrics'] ?? null
        ];
      }

      $executionTime = round((microtime(true) - $startTime) * 1000, 2);

      ogLog::success('processRules - Completado (HORARIO)', [
        'rules_processed' => $rulesProcessed,
        'actions_executed' => $actionsExecuted,
        'execution_time' => $executionTime . 'ms'
      ], self::$logMeta);

      return [
        'success' => true,
        'rules_processed' => $rulesProcessed,
        'actions_executed' => $actionsExecuted,
        'execution_time' => $executionTime . 'ms',
        'results' => $results
      ];

    } catch (Exception $e) {
      ogLog::error('processRules - Error', ['error' => $e->getMessage()], self::$logMeta);
      
      return [
        'success' => false,
        'error' => $e->getMessage(),
        'execution_time' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
      ];
    }
  }

  // Procesar reglas históricas (llamado por CRON DIARIO - 2-3 AM)
  public function processHistoricalRules($userId = null) {
    $startTime = microtime(true);
    
    ogLog::info('processHistoricalRules - Iniciando (DIARIO - rangos históricos)', ['user_id' => $userId], self::$logMeta);

    try {
      // Query de reglas activas
      $query = ogDb::t('ad_auto_scale')
        ->where('is_active', 1)
        ->where('status', 1);

      // Filtrar por usuario si se especifica
      if ($userId) {
        $query = $query->where('user_id', $userId);
      }

      $allRules = $query->get();
      
      // Filtrar solo reglas con time_range histórico (NO "today")
      $rules = [];
      foreach ($allRules as $rule) {
        $config = is_string($rule['config']) ? json_decode($rule['config'], true) : $rule['config'];
        if ($this->isHistoricalRule($config)) {
          $rules[] = $rule;
        }
      }

      if (empty($rules)) {
        ogLog::info('processHistoricalRules - No hay reglas históricas para ejecutar', [], self::$logMeta);
        return [
          'success' => true,
          'rules_processed' => 0,
          'actions_executed' => 0,
          'execution_time' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
        ];
      }

      $rulesProcessed = 0;
      $actionsExecuted = 0;
      $results = [];

      // Procesar cada regla
      foreach ($rules as $rule) {
        $result = $this->processRule($rule);
        
        $rulesProcessed++;
        
        if ($result['success'] && ($result['action_executed'] ?? false)) {
          $actionsExecuted++;
        }

        $results[] = [
          'rule_id' => $rule['id'],
          'rule_name' => $rule['name'],
          'success' => $result['success'],
          'action_executed' => $result['action_executed'] ?? false,
          'message' => $result['message'] ?? ($result['error'] ?? 'OK'),
          'blocks_evaluated' => $result['blocks_evaluated'] ?? null,
          'metrics' => $result['metrics'] ?? null
        ];
      }

      $executionTime = round((microtime(true) - $startTime) * 1000, 2);

      ogLog::success('processHistoricalRules - Completado (DIARIO)', [
        'rules_processed' => $rulesProcessed,
        'actions_executed' => $actionsExecuted,
        'execution_time' => $executionTime . 'ms'
      ], self::$logMeta);

      return [
        'success' => true,
        'rules_processed' => $rulesProcessed,
        'actions_executed' => $actionsExecuted,
        'execution_time' => $executionTime . 'ms',
        'results' => $results
      ];

    } catch (Exception $e) {
      ogLog::error('processHistoricalRules - Error', ['error' => $e->getMessage()], self::$logMeta);
      
      return [
        'success' => false,
        'error' => $e->getMessage(),
        'execution_time' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
      ];
    }
  }

  // Guardar en historial
  private function saveToHistory($data) {
    try {
      ogLog::debug('saveToHistory - Intentando guardar', $data, self::$logMeta);
      $data['executed_at'] = date('Y-m-d H:i:s');
      $data['dc'] = date('Y-m-d H:i:s');
      $data['tc'] = time();

      $idInsert = ogDb::t('ad_auto_scale_history')->insert($data);

      ogLog::debug('saveToHistory - Registro guardado', [
        'rule_id' => $data['rule_id'],
        'action_executed' => $data['action_executed'],
        'id_insert' => $idInsert
      ], self::$logMeta);

    } catch (Exception $e) {
      ogLog::error('saveToHistory - Error', ['error' => $e->getMessage(), 'data' => $data], self::$logMeta);
      // Puedes descomentar la siguiente línea para lanzar el error y detener la ejecución si lo deseas
      // throw $e;
    }
  }

  // Obtener métricas del activo (PRIVADO - solo para AdAutoScaleService)
  private function getAssetMetrics($asset, $config) {
    try {
      $productId = $asset['product_id'];
      $assetId = $asset['ad_asset_id'];

      // Obtener todos los time_ranges únicos de las condiciones
      $timeRanges = $this->getAllTimeRanges($config);
      
      // Obtener métricas para cada time_range
      $metricsData = $this->getMetricsForAllTimeRanges($asset, $timeRanges);
      
      if (!$metricsData['success']) {
        return $metricsData;
      }

      return [
        'success' => true,
        'metrics' => $metricsData['metrics'],
        'time_ranges' => $metricsData['time_ranges']
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
      'cpm' => 0,
      'days_available' => 0
    ];

    // HISTÓRICO: días anteriores a hoy (ad_metrics_daily)
    if ($dateFrom < $today) {
      $historicTo = ($dateTo < $today) ? $dateTo : date('Y-m-d', strtotime($today . ' -1 day'));

      // Contar días disponibles en el rango
      $sqlCountDays = "
        SELECT COUNT(DISTINCT metric_date) as days_count
        FROM ad_metrics_daily
        WHERE product_id = ?
          AND ad_asset_id = ?
          AND metric_date >= ?
          AND metric_date <= ?
      ";
      
      $daysCount = ogDb::raw($sqlCountDays, [$productId, $assetId, $dateFrom, $historicTo]);
      $metrics['days_available'] = !empty($daysCount) ? (int)($daysCount[0]['days_count'] ?? 0) : 0;

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

    // HOY: tomar ÚLTIMO snapshot (ya tiene valores acumulados del día)
    if ($dateTo >= $today) {
      $sqlToday = "
        SELECT
          h.spend,
          h.impressions,
          h.reach,
          h.clicks,
          h.results
        FROM ad_metrics_hourly h
        WHERE h.product_id = ?
          AND h.ad_asset_id = ?
          AND h.query_date = ?
        ORDER BY h.query_hour DESC, h.id DESC
        LIMIT 1
      ";

      $todayData = ogDb::raw($sqlToday, [$productId, $assetId, $today]);
      if (!empty($todayData)) {
        $row = $todayData[0];
        $metrics['spend'] += (float)($row['spend'] ?? 0);
        $metrics['impressions'] += (int)($row['impressions'] ?? 0);
        $metrics['reach'] += (int)($row['reach'] ?? 0);
        $metrics['clicks'] += (int)($row['clicks'] ?? 0);
        $metrics['results'] += (int)($row['results'] ?? 0);
        
        // Si incluye hoy, sumar 1 día al conteo
        if ($metrics['spend'] > 0 || $metrics['results'] > 0 || $metrics['impressions'] > 0) {
          $metrics['days_available']++;
        }
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
  // Calcular revenue (ingresos totales)
  private function calculateRevenue($productId, $dateFrom, $dateTo) {
    $sql = "
      SELECT SUM(billed_amount) as total_revenue
      FROM sales
      WHERE product_id = ?
        AND payment_date >= ?
        AND payment_date <= ?
        AND process_status = 'sale_confirmed'
        AND status = 1
    ";

    $result = ogDb::raw($sql, [$productId, $dateFrom, $dateTo . ' 23:59:59']);
    return !empty($result) ? (float)($result[0]['total_revenue'] ?? 0) : 0;
  }

  // Calcular ROAS (mantener por compatibilidad)
  private function calculateROAS($productId, $assetId, $dateFrom, $dateTo, $adSpend) {
    if ($adSpend <= 0) return 0;
    $revenue = $this->calculateRevenue($productId, $dateFrom, $dateTo);
    return $revenue / $adSpend;
  }

  // Calcular métricas de cambio temporal (1h, 2h, 3h)
  private function calculateTemporalChanges($asset) {
    $productId = $asset['product_id'];
    $assetId = $asset['ad_asset_id'];
    $today = date('Y-m-d');
    
    $metrics = [];

    // Usar el snapshot MÁS RECIENTE disponible en BD como ancla,
    // NO date('H'), porque el cron de save-all y execute corren al mismo minuto
    // y puede haber condición de carrera donde el snapshot actual no exista aún.
    $latestRow = ogDb::raw("
      SELECT query_hour
      FROM ad_metrics_hourly
      WHERE product_id = ?
        AND ad_asset_id = ?
        AND query_date = ?
      ORDER BY query_hour DESC, id DESC
      LIMIT 1
    ", [$productId, $assetId, $today]);

    if (empty($latestRow)) {
      // Sin datos hoy aún
      foreach ([1, 2, 3] as $hours) {
        $metrics["roas_change_{$hours}h"] = 0;
        $metrics["profit_change_{$hours}h"] = 0;
      }
      return $metrics;
    }

    $latestHour = (int)$latestRow[0]['query_hour'];

    // Métricas actuales (último snapshot real disponible)
    $current = $this->getMetricsUpToHour($asset, $today, $latestHour);

    // Calcular para 1h, 2h, 3h
    foreach ([1, 2, 3] as $hours) {
      $hoursAgo = $latestHour - $hours;
      
      // Si las horas atrás son negativas, no hay datos suficientes
      if ($hoursAgo < 0) {
        $metrics["roas_change_{$hours}h"] = 0;
        $metrics["profit_change_{$hours}h"] = 0;
        continue;
      }
      
      // Métricas de hace N horas (usando snapshot real de esa hora)
      $past = $this->getMetricsUpToHour($asset, $today, $hoursAgo);
      
      // Calcular cambios
      $roasChange = $current['roas'] - $past['roas'];
      $profitChange = $current['profit'] - $past['profit'];
      
      $metrics["roas_change_{$hours}h"] = round($roasChange, 2);
      $metrics["profit_change_{$hours}h"] = round($profitChange, 2);
    }
    
    return $metrics;
  }

  // Obtener métricas de tiempo (current_hour, current_day_of_week)
  private function getTimeMetrics($asset) {
    try {
      // Obtener timezone del bot (usando el mismo patrón que resetDailyBudgets)
      $productId = $asset['product_id'];
      
      // Obtener bot asociado al producto
      $product = ogDb::t('products')
        ->select('bot_id')
        ->where('id', $productId)
        ->first();
      
      if (!$product || !$product['bot_id']) {
        // Si no hay bot, usar timezone de servidor
        $timezone = date_default_timezone_get();
      } else {
        $bot = ogDb::t('bots')
          ->select('country_code')
          ->where('id', $product['bot_id'])
          ->first();
        
        if (!$bot || !$bot['country_code']) {
          $timezone = date_default_timezone_get();
        } else {
          // Obtener timezone usando ogCountry helper
          ogApp()->loadHelper('country');
          $countryInfo = ogCountry::get($bot['country_code']);
          $timezone = $countryInfo['timezone'] ?? date_default_timezone_get();
        }
      }
      
      // Crear DateTime con la zona horaria del bot
      $datetime = new DateTime('now', new DateTimeZone($timezone));
      
      // Obtener hora (0-23)
      $currentHour = (int)$datetime->format('H');
      
      // Obtener día de la semana (1=Lunes, 7=Domingo) - formato ISO-8601
      $currentDayOfWeek = (int)$datetime->format('N');
      
      return [
        'current_hour' => $currentHour,
        'current_day_of_week' => $currentDayOfWeek
      ];
      
    } catch (Exception $e) {
      ogLog::error('getTimeMetrics - Error', [
        'error' => $e->getMessage(),
        'asset' => $asset
      ], self::$logMeta);
      
      // En caso de error, usar valores por defecto del servidor
      return [
        'current_hour' => (int)date('H'),
        'current_day_of_week' => (int)date('N')
      ];
    }
  }

  // Obtener métricas acumuladas hasta una hora específica
  private function getMetricsUpToHour($asset, $date, $hour) {
    $productId = $asset['product_id'];
    $assetId = $asset['ad_asset_id'];
    
    // Tomar ÚLTIMO snapshot de la hora especificada (ya tiene acumulado)
    $sql = "
      SELECT 
        h.spend,
        h.results
      FROM ad_metrics_hourly h
      WHERE h.product_id = ?
        AND h.ad_asset_id = ?
        AND h.query_date = ?
        AND h.query_hour <= ?
      ORDER BY h.query_hour DESC, h.id DESC
      LIMIT 1
    ";
    
    $result = ogDb::raw($sql, [$productId, $assetId, $date, $hour]);
    
    $spend = !empty($result) ? (float)($result[0]['spend'] ?? 0) : 0;
    $results = !empty($result) ? (int)($result[0]['results'] ?? 0) : 0;
    
    // Calcular revenue y profit hasta esa hora
    $dateTimeEnd = $date . ' ' . str_pad($hour, 2, '0', STR_PAD_LEFT) . ':59:59';
    $revenue = $this->calculateRevenue($productId, $date . ' 00:00:00', $dateTimeEnd);
    $roas = $spend > 0 ? $revenue / $spend : 0;
    $profit = $revenue - $spend;
    
    return [
      'spend' => $spend,
      'revenue' => $revenue,
      'roas' => $roas,
      'profit' => $profit
    ];
  }

  // Evaluar condiciones con ogLogic
  // Ahora recibe el bloque completo en lugar de config completo
  private function evaluateConditions($metrics, $block) {
    $conditionGroups = $block['condition_groups'] ?? [];
    $conditionsLogic = $block['conditions_logic'] ?? 'and_or_and';

    if (empty($conditionGroups)) {
      ogLog::warning('evaluateConditions - Sin grupos de condiciones', [], self::$logMeta);
      return [
        'met' => false,
        'groups_met' => [],
        'logic_type' => $conditionsLogic
      ];
    }

    $metricsData = $metrics['metrics'] ?? [];
    $groupsMet = [];

    // Construir lógica según tipo
    if ($conditionsLogic === 'and_or_and') {
      // (AND) or (AND) - Grupos unidos por OR
      $orGroups = [];

      foreach ($conditionGroups as $groupIndex => $group) {
        $conditions = $group['conditions'] ?? [];
        if (empty($conditions)) continue;

        $andConditions = $this->buildGroupConditions($conditions);
        if (empty($andConditions)) continue;

        $orGroups[] = ['and' => $andConditions];
      }

      if (empty($orGroups)) {
        return ['met' => false, 'groups_met' => [], 'logic_type' => $conditionsLogic];
      }

      // Evaluar con ogLogic::evaluate
      $logic = ['or' => $orGroups];
      $evaluation = ogApp()->helper('logic')::evaluate($logic, $metricsData);

      // Procesar matched_rules para extraer qué grupos cumplieron
      foreach ($evaluation['matched_rules'] as $match) {
        $groupIndex = $match['index'] ?? null;
        if ($groupIndex === null) continue;

        $group = $conditionGroups[$groupIndex] ?? null;
        if (!$group) continue;

        $groupsMet[] = [
          'group_index' => $groupIndex + 1,
          'logic' => 'AND',
          'conditions' => $group['conditions'] ?? [],
          'metrics_evaluated' => $this->extractMetricsFromMatch($match['details'] ?? [], $metricsData)
        ];
      }

      return [
        'met' => $evaluation['result'],
        'groups_met' => $groupsMet,
        'logic_type' => $conditionsLogic,
        'full_evaluation' => [
          'result' => $evaluation['result'],
          'logic_type' => $conditionsLogic,
          'details' => $evaluation['details'] ?? [],
          'matched_rules' => $evaluation['matched_rules'] ?? [],
          'groups_met' => $groupsMet
        ]
      ];

    } elseif ($conditionsLogic === 'or_and_or') {
      // (OR) and (OR) - Grupos unidos por AND
      $andGroups = [];

      foreach ($conditionGroups as $groupIndex => $group) {
        $conditions = $group['conditions'] ?? [];
        if (empty($conditions)) continue;

        $orConditions = $this->buildGroupConditions($conditions);
        if (empty($orConditions)) continue;

        $andGroups[] = ['or' => $orConditions];
      }

      if (empty($andGroups)) {
        return ['met' => false, 'groups_met' => [], 'logic_type' => $conditionsLogic];
      }

      // Evaluar con ogLogic::evaluate
      $logic = ['and' => $andGroups];
      $evaluation = ogApp()->helper('logic')::evaluate($logic, $metricsData);

      // Para AND, todos los grupos deben cumplir
      if ($evaluation['result']) {
        foreach ($conditionGroups as $groupIndex => $group) {
          $groupsMet[] = [
            'group_index' => $groupIndex + 1,
            'logic' => 'OR',
            'conditions' => $group['conditions'] ?? [],
            'metrics_evaluated' => $this->formatMetricsForGroup($metricsData, $group['conditions'] ?? [])
          ];
        }
      }

      return [
        'met' => $evaluation['result'],
        'groups_met' => $groupsMet,
        'logic_type' => $conditionsLogic,
        'full_evaluation' => [
          'result' => $evaluation['result'],
          'logic_type' => $conditionsLogic,
          'details' => $evaluation['details'] ?? [],
          'matched_rules' => $evaluation['matched_rules'] ?? [],
          'groups_met' => $groupsMet
        ]
      ];

    } else {
      ogLog::error('evaluateConditions - Lógica desconocida', ['logic' => $conditionsLogic], self::$logMeta);
      return [
        'met' => false,
        'groups_met' => [],
        'logic_type' => $conditionsLogic,
        'full_evaluation' => [
          'result' => false,
          'details' => [],
          'matched_rules' => [],
          'groups_met' => [],
          'error' => 'Lógica desconocida'
        ]
      ];
    }
  }

  // Construir condiciones de un grupo
  private function buildGroupConditions($conditions) {
    $logicConditions = [];

    // Métricas que NO requieren time_range suffix
    $timeMetrics = ['current_hour', 'current_day_of_week'];
    
    // Métricas de cambio temporal que YA son específicas de hoy (no necesitan sufijo)
    $temporalChangeMetrics = [
      'roas_change_1h', 'roas_change_2h', 'roas_change_3h',
      'profit_change_1h', 'profit_change_2h', 'profit_change_3h'
    ];

    foreach ($conditions as $condition) {
      $metric = $condition['metric'] ?? null;
      $operator = $condition['operator'] ?? null;
      $timeRange = $condition['time_range'] ?? 'today';

      if (!$metric || !$operator) continue;

      // Determinar qué campo leer para el valor
      if ($metric === 'current_hour') {
        $value = $condition['value_hour'] ?? null;
      } elseif ($metric === 'current_day_of_week') {
        $value = $condition['value_day'] ?? null;
      } else {
        $value = $condition['value'] ?? null;
      }

      if ($value === null) continue;

      // Determinar el nombre de la variable
      if (in_array($metric, $timeMetrics)) {
        // Métricas de tiempo: sin sufijo
        $varName = $metric;
      } elseif (in_array($metric, $temporalChangeMetrics)) {
        // Métricas de cambio temporal: sin sufijo (ya son específicas de hoy)
        $varName = $metric;
      } else {
        // Resto de métricas: agregar sufijo time_range
        $varName = $metric . '_' . $timeRange;
      }

      // Construir condición ogLogic
      $logicConditions[] = [
        $operator => [
          ['var' => $varName],
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

    // Verificar cooldown period antes de ejecutar
    $timePeriod = $action['time_period'] ?? 'everytime';
    if (!$this->canExecuteAction($asset, $action, $timePeriod)) {
      return [
        'success' => false,
        'action' => $actionType,
        'message' => 'Acción en período de cooldown',
        'skipped' => true
      ];
    }

    try {
      switch ($actionType) {
        case 'increase_budget':
        case 'decrease_budget':
          return $this->adjustBudget($asset, $action, $actionType);

        case 'adjust_to_spend':
          return $this->adjustToSpend($asset, $action, $metrics);

        case 'pause':
          return $this->pauseAsset($asset);

        case 'disable_product':
          return $this->disableProduct($action);

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

  // Verificar si se puede ejecutar acción según cooldown
  private function canExecuteAction($asset, $action, $timePeriod) {
    // everytime siempre se ejecuta
    if ($timePeriod === 'everytime') {
      return true;
    }

    $assetId = $asset['ad_asset_id'];
    $actionType = $action['action_type'];
    $ruleId = $asset['rule_id'] ?? 0; // Necesitamos el rule_id del contexto

    // Obtener última ejecución exitosa de esta acción para este activo
    // Intentar con columna 'success' si existe, sino usar action_executed
    $query = ogDb::t('ad_auto_scale_history')
      ->select('executed_at')
      ->where('ad_asset_id', $assetId)
      ->where('action_executed', 1)
      ->where('action_type', $actionType);

    // Intentar filtrar por success = 1 si la columna existe
    try {
      $lastExecution = $query->where('success', 1)
        ->orderBy('id', 'DESC')
        ->first();
    } catch (Exception $e) {
      // Si falla (columna no existe), buscar cualquier acción ejecutada
      $lastExecution = ogDb::t('ad_auto_scale_history')
        ->select('executed_at')
        ->where('ad_asset_id', $assetId)
        ->where('action_executed', 1)
        ->where('action_type', $actionType)
        ->orderBy('id', 'DESC')
        ->first();
    }

    if (!$lastExecution) {
      // Primera ejecución, permitir
      return true;
    }

    $lastExecutedAt = strtotime($lastExecution['executed_at']);
    $now = time();
    $elapsedHours = ($now - $lastExecutedAt) / 3600;

    // Verificar según el period
    switch ($timePeriod) {
      case 'once':
        // Si ya se ejecutó una vez, no ejecutar de nuevo
        return false;

      case 'daily':
        // Verificar que haya pasado al menos 24 horas
        return $elapsedHours >= 24;

      case 'every_2h':
        // Verificar que hayan pasado al menos 2 horas
        return $elapsedHours >= 2;

      case 'every_3h':
        // Verificar que hayan pasado al menos 3 horas
        return $elapsedHours >= 3;

      case 'every_6h':
        // Verificar que hayan pasado al menos 6 horas
        return $elapsedHours >= 6;

      default:
        return true;
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

  // Ajustar presupuesto al gasto actual
  private function adjustToSpend($asset, $action, $metrics) {
    $platform = $asset['ad_platform'];
    $assetId = $asset['ad_asset_id'];
    $userId = $asset['user_id'];

    // Normalizar métricas: pueden venir como $metricsData completo o como array plano
    $metricsValues = isset($metrics['metrics']) ? $metrics['metrics'] : $metrics;

    // Obtener gasto actual desde las métricas (spend_today)
    $currentSpend = isset($metricsValues['spend_today']) ? (float)$metricsValues['spend_today'] : 0;

    if ($currentSpend <= 0) {
      return [
        'success' => false,
        'action' => 'adjust_to_spend',
        'error' => 'No hay gasto registrado hoy para este activo'
      ];
    }

    // Obtener tipo y valor de ajuste
    $adjustmentType = $action['adjustment_type'] ?? 'add'; // add o subtract
    $adjustmentValue = (float)($action['adjustment_value'] ?? 0);

    // Calcular nuevo presupuesto
    if ($adjustmentType === 'subtract') {
      $newBudget = $currentSpend - $adjustmentValue;
    } else {
      $newBudget = $currentSpend + $adjustmentValue;
    }

    // El presupuesto no puede ser menor a 1
    $newBudget = max($newBudget, 1);

    // Obtener presupuesto actual del provider para comparar
    $provider = $this->getAdProvider($platform, $userId);
    if (!$provider) {
      return [
        'success' => false,
        'action' => 'adjust_to_spend',
        'error' => "Provider no disponible para {$platform}"
      ];
    }

    $currentBudget = $provider->getBudget($assetId, $asset['ad_asset_type']);
    if (!$currentBudget['success']) {
      return [
        'success' => false,
        'action' => 'adjust_to_spend',
        'error' => 'No se pudo obtener presupuesto actual'
      ];
    }

    $budget = $currentBudget['budget'];

    // Si no hay cambio significativo, no hacer nada
    if (abs($newBudget - $budget) < 0.01) {
      return [
        'success' => true,
        'action' => 'adjust_to_spend',
        'message' => 'Presupuesto ya está ajustado al gasto',
        'budget_before' => $budget,
        'budget_after' => $newBudget,
        'current_spend' => $currentSpend,
        'changed' => false
      ];
    }

    // Actualizar presupuesto (siempre daily para adjust_to_spend)
    $updateResult = $provider->updateBudget($assetId, $asset['ad_asset_type'], $newBudget, 'daily');

    if (!$updateResult['success']) {
      return [
        'success' => false,
        'action' => 'adjust_to_spend',
        'error' => $updateResult['error'] ?? 'Error al actualizar presupuesto'
      ];
    }

    ogLog::success('adjustToSpend - Presupuesto ajustado al gasto', [
      'asset_id' => $assetId,
      'platform' => $platform,
      'current_spend' => $currentSpend,
      'adjustment_type' => $adjustmentType,
      'adjustment_value' => $adjustmentValue,
      'budget_before' => $budget,
      'budget_after' => $newBudget,
      'change' => $newBudget - $budget
    ], self::$logMeta);

    return [
      'success' => true,
      'action' => 'adjust_to_spend',
      'budget_before' => $budget,
      'budget_after' => $newBudget,
      'current_spend' => $currentSpend,
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

  // Desactivar producto
  private function disableProduct($action) {
    $productId = $action['product_id'] ?? null;

    if (!$productId) {
      return [
        'success' => false,
        'action' => 'disable_product',
        'error' => 'product_id no especificado'
      ];
    }

    try {
      // Verificar que el producto existe
      $product = ogDb::t('products')->find($productId);
      if (!$product) {
        return [
          'success' => false,
          'action' => 'disable_product',
          'error' => "Producto {$productId} no encontrado"
        ];
      }

      // Desactivar producto (status = 0)
      $updated = ogDb::t('products')
        ->where('id', $productId)
        ->update(['status' => 0]);

      if ($updated) {
        ogLog::success('disableProduct - Producto desactivado', [
          'product_id' => $productId,
          'product_name' => $product['name'] ?? ''
        ], self::$logMeta);

        return [
          'success' => true,
          'action' => 'disable_product',
          'product_id' => $productId,
          'product_name' => $product['name'] ?? '',
          'disabled' => true
        ];
      } else {
        return [
          'success' => false,
          'action' => 'disable_product',
          'error' => 'No se pudo actualizar el producto'
        ];
      }

    } catch (Exception $e) {
      ogLog::error('disableProduct - Error', [
        'product_id' => $productId,
        'error' => $e->getMessage()
      ], self::$logMeta);

      return [
        'success' => false,
        'action' => 'disable_product',
        'error' => $e->getMessage()
      ];
    }
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
  // Obtener todos los time_ranges únicos
  private function getAllTimeRanges($config) {
    $timeRanges = [];
    $conditionBlocks = $config['condition_blocks'] ?? [];

    foreach ($conditionBlocks as $block) {
      $conditionGroups = $block['condition_groups'] ?? [];
      
      foreach ($conditionGroups as $group) {
        $conditions = $group['conditions'] ?? [];
        foreach ($conditions as $condition) {
          $timeRange = $condition['time_range'] ?? null;
          if ($timeRange && !in_array($timeRange, $timeRanges)) {
            $timeRanges[] = $timeRange;
          }
        }
      }
    }

    return !empty($timeRanges) ? $timeRanges : ['today'];
  }

  // Obtener métricas para todos los time_ranges
  private function getMetricsForAllTimeRanges($asset, $timeRanges) {
    $productId = $asset['product_id'];
    $assetId = $asset['ad_asset_id'];
    $allMetrics = [];
    
    foreach ($timeRanges as $timeRange) {
      $dates = $this->getDateRangeFromTimeRange($timeRange);
      $adMetrics = $this->getAdMetrics($asset, $dates['from'], $dates['to']);
      
      // VALIDACIÓN 1: Verificar que haya datos
      if ($adMetrics['spend'] <= 0 && $adMetrics['results'] <= 0 && $adMetrics['impressions'] <= 0) {
        return [
          'success' => false,
          'error' => "Sin datos de métricas para el rango {$timeRange} ({$dates['from']} - {$dates['to']})"
        ];
      }
      
      // VALIDACIÓN 2: Días completos requeridos
      $requiredDays = $this->getRequiredDaysForTimeRange($timeRange);
      if ($requiredDays > 0 && $adMetrics['days_available'] < $requiredDays) {
        return [
          'success' => false,
          'error' => "Datos insuficientes para {$timeRange}: se requieren {$requiredDays} días, hay {$adMetrics['days_available']}"
        ];
      }
      
      // VALIDACIÓN 3: Mínimo de actividad
      if ($adMetrics['results'] < 2) {
        return [
          'success' => false,
          'error' => "Actividad insuficiente en {$timeRange}: {$adMetrics['results']} resultados (mínimo: 2)"
        ];
      }
      
      // Calcular ROAS y revenue personalizado
      $revenue = $this->calculateRevenue($productId, $dates['from'], $dates['to']);
      $roas = $adMetrics['spend'] > 0 ? $revenue / $adMetrics['spend'] : 0;
      $profit = $revenue - $adMetrics['spend'];
      
      // Almacenar métricas con sufijo de time_range
      $suffix = '_' . $timeRange;
      $allMetrics['roas' . $suffix] = round($roas, 2);
      $allMetrics['profit' . $suffix] = round($profit, 2);
      $allMetrics['cost_per_result' . $suffix] = round($adMetrics['results'] > 0 ? $adMetrics['spend'] / $adMetrics['results'] : 0, 2);
      $allMetrics['frequency' . $suffix] = round($adMetrics['reach'] > 0 ? $adMetrics['impressions'] / $adMetrics['reach'] : 0, 2);
      $allMetrics['spend' . $suffix] = $adMetrics['spend'];
      $allMetrics['results' . $suffix] = $adMetrics['results'];
      $allMetrics['impressions' . $suffix] = $adMetrics['impressions'];
      $allMetrics['reach' . $suffix] = $adMetrics['reach'];
      $allMetrics['clicks' . $suffix] = $adMetrics['clicks'];
      $allMetrics['ctr' . $suffix] = $adMetrics['ctr'];
      $allMetrics['cpc' . $suffix] = $adMetrics['cpc'];
      $allMetrics['cpm' . $suffix] = $adMetrics['cpm'];
    }
    
    // Agregar métricas de cambio temporal (1h, 2h, 3h) solo si es 'today'
    if (in_array('today', $timeRanges)) {
      $temporalMetrics = $this->calculateTemporalChanges($asset);
      $allMetrics = array_merge($allMetrics, $temporalMetrics);
    }
    
    // Agregar métricas de tiempo (current_hour, current_day_of_week)
    $timeMetrics = $this->getTimeMetrics($asset);
    $allMetrics = array_merge($allMetrics, $timeMetrics);
    
    return [
      'success' => true,
      'metrics' => $allMetrics,
      'time_ranges' => $timeRanges
    ];
  }

  // Obtener días requeridos para un time_range
  private function getRequiredDaysForTimeRange($timeRange) {
    switch ($timeRange) {
      case 'today':
        return 0; // No requiere validación de días completos
      
      case 'yesterday':
        return 1;
      
      case 'last_3d':
        return 3;
      
      case 'last_7d':
        return 7;
      
      case 'last_14d':
        return 14;
      
      case 'last_30d':
        return 30;
      
      case 'lifetime':
        return 0; // Lifetime no requiere días exactos
      
      default:
        return 0;
    }
  }

  // Verificar si una regla usa rangos históricos (no "today")
  private function isHistoricalRule($config) {
    $conditionBlocks = $config['condition_blocks'] ?? [];

    foreach ($conditionBlocks as $block) {
      $conditionGroups = $block['condition_groups'] ?? [];
      
      foreach ($conditionGroups as $group) {
        $conditions = $group['conditions'] ?? [];
        foreach ($conditions as $condition) {
          $timeRange = $condition['time_range'] ?? 'today';
          // Si alguna condición NO es 'today', es histórica
          if ($timeRange !== 'today') {
            return true;
          }
        }
      }
    }

    return false;
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

  private function extractMetricName($condition) {
    if (!is_array($condition)) {
      return null;
    }

    // Buscar recursivamente {"var": "nombre_metrica"}
    foreach ($condition as $key => $value) {
      if ($key === 'var' && is_string($value)) {
        return $value;
      }

      if (is_array($value)) {
        $found = $this->extractMetricName($value);
        if ($found) {
          return $found;
        }
      }
    }

    return null;
  }

  private function evaluateSingleCondition($metricValue, $operator, $threshold) {
    if ($operator === null || $threshold === null) return false;

    $threshold = (float)$threshold;
    $metricValue = (float)$metricValue;

    switch ($operator) {
      case '>':
        return $metricValue > $threshold;
      case '>=':
        return $metricValue >= $threshold;
      case '<':
        return $metricValue < $threshold;
      case '<=':
        return $metricValue <= $threshold;
      case '==':
        return abs($metricValue - $threshold) < 0.0001;
      case '!=':
        return abs($metricValue - $threshold) >= 0.0001;
      default:
        return false;
    }
  }


  private function extractMetricsFromMatch($matchDetails, $metricsData) {
    $metrics = [];

    if (!is_array($matchDetails)) {
      return $metrics;
    }

    foreach ($matchDetails as $detail) {
      // Si es una condición directa con operator
      if (isset($detail['details']['operator'])) {
        $detailInfo = $detail['details'];
        $operator = $detailInfo['operator'];
        $left = $detailInfo['left'] ?? null;
        $right = $detailInfo['right'] ?? null;
        $met = $detailInfo['met'] ?? false;

        // Intentar extraer nombre de métrica desde la condición
        $condition = $detail['condition'] ?? [];
        $metricName = $this->extractMetricName($condition);

        if ($metricName) {
          $metrics[$metricName] = [
            'value' => round((float)$left, 2),
            'operator' => $operator,
            'threshold' => round((float)$right, 2),
            'met' => $met
          ];
        }
      }

      // Recursivo para condiciones anidadas
      if (isset($detail['details']) && is_array($detail['details'])) {
        $nested = $this->extractMetricsFromMatch($detail['details'], $metricsData);
        $metrics = array_merge($metrics, $nested);
      }
    }

    return $metrics;
  }

  private function formatMetricsForGroup($metricsData, $conditions) {
    $formatted = [];

    foreach ($conditions as $condition) {
      $metric = $condition['metric'] ?? null;
      $operator = $condition['operator'] ?? null;
      $threshold = $condition['value'] ?? null;

      if (!$metric || !isset($metricsData[$metric])) continue;

      $metricValue = $metricsData[$metric];
      $conditionMet = $this->evaluateSingleCondition($metricValue, $operator, $threshold);

      $formatted[$metric] = [
        'value' => round((float)$metricValue, 2),
        'operator' => $operator,
        'threshold' => round((float)$threshold, 2),
        'met' => $conditionMet
      ];
    }

    return $formatted;
  }
}