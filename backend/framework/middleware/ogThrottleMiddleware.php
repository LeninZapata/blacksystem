<?php
// throttleMiddleware - Limitar peticiones (rate limiting)
class ogThrottleMiddleware {
  private $params = [];
  private $storageFile;

  function __construct() {
    $middlewareDir = ogApp()->getPath('storage') . '/middleware';
    if (!is_dir($middlewareDir)) {
      mkdir($middlewareDir, 0755, true);
    }
    $this->storageFile = $middlewareDir . '/throttle_data.json';
  }

  // Recibir parámetros: throttle:maxRequests,minutes
  function setParams($params) {
    $this->params = $params;
  }

  function handle() {
    $maxRequests = (int)($this->params[0] ?? 60); // Default: 60 requests
    $minutes = (int)($this->params[1] ?? 1);      // Default: 1 minuto

    $ip = ogRequest::ip();
    $key = $this->getKey($ip);

    $data = $this->getData();
    $now = time();
    $windowStart = $now - ($minutes * 60);

    // Limpiar requests antiguos
    if (isset($data[$key])) {
      $data[$key] = array_filter($data[$key], function($timestamp) use ($windowStart) {
        return $timestamp > $windowStart;
      });
    } else {
      $data[$key] = [];
    }

    // Verificar límite
    $requestCount = count($data[$key]);

    if ($requestCount >= $maxRequests) {
      $retryAfter = $minutes * 60;
      header("Retry-After: $retryAfter");
      header("X-RateLimit-Limit: $maxRequests");
      header("X-RateLimit-Remaining: 0");
      header("X-RateLimit-Reset: " . ($now + $retryAfter));

      ogLog::warning('middleware:throttle rate limit exceeded', [
        'ip' => $ip,
        'path' => ogRequest::path(),
        'max_requests' => $maxRequests,
        'minutes' => $minutes,
        'request_count' => $requestCount
      ], ['module' => 'throttle', 'layer' => 'framework']);

      ogResponse::error(
        __('middleware.throttle.too_many_requests', ['max' => $maxRequests, 'minutes' => $minutes]),
        429
      );
      return false;
    }

    // Agregar request actual
    $data[$key][] = $now;
    $this->saveData($data);

    // Headers informativos
    $remaining = $maxRequests - $requestCount - 1;
    header("X-RateLimit-Limit: $maxRequests");
    header("X-RateLimit-Remaining: $remaining");

    return true;
  }

  // Generar key única por IP y ruta
  private function getKey($ip) {
    $path = ogRequest::path();
    return md5($ip . ':' . $path);
  }

  // Obtener datos almacenados
  private function getData() {
    if (!file_exists($this->storageFile)) {
      return [];
    }

    $json = file_get_contents($this->storageFile);
    $data = json_decode($json, true);

    return is_array($data) ? $data : [];
  }

  // Guardar datos
  private function saveData($data) {
    // Limpiar datos viejos (más de 1 hora)
    $oneHourAgo = time() - 3600;
    foreach ($data as $key => $timestamps) {
      $data[$key] = array_filter($timestamps, function($ts) use ($oneHourAgo) {
        return $ts > $oneHourAgo;
      });
      if (empty($data[$key])) {
        unset($data[$key]);
      }
    }

    file_put_contents($this->storageFile, json_encode($data));
  }
}