<?php
class file {

  private static $logMeta = ['module' => 'file', 'layer' => 'framework'];

  static function saveJson($filePath, $data, $module = 'system', $action = 'create') {
    $dir = dirname($filePath);

    if (!is_dir($dir)) {
      if (!mkdir($dir, 0755, true)) {
        log::error("file::saveJson - No se pudo crear directorio: {$dir}", ['dir' => $dir], self::$logMeta);
        return false;
      }
    }

    $jsonData = [
      'created' => date('Y-m-d H:i:s'),
      'module' => $module,
      'action' => $action,
      'data' => $data
    ];

    $json = json_encode($jsonData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    if (file_put_contents($filePath, $json) === false) {
      log::error("file::saveJson - Error al escribir archivo: {$filePath}", ['path' => $filePath], self::$logMeta);
      return false;
    }

    return $data;
  }

  static function saveJsonItems($filePath, $items, $module = 'system', $action = 'create') {
    $dir = dirname($filePath);

    if (!is_dir($dir)) {
      if (!mkdir($dir, 0755, true)) {
        log::error("file::saveJsonItems - No se pudo crear directorio: {$dir}", ['dir' => $dir], self::$logMeta);
        return false;
      }
    }

    $jsonData = [
      'created' => date('Y-m-d H:i:s'),
      'module' => $module,
      'action' => $action,
      'items' => $items
    ];

    $json = json_encode($jsonData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    if (file_put_contents($filePath, $json) === false) {
      log::error("file::saveJsonItems - Error al escribir archivo: {$filePath}", ['path' => $filePath], self::$logMeta);
      return false;
    }

    return $items;
  }

  static function getJson($filePath, $reconstructCallback = null) {
    if (file_exists($filePath)) {
      $content = file_get_contents($filePath);
      if ($content === false) {
        log::error("file::getJson - Error al leer archivo: {$filePath}", ['path' => $filePath], self::$logMeta);
        return null;
      }

      $json = json_decode($content, true);
      if ($json === null) {
        log::error("file::getJson - JSON inválido: {$filePath}", ['path' => $filePath], self::$logMeta);
        return null;
      }

      return $json['data'] ?? $json['items'] ?? $json;
    }

    if ($reconstructCallback && is_callable($reconstructCallback)) {
      return $reconstructCallback();
    }

    return null;
  }

  static function ensureDir($path) {
    if (!is_dir($path)) {
      return mkdir($path, 0755, true);
    }
    return true;
  }

  static function delete($filePath) {
    if (!file_exists($filePath)) {
      return true;
    }

    if (unlink($filePath)) {
      return true;
    }

    log::error("file::delete - No se pudo eliminar archivo: {$filePath}", ['path' => $filePath], self::$logMeta);
    return false;
  }

  static function deletePattern($pattern) {
    $files = glob($pattern);

    if ($files === false) {
      return 0;
    }

    $deleted = 0;
    foreach ($files as $file) {
      if (is_file($file) && unlink($file)) {
        $deleted++;
      }
    }

    return $deleted;
  }
}
