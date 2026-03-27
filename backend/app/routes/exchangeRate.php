<?php
// routes/exchangeRate.php - Tasas de cambio por país vs. dólar (USD)

// Configuración de países y sus monedas locales
$exchangeCountries = ['EC', 'AR', 'PE', 'CO', 'CL', 'MX', 'BR', 'UY', 'PY'];

$exchangeCurrencies = [
  'EC' => 'USD', // Ecuador usa dólar
  'AR' => 'ARS',
  'PE' => 'PEN',
  'CO' => 'COP',
  'CL' => 'CLP',
  'MX' => 'MXN',
  'BR' => 'BRL',
  'UY' => 'UYU',
  'PY' => 'PYG',
];

$exchangeApiKey  = '8c5b0f3061aeb24c65a6456c';
$exchangeJsonPath = ogApp()->getPath('storage/json/system') . '/exchangerate.json';

/**
 * Obtiene las tasas desde la API de exchangerate-api.com y guarda el JSON.
 * Retorna el array de tasas o null en caso de error.
 */
$fetchExchangeRates = function() use ($exchangeCountries, $exchangeCurrencies, $exchangeApiKey, $exchangeJsonPath) {
  $url = "https://v6.exchangerate-api.com/v6/{$exchangeApiKey}/latest/USD";

  $result = ogApp()->helper('http')::get($url);

  if (!$result['success'] || empty($result['data'])) {
    ogLog::error('ExchangeRate fetch failed', [
      'httpCode' => $result['httpCode'],
      'error'    => $result['error'] ?? 'unknown',
    ], ['module' => 'exchangerate', 'layer' => 'app/routes']);
    return null;
  }

  $data = $result['data'];

  if (($data['result'] ?? '') !== 'success') {
    ogLog::error('ExchangeRate API error', [
      'error_type' => $data['error-type'] ?? 'unknown',
    ], ['module' => 'exchangerate', 'layer' => 'app/routes']);
    return null;
  }

  $conversionRates = $data['conversion_rates'] ?? [];

  // Construir tasas: { country: { currency, usd_rate, local_rate } }
  // usd_rate = cuántos dólares vale 1 unidad de la moneda local
  // local_rate = cuántas unidades locales vale 1 dólar
  $rates = [];
  foreach ($exchangeCountries as $country) {
    $currency = $exchangeCurrencies[$country];

    if ($currency === 'USD') {
      $rates[$country] = [
        'currency'   => 'USD',
        'usd_rate'   => 1.0,
        'local_rate' => 1.0,
      ];
      continue;
    }

    $usdToLocal = $conversionRates[$currency] ?? null;
    if ($usdToLocal === null) {
      ogLog::warning("ExchangeRate: moneda no encontrada en API", [
        'country'  => $country,
        'currency' => $currency,
      ], ['module' => 'exchangerate', 'layer' => 'app/routes']);
      continue;
    }

    $rates[$country] = [
      'currency'   => $currency,
      'usd_rate'   => round(1 / $usdToLocal, 8),  // 1 moneda local → USD
      'local_rate' => round($usdToLocal, 4),        // 1 USD → moneda local
    ];
  }

  // Crear directorio si no existe
  $dir = dirname($exchangeJsonPath);
  if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
  }

  // Guardar JSON
  $payload = [
    'updated_at' => date('Y-m-d H:i:s'),
    'base'       => 'USD',
    'rates'      => $rates,
  ];

  file_put_contents($exchangeJsonPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

  return $payload;
};

$router->group('/api/exchangeRate', function($router) use ($exchangeJsonPath, $fetchExchangeRates) {

  // GET /api/exchangeRate
  // Devuelve las tasas actuales. Si el archivo tiene más de 24h o no existe, refresca automáticamente.
  $router->get('', function() use ($exchangeJsonPath, $fetchExchangeRates) {
    $needsRefresh = true;

    if (file_exists($exchangeJsonPath)) {
      $age = time() - filemtime($exchangeJsonPath);
      $needsRefresh = $age > 84400; // 24 horas en segundos
    }

    if ($needsRefresh) {
      $payload = $fetchExchangeRates();
      if ($payload === null) {
        // Si falla el refresh y existe un archivo previo, devolver el viejo
        if (file_exists($exchangeJsonPath)) {
          $payload = json_decode(file_get_contents($exchangeJsonPath), true);
          $payload['stale'] = true;
        } else {
          ogResponse::error('No se pudo obtener las tasas de cambio', 503);
          return;
        }
      }
    } else {
      $payload = json_decode(file_get_contents($exchangeJsonPath), true);
    }

    ogResponse::success($payload);
  });

  // GET /api/exchangeRate/refresh
  // Fuerza la actualización inmediata (para cron job diario).
  $router->get('/refresh', function() use ($fetchExchangeRates) {
    $payload = $fetchExchangeRates();

    if ($payload === null) {
      ogResponse::error('Error al actualizar las tasas de cambio desde la API', 502);
      return;
    }

    ogResponse::success($payload, 'Tasas de cambio actualizadas correctamente');
  });

});
