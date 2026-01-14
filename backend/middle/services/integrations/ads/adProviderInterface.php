<?php
interface adProviderInterface {
  // Obtener nombre del provider
  function getProviderName(): string;

  // Validar configuración
  function validateConfig(): bool;

  // Obtener métricas de un activo específico
  function getAssetMetrics(string $assetId, string $assetType, string $dateFrom, string $dateTo): array;

  // Obtener métricas de múltiples activos
  function getMultipleAssetsMetrics(array $assets, string $dateFrom, string $dateTo): array;
}