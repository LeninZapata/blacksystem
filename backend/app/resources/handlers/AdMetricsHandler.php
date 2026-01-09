<?php
class adMetricsHandler {
  private static $logMeta = ['module' => 'adMetricsHandler', 'layer' => 'app/resources'];

  // Obtener métricas por product_id
  static function getByProduct($params) {
    $productId = $params['product_id'];

    // Default: HOY (para uso normal)
    $today = date('Y-m-d');

    // Priorizar $params, luego $_GET, por defecto HOY
    $dateFrom = $params['date_from'] ?? ogRequest::query('date_from', $today);
    $dateTo = $params['date_to'] ?? ogRequest::query('date_to', $today);

    try {
      $service = ogApp()->service('AdMetrics');
      return $service->getProductMetrics($productId, $dateFrom, $dateTo);
    } catch (Exception $e) {
      ogLog::error('getByProduct - Error', [
        'error' => $e->getMessage(),
        'product_id' => $productId
      ], self::$logMeta);

      return [
        'success' => false,
        'error' => $e->getMessage()
      ];
    }
  }

  // Obtener métricas por assets específicos (query params)
  static function getByAssets($params) {
    // Priorizar $params, luego $_GET
    $dateFrom = $params['date_from'] ?? ogRequest::query('date_from');
    $dateTo = $params['date_to'] ?? ogRequest::query('date_to');

    // Recibir assets como JSON en query
    $assetsJson = ogRequest::query('assets');

    if (!$assetsJson) {
      return [
        'success' => false,
        'error' => 'Parámetro "assets" requerido (JSON array)'
      ];
    }

    $assets = json_decode($assetsJson, true);

    if (!is_array($assets)) {
      return [
        'success' => false,
        'error' => 'Formato de "assets" inválido. Debe ser un array JSON'
      ];
    }

    try {
      $service = ogApp()->service('adMetrics');
      return $service->getMetricsByAssets($assets, $dateFrom, $dateTo);
    } catch (Exception $e) {
      ogLog::error('getByAssets - Error', [
        'error' => $e->getMessage()
      ], self::$logMeta);

      return [
        'success' => false,
        'error' => $e->getMessage()
      ];
    }
  }

  // Obtener métricas de prueba (usando datos del script)
  static function getTestMetrics($params) {
    $dateFrom = ogRequest::query('date_from', date('Y-m-d'));
    $dateTo = ogRequest::query('date_to', date('Y-m-d'));

    // Assets de prueba del script compartido
    $testAssets = [
      [
        'ad_asset_id' => '120232490086400388',
        'ad_asset_type' => 'adset',
        'ad_platform' => 'facebook',
        'user_id' => 3
      ],
      [
        'ad_asset_id' => '120228282605480129',
        'ad_asset_type' => 'adset',
        'ad_platform' => 'facebook',
        'user_id' => 3
      ]
    ];

    try {
      $service = ogApp()->service('adMetrics');
      return $service->getAssetsMetrics($testAssets, $dateFrom, $dateTo);
    } catch (Exception $e) {
      ogLog::error('getTestMetrics - Error', ['error' => $e->getMessage()], self::$logMeta);

      return [
        'success' => false,
        'error' => $e->getMessage()
      ];
    }
  }
  // Guardar métricas de todos los productos activos con activos activos
  // Ejecutado por CRON cada hora
  static function saveAllMetrics($params = []) {
    $logMeta = ['module' => 'AdMetricsHandler', 'layer' => 'app/resources', 'method' => 'saveAllMetrics'];

    try {
      $startTime = microtime(true);
      $hourNow = date('G'); // Hora actual 0-23
      $dateNow = date('Y-m-d');

      // 1. Obtener todos los productos activos
      $products = ogDb::table('products')
        ->where('status', 1)
        ->get();

      if (empty($products)) {
        ogLog::info('saveAllMetrics - No hay productos activos', [], $logMeta);
        return [
          'success' => true,
          'message' => 'No hay productos activos',
          'products_processed' => 0,
          'assets_saved' => 0
        ];
      }

      $productsProcessed = 0;
      $assetsSaved = 0;
      $errors = [];

      // 2. Por cada producto, obtener sus activos activos
      foreach ($products as $product) {
        $productId = $product['id'];
        $userId = $product['user_id'];

        // Obtener activos activos del producto
        $assets = ogDb::table('product_ad_assets')
          ->where('product_id', $productId)
          ->where('is_active', 1)
          ->get();

        if (empty($assets)) {
          ogLog::info('saveAllMetrics - Producto sin activos activos', [
            'product_id' => $productId,
            'product_name' => $product['name']
          ], $logMeta);
          continue;
        }

        // 3. Obtener métricas del producto
        $metricsResult = self::getByProduct([
          'product_id' => $productId,
          'date_from' => $dateNow,
          'date_to' => $dateNow
        ]);

        if (!$metricsResult['success']) {
          $errors[] = [
            'product_id' => $productId,
            'product_name' => $product['name'],
            'error' => 'Error al obtener métricas'
          ];
          continue;
        }

        // 4. Guardar métricas por activo
        if (!empty($metricsResult['by_asset'])) {
          foreach ($metricsResult['by_asset'] as $assetData) {
            if (!$assetData['success'] || !$assetData['has_data']) {
              continue;
            }

            $assetId = $assetData['asset_id'];
            $assetType = $assetData['asset_type'];
            $platform = $assetData['provider'];
            $metrics = $assetData['metrics'];

            // Buscar info del activo en product_ad_assets
            $assetInfo = null;
            foreach ($assets as $asset) {
              if ($asset['ad_asset_id'] === $assetId) {
                $assetInfo = $asset;
                break;
              }
            }

            // 4.1 Marcar registros anteriores como no-latest
            ogDb::table('ad_metrics_hourly')
              ->where('product_id', $productId)
              ->where('ad_asset_id', $assetId)
              ->update(['is_latest' => 0]);

            // 4.2 Insertar nuevo registro como latest
            $insertData = [
              'user_id' => $userId,
              'product_id' => $productId,
              'ad_platform' => $platform,
              'ad_asset_type' => $assetType,
              'ad_asset_id' => $assetId,
              'ad_asset_name' => $assetInfo['ad_asset_name'] ?? null,
              'spend' => $metrics['spend'] ?? 0,
              'impressions' => $metrics['impressions'] ?? 0,
              'reach' => $metrics['reach'] ?? 0,
              'clicks' => $metrics['clicks'] ?? 0,
              'link_clicks' => $metrics['link_clicks'] ?? 0,
              'page_views' => $metrics['page_views'] ?? 0,
              'view_content' => $metrics['view_content'] ?? 0,
              'add_to_cart' => $metrics['add_to_cart'] ?? 0,
              'initiate_checkout' => $metrics['initiate_checkout'] ?? 0,
              'add_payment_info' => $metrics['add_payment_info'] ?? 0,
              'purchase' => $metrics['purchase'] ?? 0,
              'lead' => $metrics['lead'] ?? 0,
              'purchase_value' => $metrics['purchase_value'] ?? 0,
              'conversions' => $metrics['conversions'] ?? 0,
              'results' => $metrics['results'] ?? 0,
              'cpm' => $metrics['cpm'] ?? null,
              'cpc' => $metrics['cpc'] ?? null,
              'ctr' => $metrics['ctr'] ?? null,
              'conversion_rate' => $metrics['conversion_rate'] ?? null,
              'query_date' => $dateNow,
              'query_hour' => $hourNow,
              'api_response_time' => null,
              'api_status' => 'success',
              'is_latest' => 1,
              'dc' => date('Y-m-d H:i:s'),
              'tc' => time()
            ];

            ogDb::table('ad_metrics_hourly')->insert($insertData);
            $assetsSaved++;

            ogLog::info('saveAllMetrics - Métrica guardada', [
              'product_id' => $productId,
              'asset_id' => $assetId,
              'spend' => $metrics['spend'],
              'impressions' => $metrics['impressions']
            ], $logMeta);
          }
        }

        $productsProcessed++;
      }

      // 5. Limpiar registros antiguos (>30 días)
      $thirtyDaysAgo = date('Y-m-d', strtotime('-30 days'));
      $deleted = ogDb::table('ad_metrics_hourly')
        ->where('query_date', '<', $thirtyDaysAgo)
        ->delete();

      $executionTime = round((microtime(true) - $startTime) * 1000, 2);

      ogLog::success('saveAllMetrics - Proceso completado', [
        'products_total' => count($products),
        'products_processed' => $productsProcessed,
        'assets_saved' => $assetsSaved,
        'records_deleted' => $deleted,
        'execution_time_ms' => $executionTime,
        'errors_count' => count($errors)
      ], $logMeta);

      return [
        'success' => true,
        'products_total' => count($products),
        'products_processed' => $productsProcessed,
        'assets_saved' => $assetsSaved,
        'records_deleted' => $deleted,
        'execution_time_ms' => $executionTime,
        'errors' => $errors
      ];

    } catch (Exception $e) {
      ogLog::error('saveAllMetrics - Error crítico', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
      ], $logMeta);

      return [
        'success' => false,
        'error' => $e->getMessage()
      ];
    }
  }

  // Guardar métricas diarias del DÍA ANTERIOR de todos los productos activos
  // Ejecutado por CRON varias veces (1am, 3am, etc.) pero solo inserta si no existen
  static function saveDailyMetrics($params = []) {
    $logMeta = ['module' => 'AdMetricsHandler', 'layer' => 'app/resources', 'method' => 'saveDailyMetrics'];

    try {
      $startTime = microtime(true);

      // Fecha del día ANTERIOR
      $yesterday = date('Y-m-d', strtotime('-1 day'));

      ogLog::info('saveDailyMetrics - Iniciando proceso', [
        'date_to_process' => $yesterday
      ], $logMeta);

      // 1. Obtener todos los productos activos
      $products = ogDb::table('products')
        ->where('status', 1)
        ->get();

      if (empty($products)) {
        ogLog::info('saveDailyMetrics - No hay productos activos', [], $logMeta);
        return [
          'success' => true,
          'message' => 'No hay productos activos',
          'date_processed' => $yesterday,
          'products_processed' => 0,
          'assets_saved' => 0,
          'assets_skipped' => 0
        ];
      }

      $productsProcessed = 0;
      $assetsSaved = 0;
      $assetsSkipped = 0;
      $errors = [];

      // 2. Por cada producto, obtener sus activos activos
      foreach ($products as $product) {
        $productId = $product['id'];
        $userId = $product['user_id'];

        try {
          // Obtener activos activos del producto
          $assets = ogDb::table('product_ad_assets')
            ->where('product_id', $productId)
            ->where('is_active', 1)
            ->get();

          if (empty($assets)) {
            ogLog::info('saveDailyMetrics - Producto sin activos activos', [
              'product_id' => $productId,
              'product_name' => $product['name']
            ], $logMeta);
            continue;
          }

          // 3. Obtener métricas del día anterior
          $metricsResult = self::getByProduct([
            'product_id' => $productId,
            'date_from' => $yesterday,
            'date_to' => $yesterday
          ]);

          if (!$metricsResult['success']) {
            $errors[] = [
              'product_id' => $productId,
              'product_name' => $product['name'],
              'error' => 'Error al obtener métricas del día anterior'
            ];
            ogLog::error('saveDailyMetrics - Error al obtener métricas', [
              'product_id' => $productId,
              'date' => $yesterday
            ], $logMeta);
            continue; // Continuar con el siguiente producto
          }

          // 4. Guardar métricas por activo (solo si no existen)
          if (!empty($metricsResult['by_asset'])) {
            foreach ($metricsResult['by_asset'] as $assetData) {
              if (!$assetData['success'] || !$assetData['has_data']) {
                continue;
              }

              $assetId = $assetData['asset_id'];
              $assetType = $assetData['asset_type'];
              $platform = $assetData['provider'];
              $metrics = $assetData['metrics'];

              // Buscar info del activo en product_ad_assets
              $assetInfo = null;
              foreach ($assets as $asset) {
                if ($asset['ad_asset_id'] === $assetId) {
                  $assetInfo = $asset;
                  break;
                }
              }

              // 4.1 VERIFICAR SI YA EXISTE este registro (idempotencia)
              $existing = ogDb::table('ad_metrics_daily')
                ->where('product_id', $productId)
                ->where('ad_asset_id', $assetId)
                ->where('metric_date', $yesterday)
                ->first();

              if ($existing) {
                ogLog::info('saveDailyMetrics - Registro ya existe, omitiendo', [
                  'product_id' => $productId,
                  'asset_id' => $assetId,
                  'date' => $yesterday
                ], $logMeta);
                $assetsSkipped++;
                continue; // Ya existe, no insertar
              }

              // 4.2 Insertar nuevo registro SOLO si no existe
              $insertData = [
                'user_id' => $userId,
                'product_id' => $productId,
                'ad_platform' => $platform,
                'ad_asset_type' => $assetType,
                'ad_asset_id' => $assetId,
                'ad_asset_name' => $assetInfo['ad_asset_name'] ?? null,
                'spend' => $metrics['spend'] ?? 0,
                'impressions' => $metrics['impressions'] ?? 0,
                'reach' => $metrics['reach'] ?? 0,
                'clicks' => $metrics['clicks'] ?? 0,
                'link_clicks' => $metrics['link_clicks'] ?? 0,
                'page_views' => $metrics['page_views'] ?? 0,
                'view_content' => $metrics['view_content'] ?? 0,
                'add_to_cart' => $metrics['add_to_cart'] ?? 0,
                'initiate_checkout' => $metrics['initiate_checkout'] ?? 0,
                'add_payment_info' => $metrics['add_payment_info'] ?? 0,
                'purchase' => $metrics['purchase'] ?? 0,
                'lead' => $metrics['lead'] ?? 0,
                'purchase_value' => $metrics['purchase_value'] ?? 0,
                'conversions' => $metrics['conversions'] ?? 0,
                'results' => $metrics['results'] ?? 0,
                'cpm' => $metrics['cpm'] ?? null,
                'cpc' => $metrics['cpc'] ?? null,
                'ctr' => $metrics['ctr'] ?? null,
                'conversion_rate' => $metrics['conversion_rate'] ?? null,
                'metric_date' => $yesterday,
                'generated_at' => date('Y-m-d H:i:s'),
                'data_source' => 'api_direct',
                'dc' => date('Y-m-d H:i:s'),
                'tc' => time()
              ];

              ogDb::table('ad_metrics_daily')->insert($insertData);
              $assetsSaved++;

              ogLog::success('saveDailyMetrics - Métrica diaria guardada', [
                'product_id' => $productId,
                'asset_id' => $assetId,
                'date' => $yesterday,
                'spend' => $metrics['spend'],
                'impressions' => $metrics['impressions']
              ], $logMeta);
            }
          }

          $productsProcessed++;

        } catch (Exception $e) {
          // Si un producto falla, continuar con los demás
          $errors[] = [
            'product_id' => $productId,
            'product_name' => $product['name'] ?? 'Unknown',
            'error' => $e->getMessage()
          ];

          ogLog::error('saveDailyMetrics - Error procesando producto', [
            'product_id' => $productId,
            'error' => $e->getMessage()
          ], $logMeta);

          continue;
        }
      }

      $executionTime = round((microtime(true) - $startTime) * 1000, 2);

      ogLog::success('saveDailyMetrics - Proceso completado', [
        'date_processed' => $yesterday,
        'products_total' => count($products),
        'products_processed' => $productsProcessed,
        'assets_saved' => $assetsSaved,
        'assets_skipped' => $assetsSkipped,
        'execution_time_ms' => $executionTime,
        'errors_count' => count($errors)
      ], $logMeta);

      return [
        'success' => true,
        'date_processed' => $yesterday,
        'products_total' => count($products),
        'products_processed' => $productsProcessed,
        'assets_saved' => $assetsSaved,
        'assets_skipped' => $assetsSkipped,
        'execution_time_ms' => $executionTime,
        'errors' => $errors
      ];

    } catch (Exception $e) {
      ogLog::error('saveDailyMetrics - Error crítico', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
      ], $logMeta);

      return [
        'success' => false,
        'error' => $e->getMessage()
      ];
    }
  }

  // Obtener métricas de gastos por día (combina daily + hourly del día actual)
  static function getAdSpendByDay($params) {
    // Obtener user_id autenticado
    if (!isset($GLOBALS['auth_user_id'])) {
      return ['success' => false, 'error' => __('auth.unauthorized')];
    }
    $userId = $GLOBALS['auth_user_id'];

    $range = $params['range'] ?? 'last_7_days';
    $dates = ogApp()->helper('date')::getDateRange($range);

    if (!$dates) {
      return ['success' => false, 'error' => 'Rango inválido'];
    }

    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    // Determinar si necesitamos datos de hoy
    $needsToday = strtotime($dates['end']) >= strtotime($today);

    try {
      $results = [];

      // PASO 1: Obtener datos históricos (ad_metrics_daily)
      // Hasta ayer inclusive si necesitamos hoy, sino hasta la fecha final
      $historicEnd = $needsToday ? $yesterday : date('Y-m-d', strtotime($dates['end']));

      if (strtotime($dates['start']) <= strtotime($historicEnd)) {
        $sqlHistoric = "
          SELECT
            d.metric_date as date,
            SUM(d.spend) as spend,
            SUM(d.impressions) as impressions,
            SUM(d.clicks) as clicks,
            SUM(d.link_clicks) as link_clicks,
            SUM(d.conversions) as conversions,
            SUM(d.results) as results,
            SUM(d.purchase) as ad_purchases,
            SUM(d.purchase_value) as ad_purchase_value,
            COUNT(DISTINCT s.id) as real_purchases,
            COALESCE(SUM(s.billed_amount), 0) as real_purchase_value
          FROM ad_metrics_daily d
          LEFT JOIN sales s ON DATE(s.payment_date) = d.metric_date
            AND s.product_id = d.product_id
            AND s.context = 'whatsapp'
            AND s.process_status = 'sale_confirmed'
            AND s.status = 1
          WHERE d.user_id = ? AND d.metric_date >= ? AND d.metric_date <= ?
          GROUP BY d.metric_date
          ORDER BY d.metric_date ASC
        ";

        $historicData = ogDb::raw($sqlHistoric, [$userId, $dates['start'], $historicEnd]);

        foreach ($historicData as $row) {
          $results[$row['date']] = [
            'date' => $row['date'],
            'spend' => (float)$row['spend'],
            'impressions' => (int)$row['impressions'],
            'clicks' => (int)$row['clicks'],
            'link_clicks' => (int)$row['link_clicks'],
            'conversions' => (int)$row['conversions'],
            'results' => (int)$row['results'],
            'ad_purchases' => (int)$row['ad_purchases'],
            'ad_purchase_value' => (float)$row['ad_purchase_value'],
            'real_purchases' => (int)$row['real_purchases'],
            'real_purchase_value' => (float)$row['real_purchase_value'],
            'cpm' => 0,
            'cpc' => 0,
            'ctr' => 0,
            'roas' => 0
          ];
        }
      }

      // PASO 2: Obtener datos de hoy (ad_metrics_hourly con is_latest = 1)
      if ($needsToday) {
        $sqlToday = "
          SELECT
            h.query_date as date,
            SUM(h.spend) as spend,
            SUM(h.impressions) as impressions,
            SUM(h.clicks) as clicks,
            SUM(h.link_clicks) as link_clicks,
            SUM(h.conversions) as conversions,
            SUM(h.results) as results,
            SUM(h.purchase) as ad_purchases,
            SUM(h.purchase_value) as ad_purchase_value,
            COUNT(DISTINCT s.id) as real_purchases,
            COALESCE(SUM(s.billed_amount), 0) as real_purchase_value
          FROM ad_metrics_hourly h
          LEFT JOIN sales s ON DATE(s.payment_date) = h.query_date
            AND s.product_id = h.product_id
            AND s.context = 'whatsapp'
            AND s.process_status = 'sale_confirmed'
            AND s.status = 1
          WHERE h.user_id = ?
            AND h.query_date = ?
            AND h.is_latest = 1
          GROUP BY h.query_date
        ";

        $todayData = ogDb::raw($sqlToday, [$userId, $today]);

        if (!empty($todayData)) {
          $row = $todayData[0];
          $results[$today] = [
            'date' => $today,
            'spend' => (float)$row['spend'],
            'impressions' => (int)$row['impressions'],
            'clicks' => (int)$row['clicks'],
            'link_clicks' => (int)$row['link_clicks'],
            'conversions' => (int)$row['conversions'],
            'results' => (int)$row['results'],
            'ad_purchases' => (int)$row['ad_purchases'],
            'ad_purchase_value' => (float)$row['ad_purchase_value'],
            'real_purchases' => (int)$row['real_purchases'],
            'real_purchase_value' => (float)$row['real_purchase_value'],
            'cpm' => 0,
            'cpc' => 0,
            'ctr' => 0,
            'roas' => 0
          ];
        }
      }

      // PASO 3: Calcular métricas derivadas
      foreach ($results as &$row) {
        $row['cpm'] = $row['impressions'] > 0 ? ($row['spend'] / $row['impressions']) * 1000 : 0;
        $row['cpc'] = $row['clicks'] > 0 ? $row['spend'] / $row['clicks'] : 0;
        $row['ctr'] = $row['impressions'] > 0 ? ($row['clicks'] / $row['impressions']) * 100 : 0;

        // ROAS = (Ingresos / Gasto) - usando ventas reales
        $row['roas'] = $row['spend'] > 0 ? ($row['real_purchase_value'] / $row['spend']) : 0;

        // Redondear
        $row['cpm'] = round($row['cpm'], 2);
        $row['cpc'] = round($row['cpc'], 2);
        $row['ctr'] = round($row['ctr'], 2);
        $row['roas'] = round($row['roas'], 2);
        $row['spend'] = round($row['spend'], 2);
        $row['ad_purchase_value'] = round($row['ad_purchase_value'], 2);
        $row['real_purchase_value'] = round($row['real_purchase_value'], 2);
      }

      // Ordenar por fecha
      ksort($results);
      $results = array_values($results);

      // Calcular totales
      $totals = [
        'spend' => 0,
        'impressions' => 0,
        'clicks' => 0,
        'link_clicks' => 0,
        'conversions' => 0,
        'results' => 0,
        'ad_purchases' => 0,
        'ad_purchase_value' => 0,
        'real_purchases' => 0,
        'real_purchase_value' => 0
      ];

      foreach ($results as $row) {
        $totals['spend'] += $row['spend'];
        $totals['impressions'] += $row['impressions'];
        $totals['clicks'] += $row['clicks'];
        $totals['link_clicks'] += $row['link_clicks'];
        $totals['conversions'] += $row['conversions'];
        $totals['results'] += $row['results'];
        $totals['ad_purchases'] += $row['ad_purchases'];
        $totals['ad_purchase_value'] += $row['ad_purchase_value'];
        $totals['real_purchases'] += $row['real_purchases'];
        $totals['real_purchase_value'] += $row['real_purchase_value'];
      }

      // Métricas derivadas de totales
      $totals['cpm'] = $totals['impressions'] > 0 ? ($totals['spend'] / $totals['impressions']) * 1000 : 0;
      $totals['cpc'] = $totals['clicks'] > 0 ? $totals['spend'] / $totals['clicks'] : 0;
      $totals['ctr'] = $totals['impressions'] > 0 ? ($totals['clicks'] / $totals['impressions']) * 100 : 0;
      $totals['roas'] = $totals['spend'] > 0 ? ($totals['real_purchase_value'] / $totals['spend']) : 0;

      // Redondear totales
      $totals['spend'] = round($totals['spend'], 2);
      $totals['ad_purchase_value'] = round($totals['ad_purchase_value'], 2);
      $totals['real_purchase_value'] = round($totals['real_purchase_value'], 2);
      $totals['cpm'] = round($totals['cpm'], 2);
      $totals['cpc'] = round($totals['cpc'], 2);
      $totals['ctr'] = round($totals['ctr'], 2);
      $totals['roas'] = round($totals['roas'], 2);

      return [
        'success' => true,
        'data' => $results,
        'totals' => $totals,
        'period' => [
          'start' => $dates['start'],
          'end' => $dates['end'],
          'range' => $range,
          'includes_today' => $needsToday
        ]
      ];

    } catch (Exception $e) {
      ogLog::error('getAdSpendByDay - Error', ['error' => $e->getMessage()], self::$logMeta);
      return [
        'success' => false,
        'error' => 'Error al obtener métricas de gastos',
        'details' => OG_IS_DEV ? $e->getMessage() : null
      ];
    }
  }

  // Obtener métricas de gastos por producto
  static function getAdSpendByProduct($params) {
    $range = $params['range'] ?? 'last_7_days';
    $dates = ogApp()->helper('date')::getDateRange($range);

    if (!$dates) {
      return ['success' => false, 'error' => 'Rango inválido'];
    }

    $today = date('Y-m-d');

    try {
      // Obtener datos históricos agrupados por producto
      $sqlHistoric = "
        SELECT
          d.product_id,
          p.name as product_name,
          SUM(d.spend) as spend,
          SUM(d.impressions) as impressions,
          SUM(d.clicks) as clicks,
          SUM(d.conversions) as conversions,
          SUM(d.purchase) as purchases,
          SUM(d.purchase_value) as purchase_value
        FROM ad_metrics_daily d
        LEFT JOIN products p ON d.product_id = p.id
        WHERE d.metric_date >= ? AND d.metric_date < ?
        GROUP BY d.product_id, p.name
        ORDER BY spend DESC
      ";

      $historicData = ogDb::raw($sqlHistoric, [$dates['start'], $today]);

      // Obtener datos de hoy
      $sqlToday = "
        SELECT
          h.product_id,
          p.name as product_name,
          SUM(h.spend) as spend,
          SUM(h.impressions) as impressions,
          SUM(h.clicks) as clicks,
          SUM(h.conversions) as conversions,
          SUM(h.purchase) as purchases,
          SUM(h.purchase_value) as purchase_value
        FROM ad_metrics_hourly h
        LEFT JOIN products p ON h.product_id = p.id
        WHERE h.query_date = ?
          AND h.is_latest = 1
        GROUP BY h.product_id, p.name
      ";

      $todayData = ogDb::raw($sqlToday, [$today]);

      // Combinar resultados por product_id
      $productMap = [];

      foreach ($historicData as $row) {
        $productId = $row['product_id'];
        $productMap[$productId] = [
          'product_id' => $productId,
          'product_name' => $row['product_name'] ?? "Producto #{$productId}",
          'spend' => (float)$row['spend'],
          'impressions' => (int)$row['impressions'],
          'clicks' => (int)$row['clicks'],
          'conversions' => (int)$row['conversions'],
          'purchases' => (int)$row['purchases'],
          'purchase_value' => (float)$row['purchase_value']
        ];
      }

      // Agregar datos de hoy
      foreach ($todayData as $row) {
        $productId = $row['product_id'];

        if (!isset($productMap[$productId])) {
          $productMap[$productId] = [
            'product_id' => $productId,
            'product_name' => $row['product_name'] ?? "Producto #{$productId}",
            'spend' => 0,
            'impressions' => 0,
            'clicks' => 0,
            'conversions' => 0,
            'purchases' => 0,
            'purchase_value' => 0
          ];
        }

        $productMap[$productId]['spend'] += (float)$row['spend'];
        $productMap[$productId]['impressions'] += (int)$row['impressions'];
        $productMap[$productId]['clicks'] += (int)$row['clicks'];
        $productMap[$productId]['conversions'] += (int)$row['conversions'];
        $productMap[$productId]['purchases'] += (int)$row['purchases'];
        $productMap[$productId]['purchase_value'] += (float)$row['purchase_value'];
      }

      // Calcular métricas derivadas y redondear
      $results = [];
      foreach ($productMap as $product) {
        $product['cpm'] = $product['impressions'] > 0 ? ($product['spend'] / $product['impressions']) * 1000 : 0;
        $product['cpc'] = $product['clicks'] > 0 ? $product['spend'] / $product['clicks'] : 0;
        $product['ctr'] = $product['impressions'] > 0 ? ($product['clicks'] / $product['impressions']) * 100 : 0;
        $product['roi'] = $product['spend'] > 0 ? (($product['purchase_value'] - $product['spend']) / $product['spend']) * 100 : 0;

        // Redondear
        $product['spend'] = round($product['spend'], 2);
        $product['purchase_value'] = round($product['purchase_value'], 2);
        $product['cpm'] = round($product['cpm'], 2);
        $product['cpc'] = round($product['cpc'], 2);
        $product['ctr'] = round($product['ctr'], 2);
        $product['roi'] = round($product['roi'], 2);

        $results[] = $product;
      }

      // Ordenar por gasto descendente
      usort($results, fn($a, $b) => $b['spend'] <=> $a['spend']);

      return [
        'success' => true,
        'data' => $results,
        'period' => [
          'start' => $dates['start'],
          'end' => $dates['end'],
          'range' => $range
        ]
      ];

    } catch (Exception $e) {
      ogLog::error('getAdSpendByProduct - Error', ['error' => $e->getMessage()], self::$logMeta);
      return [
        'success' => false,
        'error' => 'Error al obtener métricas por producto',
        'details' => OG_IS_DEV ? $e->getMessage() : null
      ];
    }
  }
}