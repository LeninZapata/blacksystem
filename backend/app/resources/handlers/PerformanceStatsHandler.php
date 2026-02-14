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
    // Obtener el último registro de cada hora (sin filtrar por is_latest que solo devuelve 1 registro)
    $sql = "
      SELECT
        h.query_hour as hour,
        SUM(h.link_clicks) as link_clicks,
        SUM(h.reach) as reach,
        SUM(h.results) as results
      FROM ad_metrics_hourly h
      INNER JOIN product_ad_assets pa ON h.ad_asset_id = pa.ad_asset_id
      INNER JOIN (
        SELECT ad_asset_id, query_hour, MAX(id) as max_id
        FROM ad_metrics_hourly
        WHERE user_id = ? AND query_date = ?
        GROUP BY ad_asset_id, query_hour
      ) latest ON h.ad_asset_id = latest.ad_asset_id 
                 AND h.query_hour = latest.query_hour 
                 AND h.id = latest.max_id
      WHERE h.user_id = ?
        AND pa.product_id IN (
          SELECT id FROM " . ogDb::t('products', true) . "
          WHERE user_id = ? AND bot_id = ? AND status = 1
        )
        AND h.query_date = ?
    ";

    $queryParams = [$userId, $date, $userId, $userId, $botId, $date];

    if ($productId) {
      $sql .= " AND pa.product_id = ?";
      $queryParams[] = $productId;
    }

    $sql .= "
      GROUP BY h.query_hour
      ORDER BY h.query_hour ASC
    ";

    $results = ogDb::raw($sql, $queryParams);

    // Crear array con todas las 24 horas con datos acumulativos
    $accumulatedData = [];
    for ($h = 0; $h < 24; $h++) {
      $accumulatedData[$h] = [
        'link_clicks' => 0,
        'reach' => 0,
        'results' => 0
      ];
    }

    // Llenar con datos acumulativos reales
    foreach ($results as $row) {
      $hour = (int)$row['hour'];
      $accumulatedData[$hour] = [
        'link_clicks' => (int)$row['link_clicks'],
        'reach' => (int)$row['reach'],
        'results' => (int)$row['results']
      ];
    }

    // Calcular valores incrementales por hora (diferencia entre horas consecutivas)
    $hourlyData = [];
    for ($h = 0; $h < 24; $h++) {
      if ($h === 0) {
        // Primera hora: usar el valor directo
        $hourlyData[$h] = [
          'hour' => $h,
          'chats_initiated' => $accumulatedData[$h]['results'],
          'whatsapp_clicks' => $accumulatedData[$h]['link_clicks'],
          'reach' => $accumulatedData[$h]['reach']
        ];
      } else {
        // Horas siguientes: calcular diferencia con la hora anterior
        $hourlyData[$h] = [
          'hour' => $h,
          'chats_initiated' => max(0, $accumulatedData[$h]['results'] - $accumulatedData[$h-1]['results']),
          'whatsapp_clicks' => max(0, $accumulatedData[$h]['link_clicks'] - $accumulatedData[$h-1]['link_clicks']),
          'reach' => max(0, $accumulatedData[$h]['reach'] - $accumulatedData[$h-1]['reach'])
        ];
      }
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

    $today = date('Y-m-d');
    $endDateOnly = substr($dates['end'], 0, 10);
    $includesYesterday = ($range === 'yesterday_today');
    
    // Determinar si necesitamos datos de hoy desde ad_metrics_hourly
    $needsToday = ($endDateOnly === $today);

    // PASO 1: Obtener datos históricos de ad_metrics_daily (excluir hoy si está en el rango)
    $data = [];
    $totalChatsInitiated = 0;
    $totalWhatsappClicks = 0;
    $totalReach = 0;
    $totalSpend = 0;
    $totalImpressions = 0;

    // Query para métricas diarias
    if (!$needsToday || $includesYesterday) {
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
      ";

      $queryParams = [$userId, $userId, $botId, $dates['start']];

      // Si incluye hoy, solo traer hasta ayer
      if ($needsToday) {
        $sql .= " AND d.metric_date < ?";
        $queryParams[] = $today;
      } else {
        $sql .= " AND d.metric_date <= ?";
        $queryParams[] = $dates['end'];
      }

      if ($productId) {
        $sql .= " AND pa.product_id = ?";
        $queryParams[] = $productId;
      }

      $sql .= "
        GROUP BY d.metric_date
        ORDER BY d.metric_date ASC
      ";

      $results = ogDb::raw($sql, $queryParams);

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
    }

    // PASO 2: Si el rango incluye hoy, obtener datos de ad_metrics_hourly
    // Como los datos son acumulativos, tomar solo el último snapshot de cada activo
    if ($needsToday) {
      $sqlToday = "
        SELECT
          SUM(h.link_clicks) as link_clicks,
          SUM(h.reach) as reach,
          SUM(h.results) as results,
          SUM(h.spend) as spend,
          SUM(h.impressions) as impressions
        FROM ad_metrics_hourly h
        INNER JOIN product_ad_assets pa ON h.ad_asset_id = pa.ad_asset_id
        INNER JOIN (
          SELECT ad_asset_id, MAX(query_hour) as max_hour
          FROM ad_metrics_hourly
          WHERE user_id = ? AND query_date = ?
          GROUP BY ad_asset_id
        ) latest ON h.ad_asset_id = latest.ad_asset_id 
                   AND h.query_hour = latest.max_hour
        INNER JOIN (
          SELECT ad_asset_id, query_hour, MAX(id) as max_id
          FROM ad_metrics_hourly
          WHERE user_id = ? AND query_date = ?
          GROUP BY ad_asset_id, query_hour
        ) latest_id ON h.ad_asset_id = latest_id.ad_asset_id 
                      AND h.query_hour = latest_id.query_hour 
                      AND h.id = latest_id.max_id
        WHERE h.user_id = ?
          AND pa.product_id IN (
            SELECT id FROM " . ogDb::t('products', true) . "
            WHERE user_id = ? AND bot_id = ? AND status = 1
          )
          AND h.query_date = ?
      ";

      $queryParamsToday = [$userId, $today, $userId, $today, $userId, $userId, $botId, $today];

      if ($productId) {
        $sqlToday .= " AND pa.product_id = ?";
        $queryParamsToday[] = $productId;
      }

      $todayResults = ogDb::raw($sqlToday, $queryParamsToday);

      if (!empty($todayResults) && $todayResults[0]['link_clicks'] !== null) {
        $todayRow = $todayResults[0];
        $chatsInitiated = (int)$todayRow['results'];
        $whatsappClicks = (int)$todayRow['link_clicks'];
        $reach = (int)$todayRow['reach'];
        $spend = (float)$todayRow['spend'];
        $impressions = (int)$todayRow['impressions'];

        $data[] = [
          'date' => $today,
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
