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
    if (!self::$config['enabled']) return;

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
    $contextJson = !empty($fullContext) ? json_encode($fullContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '-';

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

    // Manejar errores fatales solo si viene de throwError
    if (strtoupper($level) === 'ERROR' && self::$isFatalError) {
      self::handleFatalError($level, $msg, $ctx, $meta);
    }
  }

  // Handler para errores fatales - vacío por ahora, listo para notificaciones
  private static function handleFatalError($level, $msg, $ctx, $meta) {
    // TODO: Implementar notificaciones (email, Slack, SMS, etc)
  }

  // Obtener ruta del archivo según formato
  private static function getLogFilePath($level, $module, $meta) {
    $basePath = LOG_PATH;
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