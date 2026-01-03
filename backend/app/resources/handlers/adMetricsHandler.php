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
      $service = ogApp()->service('adMetrics');
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
}