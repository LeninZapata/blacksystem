<?php
/**
 * logReader - Leer, parsear y filtrar logs
 *
 * Responsabilidad: SOLO leer/consultar logs
 * Separado de log.php para mantener responsabilidades claras
 */
class logReader {

  /**
   * Parsear archivo de log
   *
   * @param string $file Ruta del archivo
   * @param int $limit Límite de líneas (0 = todas)
   * @return array Array de logs parseados
   */
  static function parse($file, $limit = 0) {
    if (!file_exists($file)) {
      return [];
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($limit > 0) {
      $lines = array_slice($lines, -$limit);
    }

    $logs = [];
    foreach ($lines as $line) {
      $parts = explode("\t", $line);

      if (count($parts) >= 8) {
        // Formato nuevo: [timestamp] [level] [module] [message] [context_json] [file:line] [user_id] [tags]
        $contextJson = $parts[4] ?? '';
        $context = ($contextJson && $contextJson !== '-') ? json_decode($contextJson, true) : null;

        $userId = $parts[6] ?? '';
        $userId = ($userId && $userId !== '-') ? $userId : null;

        $tagsStr = $parts[7] ?? '';
        $tags = ($tagsStr && $tagsStr !== '-') ? explode(',', $tagsStr) : [];

        $logs[] = [
          'timestamp' => trim($parts[0], '[]'),
          'level' => $parts[1],
          'module' => $parts[2],
          'message' => $parts[3],
          'context' => $context,
          'location' => $parts[5],
          'user_id' => $userId,
          'tags' => $tags
        ];
      } elseif (count($parts) >= 6) {
        // Formato anterior: [timestamp] [level] [module] [file:line] [message] [context_json] [tags]
        $contextJson = $parts[5] ?? '';
        $context = ($contextJson && $contextJson !== '-') ? json_decode($contextJson, true) : null;

        $tagsStr = $parts[6] ?? '';
        $tags = ($tagsStr && $tagsStr !== '-') ? explode(',', $tagsStr) : [];

        $logs[] = [
          'timestamp' => trim($parts[0], '[]'),
          'level' => $parts[1],
          'module' => $parts[2],
          'message' => $parts[4],
          'location' => $parts[3],
          'context' => $context,
          'user_id' => null,
          'tags' => $tags
        ];
      } elseif (count($parts) >= 5) {
        // Formato antiguo sin tags (retrocompatibilidad)
        $contextJson = $parts[5] ?? '';
        $context = ($contextJson && $contextJson !== '-') ? json_decode($contextJson, true) : null;

        $logs[] = [
          'timestamp' => trim($parts[0], '[]'),
          'level' => $parts[1],
          'module' => $parts[2],
          'location' => $parts[3],
          'message' => $parts[4],
          'tags' => [],
          'context' => $context
        ];
      }
    }

    return $logs;
  }

  /**
   * Buscar archivos de log según criterios
   *
   * @param array $criteria Criterios: year, month, day
   * @return array Array de rutas de archivos
   */
  static function find($criteria = []) {
    $basePath = LOG_PATH;
    $pattern = $basePath;

    if (isset($criteria['year'])) {
      $pattern .= '/' . $criteria['year'];
    } else {
      $pattern .= '/*';
    }

    if (isset($criteria['month'])) {
      $pattern .= '/' . $criteria['month'];
    } else {
      $pattern .= '/*';
    }

    if (isset($criteria['day'])) {
      $pattern .= '/' . $criteria['day'];
    } else {
      $pattern .= '/*';
    }

    $files = glob($pattern . '/*.log');

    // Buscar en subdirectorios también
    $subdirs = glob($pattern . '/*', GLOB_ONLYDIR);
    foreach ($subdirs as $subdir) {
      $files = array_merge($files, glob($subdir . '/*.log'));
    }

    return $files ?: [];
  }

  /**
   * Obtener logs de hoy
   *
   * @param int $limit Límite de logs
   * @return array Array de logs
   */
  static function today($limit = 100) {
    $files = self::find([
      'year' => date('Y'),
      'month' => date('m'),
      'day' => date('d')
    ]);

    return self::getLogsFromFiles($files, $limit);
  }

  /**
   * Obtener últimos logs
   *
   * @param int $limit Límite de logs
   * @return array Array de logs
   */
  static function latest($limit = 50) {
    $files = self::find([]);

    // Ordenar por fecha de modificación DESC
    usort($files, function($a, $b) {
      return filemtime($b) - filemtime($a);
    });

    // Solo últimos 10 archivos
    $files = array_slice($files, 0, 10);

    return self::getLogsFromFiles($files, $limit);
  }

  /**
   * Obtener logs en un rango de fechas
   *
   * @param string $from Fecha inicio (Y-m-d)
   * @param string $to Fecha fin (Y-m-d)
   * @param int $limit Límite de logs
   * @return array Array de logs
   */
  static function range($from, $to, $limit = 500) {
    $allLogs = [];
    $currentDate = strtotime($from);
    $endDate = strtotime($to);

    while ($currentDate <= $endDate) {
      $date = date('Y-m-d', $currentDate);
      list($year, $month, $day) = explode('-', $date);

      $files = self::find([
        'year' => $year,
        'month' => $month,
        'day' => $day
      ]);

      foreach ($files as $file) {
        $logs = self::parse($file);
        $allLogs = array_merge($allLogs, $logs);
      }

      $currentDate = strtotime('+1 day', $currentDate);
    }

    usort($allLogs, function($a, $b) {
      return strtotime($b['timestamp']) - strtotime($a['timestamp']);
    });

    return $limit > 0 ? array_slice($allLogs, 0, $limit) : $allLogs;
  }

  /**
   * Filtrar logs según criterios
   *
   * @param array $logs Array de logs
   * @param array $filters Filtros disponibles:
   *   - level: Nivel del log
   *   - module: Módulo específico
   *   - tags: Array de tags (busca si contiene al menos uno)
   *   - search: Buscar en mensaje o contexto
   *   - [custom_var]: Buscar en context (ej: number, bot_id, user_id)
   * @return array Logs filtrados
   */
  static function filter($logs, $filters = []) {
    if (empty($filters)) {
      return $logs;
    }

    foreach ($logs as $key => $log) {
      // Filtro por nivel
      if (isset($filters['level'])) {
        if (strtoupper($log['level']) !== strtoupper($filters['level'])) {
          unset($logs[$key]);
          continue;
        }
      }

      // Filtro por módulo
      if (isset($filters['module'])) {
        if ($log['module'] !== $filters['module']) {
          unset($logs[$key]);
          continue;
        }
      }

      // Filtro por TAGS
      if (isset($filters['tags'])) {
        $searchTags = is_array($filters['tags']) ? $filters['tags'] : [$filters['tags']];
        $hasTag = false;
        foreach ($searchTags as $tag) {
          if (in_array($tag, $log['tags'])) {
            $hasTag = true;
            break;
          }
        }
        if (!$hasTag) {
          unset($logs[$key]);
          continue;
        }
      }

      // Búsqueda en mensaje
      if (isset($filters['search'])) {
        $search = strtolower($filters['search']);
        $found = false;

        if (stripos($log['message'], $search) !== false) {
          $found = true;
        }

        if (!$found && $log['context'] && is_array($log['context'])) {
          $contextStr = json_encode($log['context']);
          if (stripos($contextStr, $search) !== false) {
            $found = true;
          }
        }

        if (!$found) {
          unset($logs[$key]);
          continue;
        }
      }

      // Filtros por custom vars (number, bot_id, etc.)
      foreach ($filters as $filterKey => $filterValue) {
        if (in_array($filterKey, ['level', 'module', 'tags', 'search'])) continue;

        if ($log['context'] && isset($log['context'][$filterKey])) {
          if ($log['context'][$filterKey] != $filterValue) {
            unset($logs[$key]);
            continue 2;
          }
        } else {
          unset($logs[$key]);
          continue 2;
        }
      }
    }

    return array_values($logs);
  }

  /**
   * Obtener estadísticas de logs
   *
   * @param array $logs Array de logs
   * @return array Estadísticas
   */
  static function stats($logs) {
    $stats = [
      'total' => count($logs),
      'by_level' => [],
      'by_module' => [],
      'by_tags' => []
    ];

    foreach ($logs as $log) {
      // Por nivel
      $level = $log['level'];
      $stats['by_level'][$level] = ($stats['by_level'][$level] ?? 0) + 1;

      // Por módulo
      $module = $log['module'];
      $stats['by_module'][$module] = ($stats['by_module'][$module] ?? 0) + 1;

      // Por tags
      foreach ($log['tags'] as $tag) {
        $stats['by_tags'][$tag] = ($stats['by_tags'][$tag] ?? 0) + 1;
      }
    }

    return $stats;
  }

  // Helper: Obtener logs de múltiples archivos
  private static function getLogsFromFiles($files, $limit = 0) {
    $allLogs = [];
    foreach ($files as $file) {
      $logs = self::parse($file, 0);
      $allLogs = array_merge($allLogs, $logs);
    }

    usort($allLogs, function($a, $b) {
      return strtotime($b['timestamp']) - strtotime($a['timestamp']);
    });

    return $limit > 0 ? array_slice($allLogs, 0, $limit) : $allLogs;
  }
}