<?php
class adMetricsService {
  private static $logMeta = ['module' => 'adMetricsService', 'layer' => 'app/services'];

  // Obtener métricas de un producto (busca sus activos asociados)
  function getProductMetrics($productId, $dateFrom = null, $dateTo = null) {
    try {
      // Fechas por defecto (hoy)
      if (!$dateFrom) $dateFrom = date('Y-m-d');
      if (!$dateTo) $dateTo = date('Y-m-d');

      // Buscar activos del producto
      $assets = ogDb::table('product_ad_assets')
        ->where('product_id', $productId)
        ->where('status', 1)
        ->where('is_active', 1)
        ->get();

      if (empty($assets)) {
        return [
          'success' => true,
          'product_id' => $productId,
          'total_assets' => 0,
          'message' => 'No hay activos publicitarios asociados a este producto',
          'totals' => $this->getEmptyMetrics(),
          'by_platform' => [],
          'by_asset' => []
        ];
      }

      // Agrupar activos por plataforma
      $assetsByPlatform = [];
      foreach ($assets as $asset) {
        $platform = $asset['ad_platform'];
        if (!isset($assetsByPlatform[$platform])) {
          $assetsByPlatform[$platform] = [];
        }
        $assetsByPlatform[$platform][] = $asset;
      }

      // Procesar por plataforma
      $allResults = [];
      $platformResults = [];

      foreach ($assetsByPlatform as $platform => $platformAssets) {
        $platformResult = $this->getMetricsByPlatform($platform, $platformAssets, $dateFrom, $dateTo);
        $platformResults[$platform] = $platformResult;

        // Agregar resultados individuales
        if (isset($platformResult['by_asset'])) {
          $allResults = array_merge($allResults, $platformResult['by_asset']);
        }
      }

      // Calcular totales globales
      $globalTotals = $this->sumAllPlatforms($platformResults);

      return [
        'success' => true,
        'product_id' => $productId,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'total_assets' => count($assets),
        'totals' => $globalTotals,
        'by_platform' => $platformResults,
        'by_asset' => $allResults
      ];

    } catch (Exception $e) {
      ogLog::error('getProductMetrics - Error', ['error' => $e->getMessage()], self::$logMeta);
      return [
        'success' => false,
        'error' => $e->getMessage(),
        'product_id' => $productId
      ];
    }
  }

  // Obtener métricas de activos específicos (sin asociar a producto)
  function getAssetsMetrics(array $assets, $dateFrom = null, $dateTo = null) {
    try {
      if (!$dateFrom) $dateFrom = date('Y-m-d');
      if (!$dateTo) $dateTo = date('Y-m-d');

      // Agrupar por plataforma
      $assetsByPlatform = [];
      foreach ($assets as $asset) {
        $platform = $asset['platform'] ?? $asset['ad_platform'] ?? 'facebook';
        if (!isset($assetsByPlatform[$platform])) {
          $assetsByPlatform[$platform] = [];
        }
        $assetsByPlatform[$platform][] = $asset;
      }

      // Procesar por plataforma
      $platformResults = [];
      $allResults = [];

      foreach ($assetsByPlatform as $platform => $platformAssets) {
        $platformResult = $this->getMetricsByPlatform($platform, $platformAssets, $dateFrom, $dateTo);
        $platformResults[$platform] = $platformResult;

        if (isset($platformResult['by_asset'])) {
          $allResults = array_merge($allResults, $platformResult['by_asset']);
        }
      }

      // Totales globales
      $globalTotals = $this->sumAllPlatforms($platformResults);

      return [
        'success' => true,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'total_assets' => count($assets),
        'totals' => $globalTotals,
        'by_platform' => $platformResults,
        'by_asset' => $allResults
      ];

    } catch (Exception $e) {
      ogLog::error('getAssetsMetrics - Error', ['error' => $e->getMessage()], self::$logMeta);
      return [
        'success' => false,
        'error' => $e->getMessage()
      ];
    }
  }

  // Obtener métricas por plataforma
  private function getMetricsByPlatform($platform, $assets, $dateFrom, $dateTo) {
    // Obtener credencial para esta plataforma
    $credential = $this->getCredentialForPlatform($platform, $assets[0]['user_id']);

    if (!$credential) {
      return [
        'success' => false,
        'platform' => $platform,
        'error' => "No se encontró credencial para {$platform}"
      ];
    }

    // Crear provider
    $provider = $this->createProvider($platform, $credential);

    if (!$provider) {
      return [
        'success' => false,
        'platform' => $platform,
        'error' => "Provider no disponible para {$platform}"
      ];
    }

    // Obtener métricas
    return $provider->getMultipleAssetsMetrics($assets, $dateFrom, $dateTo);
  }

  // Crear instancia del provider
  private function createProvider($platform, $credential) {
    $basePath = ogCache::memoryGet('path_middle') . '/services/integrations/ads';

    // Mapeo de plataformas a providers
    $providerMap = [
      'facebook' => ['class' => 'facebookAdProvider', 'folder' => 'facebook'],
      'tiktok' => ['class' => 'tiktokAdProvider', 'folder' => 'tiktok'],
      'google' => ['class' => 'googleAdProvider', 'folder' => 'google']
    ];

    $platform = strtolower($platform);

    if (!isset($providerMap[$platform])) {
      ogLog::error('createProvider - Plataforma no soportada', ['platform' => $platform], self::$logMeta);
      return null;
    }

    $providerInfo = $providerMap[$platform];
    $providerClass = $providerInfo['class'];
    $providerFolder = $providerInfo['folder'];

    // Cargar interface
    if (!interface_exists('adProviderInterface')) {
      $interfacePath = "{$basePath}/adProviderInterface.php";
      if (!file_exists($interfacePath)) {
        ogLog::error('createProvider - Interface no encontrada', ['path' => $interfacePath], self::$logMeta);
        return null;
      }
      require_once $interfacePath;
    }

    // Cargar base
    if (!class_exists('baseAdProvider')) {
      $basePath2 = "{$basePath}/baseAdProvider.php";
      if (!file_exists($basePath2)) {
        ogLog::error('createProvider - Base class no encontrada', ['path' => $basePath2], self::$logMeta);
        return null;
      }
      require_once $basePath2;
    }

    // Cargar provider específico
    $providerPath = "{$basePath}/{$providerFolder}/{$providerClass}.php";
    if (!file_exists($providerPath)) {
      ogLog::error('createProvider - Provider no encontrado', ['path' => $providerPath], self::$logMeta);
      return null;
    }
    require_once $providerPath;

    if (!class_exists($providerClass)) {
      ogLog::error('createProvider - Clase de provider no encontrada', ['class' => $providerClass], self::$logMeta);
      return null;
    }

    return new $providerClass($credential);
  }

  // Obtener credencial para una plataforma
  private function getCredentialForPlatform($platform, $userId) {
    $type_value = "ad-{$platform}";

    $credentials = ogDb::table('credentials')
      ->where('user_id', $userId)
      ->where('type', 'ad')
      ->where('status', 1)
      ->get();

    if (empty($credentials)) {
      ogLog::warning('getCredentialForPlatform - Tipo de Credencial "Ad" no encontrada', [ 'platform' => $platform, 'type' => 'ad', 'user_id' => $userId ], self::$logMeta);
      return null;
    }

    // Buscar la credencial con el type_value correcto en el campo config
    foreach ($credentials as $credential) {
      $config = is_string($credential['config']) ? json_decode($credential['config'], true) : $credential['config'];
      if (isset($config['type_value']) && $config['type_value'] === $type_value) {
        $config['credential_value'] = $config['credential_value'] ?? $config['access_token'] ?? '';
        return $config;
      }
    }

    ogLog::error('getCredentialForPlatform - No se encontró credencial con type_value esperado', [ 'platform' => $platform, 'type_value' => $type_value, 'user_id' => $userId ], self::$logMeta);
    return null;
  }

  // Sumar métricas de todas las plataformas
  private function sumAllPlatforms($platformResults) {
    $totals = $this->getEmptyMetrics();

    foreach ($platformResults as $platform => $result) {
      if (isset($result['totals'])) {
        foreach ($totals as $key => $value) {
          if (in_array($key, ['cpm', 'cpc', 'ctr', 'conversion_rate'])) {
            continue; // No sumar métricas calculadas
          }
          $totals[$key] += $result['totals'][$key] ?? 0;
        }
      }
    }

    // Recalcular métricas derivadas
    $totals['cpm'] = $totals['impressions'] > 0 ? ($totals['spend'] / $totals['impressions']) * 1000 : 0;
    $totals['cpc'] = $totals['clicks'] > 0 ? $totals['spend'] / $totals['clicks'] : 0;
    $totals['ctr'] = $totals['impressions'] > 0 ? ($totals['clicks'] / $totals['impressions']) * 100 : 0;
    $totals['conversion_rate'] = $totals['clicks'] > 0 ? ($totals['conversions'] / $totals['clicks']) * 100 : 0;

    // Redondear
    $totals['cpm'] = round($totals['cpm'], 2);
    $totals['cpc'] = round($totals['cpc'], 2);
    $totals['ctr'] = round($totals['ctr'], 2);
    $totals['conversion_rate'] = round($totals['conversion_rate'], 2);

    return $totals;
  }

  // Métricas vacías
  private function getEmptyMetrics() {
    return [
      'spend' => 0,
      'impressions' => 0,
      'reach' => 0,
      'clicks' => 0,
      'link_clicks' => 0,
      'page_views' => 0,
      'view_content' => 0,
      'add_to_cart' => 0,
      'initiate_checkout' => 0,
      'add_payment_info' => 0,
      'purchase' => 0,
      'lead' => 0,
      'purchase_value' => 0,
      'conversions' => 0,
      'results' => 0,
      'cpm' => 0,
      'cpc' => 0,
      'ctr' => 0,
      'conversion_rate' => 0
    ];
  }
}