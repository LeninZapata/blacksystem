<?php
class SaleStatsHandler {

  // Ventas confirmadas ($ y conversión %)
  static function getSalesRevenueAndConversion($params) {
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

    // Query: Monto total por día (ventas confirmadas)
    $sqlRevenue = "
      SELECT
        DATE(payment_date) as date,
        SUM(billed_amount) as revenue,
        COUNT(*) as sales_count,
        SUM(CASE WHEN tracking_funnel_id IS NULL AND (origin != 'upsell' OR origin IS NULL) THEN billed_amount ELSE 0 END) as direct_revenue,
        SUM(CASE WHEN tracking_funnel_id IS NOT NULL AND (origin != 'upsell' OR origin IS NULL) THEN billed_amount ELSE 0 END) as remarketing_revenue,
        SUM(CASE WHEN origin = 'upsell' THEN billed_amount ELSE 0 END) as upsell_revenue,
        COUNT(CASE WHEN tracking_funnel_id IS NULL AND (origin != 'upsell' OR origin IS NULL) THEN 1 END) as direct_count,
        COUNT(CASE WHEN tracking_funnel_id IS NOT NULL AND (origin != 'upsell' OR origin IS NULL) THEN 1 END) as remarketing_count,
        COUNT(CASE WHEN origin = 'upsell' THEN 1 END) as upsell_count
      FROM " . ogDb::t('sales', true) . "
      WHERE user_id = ?
        AND payment_date >= ? AND payment_date <= ?
        AND process_status = 'sale_confirmed'
        AND status = 1";
    
    $revenueParams = [$userId, $dates['start'], $dates['end']];
    
    if ($botId) {
      $sqlRevenue .= " AND bot_id = ?";
      $revenueParams[] = $botId;
    }
    
    if ($productId) {
      $sqlRevenue .= " AND product_id = ?";
      $revenueParams[] = $productId;
    }
    
    $sqlRevenue .= "
      GROUP BY DATE(payment_date)
      ORDER BY date ASC
    ";

    $revenueData = ogDb::raw($sqlRevenue, $revenueParams);

    // Query: Conversión % por día (usando dc = fecha iniciada para medir desempeño del día)
    // Cuenta TODAS las ventas iniciadas en el día (confirmadas, pendientes, canceladas)
    $sqlConversion = "
      SELECT
        DATE(dc) as date,
        COUNT(*) as total_sales,
        SUM(CASE WHEN process_status = 'sale_confirmed' THEN 1 ELSE 0 END) as confirmed_sales,
        ROUND((SUM(CASE WHEN process_status = 'sale_confirmed' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as conversion_rate
      FROM " . ogDb::t('sales', true) . "
      WHERE user_id = ?
        AND dc >= ? AND dc <= ?
        AND status = 1";
    
    $conversionParams = [$userId, $dates['start'], $dates['end']];
    
    if ($botId) {
      $sqlConversion .= " AND bot_id = ?";
      $conversionParams[] = $botId;
    }
    
    if ($productId) {
      $sqlConversion .= " AND product_id = ?";
      $conversionParams[] = $productId;
    }
    
    $sqlConversion .= "
      GROUP BY DATE(dc)
      ORDER BY date ASC
    ";

    $conversionData = ogDb::raw($sqlConversion, $conversionParams);

    // Combinar resultados
    $statsMap = [];

    // Inicializar con revenue
    foreach ($revenueData as $row) {
      $statsMap[$row['date']] = [
        'date' => $row['date'],
        'revenue' => (float)$row['revenue'],
        'sales_count' => (int)$row['sales_count'],
        'direct_revenue' => (float)$row['direct_revenue'],
        'remarketing_revenue' => (float)$row['remarketing_revenue'],
        'upsell_revenue' => (float)$row['upsell_revenue'],
        'direct_count' => (int)$row['direct_count'],
        'remarketing_count' => (int)$row['remarketing_count'],
        'upsell_count' => (int)$row['upsell_count'],
        'conversion_rate' => 0
      ];
    }

    // Agregar conversión
    foreach ($conversionData as $row) {
      $date = $row['date'];

      if (!isset($statsMap[$date])) {
        $statsMap[$date] = [
          'date' => $date,
          'revenue' => 0,
          'sales_count' => 0,
          'direct_revenue' => 0,
          'remarketing_revenue' => 0,
          'upsell_revenue' => 0,
          'direct_count' => 0,
          'remarketing_count' => 0,
          'upsell_count' => 0,
          'conversion_rate' => 0
        ];
      }

      $statsMap[$date]['conversion_rate'] = (float)$row['conversion_rate'];
      $statsMap[$date]['total_sales'] = (int)$row['total_sales'];
      $statsMap[$date]['confirmed_sales'] = (int)$row['confirmed_sales'];
    }

    // Convertir a array ordenado
    $results = array_values($statsMap);
    usort($results, fn($a, $b) => strcmp($a['date'], $b['date']));

    // Calcular totales
    $totalRevenue = array_sum(array_column($results, 'revenue'));
    $totalSales = array_sum(array_column($results, 'sales_count'));
    $totalDirectRevenue = array_sum(array_column($results, 'direct_revenue'));
    $totalRemarketingRevenue = array_sum(array_column($results, 'remarketing_revenue'));
    $totalUpsellRevenue = array_sum(array_column($results, 'upsell_revenue'));
    $totalDirectCount = array_sum(array_column($results, 'direct_count'));
    $totalRemarketingCount = array_sum(array_column($results, 'remarketing_count'));
    $totalUpsellCount = array_sum(array_column($results, 'upsell_count'));
    
    // Calcular conversión promedio global
    $totalProspects = array_sum(array_column($results, 'total_sales'));
    $totalConfirmed = array_sum(array_column($results, 'confirmed_sales'));
    $avgConversion = $totalProspects > 0 ? round(($totalConfirmed * 100.0) / $totalProspects, 2) : 0;

    return [
      'success' => true,
      'data' => $results,
      'summary' => [
        'total_revenue' => $totalRevenue,
        'total_sales' => $totalSales,
        'direct_revenue' => $totalDirectRevenue,
        'remarketing_revenue' => $totalRemarketingRevenue,
        'upsell_revenue' => $totalUpsellRevenue,
        'direct_count' => $totalDirectCount,
        'remarketing_count' => $totalRemarketingCount,
        'upsell_count' => $totalUpsellCount,
        'avg_conversion' => $avgConversion,
        'total_prospects' => $totalProspects,
        'total_confirmed' => $totalConfirmed
      ],
      'period' => [
        'start' => $dates['start'],
        'end' => $dates['end'],
        'range' => $range
      ]
    ];
  }

  // Ventas directas vs remarketing - CORREGIDO: usa payment_date para ingresos
  static function getSalesDirectVsRemarketing($params) {
    // Obtener user_id autenticado
    if (!isset($GLOBALS['auth_user_id'])) {
      return ['success' => false, 'error' => __('auth.unauthorized')];
    }
    $userId = $GLOBALS['auth_user_id'];

    $range = $params['range'] ?? 'last_7_days';
    $dates = ogApp()->helper('date')::getDateRange($range);

    if (!$dates) {
      return ['success' => false, 'error' => 'Rango inválido'];
    }

    // CORREGIDO: Usar payment_date para agrupar ingresos (consistente con AdMetricsHandler)
    // Para conversión seguimos usando dc (fecha de prospecto)
    $sql = "
      SELECT
        DATE(payment_date) as date,
        SUM(CASE WHEN tracking_funnel_id IS NULL AND process_status = 'sale_confirmed' THEN billed_amount ELSE 0 END) as direct_revenue,
        SUM(CASE WHEN tracking_funnel_id IS NOT NULL AND process_status = 'sale_confirmed' THEN billed_amount ELSE 0 END) as remarketing_revenue,
        COUNT(CASE WHEN tracking_funnel_id IS NULL AND process_status = 'sale_confirmed' THEN 1 END) as direct_count,
        COUNT(CASE WHEN tracking_funnel_id IS NOT NULL AND process_status = 'sale_confirmed' THEN 1 END) as remarketing_count
      FROM " . ogDb::t('sales', true) . "
      WHERE user_id = ?
        AND payment_date >= ? AND payment_date <= ?
        AND process_status = 'sale_confirmed'
        AND status = 1
      GROUP BY DATE(payment_date)
      ORDER BY date ASC
    ";

    $revenueData = ogDb::raw($sql, [$userId, $dates['start'], $dates['end']]);

    // Query separada para conversión usando dc
    $sqlConversion = "
      SELECT
        DATE(dc) as date,
        COUNT(CASE WHEN origin != 'upsell' OR origin IS NULL THEN 1 END) as total_sales,
        SUM(CASE WHEN process_status = 'sale_confirmed' AND (origin != 'upsell' OR origin IS NULL) THEN 1 ELSE 0 END) as confirmed_sales,
        ROUND((SUM(CASE WHEN process_status = 'sale_confirmed' AND (origin != 'upsell' OR origin IS NULL) THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(CASE WHEN origin != 'upsell' OR origin IS NULL THEN 1 END), 0)), 2) as conversion_rate
      FROM " . ogDb::t('sales', true) . "
      WHERE user_id = ?
        AND dc >= ? AND dc <= ?
        AND status = 1
      GROUP BY DATE(dc)
      ORDER BY date ASC
    ";

    $conversionData = ogDb::raw($sqlConversion, [$userId, $dates['start'], $dates['end']]);

    // Combinar resultados
    $statsMap = [];

    // Inicializar con ingresos (por payment_date)
    foreach ($revenueData as $row) {
      $statsMap[$row['date']] = [
        'date' => $row['date'],
        'direct_revenue' => (float)$row['direct_revenue'],
        'remarketing_revenue' => (float)$row['remarketing_revenue'],
        'direct_count' => (int)$row['direct_count'],
        'remarketing_count' => (int)$row['remarketing_count'],
        'total_sales' => 0,
        'conversion_rate' => 0
      ];
    }

    // Agregar conversión (por dc)
    foreach ($conversionData as $row) {
      $date = $row['date'];
      
      if (!isset($statsMap[$date])) {
        $statsMap[$date] = [
          'date' => $date,
          'direct_revenue' => 0,
          'remarketing_revenue' => 0,
          'direct_count' => 0,
          'remarketing_count' => 0,
          'total_sales' => 0,
          'conversion_rate' => 0
        ];
      }

      $statsMap[$date]['total_sales'] = (int)$row['total_sales'];
      $statsMap[$date]['conversion_rate'] = (float)$row['conversion_rate'];
    }

    // Convertir a array ordenado
    $formattedResults = array_values($statsMap);
    usort($formattedResults, fn($a, $b) => strcmp($a['date'], $b['date']));

    return [
      'success' => true,
      'data' => $formattedResults,
      'period' => [
        'start' => $dates['start'],
        'end' => $dates['end'],
        'range' => $range
      ]
    ];
  }

  // Ventas por hora (para today/yesterday)
  static function getSalesHourly($params) {
    // Obtener user_id autenticado
    if (!isset($GLOBALS['auth_user_id'])) {
      return ['success' => false, 'error' => __('auth.unauthorized')];
    }
    $userId = $GLOBALS['auth_user_id'];

    // Parámetros requeridos
    $date = $params['date'] ?? date('Y-m-d'); // Fecha específica (hoy por defecto)
    $botId = $params['bot_id'] ?? null;
    $productId = $params['product_id'] ?? null;

    if (!$botId) {
      return ['success' => false, 'error' => 'bot_id es requerido'];
    }

    // Construir query
    $sql = "
      SELECT
        HOUR(payment_date) as hour,
        COUNT(*) as sales_count,
        SUM(billed_amount) as revenue,
        SUM(CASE WHEN tracking_funnel_id IS NULL AND (origin != 'upsell' OR origin IS NULL) THEN billed_amount ELSE 0 END) as direct_revenue,
        SUM(CASE WHEN tracking_funnel_id IS NOT NULL AND (origin != 'upsell' OR origin IS NULL) THEN billed_amount ELSE 0 END) as remarketing_revenue,
        SUM(CASE WHEN origin = 'upsell' THEN billed_amount ELSE 0 END) as upsell_revenue,
        COUNT(CASE WHEN tracking_funnel_id IS NULL AND (origin != 'upsell' OR origin IS NULL) THEN 1 END) as direct_count,
        COUNT(CASE WHEN tracking_funnel_id IS NOT NULL AND (origin != 'upsell' OR origin IS NULL) THEN 1 END) as remarketing_count,
        COUNT(CASE WHEN origin = 'upsell' THEN 1 END) as upsell_count
      FROM " . ogDb::t('sales', true) . "
      WHERE user_id = ?
        AND bot_id = ?
        AND DATE(payment_date) = ?
        AND process_status = 'sale_confirmed'
        AND status = 1
    ";

    $queryParams = [$userId, $botId, $date];

    // Filtrar por producto si se proporciona
    if ($productId) {
      $sql .= " AND product_id = ?";
      $queryParams[] = $productId;
    }

    $sql .= "
      GROUP BY HOUR(payment_date)
      ORDER BY hour ASC
    ";

    $results = ogDb::raw($sql, $queryParams);

    // Crear array con todas las 24 horas (0-23) inicializadas en 0
    $hourlyData = [];
    for ($h = 0; $h < 24; $h++) {
      $hourlyData[$h] = [
        'hour' => $h,
        'sales_count' => 0,
        'revenue' => 0,
        'direct_revenue' => 0,
        'remarketing_revenue' => 0,
        'upsell_revenue' => 0,
        'direct_count' => 0,
        'remarketing_count' => 0,
        'upsell_count' => 0
      ];
    }

    // Llenar con datos reales
    foreach ($results as $row) {
      $hour = (int)$row['hour'];
      $hourlyData[$hour] = [
        'hour' => $hour,
        'sales_count' => (int)$row['sales_count'],
        'revenue' => (float)$row['revenue'],
        'direct_revenue' => (float)$row['direct_revenue'],
        'remarketing_revenue' => (float)$row['remarketing_revenue'],
        'upsell_revenue' => (float)$row['upsell_revenue'],
        'direct_count' => (int)$row['direct_count'],
        'remarketing_count' => (int)$row['remarketing_count'],
        'upsell_count' => (int)$row['upsell_count']
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
}