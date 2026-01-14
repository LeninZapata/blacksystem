<?php
/**
 * Log - Sistema de logging minimalista
 *
 * Responsabilidad: SOLO escribir logs
 * Formato: [timestamp] [sequence] [level] [layer] [module] [message] [context] [file:line] [user_id] [tags]
 */
class ogLog {

  private static $config = [
    'format' => 'daily',
    'template' => null,
    'level' => 'debug',
    'max_size' => 1048576,
    'enabled' => true,
    'columns' => ['timestamp', 'sequence', 'level', 'layer', 'module', 'message', 'context', 'file_line', 'user_id', 'tags'],
    'separator' => "\t"
  ];

  private static $levels = [
    'debug' => 0,
    'info' => 1,
    'warning' => 2,
    'error' => 3
  ];

  private static $isFatalError = false;
  private static $executionSequence = 0;
  private static $isWriting = false; // Prevenir recursión

  static function setConfig($config) {
    self::$config = array_merge(self::$config, $config);
  }

  static function getConfig() {
    return self::$config;
  }

  static function debug($msg, $ctx = [], $meta = []) {
    if (!OG_IS_DEV) return;
    self::write('DEBUG', $msg, $ctx, $meta);
  }

  static function info($msg, $ctx = [], $meta = []) {
    self::write('INFO', $msg, $ctx, $meta);
  }

  static function success($msg, $ctx = [], $meta = []) {
    self::write('SUCCESS', $msg, $ctx, $meta);
  }

  static function warning($msg, $ctx = [], $meta = []) {
    self::write('WARNING', $msg, $ctx, $meta);
  }

  static function warn($msg, $ctx = [], $meta = []) {
    self::warning($msg, $ctx, $meta);
  }

  static function error($msg, $ctx = [], $meta = []) {
    self::write('ERROR', $msg, $ctx, $meta);
  }

  static function sql($sql, $bindings = []) {
    if (!OG_IS_DEV) return;
    self::write('SQL', $sql, ['bindings' => $bindings], ['module' => 'database']);
  }

  // Combina ogLog::error + throw Exception
  static function throwError($msg, $ctx = [], $meta = []) {
    self::$isFatalError = true;
    self::error($msg, $ctx, $meta);
    self::$isFatalError = false;
    throw new Exception($msg);
  }

  /**
   * Escribir log con microsegundos y secuencia
   */
  private static function write($level, $msg, $ctx = [], $meta = []) {
    // Prevenir recursión infinita
    if (self::$isWriting) return;
    
    // Verificar memoria disponible (si queda menos de 50MB, no loguear)
    $memLimit = ini_get('memory_limit');
    if ($memLimit !== '-1') {
      $limit = self::parseMemoryLimit($memLimit);
      $used = memory_get_usage(true);
      if ($limit - $used < 52428800) { // 50MB
        return; // No loguear si hay poca memoria
      }
    }
    
    self::$isWriting = true;
    
    if (!self::$config['enabled']) {
      self::$isWriting = false;
      return;
    }

    // Protección contra out of memory: limitar contexto desde el inicio
    if (!empty($ctx)) {
      $tempJson = @json_encode($ctx);
      if ($tempJson === false || strlen($tempJson) > 5000) {
        $ctx = ['_note' => 'Context too large, truncated', '_size' => is_array($ctx) ? count($ctx) : 'N/A'];
      }
    }

    $minLevel = self::$levels[self::$config['level']] ?? 0;
    $currentLevel = self::$levels[strtolower($level)] ?? 0;
    if ($currentLevel < $minLevel) return;

    // Incrementar secuencia
    self::$executionSequence++;

    // Timestamp con microsegundos
    $microtime = microtime(true);
    $timestamp = date('Y-m-d H:i:s', (int)$microtime) . '.' . str_pad(substr(explode('.', (string)$microtime)[1] ?? '000000', 0, 6), 6, '0');

    // Auto-detectar file y line
    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
    $caller = $trace[2] ?? $trace[1] ?? [];

    $file = isset($caller['file']) ? basename($caller['file']) : 'unknown';
    $line = $caller['line'] ?? 0;
    $module = $meta['module'] ?? 'DEBUG';
    $layer = $meta['layer'] ?? 'app';

    // Extraer tags
    $tags = $meta['tags'] ?? [];
    if (!is_array($tags)) $tags = [$tags];
    $tagsStr = !empty($tags) ? implode(',', $tags) : '';

    // Normalizar $ctx a array
    if ($ctx === null || $ctx === '') {
      $ctx = [];
    } elseif (!is_array($ctx)) {
      $ctx = ['value' => $ctx];
    }

    // Extraer custom vars (todo excepto 'module', 'layer' y 'tags')
    $customVars = [];
    foreach ($meta as $key => $value) {
      if (!in_array($key, ['module', 'layer', 'tags', 'custom'])) {
        $customVars[$key] = $value;
      }
    }

    if (isset($meta['custom']) && is_array($meta['custom'])) {
      $customVars = array_merge($customVars, $meta['custom']);
    }

    // Extraer user_id si existe (para columna separada)
    $userId = $customVars['user_id'] ?? ($GLOBALS['auth_user_id'] ?? null);
    $userIdStr = $userId ? (string)$userId : '';
    
    // Remover user_id de customVars para no duplicarlo en context
    unset($customVars['user_id']);

    // Combinar contexto + custom vars
    $fullContext = array_merge($ctx, $customVars);
    
    // Protección contra contextos muy grandes que causan out of memory
    if (!empty($fullContext)) {
      $contextJson = @json_encode($fullContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      if ($contextJson === false || strlen($contextJson) > 10000) {
        // Si falla o es muy grande, crear versión simplificada
        $simplified = [];
        foreach ($fullContext as $key => $value) {
          if (is_array($value)) {
            $simplified[$key] = '[Array: ' . count($value) . ' items]';
          } elseif (is_object($value)) {
            $simplified[$key] = '[Object: ' . get_class($value) . ']';
          } elseif (is_string($value) && strlen($value) > 200) {
            $simplified[$key] = substr($value, 0, 200) . '...[truncated]';
          } else {
            $simplified[$key] = $value;
          }
        }
        $contextJson = json_encode($simplified, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      }
    } else {
      $contextJson = '-';
    }

    // Preparar datos para columnas
    $columnData = [
      'timestamp' => "[$timestamp]",
      'sequence' => self::$executionSequence,
      'level' => $level,
      'layer' => $layer,
      'module' => $module,
      'message' => $msg,
      'context' => $contextJson,
      'file_line' => "$file:$line",
      'user_id' => $userIdStr ?: '-',
      'tags' => $tagsStr ?: '-'
    ];

    // Construir línea de log según columnas configuradas
    $separator = self::$config['separator'];
    $logParts = [];
    foreach (self::$config['columns'] as $column) {
      if (isset($columnData[$column])) {
        $value = $columnData[$column];
        // Sanitizar: trim + reemplazar separator interno por espacio
        $value = trim($value, $separator);
        $value = str_replace($separator, ' ', $value);
        $logParts[] = $value;
      }
    }

    $logLine = implode($separator, $logParts) . PHP_EOL;

    // Obtener ruta y escribir
    $logFile = self::getLogFilePath($level, $module, $meta);

    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
      mkdir($logDir, 0755, true);
    }

    $logFile = self::checkRotation($logFile);

    file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);

    // Resetear flag de escritura
    self::$isWriting = false;

    // Manejar errores fatales solo si viene de throwError
    if (strtoupper($level) === 'ERROR' && self::$isFatalError) {
      self::handleFatalError($level, $msg, $ctx, $meta);
    }
  }

  // Parsear memory_limit a bytes
  private static function parseMemoryLimit($limit) {
    $limit = trim($limit);
    $last = strtolower($limit[strlen($limit)-1]);
    $value = (int)$limit;
    switch($last) {
      case 'g': $value *= 1024;
      case 'm': $value *= 1024;
      case 'k': $value *= 1024;
    }
    return $value;
  }

  // Handler para errores fatales - vacío por ahora, listo para notificaciones
  private static function handleFatalError($level, $msg, $ctx, $meta) {
    // TODO: Implementar notificaciones (email, Slack, SMS, etc)
  }

  // Obtener ruta del archivo según formato
  private static function getLogFilePath($level, $module, $meta) {
    $basePath = ogApp()->getPath('storage/logs');
    $date = [
      'year' => date('Y'),
      'month' => date('m'),
      'day' => date('d'),
      'hour' => date('H')
    ];

    $format = self::$config['format'];

    switch ($format) {
      case 'single':
        return "$basePath/app.log";

      case 'monthly':
        return "$basePath/{$date['year']}/{$date['month']}/app.log";

      case 'daily':
        return "$basePath/{$date['year']}/{$date['month']}/{$date['day']}/app.log";

      case 'custom':
        return self::parseTemplate($basePath, $date, $module, $meta);

      default:
        return "$basePath/{$date['year']}/{$date['month']}/{$date['day']}/app.log";
    }
  }

  // Parsear template personalizado
  private static function parseTemplate($basePath, $date, $module, $meta) {
    $template = self::$config['template'] ?? '{year}/{month}/{day}/{module}.log';

    $template = str_replace(
      ['{year}', '{month}', '{day}', '{hour}'],
      [$date['year'], $date['month'], $date['day'], $date['hour']],
      $template
    );

    $template = str_replace('{module}', $module, $template);

    // Custom vars
    foreach ($meta as $key => $value) {
      if (!in_array($key, ['module', 'tags', 'custom'])) {
        $template = str_replace("{{$key}}", $value, $template);
      }
    }

    if (isset($meta['custom']) && is_array($meta['custom'])) {
      foreach ($meta['custom'] as $key => $value) {
        $template = str_replace("{{$key}}", $value, $template);
      }
    }

    $template = preg_replace('/\{[^}]+\}/', 'default', $template);

    if (!str_ends_with($template, '.log')) {
      $template .= '.log';
    }

    return "$basePath/$template";
  }

  // Rotación por tamaño
  private static function checkRotation($logFile) {
    $maxSize = self::$config['max_size'];

    if (!file_exists($logFile) || filesize($logFile) < $maxSize) {
      return $logFile;
    }

    $pathInfo = pathinfo($logFile);
    $dir = $pathInfo['dirname'];
    $filename = $pathInfo['filename'];
    $ext = $pathInfo['extension'];

    $counter = 1;
    while (true) {
      $newFile = "$dir/{$filename}_{$counter}.$ext";

      if (!file_exists($newFile) || filesize($newFile) < $maxSize) {
        return $newFile;
      }

      $counter++;

      if ($counter > 1000) {
        return $logFile;
      }
    }
  }
}