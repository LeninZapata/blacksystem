<?php
abstract class baseAdProvider implements adProviderInterface {
  protected $apiKey;
  protected $config;
  protected static $logMeta = ['module' => 'ads', 'layer' => 'app/services'];

  function __construct(array $config) {
    $this->config = $config;
    $this->apiKey = $config['credential_value'] ?? $config['api_key'] ?? $config['access_token'] ?? '';
    $this->validateConfig();
  }

  function validateConfig(): bool {
    if (empty($this->apiKey)) {
      ogLog::error('API key no configurada para ' . $this->getProviderName(), [], self::$logMeta);
      throw new Exception('API key requerida para ' . $this->getProviderName());
    }
    return true;
  }

  // Respuesta exitosa
  protected function successResponse($data): array {
    return array_merge([
      'success' => true,
      'provider' => $this->getProviderName(),
      'timestamp' => time()
    ], $data);
  }

  // Respuesta de error
  protected function errorResponse($message, $code = null): array {
    $response = [
      'success' => false,
      'provider' => $this->getProviderName(),
      'error' => $message,
      'timestamp' => time()
    ];
    if ($code !== null) $response['error_code'] = $code;
    return $response;
  }

  // Log helper
  protected function log($message, $data = []): void {
    ogLog::debug(sprintf('[%s] %s', $this->getProviderName(), $message), $data, self::$logMeta);
  }

  // Formatear fecha para APIs
  protected function formatDate($date): string {
    if (is_numeric($date)) {
      return date('Y-m-d', $date);
    }
    return date('Y-m-d', strtotime($date));
  }

  // Validar tipo de activo
  protected function validateAssetType($assetType): bool {
    $validTypes = ['campaign', 'adset', 'ad'];
    return in_array(strtolower($assetType), $validTypes);
  }

  // Sumar métricas de múltiples activos
  protected function sumMetrics(array $metricsArray): array {
    $totals = [
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
      'results' => 0
    ];

    foreach ($metricsArray as $metrics) {
      if (isset($metrics['metrics'])) {
        foreach ($totals as $key => $value) {
          $totals[$key] += $metrics['metrics'][$key] ?? 0;
        }
      }
    }

    // Calcular métricas derivadas
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
}