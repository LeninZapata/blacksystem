<?php
class ProfitStatsHandler {
  private static $logMeta = ['module' => 'ProfitStatsHandler', 'layer' => 'app/resources'];

  /**
   * Obtener profit por hora - Para hoy/ayer
   * Cruza ad_metrics_hourly con sales agrupadas por hora
   */
  static function getProfitHourly($params) {
    $date = $params['date'] ?? ogRequest::query('date', date('Y-m-d'));
    $botId = $params['bot_id'] ?? ogRequest::query('bot_id');
    $productId = $params['product_id'] ?? ogRequest::query('product_id');
    $userId = $GLOBALS['auth_user_id'];

    try {
      $today = date('Y-m-d');
      $isToday = ($date === $today);

      // PASO 1: Obtener métricas de ads por hora
      $sqlAds = "
        SELECT
          h.query_hour as hour,
          SUM(h.spend) as spend,
          SUM(h.impressions) as impressions,
          SUM(h.clicks) as clicks,
          SUM(h.link_clicks) as link_clicks,
          SUM(h.results) as results,
          SUM(h.purchase) as ad_purchases,
          SUM(h.purchase_value) as ad_purchase_value
        FROM ad_metrics_hourly h
      ";

      // JOIN con product_ad_assets si se filtra por bot o producto
      if ($botId || $productId) {
        $sqlAds .= "
          INNER JOIN (
            SELECT DISTINCT paa.ad_asset_id, paa.product_id, p.bot_id
            FROM product_ad_assets paa
            INNER JOIN products p ON paa.product_id = p.id
          ) paa ON h.ad_asset_id = paa.ad_asset_id
        ";
      }

      $sqlAds .= "
        WHERE h.user_id = ?
          AND h.query_date = ?
      ";

      // Tomar el último snapshot de cada hora (tanto para hoy como días históricos)
      // Esto asegura que obtengamos datos de todas las horas que han transcurrido
      $sqlAds .= " AND h.id IN (
        SELECT MAX(h2.id)
        FROM ad_metrics_hourly h2
        WHERE h2.user_id = ? 
          AND h2.query_date = ?
          AND h2.ad_asset_id = h.ad_asset_id
          AND h2.query_hour = h.query_hour
        GROUP BY h2.ad_asset_id, h2.query_hour
      )";

      $params = [$userId, $date, $userId, $date];

      if ($botId) {
        $sqlAds .= " AND paa.bot_id = ?";
        $params[] = $botId;
      }

      if ($productId) {
        $sqlAds .= " AND paa.product_id = ?";
        $params[] = $productId;
      }

      $sqlAds .= " GROUP BY h.query_hour ORDER BY h.query_hour ASC";

      $adsData = ogDb::raw($sqlAds, $params);

      // PASO 2: Obtener ventas reales por hora del mismo día
      $sqlSales = "
        SELECT
          HOUR(s.payment_date) as hour,
          COUNT(DISTINCT s.id) as real_purchases,
          COALESCE(SUM(s.billed_amount), 0) as revenue
        FROM sales s
        WHERE s.user_id = ?
          AND DATE(s.payment_date) = ?
          AND s.context = 'whatsapp'
          AND s.process_status = 'sale_confirmed'
          AND s.status = 1
      ";

      $salesParams = [$userId, $date];

      if ($botId) {
        $sqlSales .= " AND s.bot_id = ?";
        $salesParams[] = $botId;
      }

      if ($productId) {
        $sqlSales .= " AND s.product_id = ?";
        $salesParams[] = $productId;
      }

      $sqlSales .= " GROUP BY HOUR(s.payment_date)";

      $salesData = ogDb::raw($sqlSales, $salesParams);

      // Crear mapa de ventas por hora
      $salesMap = [];
      foreach ($salesData as $sale) {
        $salesMap[(int)$sale['hour']] = [
          'real_purchases' => (int)$sale['real_purchases'],
          'revenue' => (float)$sale['revenue']
        ];
      }

      // PASO 3: Combinar datos y calcular profit
      // Acumular revenue progresivamente (tanto para hoy como para días históricos)
      // El gasto (spend) ya viene acumulado de ad_metrics_hourly
      $results = [];
      $accumulatedRevenue = 0;
      $accumulatedPurchases = 0;
      
      foreach ($adsData as $row) {
        $hour = (int)$row['hour'];
        $spend = (float)$row['spend']; // Ya viene acumulado de la tabla
        $salesInfo = $salesMap[$hour] ?? ['real_purchases' => 0, 'revenue' => 0];
        
        // Acumular revenue para mostrar datos progresivos
        $accumulatedRevenue += $salesInfo['revenue'];
        $accumulatedPurchases += $salesInfo['real_purchases'];
        $revenue = $accumulatedRevenue;
        $realPurchases = $accumulatedPurchases;
        
        $profit = $revenue - $spend;
        $roas = $spend > 0 ? round($revenue / $spend, 2) : 0;

        $results[] = [
          'hour' => $hour,
          'spend' => round($spend, 2),
          'revenue' => round($revenue, 2),
          'profit' => round($profit, 2),
          'roas' => $roas,
          'real_purchases' => $realPurchases,
          'ad_purchases' => (int)$row['ad_purchases'],
          'impressions' => (int)$row['impressions'],
          'clicks' => (int)$row['clicks'],
          'link_clicks' => (int)$row['link_clicks'],
          'results' => (int)$row['results']
        ];
      }

      return [
        'success' => true,
        'data' => $results,
        'filters' => [
          'date' => $date,
          'bot_id' => $botId,
          'product_id' => $productId
        ]
      ];

    } catch (Exception $e) {
      ogLog::error('getProfitHourly - Error', ['error' => $e->getMessage()], self::$logMeta);
      return [
        'success' => false,
        'error' => 'Error al obtener profit por hora',
        'details' => OG_IS_DEV ? $e->getMessage() : null
      ];
    }
  }

  /**
   * Obtener profit por día - Para rangos históricos
   * Cruza ad_metrics_daily con sales agrupadas por día
   */
  static function getProfitDaily($params) {
    $range = $params['range'] ?? ogRequest::query('range', 'last_7_days');
    $botId = $params['bot_id'] ?? ogRequest::query('bot_id');
    $productId = $params['product_id'] ?? ogRequest::query('product_id');
    $userId = $GLOBALS['auth_user_id'];

    $dates = ogApp()->helper('date')::getDateRange($range);
    if (!$dates) {
      return [
        'success' => false,
        'error' => 'Rango de fechas inválido'
      ];
    }

    try {
      $today = date('Y-m-d');
      
      // Extraer solo la fecha (sin hora) para comparación correcta
      $endDateOnly = substr($dates['end'], 0, 10); // Extrae 'YYYY-MM-DD' de 'YYYY-MM-DD HH:MM:SS'
      $startDateOnly = substr($dates['start'], 0, 10);
      
      $needsToday = ($endDateOnly >= $today);
      $historicEnd = $needsToday ? date('Y-m-d', strtotime($today . ' -1 day')) : $endDateOnly;

      $results = [];

      // PASO 1: Datos históricos desde ad_metrics_daily
      if ($startDateOnly <= $historicEnd) {
        // Query 1: Métricas de ads
        $sqlAds = "
          SELECT
            d.metric_date as date,
            SUM(d.spend) as spend,
            SUM(d.impressions) as impressions,
            SUM(d.clicks) as clicks,
            SUM(d.link_clicks) as link_clicks,
            SUM(d.results) as results,
            SUM(d.purchase) as ad_purchases,
            SUM(d.purchase_value) as ad_purchase_value
          FROM ad_metrics_daily d
        ";

        // JOIN con product_ad_assets si se filtra
        if ($botId || $productId) {
          $sqlAds .= "
            INNER JOIN product_ad_assets paa ON d.ad_asset_id = paa.ad_asset_id
            INNER JOIN products p ON paa.product_id = p.id
          ";
        }

        $sqlAds .= "
          WHERE d.user_id = ?
            AND d.metric_date >= ?
            AND d.metric_date <= ?
        ";

        $paramsAds = [$userId, $startDateOnly, $historicEnd];

        if ($botId) {
          $sqlAds .= " AND p.bot_id = ?";
          $paramsAds[] = $botId;
        }

        if ($productId) {
          $sqlAds .= " AND paa.product_id = ?";
          $paramsAds[] = $productId;
        }

        $sqlAds .= " GROUP BY d.metric_date ORDER BY d.metric_date ASC";

        $adsData = ogDb::raw($sqlAds, $paramsAds);

        // Query 2: Ventas reales del mismo rango
        $sqlSales = "
          SELECT
            DATE(s.payment_date) as date,
            COUNT(DISTINCT s.id) as real_purchases,
            COALESCE(SUM(s.billed_amount), 0) as revenue
          FROM sales s
          WHERE s.user_id = ?
            AND DATE(s.payment_date) >= ?
            AND DATE(s.payment_date) <= ?
            AND s.context = 'whatsapp'
            AND s.process_status = 'sale_confirmed'
            AND s.status = 1
        ";

        $paramsSales = [$userId, $startDateOnly, $historicEnd];

        if ($botId) {
          $sqlSales .= " AND s.bot_id = ?";
          $paramsSales[] = $botId;
        }

        if ($productId) {
          $sqlSales .= " AND s.product_id = ?";
          $paramsSales[] = $productId;
        }

        $sqlSales .= " GROUP BY DATE(s.payment_date)";

        $salesData = ogDb::raw($sqlSales, $paramsSales);

        // Crear mapa de ventas
        $salesMap = [];
        foreach ($salesData as $sale) {
          $salesMap[$sale['date']] = [
            'real_purchases' => (int)$sale['real_purchases'],
            'revenue' => (float)$sale['revenue']
          ];
        }

        // Combinar datos
        foreach ($adsData as $row) {
          $date = $row['date'];
          $spend = (float)$row['spend'];
          $salesInfo = $salesMap[$date] ?? ['real_purchases' => 0, 'revenue' => 0];
          $revenue = $salesInfo['revenue'];
          $profit = $revenue - $spend;

          $results[$date] = [
            'date' => $date,
            'spend' => round($spend, 2),
            'revenue' => round($revenue, 2),
            'profit' => round($profit, 2),
            'real_purchases' => $salesInfo['real_purchases'],
            'roas' => $spend > 0 ? round($revenue / $spend, 2) : 0
          ];
        }
      }

      // PASO 2: Datos de hoy desde ad_metrics_hourly
      if ($needsToday) {
        // Query 1: Métricas de ads de hoy
        $sqlTodayAds = "
          SELECT
            h.query_date as date,
            SUM(h.spend) as spend,
            SUM(h.purchase) as ad_purchases,
            SUM(h.purchase_value) as ad_purchase_value
          FROM ad_metrics_hourly h
        ";

        if ($botId || $productId) {
          $sqlTodayAds .= "
            INNER JOIN product_ad_assets paa ON h.ad_asset_id = paa.ad_asset_id
            INNER JOIN products p ON paa.product_id = p.id
          ";
        }

        $sqlTodayAds .= "
          WHERE h.user_id = ?
            AND h.query_date = ?
            AND h.is_latest = 1
        ";

        $paramsTodayAds = [$userId, $today];

        if ($botId) {
          $sqlTodayAds .= " AND p.bot_id = ?";
          $paramsTodayAds[] = $botId;
        }

        if ($productId) {
          $sqlTodayAds .= " AND paa.product_id = ?";
          $paramsTodayAds[] = $productId;
        }

        $sqlTodayAds .= " GROUP BY h.query_date";

        $todayAdsData = ogDb::raw($sqlTodayAds, $paramsTodayAds);

        // Query 2: Ventas de hoy
        $sqlTodaySales = "
          SELECT
            COUNT(DISTINCT s.id) as real_purchases,
            COALESCE(SUM(s.billed_amount), 0) as revenue
          FROM sales s
          WHERE s.user_id = ?
            AND DATE(s.payment_date) = ?
            AND s.context = 'whatsapp'
            AND s.process_status = 'sale_confirmed'
            AND s.status = 1
        ";

        $paramsTodaySales = [$userId, $today];

        if ($botId) {
          $sqlTodaySales .= " AND s.bot_id = ?";
          $paramsTodaySales[] = $botId;
        }

        if ($productId) {
          $sqlTodaySales .= " AND s.product_id = ?";
          $paramsTodaySales[] = $productId;
        }

        $todaySalesData = ogDb::raw($sqlTodaySales, $paramsTodaySales);

        if (!empty($todayAdsData)) {
          $adsRow = $todayAdsData[0];
          $salesRow = !empty($todaySalesData) ? $todaySalesData[0] : ['real_purchases' => 0, 'revenue' => 0];
          
          $spend = (float)$adsRow['spend'];
          $revenue = (float)$salesRow['revenue'];
          $profit = $revenue - $spend;

          $results[$today] = [
            'date' => $today,
            'spend' => round($spend, 2),
            'revenue' => round($revenue, 2),
            'profit' => round($profit, 2),
            'real_purchases' => (int)$salesRow['real_purchases'],
            'roas' => $spend > 0 ? round($revenue / $spend, 2) : 0
          ];
        }
      }

      // Ordenar por fecha
      ksort($results);
      $results = array_values($results);

      // PASO 3: Calcular totales para summary
      $totalSpend = 0;
      $totalRevenue = 0;
      $totalProfit = 0;
      $totalPurchases = 0;

      foreach ($results as $row) {
        $totalSpend += $row['spend'];
        $totalRevenue += $row['revenue'];
        $totalProfit += $row['profit'];
        $totalPurchases += $row['real_purchases'];
      }

      $avgRoas = $totalSpend > 0 ? round($totalRevenue / $totalSpend, 2) : 0;

      return [
        'success' => true,
        'data' => $results,
        'summary' => [
          'total_profit' => round($totalProfit, 2),
          'total_revenue' => round($totalRevenue, 2),
          'total_spend' => round($totalSpend, 2),
          'avg_roas' => $avgRoas,
          'total_purchases' => $totalPurchases
        ],
        'filters' => [
          'range' => $range,
          'bot_id' => $botId,
          'product_id' => $productId
        ],
        'period' => [
          'start' => $dates['start'],
          'end' => $dates['end']
        ]
      ];

    } catch (Exception $e) {
      ogLog::error('getProfitDaily - Error', ['error' => $e->getMessage()], self::$logMeta);
      return [
        'success' => false,
        'error' => 'Error al obtener profit por día',
        'details' => OG_IS_DEV ? $e->getMessage() : null
      ];
    }
  }
}
