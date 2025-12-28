<?php
class ogUrl {
  static function normalizeUrl($url) {
    if (empty($url) || !is_string($url)) return $url;
    $parts = explode('://', $url, 2);
    if (count($parts) === 2) {
      $protocol = $parts[0];
      $path = preg_replace('#/+#', '/', $parts[1]);
      $path = rtrim($path, '/');
      return $protocol . '://' . $path;
    }
    $url = preg_replace('#/+#', '/', $url);
    return rtrim($url, '/');
  }

  static function addQueryParams($url, $params) {
    if (empty($params) || !is_array($params)) return $url;
    $queryString = http_build_query($params);
    if (empty($queryString)) return $url;
    $separator = strpos($url, '?') !== false ? '&' : '?';
    return $url . $separator . $queryString;
  }

  static function isValid($url) {
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
  }

  static function getDomain($url) {
    $parsed = parse_url($url);
    return $parsed['host'] ?? null;
  }

  static function join(...$segments) {
    $url = implode('/', array_map(function($segment) {
      return trim($segment, '/');
    }, $segments));
    return self::normalizeUrl($url);
  }
}