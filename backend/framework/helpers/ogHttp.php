<?php
class ogHttp {

  private static $logMeta = ['module' => 'http', 'layer' => 'framework'];

  static function get($url, $options = []) {
    return self::request('GET', $url, null, $options);
  }

  static function post($url, $data = null, $options = []) {
    return self::request('POST', $url, $data, $options);
  }

  static function put($url, $data = null, $options = []) {
    return self::request('PUT', $url, $data, $options);
  }

  static function delete($url, $options = []) {
    return self::request('DELETE', $url, null, $options);
  }

  private static function request($method, $url, $data = null, $options = []) {
    $config = array_merge([
      'timeout' => 30,
      'headers' => [],
      'ssl_verify' => false,
      'auto_json' => true
    ], $options);

    try {
      $ch = curl_init();
      $url = ogApp()->helper('url')::normalizeUrl($url);
      $curlOpts = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $config['timeout'],
        CURLOPT_SSL_VERIFYPEER => $config['ssl_verify'],
        CURLOPT_SSL_VERIFYHOST => $config['ssl_verify'] ? 2 : 0
      ];

      switch (strtoupper($method)) {
        case 'POST':
          $curlOpts[CURLOPT_POST] = true;
          break;
        case 'PUT':
          $curlOpts[CURLOPT_CUSTOMREQUEST] = 'PUT';
          break;
        case 'DELETE':
          $curlOpts[CURLOPT_CUSTOMREQUEST] = 'DELETE';
          break;
      }

      if ($data && in_array(strtoupper($method), ['POST', 'PUT'])) {
        $isJson = false;
        foreach ($config['headers'] as $header) {
          if (stripos($header, 'Content-Type: application/json') === 0) {
            $isJson = true;
            break;
          }
        }
        $curlOpts[CURLOPT_POSTFIELDS] = $isJson && is_array($data) ? json_encode($data) : $data;
      }

      if (!empty($config['headers'])) {
        $curlOpts[CURLOPT_HTTPHEADER] = $config['headers'];
      }

      curl_setopt_array($ch, $curlOpts);

      $response = curl_exec($ch);
      $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $curlError = curl_error($ch);
      $curlErrno = curl_errno($ch);

      curl_close($ch);

      if ($curlErrno !== 0) {
        ogLog::error('ogHttp::request - cURL error', ['url' => $url, 'error' => $curlError], self::$logMeta);
        return ['success' => false, 'data' => null, 'httpCode' => $httpCode, 'error' => $curlError];
      }

      $decodedData = $response;
      if ($config['auto_json'] && self::isJson($response)) {
        $decodedData = json_decode($response, true);
      }

      $isSuccess = $httpCode >= 200 && $httpCode < 300;

      return [
        'success' => $isSuccess,
        'data' => $decodedData,
        'raw' => $response,
        'httpCode' => $httpCode,
        'error' => $isSuccess ? null : "HTTP Error: {$httpCode}"
      ];

    } catch (Exception $e) {
      ogLog::error('ogHttp::request - Exception', ['url' => $url, 'error' => $e->getMessage()], self::$logMeta);
      return ['success' => false, 'data' => null, 'httpCode' => 0, 'error' => $e->getMessage()];
    }
  }

  private static function isJson($string) {
    if (!is_string($string)) return false;
    json_decode($string);
    return json_last_error() === JSON_ERROR_NONE;
  }
}