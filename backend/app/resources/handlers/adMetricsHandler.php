<?php
class adMetricsHandler {
  private static $logMeta = ['module' => 'adMetricsHandler', 'layer' => 'app/resources'];

  // Obtener métricas por product_id
  static function getByProduct($params) {
    $productId = $params['product_id'];
    $dateFrom = ogRequest::query('date_from');
    $dateTo = ogRequest::query('date_to');

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
    $dateFrom = ogRequest::query('date_from');
    $dateTo = ogRequest::query('date_to');

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
      return $service->getAssetsMetrics($assets, $dateFrom, $dateTo);
    } catch (Exception $e) {
      ogLog::error('getByAssets - Error', ['error' => $e->getMessage()], self::$logMeta);

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
}