<?php
class AdAutoScaleStatsHandler {
  private static $logMeta = ['module' => 'AdAutoScaleStatsHandler', 'layer' => 'app/handlers'];

  // Obtener cambios de presupuesto por activo
  static function getBudgetChanges($params) {
    try {
      $assetId = $params['asset_id'] ?? null;
      $range = $params['range'] ?? 'today';
      
      // ✅ Obtener user_id desde memoria (viene del middleware auth)
      $userId = ogCache::memoryGet('auth_user_id', $GLOBALS['auth_user_id'] ?? null);

      if (!$userId) {
        return ogResponse::error('Usuario no autenticado');
      }

      if (!$assetId) {
        return ogResponse::error('asset_id es requerido');
      }

      // Obtener fechas según rango
      $dates = self::getDateRange($range);

      // Consultar historial de cambios
      $sql = "
        SELECT 
          h.id,
          h.rule_id,
          r.name as rule_name,
          h.ad_assets_id,
          h.action_type,
          h.action_result,
          h.executed_at,
          JSON_EXTRACT(h.action_result, '$.budget_before') as budget_before,
          JSON_EXTRACT(h.action_result, '$.budget_after') as budget_after,
          JSON_EXTRACT(h.action_result, '$.change') as budget_change
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

      // Formatear datos
      $data = array_map(function($row) {
        return [
          'id' => (int)$row['id'],
          'rule_id' => (int)$row['rule_id'],
          'rule_name' => $row['rule_name'],
          'action_type' => $row['action_type'],
          'budget_before' => round((float)$row['budget_before'], 2),
          'budget_after' => round((float)$row['budget_after'], 2),
          'budget_change' => round((float)$row['budget_change'], 2),
          'executed_at' => $row['executed_at']
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
    
    switch ($range) {
      case 'today':
        return ['from' => $today, 'to' => $today];
      
      case 'yesterday':
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        return ['from' => $yesterday, 'to' => $yesterday];
      
      case 'last_7_days':
        return ['from' => date('Y-m-d', strtotime('-7 days')), 'to' => $today];
      
      case 'last_15_days':
        return ['from' => date('Y-m-d', strtotime('-15 days')), 'to' => $today];
      
      case 'last_30_days':
        return ['from' => date('Y-m-d', strtotime('-30 days')), 'to' => $today];
      
      case 'this_month':
        return ['from' => date('Y-m-01'), 'to' => $today];
      
      default:
        return ['from' => $today, 'to' => $today];
    }
  }
}