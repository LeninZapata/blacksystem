<?php
class facebookAdProvider extends baseAdProvider {
  private $apiVersion = 'v21.0';
  private $baseUrl;

  function __construct(array $config) {
    parent::__construct($config);
    $this->apiVersion = $config['api_version'] ?? 'v21.0';
    $this->baseUrl = "https://graph.facebook.com/{$this->apiVersion}";
  }

  function getProviderName(): string {
    return 'facebook';
  }

  // Obtener métricas de un solo activo
  function getAssetMetrics(string $assetId, string $assetType, string $dateFrom, string $dateTo): array {
    try {
      if (!$this->validateAssetType($assetType)) {
        return $this->errorResponse("Tipo de activo inválido: {$assetType}");
      }

      $dateFrom = $this->formatDate($dateFrom);
      $dateTo = $this->formatDate($dateTo);

      // Campos a consultar
      $fields = implode(',', [
        'spend',
        'impressions',
        'reach',
        'clicks',
        'actions',
        'action_values',
        'cpm',
        'cpc',
        'ctr'
      ]);

      // URL del endpoint
      $url = "{$this->baseUrl}/{$assetId}/insights";
      $url .= "?fields={$fields}";
      $url .= "&time_range={'since':'{$dateFrom}','until':'{$dateTo}'}";
      $url .= "&access_token={$this->apiKey}";

      $this->log("Consultando métricas de {$assetType}: {$assetId}", [
        'date_from' => $dateFrom,
        'date_to' => $dateTo
      ]);

      $response = ogApp()->helper('http')::get($url);

      if (!$response['success']) {
        return $this->errorResponse(
          "Error HTTP al consultar Facebook API: " . ($response['error'] ?? 'Unknown'),
          $response['httpCode'] ?? 500
        );
      }

      $data = $response['data'];

      // Verificar si hay datos
      if (!isset($data['data']) || empty($data['data'])) {
        return $this->successResponse([
          'asset_id' => $assetId,
          'asset_type' => $assetType,
          'date_from' => $dateFrom,
          'date_to' => $dateTo,
          'metrics' => $this->getEmptyMetrics(),
          'has_data' => false
        ]);
      }

      // Procesar métricas
      $metrics = $this->processMetrics($data['data'][0]);

      return $this->successResponse([
        'asset_id' => $assetId,
        'asset_type' => $assetType,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'metrics' => $metrics,
        'has_data' => true
      ]);

    } catch (Exception $e) {
      $this->log('Error obteniendo métricas', ['error' => $e->getMessage()]);
      return $this->errorResponse($e->getMessage());
    }
  }

  // Obtener métricas de múltiples activos
  function getMultipleAssetsMetrics(array $assets, string $dateFrom, string $dateTo): array {
    try {
      $results = [];
      $errors = [];

      foreach ($assets as $asset) {
        $assetId = $asset['ad_asset_id'] ?? $asset['asset_id'] ?? null;
        $assetType = $asset['ad_asset_type'] ?? $asset['asset_type'] ?? 'adset';

        if (!$assetId) {
          $errors[] = "Asset sin ID: " . json_encode($asset);
          continue;
        }

        $metrics = $this->getAssetMetrics($assetId, $assetType, $dateFrom, $dateTo);
        $results[] = $metrics;
      }

      // Sumar todas las métricas
      $totals = $this->sumMetrics($results);

      return $this->successResponse([
        'date_from' => $this->formatDate($dateFrom),
        'date_to' => $this->formatDate($dateTo),
        'total_assets' => count($assets),
        'assets_processed' => count($results),
        'assets_with_errors' => count($errors),
        'totals' => $totals,
        'by_asset' => $results,
        'errors' => $errors
      ]);

    } catch (Exception $e) {
      $this->log('Error obteniendo métricas múltiples', ['error' => $e->getMessage()]);
      return $this->errorResponse($e->getMessage());
    }
  }

  // Procesar métricas del API de Facebook
  private function processMetrics(array $data): array {
    $metrics = [
      'spend' => floatval($data['spend'] ?? 0),
      'impressions' => intval($data['impressions'] ?? 0),
      'reach' => intval($data['reach'] ?? 0),
      'clicks' => intval($data['clicks'] ?? 0),
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
      'cpm' => floatval($data['cpm'] ?? 0),
      'cpc' => floatval($data['cpc'] ?? 0),
      'ctr' => floatval($data['ctr'] ?? 0),
      'conversion_rate' => 0
    ];

    // Extraer actions (conversiones, clicks, etc)
    if (isset($data['actions']) && is_array($data['actions'])) {
      foreach ($data['actions'] as $action) {
        $actionType = $action['action_type'] ?? '';
        $value = intval($action['value'] ?? 0);

        switch ($actionType) {
          case 'link_click':
            $metrics['link_clicks'] = $value;
            break;
          case 'landing_page_view':
          case 'page_view':
            $metrics['page_views'] = $value;
            break;
          case 'view_content':
            $metrics['view_content'] = $value;
            break;
          case 'add_to_cart':
            $metrics['add_to_cart'] = $value;
            break;
          case 'initiate_checkout':
            $metrics['initiate_checkout'] = $value;
            break;
          case 'add_payment_info':
            $metrics['add_payment_info'] = $value;
            break;
          case 'purchase':
          case 'offsite_conversion.fb_pixel_purchase':
            $metrics['purchase'] = $value;
            break;
          case 'lead':
          case 'offsite_conversion.fb_pixel_lead':
            $metrics['lead'] = $value;
            break;
          case 'onsite_conversion.messaging_conversation_started_7d':
            $metrics['results'] += $value;
            break;
        }
      }
    }

    // Extraer valores de conversiones (purchase_value)
    if (isset($data['action_values']) && is_array($data['action_values'])) {
      foreach ($data['action_values'] as $actionValue) {
        $actionType = $actionValue['action_type'] ?? '';
        if ($actionType === 'purchase' || $actionType === 'offsite_conversion.fb_pixel_purchase') {
          $metrics['purchase_value'] = floatval($actionValue['value'] ?? 0);
          break;
        }
      }
    }

    // Calcular conversiones totales
    $metrics['conversions'] = $metrics['purchase'] + $metrics['lead'];

    // Calcular métricas derivadas si no vienen de Facebook
    if ($metrics['cpm'] == 0 && $metrics['impressions'] > 0) {
      $metrics['cpm'] = ($metrics['spend'] / $metrics['impressions']) * 1000;
    }

    if ($metrics['cpc'] == 0 && $metrics['clicks'] > 0) {
      $metrics['cpc'] = $metrics['spend'] / $metrics['clicks'];
    }

    if ($metrics['ctr'] == 0 && $metrics['impressions'] > 0) {
      $metrics['ctr'] = ($metrics['clicks'] / $metrics['impressions']) * 100;
    }

    if ($metrics['clicks'] > 0) {
      $metrics['conversion_rate'] = ($metrics['conversions'] / $metrics['clicks']) * 100;
    }

    // Redondear
    $metrics['cpm'] = round($metrics['cpm'], 2);
    $metrics['cpc'] = round($metrics['cpc'], 2);
    $metrics['ctr'] = round($metrics['ctr'], 2);
    $metrics['conversion_rate'] = round($metrics['conversion_rate'], 2);
    $metrics['purchase_value'] = round($metrics['purchase_value'], 2);

    return $metrics;
  }

  // Métricas vacías por defecto
  private function getEmptyMetrics(): array {
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

  // Obtener presupuesto actual
  function getBudget(string $assetId, string $assetType): array {
    try {
      $fields = $assetType === 'campaign' ? 'daily_budget,lifetime_budget,status' : 'daily_budget,status';
      $url = "{$this->baseUrl}/{$assetId}?fields={$fields}&access_token={$this->apiKey}";

      $this->log("Obteniendo presupuesto de {$assetType}: {$assetId}");

      $response = ogApp()->helper('http')::get($url);

      if (!$response['success']) {
        return $this->errorResponse(
          "Error HTTP al consultar presupuesto: " . ($response['error'] ?? 'Unknown'),
          $response['httpCode'] ?? 500
        );
      }

      $data = $response['data'];

      // Presupuesto en centavos, convertir a dólares
      $dailyBudget = isset($data['daily_budget']) ? (int)$data['daily_budget'] / 100 : null;
      $lifetimeBudget = isset($data['lifetime_budget']) ? (int)$data['lifetime_budget'] / 100 : null;

      return $this->successResponse([
        'asset_id' => $assetId,
        'asset_type' => $assetType,
        'budget' => $dailyBudget ?? $lifetimeBudget ?? 0,
        'daily_budget' => $dailyBudget,
        'lifetime_budget' => $lifetimeBudget,
        'status' => $data['status'] ?? null
      ]);

    } catch (Exception $e) {
      $this->log('Error obteniendo presupuesto', ['error' => $e->getMessage()]);
      return $this->errorResponse($e->getMessage());
    }
  }

  // Actualizar presupuesto
  function updateBudget(string $assetId, string $assetType, float $newBudget, string $budgetType = 'daily'): array {
    try {
      // Convertir a centavos
      $budgetCents = (int)($newBudget * 100);

      $field = $budgetType === 'lifetime' ? 'lifetime_budget' : 'daily_budget';
      $url = "{$this->baseUrl}/{$assetId}?{$field}={$budgetCents}&access_token={$this->apiKey}";

      $this->log("Actualizando presupuesto de {$assetType}: {$assetId}", [
        'new_budget' => $newBudget,
        'budget_type' => $budgetType
      ]);

      $response = ogApp()->helper('http')::post($url);

      if (!$response['success']) {
        return $this->errorResponse(
          "Error HTTP al actualizar presupuesto: " . ($response['error'] ?? 'Unknown'),
          $response['httpCode'] ?? 500
        );
      }

      return $this->successResponse([
        'asset_id' => $assetId,
        'asset_type' => $assetType,
        'budget' => $newBudget,
        'budget_type' => $budgetType,
        'updated' => true
      ]);

    } catch (Exception $e) {
      $this->log('Error actualizando presupuesto', ['error' => $e->getMessage()]);
      return $this->errorResponse($e->getMessage());
    }
  }

  // Pausar activo
  function pauseAsset(string $assetId, string $assetType): array {
    try {
      $url = "{$this->baseUrl}/{$assetId}?status=PAUSED&access_token={$this->apiKey}";

      $this->log("Pausando {$assetType}: {$assetId}");

      $response = ogApp()->helper('http')::post($url);

      if (!$response['success']) {
        return $this->errorResponse(
          "Error HTTP al pausar activo: " . ($response['error'] ?? 'Unknown'),
          $response['httpCode'] ?? 500
        );
      }

      return $this->successResponse([
        'asset_id' => $assetId,
        'asset_type' => $assetType,
        'status' => 'PAUSED',
        'paused' => true
      ]);

    } catch (Exception $e) {
      $this->log('Error pausando activo', ['error' => $e->getMessage()]);
      return $this->errorResponse($e->getMessage());
    }
  }
}