<?php
class AdAutoScaleStatsHandler {
  private static $logMeta = ['module' => 'AdAutoScaleStatsHandler', 'layer' => 'app/handlers'];

  // Obtener cambios de presupuesto por activo
  static function getBudgetChanges($params) {
    try {
      $assetId = $params['asset_id'] ?? null;
      $range = $params['range'] ?? 'today';
      
      $userId = ogCache::memoryGet('auth_user_id', $GLOBALS['auth_user_id'] ?? null);
      $userId = $userId ?? ($params['user_id'] ?? null); // Fallback para testing

      if (!$userId) {
        return ogResponse::error('Usuario no autenticado');
      }

      if (!$assetId) {
        return ogResponse::error('asset_id es requerido');
      }

      // Obtener fechas según rango
      $dates = self::getDateRange($range);

      // Consultar historial de cambios - incluir conditions_result y metrics_snapshot
      $sql = "
        SELECT 
          h.id,
          h.rule_id,
          r.name as rule_name,
          h.ad_assets_id,
          h.action_type,
          h.execution_source,
          h.executed_at,
          h.action_result,
          h.conditions_result,
          h.metrics_snapshot,
          h.execution_time_ms
        FROM ad_auto_scale_history h
        LEFT JOIN ad_auto_scale r ON h.rule_id = r.id
        WHERE h.ad_assets_id = ?
          AND h.user_id = ?
          AND h.action_executed = 1
          AND h.action_type IN ('increase_budget', 'decrease_budget', 'pause')
          AND h.executed_at >= ?
          AND h.executed_at <= ?
        ORDER BY h.executed_at ASC
      ";

      $results = ogDb::raw($sql, [
        $assetId,
        $userId,
        $dates['from'],
        $dates['to'] . ' 23:59:59'
      ]);

      // Formatear datos - extraer desde action_result, incluir conditions y metrics
      $data = array_map(function($row) {
        // Decodificar action_result (aquí está budget_before/after)
        $actionResult = is_string($row['action_result']) 
          ? json_decode($row['action_result'], true) 
          : $row['action_result'];

        $budgetBefore = $actionResult['budget_before'] ?? 0;
        $budgetAfter = $actionResult['budget_after'] ?? 0;
        $adjustmentAmount = $actionResult['change'] ?? ($budgetAfter - $budgetBefore);

        // Decodificar conditions_result
        $conditionsResult = null;
        if (!empty($row['conditions_result'])) {
          $conditionsResult = is_string($row['conditions_result']) 
            ? json_decode($row['conditions_result'], true) 
            : $row['conditions_result'];
          
          // Debug: Log de conditions_result
          if ($conditionsResult) {
            ogLog::debug('getBudgetChanges - conditions_result decoded', [
              'id' => $row['id'],
              'has_details' => isset($conditionsResult['details']),
              'details_count' => isset($conditionsResult['details']) ? count($conditionsResult['details']) : 0,
              'keys' => array_keys($conditionsResult)
            ], self::$logMeta);
          }
        }

        // Decodificar metrics_snapshot
        $metricsSnapshot = null;
        if (!empty($row['metrics_snapshot'])) {
          $metricsSnapshot = is_string($row['metrics_snapshot']) 
            ? json_decode($row['metrics_snapshot'], true) 
            : $row['metrics_snapshot'];
        }

        return [
          'id' => (int)$row['id'],
          'rule_id' => (int)$row['rule_id'],
          'rule_name' => $row['rule_name'],
          'action_type' => $row['action_type'],
          'execution_source' => $row['execution_source'],
          'budget_before' => round((float)$budgetBefore, 2),
          'budget_after' => round((float)$budgetAfter, 2),
          'budget_change' => round((float)$adjustmentAmount, 2),
          'conditions_result' => $conditionsResult,
          'metrics_snapshot' => $metricsSnapshot,
          'executed_at' => $row['executed_at'],
          'execution_time_ms' => $row['execution_time_ms']
        ];
      }, $results);

      ogLog::info('getBudgetChanges - Datos obtenidos', [
        'asset_id' => $assetId,
        'range' => $range,
        'count' => count($data)
      ], self::$logMeta);

      return ogResponse::success($data);

    } catch (Exception $e) {
      ogLog::error('getBudgetChanges - Error', ['error' => $e->getMessage()], self::$logMeta);
      return ogResponse::error($e->getMessage());
    }
  }

  // Convertir rango a fechas
  private static function getDateRange($range) {
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    
    // Soporte para fechas personalizadas (formato: custom:2026-02-13)
    if (strpos($range, 'custom:') === 0) {
      $customDate = substr($range, 7);
      if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $customDate)) {
        return ['from' => $customDate, 'to' => $customDate];
      }
    }
    
    switch ($range) {
      case 'today':
        return ['from' => $today, 'to' => $today];
      
      case 'yesterday':
        return ['from' => $yesterday, 'to' => $yesterday];
      
      case 'yesterday_today':
        return ['from' => $yesterday, 'to' => $today];
      
      case 'last_3_days':
        // Últimos 3 días SIN incluir hoy
        return ['from' => date('Y-m-d', strtotime('-3 days')), 'to' => $yesterday];
      
      case 'last_7_days':
        // Últimos 7 días SIN incluir hoy
        return ['from' => date('Y-m-d', strtotime('-7 days')), 'to' => $yesterday];
      
      case 'last_15_days':
        // Últimos 15 días SIN incluir hoy
        return ['from' => date('Y-m-d', strtotime('-15 days')), 'to' => $yesterday];
      
      case 'last_30_days':
        // Últimos 30 días SIN incluir hoy
        return ['from' => date('Y-m-d', strtotime('-30 days')), 'to' => $yesterday];
      
      case 'this_month':
        return ['from' => date('Y-m-01'), 'to' => $today];
      
      default:
        return ['from' => $today, 'to' => $today];
    }
  }

  // Obtener cambios de presupuesto agrupados por día
  static function getBudgetChangesByDay($params) {
    try {
      $assetId = $params['asset_id'] ?? null;
      $range = $params['range'] ?? 'last_7_days';
      
      $userId = ogCache::memoryGet('auth_user_id', $GLOBALS['auth_user_id'] ?? null);
      $userId = $userId ?? ($params['user_id'] ?? null); // Fallback para testing

      if (!$userId) {
        return ogResponse::error('Usuario no autenticado');
      }

      if (!$assetId) {
        return ogResponse::error('asset_id es requerido');
      }

      // Obtener fechas según rango
      $dates = self::getDateRange($range);

      // Consultar historial agrupado por día
      $sql = "
        SELECT 
          DATE(h.executed_at) as date,
          COUNT(CASE WHEN h.action_type = 'increase_budget' THEN 1 END) as positive_rules_count,
          COUNT(CASE WHEN h.action_type = 'decrease_budget' THEN 1 END) as negative_rules_count,
          COUNT(CASE WHEN h.action_type = 'pause' THEN 1 END) as pause_count,
          COUNT(*) as total_changes,
          MAX(
            CASE 
              WHEN h.action_type IN ('increase_budget', 'decrease_budget') THEN
                JSON_EXTRACT(h.action_result, '$.budget_after')
              ELSE NULL
            END
          ) as final_budget_raw
        FROM ad_auto_scale_history h
        WHERE h.ad_assets_id = ?
          AND h.user_id = ?
          AND h.action_executed = 1
          AND h.action_type IN ('increase_budget', 'decrease_budget', 'pause')
          AND h.executed_at >= ?
          AND h.executed_at <= ?
        GROUP BY DATE(h.executed_at)
        ORDER BY date ASC
      ";

      $results = ogDb::raw($sql, [
        $assetId,
        $userId,
        $dates['from'],
        $dates['to'] . ' 23:59:59'
      ]);

      // Formatear datos
      $data = array_map(function($row) {
        // Limpiar el valor final_budget_raw (quitar comillas si es JSON string)
        $finalBudget = $row['final_budget_raw'];
        if (is_string($finalBudget)) {
          $finalBudget = trim($finalBudget, '"');
        }

        return [
          'date' => $row['date'],
          'positive_rules_count' => (int)$row['positive_rules_count'],
          'negative_rules_count' => (int)$row['negative_rules_count'],
          'pause_count' => (int)$row['pause_count'],
          'total_changes' => (int)$row['total_changes'],
          'final_budget' => round((float)$finalBudget, 2)
        ];
      }, $results);

      ogLog::info('getBudgetChangesByDay - Datos obtenidos', [
        'asset_id' => $assetId,
        'range' => $range,
        'days' => count($data)
      ], self::$logMeta);

      return ogResponse::success($data);

    } catch (Exception $e) {
      ogLog::error('getBudgetChangesByDay - Error', ['error' => $e->getMessage()], self::$logMeta);
      return ogResponse::error($e->getMessage());
    }
  }
}