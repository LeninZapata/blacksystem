<?php
class tiktokAdProvider extends baseAdProvider {
  private $baseUrl = 'https://business-api.tiktok.com/open_api/v1.3';

  function getProviderName(): string {
    return 'tiktok';
  }

  function getAssetMetrics(string $assetId, string $assetType, string $dateFrom, string $dateTo): array {
    return $this->errorResponse('TikTok Ads provider no implementado aún', 'NOT_IMPLEMENTED');
  }

  function getMultipleAssetsMetrics(array $assets, string $dateFrom, string $dateTo): array {
    return $this->errorResponse('TikTok Ads provider no implementado aún', 'NOT_IMPLEMENTED');
  }
}