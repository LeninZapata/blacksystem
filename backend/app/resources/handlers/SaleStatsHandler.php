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
    $dates = ogApp()->helper('date')::getDateRange($range);

    if (!$dates) {
      return ['success' => false, 'error' => 'Rango inválido'];
    }

    // Query: Monto total por día (ventas confirmadas)
    $sqlRevenue = "
      SELECT
        DATE(payment_date) as date,
        SUM(billed_amount) as revenue,
        COUNT(*) as sales_count
      FROM " . DB_TABLES['sales'] . "
      WHERE user_id = ?
        AND payment_date >= ? AND payment_date <= ?
        AND process_status = 'sale_confirmed'
        AND status = 1
      GROUP BY DATE(payment_date)
      ORDER BY date ASC
    ";

    $revenueData = ogDb::raw($sqlRevenue, [$userId, $dates['start'], $dates['end']]);

    // Query: Conversión % por día
    $sqlConversion = "
      SELECT
        DATE(dc) as date,
        COUNT(*) as total_sales,
        SUM(CASE WHEN process_status = 'sale_confirmed' THEN 1 ELSE 0 END) as confirmed_sales,
        ROUND((SUM(CASE WHEN process_status = 'sale_confirmed' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as conversion_rate
      FROM " . DB_TABLES['sales'] . "
      WHERE user_id = ?
        AND dc >= ? AND dc <= ?
        AND status = 1
      GROUP BY DATE(dc)
      ORDER BY date ASC
    ";

    $conversionData = ogDb::raw($sqlConversion, [$userId, $dates['start'], $dates['end']]);

    // Combinar resultados
    $statsMap = [];

    // Inicializar con revenue
    foreach ($revenueData as $row) {
      $statsMap[$row['date']] = [
        'date' => $row['date'],
        'revenue' => (float)$row['revenue'],
        'sales_count' => (int)$row['sales_count'],
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

    return [
      'success' => true,
      'data' => $results,
      'period' => [
        'start' => $dates['start'],
        'end' => $dates['end'],
        'range' => $range
      ]
    ];
  }

  // Ventas directas vs remarketing (barras apiladas)
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

    // Query combinada: todas las ventas del día con sus estados
    // Nota: Para conversión excluimos origin='upsell' porque son ventas automáticas sin costo de adquisición
    // Pero para ingresos totales SÍ incluimos upsells
    $sql = "
      SELECT
        DATE(dc) as date,
        COUNT(CASE WHEN origin != 'upsell' OR origin IS NULL THEN 1 END) as total_sales,
        SUM(CASE WHEN process_status = 'sale_confirmed' AND (origin != 'upsell' OR origin IS NULL) THEN 1 ELSE 0 END) as confirmed_sales,
        SUM(CASE WHEN tracking_funnel_id IS NULL AND process_status = 'sale_confirmed' THEN billed_amount ELSE 0 END) as direct_revenue,
        SUM(CASE WHEN tracking_funnel_id IS NOT NULL AND process_status = 'sale_confirmed' THEN billed_amount ELSE 0 END) as remarketing_revenue,
        COUNT(CASE WHEN tracking_funnel_id IS NULL AND process_status = 'sale_confirmed' THEN 1 END) as direct_count,
        COUNT(CASE WHEN tracking_funnel_id IS NOT NULL AND process_status = 'sale_confirmed' THEN 1 END) as remarketing_count,
        ROUND((SUM(CASE WHEN process_status = 'sale_confirmed' AND (origin != 'upsell' OR origin IS NULL) THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(CASE WHEN origin != 'upsell' OR origin IS NULL THEN 1 END), 0)), 2) as conversion_rate
      FROM " . DB_TABLES['sales'] . "
      WHERE user_id = ?
        AND dc >= ? AND dc <= ?
        AND status = 1
      GROUP BY DATE(dc)
      ORDER BY date ASC
    ";

    $results = ogDb::raw($sql, [$userId, $dates['start'], $dates['end'] . ' 23:59:59']);

    // Convertir a formato esperado
    $formattedResults = [];
    foreach ($results as $row) {
      $formattedResults[] = [
        'date' => $row['date'],
        'direct_revenue' => (float)$row['direct_revenue'],
        'remarketing_revenue' => (float)$row['remarketing_revenue'],
        'direct_count' => (int)$row['direct_count'],
        'remarketing_count' => (int)$row['remarketing_count'],
        'total_sales' => (int)$row['total_sales'],
        'conversion_rate' => (float)$row['conversion_rate']
      ];
    }

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
}