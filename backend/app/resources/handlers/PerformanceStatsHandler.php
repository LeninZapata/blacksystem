<?php
class PerformanceStatsHandler {

  // Métricas de rendimiento por hora (para hoy/ayer)
  static function getPerformanceHourly($params) {
    // Obtener user_id autenticado
    if (!isset($GLOBALS['auth_user_id'])) {
      return ['success' => false, 'error' => __('auth.unauthorized')];
    }
    $userId = $GLOBALS['auth_user_id'];

    // Parámetros requeridos
    $date = $params['date'] ?? date('Y-m-d');
    $botId = $params['bot_id'] ?? null;
    $productId = $params['product_id'] ?? null;

    if (!$botId) {
      return ['success' => false, 'error' => 'bot_id es requerido'];
    }

    // Construir query para métricas de ads (hourly)
    $sql = "
      SELECT
        h.query_hour as hour,
        SUM(h.link_clicks) as link_clicks,
        SUM(h.reach) as reach,
        SUM(h.results) as results
      FROM ad_metrics_hourly h
      INNER JOIN product_ad_assets pa ON h.ad_asset_id = pa.ad_asset_id
      WHERE h.user_id = ?
        AND pa.product_id IN (
          SELECT id FROM " . ogDb::t('products', true) . "
          WHERE user_id = ? AND bot_id = ? AND status = 1
        )
        AND h.query_date = ?
        AND h.is_latest = 1
    ";

    $queryParams = [$userId, $userId, $botId, $date];

    if ($productId) {
      $sql .= " AND pa.product_id = ?";
      $queryParams[] = $productId;
    }

    $sql .= "
      GROUP BY h.query_hour
      ORDER BY h.query_hour ASC
    ";

    $results = ogDb::raw($sql, $queryParams);

    // Crear array con todas las 24 horas inicializadas en 0
    $hourlyData = [];
    for ($h = 0; $h < 24; $h++) {
      $hourlyData[$h] = [
        'hour' => $h,
        'chats_initiated' => 0,
        'whatsapp_clicks' => 0,
        'reach' => 0
      ];
    }

    // Llenar con datos reales
    foreach ($results as $row) {
      $hour = (int)$row['hour'];
      $hourlyData[$hour] = [
        'hour' => $hour,
        'chats_initiated' => (int)$row['results'],
        'whatsapp_clicks' => (int)$row['link_clicks'],
        'reach' => (int)$row['reach']
      ];
    }

    // Convertir a array indexado
    $data = array_values($hourlyData);

    return [
      'success' => true,
      'data' => $data,
      'date' => $date,
      'bot_id' => $botId,
      'product_id' => $productId
    ];
  }

  // Métricas de rendimiento por día (histórico)
  static function getPerformanceDaily($params) {
    // Obtener user_id autenticado
    if (!isset($GLOBALS['auth_user_id'])) {
      return ['success' => false, 'error' => __('auth.unauthorized')];
    }
    $userId = $GLOBALS['auth_user_id'];

    $range = $params['range'] ?? 'last_7_days';
    $botId = $params['bot_id'] ?? null;
    $productId = $params['product_id'] ?? null;
    $dates = ogApp()->helper('date')::getDateRange($range);

    if (!$dates) {
      return ['success' => false, 'error' => 'Rango inválido'];
    }

    if (!$botId) {
      return ['success' => false, 'error' => 'bot_id es requerido'];
    }

    // Query para métricas diarias de ads
    $sql = "
      SELECT
        d.metric_date as date,
        SUM(d.link_clicks) as link_clicks,
        SUM(d.reach) as reach,
        SUM(d.results) as results,
        SUM(d.spend) as spend,
        SUM(d.impressions) as impressions
      FROM ad_metrics_daily d
      INNER JOIN product_ad_assets pa ON d.ad_asset_id = pa.ad_asset_id
      WHERE d.user_id = ?
        AND pa.product_id IN (
          SELECT id FROM " . ogDb::t('products', true) . "
          WHERE user_id = ? AND bot_id = ? AND status = 1
        )
        AND d.metric_date >= ?
        AND d.metric_date <= ?
    ";

    $queryParams = [$userId, $userId, $botId, $dates['start'], $dates['end']];

    if ($productId) {
      $sql .= " AND pa.product_id = ?";
      $queryParams[] = $productId;
    }

    $sql .= "
      GROUP BY d.metric_date
      ORDER BY d.metric_date ASC
    ";

    $results = ogDb::raw($sql, $queryParams);

    // Preparar datos
    $data = [];
    $totalChatsInitiated = 0;
    $totalWhatsappClicks = 0;
    $totalReach = 0;
    $totalSpend = 0;
    $totalImpressions = 0;

    foreach ($results as $row) {
      $chatsInitiated = (int)$row['results'];
      $whatsappClicks = (int)$row['link_clicks'];
      $reach = (int)$row['reach'];
      $spend = (float)$row['spend'];
      $impressions = (int)$row['impressions'];

      $data[] = [
        'date' => $row['date'],
        'chats_initiated' => $chatsInitiated,
        'whatsapp_clicks' => $whatsappClicks,
        'reach' => $reach,
        'spend' => $spend,
        'impressions' => $impressions
      ];

      $totalChatsInitiated += $chatsInitiated;
      $totalWhatsappClicks += $whatsappClicks;
      $totalReach += $reach;
      $totalSpend += $spend;
      $totalImpressions += $impressions;
    }

    // Calcular tasa de conversión de clics a chats
    $clickToChat = $totalWhatsappClicks > 0 
      ? round(($totalChatsInitiated / $totalWhatsappClicks) * 100, 2) 
      : 0;

    return [
      'success' => true,
      'data' => $data,
      'summary' => [
        'total_chats_initiated' => $totalChatsInitiated,
        'total_whatsapp_clicks' => $totalWhatsappClicks,
        'total_reach' => $totalReach,
        'total_spend' => $totalSpend,
        'total_impressions' => $totalImpressions,
        'click_to_chat_rate' => $clickToChat
      ],
      'period' => [
        'start' => $dates['start'],
        'end' => $dates['end'],
        'range' => $range
      ]
    ];
  }
}
