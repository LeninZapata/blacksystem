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

    // Convertir fecha local a rango UTC
    $userTz   = ogApp()->helper('date')::getUserTimezone();
    $utcRange = ogApp()->helper('date')::localDateToUtcRange($date, $userTz);
    $offsetSec = (int)$utcRange['offset_sec'];

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

      // query_date y query_hour se almacenan en el timezone LOCAL del activo (ver AdMetricsHandler).
      // Filtrar por fecha local directamente: no usar rango UTC con CONCAT.
      $sqlAds .= "
        WHERE h.user_id = ?
          AND h.query_date = ?
      ";

      // Tomar el último snapshot de cada hora (tanto para hoy como días históricos)
      $sqlAds .= " AND h.id IN (
        SELECT MAX(h2.id)
        FROM ad_metrics_hourly h2
        WHERE h2.user_id = ?
          AND h2.query_date = ?
          AND h2.ad_asset_id = h.ad_asset_id
          AND h2.query_hour = h.query_hour
          AND HOUR(h2.dc) = h2.query_hour
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
      } elseif ($botId) {
        $sqlAds .= " AND paa.product_id NOT IN (SELECT id FROM products WHERE env = 'T')";
      }

      $sqlAds .= " GROUP BY h.query_hour ORDER BY h.query_hour ASC";

      $adsData = ogDb::raw($sqlAds, $params);

      // PASO 2: Obtener ventas reales por hora del mismo día
      $sqlSales = "
        SELECT
          HOUR(DATE_ADD(s.payment_date, INTERVAL {$offsetSec} SECOND)) as hour,
          COUNT(DISTINCT s.id) as real_purchases,
          COALESCE(SUM(s.billed_amount), 0) as revenue
        FROM sales s
        WHERE s.user_id = ?
          AND s.payment_date >= ? AND s.payment_date <= ?
          AND s.process_status = 'sale_confirmed'
          AND s.status = 1
      ";

      $salesParams = [$userId, $utcRange['start'], $utcRange['end']];

      if ($botId) {
        $sqlSales .= " AND s.bot_id = ?";
        $salesParams[] = $botId;
      }

      if ($productId) {
        // Incluir ventas directas del producto Y upsell cuyo padre pertenece a este producto
        $sqlSales .= " AND (s.product_id = ? OR (s.origin = 'upsell' AND s.parent_sale_id IN (SELECT id FROM sales ps WHERE ps.product_id = ? AND ps.user_id = ?)))";
        $salesParams[] = $productId;
        $salesParams[] = $productId;
        $salesParams[] = $userId;
      } else {
        $sqlSales .= " AND s.product_id NOT IN (SELECT id FROM products WHERE env = 'T')";
      }

      $sqlSales .= " GROUP BY HOUR(DATE_ADD(s.payment_date, INTERVAL {$offsetSec} SECOND));";

      $salesData = ogDb::raw($sqlSales, $salesParams);

      // Crear mapa de ventas por hora (hora local)
      $salesMap = [];
      $totalSalesRevenue = 0;
      $totalSalesPurchases = 0;
      foreach ($salesData as $sale) {
        $salesMap[(int)$sale['hour']] = [
          'real_purchases' => (int)$sale['real_purchases'],
          'revenue' => (float)$sale['revenue']
        ];
        $totalSalesRevenue += (float)$sale['revenue'];
        $totalSalesPurchases += (int)$sale['real_purchases'];
      }

      // PASO 3: Combinar datos y calcular profit
      // query_hour está en UTC del servidor. Convertir a hora local del usuario para display.
      $offsetHours = $utcRange['offset_hours'];
      foreach ($adsData as &$row) {
        $row['local_hour'] = (int)((($row['hour'] + $offsetHours) % 24 + 24) % 24);
      }
      unset($row);
      usort($adsData, function($a, $b) { return $a['local_hour'] - $b['local_hour']; });

      $results = [];
      $accumulatedRevenue = 0;
      $accumulatedPurchases = 0;

      $salesHours = array_keys($salesMap);
      sort($salesHours);
      $salesHourIndex = 0;
      
      foreach ($adsData as $row) {
        $hour = $row['local_hour'];
        $spend = (float)$row['spend']; // Ya viene acumulado de la tabla

        // Acumular TODAS las ventas de horas <= hora actual (captura ventas en horas sin ads)
        while ($salesHourIndex < count($salesHours) && $salesHours[$salesHourIndex] <= $hour) {
          $saleHour = $salesHours[$salesHourIndex];
          $accumulatedRevenue += $salesMap[$saleHour]['revenue'];
          $accumulatedPurchases += $salesMap[$saleHour]['real_purchases'];
          $salesHourIndex++;
        }

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

      // PASO 3.5: Forzar monotonía en spend.
      // Facebook acumula spend por día en timezone de la cuenta (ECU), así que el acumulado
      // es continuo durante todo el día ECU sin resetear en medianoche UTC.
      // La monotonía protege contra snapshots faltantes de algún activo en cierta hora.
      $runMaxSpend = 0;
      foreach ($results as &$row) {
        if ($row['spend'] > $runMaxSpend) {
          $runMaxSpend = $row['spend'];
        } else {
          $row['spend'] = $runMaxSpend;
        }
        $row['profit'] = round($row['revenue'] - $row['spend'], 2);
        $row['roas']   = $row['spend'] > 0 ? round($row['revenue'] / $row['spend'], 2) : 0;
      }
      unset($row);

      // PASO 4: Calcular resumen usando totales directos de ventas
      // (no desde el acumulado del chart, para capturar ventas en horas sin datos de ads)
      $summary = null;
      
      if (!empty($results)) {
        // Buscar el registro con mayor spend (última hora con datos acumulados)
        $lastRecord = $results[0];
        foreach ($results as $record) {
          if ($record['spend'] > $lastRecord['spend']) {
            $lastRecord = $record;
          }
        }

        $totalSpend = $lastRecord['spend'];
        // Usar totales directos de ventas (no el acumulado del chart que puede perder ventas en horas sin ads)
        $totalRoas = $totalSpend > 0 ? round($totalSalesRevenue / $totalSpend, 2) : 0;
        
        $summary = [
          'total_spend' => $totalSpend,
          'total_revenue' => round($totalSalesRevenue, 2),
          'total_profit' => round($totalSalesRevenue - $totalSpend, 2),
          'total_roas' => $totalRoas,
          'total_purchases' => $totalSalesPurchases,
          'source' => 'ad_metrics_hourly' // Calculado desde datos horarios
        ];
      }

      return [
        'success' => true,
        'data' => $results,
        'summary' => $summary, // Puede ser null si es hoy o si no hay datos consolidados
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

    $userTz = ogApp()->helper('date')::getUserTimezone();
    $dates = ogApp()->helper('date')::getDateRange($range, $userTz);
    if (!$dates) {
      return [
        'success' => false,
        'error' => 'Rango de fechas inválido'
      ];
    }

    try {
      // Fecha local del usuario (no UTC del servidor) para query_date y detección de hoy/ayer
      $localToday = (new DateTime('now', new DateTimeZone($userTz)))->format('Y-m-d');
      
      // Extraer solo la fecha (sin hora) para comparación correcta
      $endDateOnly   = substr($dates['end'],   0, 10);
      $startDateOnly = substr($dates['start'], 0, 10);
      
      $needsToday  = ($endDateOnly >= $localToday);
      $historicEnd = $needsToday ? date('Y-m-d', strtotime($localToday . ' -1 day')) : $endDateOnly;

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
        } elseif ($botId) {
          $sqlAds .= " AND paa.product_id NOT IN (SELECT id FROM products WHERE env = 'T')";
        }

        $sqlAds .= " GROUP BY d.metric_date ORDER BY d.metric_date ASC";

        $adsData = ogDb::raw($sqlAds, $paramsAds);

        // Query 2: Ventas reales del mismo rango
        // payment_date se guarda en UTC → convertir al timezone local del usuario para agrupar por día correcto
        $sqlSales = "
          SELECT
            DATE(CONVERT_TZ(s.payment_date, 'UTC', ?)) as date,
            COUNT(DISTINCT s.id) as real_purchases,
            COALESCE(SUM(s.billed_amount), 0) as revenue
          FROM sales s
          WHERE s.user_id = ?
            AND DATE(CONVERT_TZ(s.payment_date, 'UTC', ?)) >= ?
            AND DATE(CONVERT_TZ(s.payment_date, 'UTC', ?)) <= ?
            AND s.process_status = 'sale_confirmed'
            AND s.status = 1
        ";

        $paramsSales = [$userTz, $userId, $userTz, $startDateOnly, $userTz, $historicEnd];

        if ($botId) {
          $sqlSales .= " AND s.bot_id = ?";
          $paramsSales[] = $botId;
        }

        if ($productId) {
          $sqlSales .= " AND s.product_id = ?";
          $paramsSales[] = $productId;
        } else {
          $sqlSales .= " AND s.product_id NOT IN (SELECT id FROM products WHERE env = 'T')";
        }

        $sqlSales .= " GROUP BY DATE(CONVERT_TZ(s.payment_date, 'UTC', ?))";
        $paramsSales[] = $userTz;

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

      // PASO 1.5: FALLBACK para AYER si no hay datos en ad_metrics_daily
      // Si consultamos ayer y no hay datos (porque el cron de las 2am aún no corrió), 
      // usar ad_metrics_hourly como fallback
      $yesterday = date('Y-m-d', strtotime($localToday . ' -1 day'));
      $isYesterdayOnly = ($startDateOnly === $yesterday && $endDateOnly === $yesterday);
      
      if ($isYesterdayOnly && !isset($results[$yesterday])) {
        // No hay datos de ayer en ad_metrics_daily, intentar fallback a hourly.
        // IMPORTANTE: h.spend es acumulativo (crece durante el día), por lo que se debe
        // tomar MAX(h.spend) por activo (valor final del día), no SUM de todas las horas.
        $sqlYesterdayAds = "
          SELECT SUM(asset_spend) as spend
          FROM (
            SELECT h.ad_asset_id, MAX(h.spend) as asset_spend
            FROM ad_metrics_hourly h
        ";

        if ($botId || $productId) {
          $sqlYesterdayAds .= "
            INNER JOIN product_ad_assets paa ON h.ad_asset_id = paa.ad_asset_id
            INNER JOIN products p ON paa.product_id = p.id
          ";
        }

        $sqlYesterdayAds .= "
            WHERE h.user_id = ?
              AND h.query_date = ?
        ";

        $paramsYesterdayAds = [$userId, $yesterday];

        if ($botId) {
          $sqlYesterdayAds .= " AND p.bot_id = ?";
          $paramsYesterdayAds[] = $botId;
        }

        if ($productId) {
          $sqlYesterdayAds .= " AND paa.product_id = ?";
          $paramsYesterdayAds[] = $productId;
        } elseif ($botId) {
          $sqlYesterdayAds .= " AND paa.product_id NOT IN (SELECT id FROM products WHERE env = 'T')";
        }

        $sqlYesterdayAds .= "
            GROUP BY h.ad_asset_id
          ) max_spends
        ";

        $yesterdayAdsData = ogDb::raw($sqlYesterdayAds, $paramsYesterdayAds);

        // Ventas de ayer — usar rango UTC para no perder ventas nocturnas (payment_date está en UTC)
        $utcRangeYest = ogApp()->helper('date')::localDateToUtcRange($yesterday, $userTz);
        $sqlYesterdaySales = "
          SELECT
            COUNT(DISTINCT s.id) as real_purchases,
            COALESCE(SUM(s.billed_amount), 0) as revenue
          FROM sales s
          WHERE s.user_id = ?
            AND s.payment_date >= ? AND s.payment_date <= ?
            AND s.process_status = 'sale_confirmed'
            AND s.status = 1
        ";

        $paramsYesterdaySales = [$userId, $utcRangeYest['start'], $utcRangeYest['end']];

        if ($botId) {
          $sqlYesterdaySales .= " AND s.bot_id = ?";
          $paramsYesterdaySales[] = $botId;
        }

        if ($productId) {
          // Incluir ventas directas Y upsell del mismo producto
          $sqlYesterdaySales .= " AND (s.product_id = ? OR (s.origin = 'upsell' AND s.parent_sale_id IN (SELECT id FROM sales ps WHERE ps.product_id = ? AND ps.user_id = ?)))";
          $paramsYesterdaySales[] = $productId;
          $paramsYesterdaySales[] = $productId;
          $paramsYesterdaySales[] = $userId;
        } else {
          $sqlYesterdaySales .= " AND s.product_id NOT IN (SELECT id FROM products WHERE env = 'T')";
        }

        $yesterdaySalesData = ogDb::raw($sqlYesterdaySales, $paramsYesterdaySales);

        if (!empty($yesterdayAdsData)) {
          $adsRow = $yesterdayAdsData[0];
          $salesRow = !empty($yesterdaySalesData) ? $yesterdaySalesData[0] : ['real_purchases' => 0, 'revenue' => 0];
          
          $spend = (float)$adsRow['spend'];
          $revenue = (float)$salesRow['revenue'];
          $profit = $revenue - $spend;

          $results[$yesterday] = [
            'date' => $yesterday,
            'spend' => round($spend, 2),
            'revenue' => round($revenue, 2),
            'profit' => round($profit, 2),
            'real_purchases' => (int)$salesRow['real_purchases'],
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

        $paramsTodayAds = [$userId, $localToday];

        if ($botId) {
          $sqlTodayAds .= " AND p.bot_id = ?";
          $paramsTodayAds[] = $botId;
        }

        if ($productId) {
          $sqlTodayAds .= " AND paa.product_id = ?";
          $paramsTodayAds[] = $productId;
        } elseif ($botId) {
          $sqlTodayAds .= " AND paa.product_id NOT IN (SELECT id FROM products WHERE env = 'T')";
        }

        $sqlTodayAds .= " GROUP BY h.query_date";

        $todayAdsData = ogDb::raw($sqlTodayAds, $paramsTodayAds);

        // Query 2: Ventas de hoy — usar rango UTC para no perder ventas nocturnas (payment_date está en UTC)
        $utcRangeToday = ogApp()->helper('date')::localDateToUtcRange($localToday, $userTz);
        $sqlTodaySales = "
          SELECT
            COUNT(DISTINCT s.id) as real_purchases,
            COALESCE(SUM(s.billed_amount), 0) as revenue
          FROM sales s
          WHERE s.user_id = ?
            AND s.payment_date >= ? AND s.payment_date <= ?
            AND s.process_status = 'sale_confirmed'
            AND s.status = 1
        ";

        $paramsTodaySales = [$userId, $utcRangeToday['start'], $utcRangeToday['end']];

        if ($botId) {
          $sqlTodaySales .= " AND s.bot_id = ?";
          $paramsTodaySales[] = $botId;
        }

        if ($productId) {
          // Incluir ventas directas Y upsell del mismo producto
          $sqlTodaySales .= " AND (s.product_id = ? OR (s.origin = 'upsell' AND s.parent_sale_id IN (SELECT id FROM sales ps WHERE ps.product_id = ? AND ps.user_id = ?)))";
          $paramsTodaySales[] = $productId;
          $paramsTodaySales[] = $productId;
          $paramsTodaySales[] = $userId;
        } else {
          $sqlTodaySales .= " AND s.product_id NOT IN (SELECT id FROM products WHERE env = 'T')";
        }

        $todaySalesData = ogDb::raw($sqlTodaySales, $paramsTodaySales);

        if (!empty($todayAdsData)) {
          $adsRow = $todayAdsData[0];
          $salesRow = !empty($todaySalesData) ? $todaySalesData[0] : ['real_purchases' => 0, 'revenue' => 0];
          
          $spend = (float)$adsRow['spend'];
          $revenue = (float)$salesRow['revenue'];
          $profit = $revenue - $spend;

          $results[$localToday] = [
            'date' => $localToday,
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

  /**
   * Resumen de profit por producto — para la tab Resumen del módulo Automation.
   * Devuelve chats iniciados, ingresos confirmados y gasto publicitario por producto
   * del bot dado, en el rango de fechas solicitado.
   *
   * GET /api/profit/summary-by-product?bot_id=1&range=today&date=YYYY-MM-DD
   */
  static function getSummaryByProduct($params) {
    $range     = $params['range']   ?? ogRequest::query('range',   'today');
    $inputDate = $params['date']    ?? ogRequest::query('date',    date('Y-m-d'));
    $botId     = $params['bot_id']  ?? ogRequest::query('bot_id');
    $userId    = $GLOBALS['auth_user_id'];

    if (!$botId) {
      return ['success' => false, 'error' => 'bot_id es obligatorio'];
    }

    $userTz     = ogApp()->helper('date')::getUserTimezone();
    // Fecha local del usuario (no UTC del servidor) para query_date y detección de hoy/ayer
    $localToday  = (new DateTime('now', new DateTimeZone($userTz)))->format('Y-m-d');
    $isSingleDay = in_array($range, ['today', 'yesterday', 'custom_date']);

    // ── Calcular rango UTC ─────────────────────────────────────────────────
    if ($isSingleDay) {
      if      ($range === 'today')     $targetDate = $localToday;
      else if ($range === 'yesterday') $targetDate = date('Y-m-d', strtotime($localToday . ' -1 day'));
      else                             $targetDate = $inputDate ?: $localToday;

      $utcRange    = ogApp()->helper('date')::localDateToUtcRange($targetDate, $userTz);
      $startUtc    = $utcRange['start'];
      $endUtc      = $utcRange['end'];
      $isToday     = ($targetDate === $localToday);
      $startDate   = $targetDate;
      $historicEnd = $isToday ? date('Y-m-d', strtotime($localToday . ' -1 day')) : $targetDate;
      $needsToday  = $isToday;
    } else {
      $dates = ogApp()->helper('date')::getDateRange($range, $userTz);
      if (!$dates) {
        return ['success' => false, 'error' => 'Rango de fechas inválido'];
      }
      $startUtc    = $dates['start'];
      $endUtc      = $dates['end'];
      $startDate   = substr($startUtc, 0, 10);
      $historicEnd = (substr($endUtc, 0, 10) >= $localToday)
        ? date('Y-m-d', strtotime($localToday . ' -1 day'))
        : substr($endUtc, 0, 10);
      $needsToday  = (substr($endUtc, 0, 10) >= $localToday);
      $isToday     = false;
      $targetDate  = null;
    }

    try {
      // 1. Productos activos en el rango por métricas de ads ───────────────
      // Se usa ad_metrics_hourly para detectar productos vivos (con gasto o ingresos)
      // incluso si aún no tienen ventas registradas en el período.
      $localEndDate = $isSingleDay ? $targetDate : substr($endUtc, 0, 10);

      $products = ogDb::raw("
        SELECT DISTINCT p.id, p.name, p.env, p.status, p.sale_type_mode
        FROM " . ogDb::t('ad_metrics_hourly', true) . " h
        INNER JOIN " . ogDb::t('products', true) . " p ON h.product_id = p.id
        WHERE h.user_id = ?
          AND p.bot_id  = ?
          AND h.query_date BETWEEN ? AND ?
          AND p.context = 'infoproductws'
          AND p.sale_type_mode != 2
      ", [$userId, (int)$botId, $startDate, $localEndDate]);

      if (empty($products)) {
        return ['success' => true, 'data' => [],
          'summary' => ['total_revenue' => 0, 'total_spend' => 0, 'total_profit' => 0]];
      }

      $productIds   = array_column($products, 'id');
      $productMap   = [];
      foreach ($products as $p) {
        $productMap[(int)$p['id']] = [
          'id'            => (int)$p['id'],
          'name'          => $p['name'],
          'env'           => $p['env'] ?? null,
          'chats'         => 0,
          'revenue'       => 0.0,
          'sales_count'   => 0,
          'upsell_revenue'=> 0.0,
          'upsell_count'  => 0,
          'spend'         => 0.0,
          'profit'        => 0.0,
        ];
      }

      $placeholders = implode(',', array_fill(0, count($productIds), '?'));

      // 2. Chats iniciados (solo ventas directas, sin upsell) ──────────────
      $chatsData = ogDb::raw("
        SELECT s.product_id, COUNT(DISTINCT s.id) AS chats
        FROM sales s
        WHERE s.user_id = ? AND s.product_id IN ($placeholders)
          AND s.dc >= ? AND s.dc <= ?
          AND (s.origin IS NULL OR s.origin != 'upsell')
        GROUP BY s.product_id
      ", array_merge([$userId], $productIds, [$startUtc, $endUtc]));

      foreach ($chatsData as $row) {
        if (isset($productMap[(int)$row['product_id']])) {
          $productMap[(int)$row['product_id']]['chats'] = (int)$row['chats'];
        }
      }

      // 3. Ingresos directos (ventas confirmadas, sin upsell) ───────────────
      $revenueData = ogDb::raw("
        SELECT s.product_id,
               COUNT(DISTINCT s.id)             AS sales_count,
               COALESCE(SUM(s.billed_amount), 0) AS revenue
        FROM sales s
        WHERE s.user_id = ? AND s.product_id IN ($placeholders)
          AND s.payment_date >= ? AND s.payment_date <= ?
          AND s.process_status = 'sale_confirmed'
          AND s.status = 1
          AND (s.origin IS NULL OR s.origin != 'upsell')
        GROUP BY s.product_id
      ", array_merge([$userId], $productIds, [$startUtc, $endUtc]));

      foreach ($revenueData as $row) {
        if (isset($productMap[(int)$row['product_id']])) {
          $productMap[(int)$row['product_id']]['revenue']     = round((float)$row['revenue'], 2);
          $productMap[(int)$row['product_id']]['sales_count'] = (int)$row['sales_count'];
        }
      }

      // 3b. Ingresos por upsell (via parent_sale_id → product del padre) ────
      // Identifica ventas con origin=upsell cuyo padre pertenece a este producto
      $upsellData = ogDb::raw("
        SELECT parent.product_id,
               COUNT(DISTINCT s.id)              AS upsell_count,
               COALESCE(SUM(s.billed_amount), 0) AS upsell_revenue
        FROM sales s
        INNER JOIN sales parent ON s.parent_sale_id = parent.id
        WHERE s.user_id = ? AND parent.product_id IN ($placeholders)
          AND s.origin = 'upsell'
          AND s.process_status = 'sale_confirmed'
          AND s.status = 1
          AND s.payment_date >= ? AND s.payment_date <= ?
        GROUP BY parent.product_id
      ", array_merge([$userId], $productIds, [$startUtc, $endUtc]));

      foreach ($upsellData as $row) {
        if (isset($productMap[(int)$row['product_id']])) {
          $productMap[(int)$row['product_id']]['upsell_revenue'] = round((float)$row['upsell_revenue'], 2);
          $productMap[(int)$row['product_id']]['upsell_count']   = (int)$row['upsell_count'];
        }
      }

      // 4. Gastos publicitarios por producto ────────────────────────────────
      $spendByProduct = [];

      if ($isSingleDay && $isToday) {
        // Hoy: último acumulado por activo (is_latest = 1)
        $spendData = ogDb::raw("
          SELECT paa.product_id, SUM(h.spend) AS spend
          FROM ad_metrics_hourly h
          INNER JOIN product_ad_assets paa ON h.ad_asset_id = paa.ad_asset_id
          WHERE h.user_id = ? AND h.query_date = ? AND h.is_latest = 1
            AND paa.product_id IN ($placeholders)
          GROUP BY paa.product_id
        ", array_merge([$userId, $targetDate], $productIds));

        foreach ($spendData as $row) {
          $spendByProduct[(int)$row['product_id']] = (float)$row['spend'];
        }

      } elseif ($isSingleDay && !$isToday) {
        // Día histórico: ad_metrics_daily con fallback a hourly por producto
        $spendData = ogDb::raw("
          SELECT paa.product_id, SUM(d.spend) AS spend
          FROM ad_metrics_daily d
          INNER JOIN product_ad_assets paa ON d.ad_asset_id = paa.ad_asset_id
          WHERE d.user_id = ? AND d.metric_date = ?
            AND paa.product_id IN ($placeholders)
          GROUP BY paa.product_id
        ", array_merge([$userId, $targetDate], $productIds));

        foreach ($spendData as $row) {
          $spendByProduct[(int)$row['product_id']] = (float)$row['spend'];
        }

        // Fallback a hourly solo para productos sin datos en ad_metrics_daily
        // (ventana 00:00-01:xx antes de que corra el cron de daily)
        $missingIds = array_values(array_filter($productIds, fn($pid) => !isset($spendByProduct[$pid])));

        if (!empty($missingIds)) {
          $missingPlaceholders = implode(',', array_fill(0, count($missingIds), '?'));
          // spend en hourly es acumulativo: tomar MAX(spend) por activo (último valor = total del día)
          $fallbackData = ogDb::raw("
            SELECT paa.product_id, SUM(last_spend.max_spend) AS spend
            FROM (
              SELECT ad_asset_id, MAX(spend) AS max_spend
              FROM ad_metrics_hourly
              WHERE user_id = ? AND query_date = ?
              GROUP BY ad_asset_id
            ) last_spend
            INNER JOIN product_ad_assets paa ON last_spend.ad_asset_id = paa.ad_asset_id
            WHERE paa.product_id IN ($missingPlaceholders)
            GROUP BY paa.product_id
          ", array_merge([$userId, $targetDate], $missingIds));

          foreach ($fallbackData as $row) {
            $spendByProduct[(int)$row['product_id']] = (float)$row['spend'];
          }
        }

      } else {
        // Multi-día: daily (hasta ayer) + hourly de hoy si aplica
        if ($startDate <= $historicEnd) {
          $spendData = ogDb::raw("
            SELECT paa.product_id, SUM(d.spend) AS spend
            FROM ad_metrics_daily d
            INNER JOIN product_ad_assets paa ON d.ad_asset_id = paa.ad_asset_id
            WHERE d.user_id = ? AND d.metric_date >= ? AND d.metric_date <= ?
              AND paa.product_id IN ($placeholders)
            GROUP BY paa.product_id
          ", array_merge([$userId, $startDate, $historicEnd], $productIds));

          foreach ($spendData as $row) {
            $pid = (int)$row['product_id'];
            $spendByProduct[$pid] = ($spendByProduct[$pid] ?? 0) + (float)$row['spend'];
          }
        }

        if ($needsToday) {
          $spendTodayData = ogDb::raw("
            SELECT paa.product_id, SUM(h.spend) AS spend
            FROM ad_metrics_hourly h
            INNER JOIN product_ad_assets paa ON h.ad_asset_id = paa.ad_asset_id
            WHERE h.user_id = ? AND h.query_date = ? AND h.is_latest = 1
              AND paa.product_id IN ($placeholders)
            GROUP BY paa.product_id
          ", array_merge([$userId, $localToday], $productIds));

          foreach ($spendTodayData as $row) {
            $pid = (int)$row['product_id'];
            $spendByProduct[$pid] = ($spendByProduct[$pid] ?? 0) + (float)$row['spend'];
          }
        }
      }

      // 5. Asignar gastos y calcular profit (upsell suma al ingreso) ─────────
      $totalRevenue = 0;
      $totalSpend   = 0;
      foreach ($productMap as $pid => &$p) {
        $p['spend']  = round($spendByProduct[$pid] ?? 0, 2);
        $p['profit'] = round($p['revenue'] + $p['upsell_revenue'] - $p['spend'], 2);
        $totalRevenue += $p['revenue'] + $p['upsell_revenue'];
        $totalSpend   += $p['spend'];
      }
      unset($p);

      return [
        'success' => true,
        'data'    => array_values($productMap),
        'summary' => [
          'total_revenue' => round($totalRevenue, 2),
          'total_spend'   => round($totalSpend,   2),
          'total_profit'  => round($totalRevenue - $totalSpend, 2),
        ],
        'filters' => ['bot_id' => $botId, 'range' => $range],
      ];

    } catch (Exception $e) {
      ogLog::error('getSummaryByProduct - Error', ['error' => $e->getMessage()], self::$logMeta);
      return [
        'success' => false,
        'error'   => 'Error al obtener resumen por producto',
        'details' => OG_IS_DEV ? $e->getMessage() : null
      ];
    }
  }
}
