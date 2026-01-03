<?php
class googleAdProvider extends baseAdProvider {
  private $baseUrl = 'https://googleads.googleapis.com/v16';

  function getProviderName(): string {
    return 'google';
  }

  function getAssetMetrics(string $assetId, string $assetType, string $dateFrom, string $dateTo): array {
    return $this->errorResponse('Google Ads provider no implementado aún', 'NOT_IMPLEMENTED');
  }

  function getMultipleAssetsMetrics(array $assets, string $dateFrom, string $dateTo): array {
    return $this->errorResponse('Google Ads provider no implementado aún', 'NOT_IMPLEMENTED');
  }
}