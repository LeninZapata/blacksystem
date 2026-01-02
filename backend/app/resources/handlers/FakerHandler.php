<?php
class FakerHandler {
  private static $logMeta = ['module' => 'FakerHandler', 'layer' => 'app/handler'];

  // Datos latinos
  private static $nombres = [
    'Carlos', 'María', 'José', 'Ana', 'Luis', 'Carmen', 'Miguel', 'Rosa',
    'Pedro', 'Laura', 'Jorge', 'Isabel', 'Diego', 'Patricia', 'Fernando', 'Lucía',
    'Roberto', 'Sofía', 'Manuel', 'Elena', 'Ricardo', 'Gabriela', 'Alberto', 'Daniela',
    'Andrés', 'Valeria', 'Francisco', 'Carolina', 'Raúl', 'Natalia'
  ];

  private static $apellidos = [
    'García', 'Rodríguez', 'Martínez', 'López', 'González', 'Pérez', 'Sánchez', 'Ramírez',
    'Torres', 'Flores', 'Rivera', 'Gómez', 'Díaz', 'Cruz', 'Morales', 'Reyes',
    'Jiménez', 'Gutiérrez', 'Hernández', 'Ruiz', 'Mendoza', 'Castillo', 'Ortiz', 'Vargas',
    'Romero', 'Medina', 'Aguilar', 'Castro', 'Moreno', 'Vega'
  ];

  private static $devices = ['android', 'iphone', 'web'];

  // Generar datos fake
  static function generate($params) {
    $num = $params['num'] ?? 50;
    $startDate = $params['start_date'] ?? date('Y-m-01');
    $endDate = $params['end_date'] ?? date('Y-m-t');

    // Obtener primer usuario admin
    $adminUser = ogDb::table('users')
      ->where('role', 'admin')
      ->orderBy('id', 'ASC')
      ->first();

    if (!$adminUser) {
      return ['success' => false, 'error' => 'No hay usuarios admin en el sistema'];
    }

    $userId = $adminUser['id'];

    $stats = [
      'clients' => 0,
      'sales' => 0,
      'chats' => 0,
      'followups' => 0
    ];

    // Obtener bots disponibles del usuario
    $bots = ogDb::table(DB_TABLES['bots'])
      ->where('status', 1)
      ->where('user_id', $userId)
      ->get();

    if (empty($bots)) {
      return ['success' => false, 'error' => 'No hay bots activos para este usuario'];
    }

    // Obtener productos disponibles del usuario
    $products = ogDb::table(DB_TABLES['products'])
      ->where('user_id', $userId)
      ->get();

    if (empty($products)) {
      return ['success' => false, 'error' => 'No hay productos disponibles para este usuario'];
    }

    // Distribución de fechas
    $dates = self::generateDateDistribution($startDate, $endDate, $num);

    ogApp()->loadHandler('client');
    ogApp()->loadHandler('sale');
    ogApp()->loadHandler('chat');
    ogApp()->loadHandler('followup');

    foreach ($dates as $date) {
      $bot = $bots[array_rand($bots)];
      $product = $products[array_rand($products)];

      // Generar cliente
      $clientData = self::generateClient($bot['country_code'], $date, $userId);
      $clientId = ogDb::table(DB_TABLES['clients'])->insert($clientData);
      $stats['clients']++;

      // Generar venta
      $saleData = self::generateSale($clientData, $bot, $product, $date, $userId);
      $saleId = ogDb::table(DB_TABLES['sales'])->insert($saleData);
      $stats['sales']++;

      // Generar chats (2-5 por venta)
      $numChats = rand(2, 5);
      for ($i = 0; $i < $numChats; $i++) {
        $chatData = self::generateChat($clientId, $clientData['number'], $bot, $saleId, $date, $i);
        ogDb::table(DB_TABLES['chats'])->insert($chatData);
        $stats['chats']++;
      }

      // Generar followups (1-4 por venta)
      $numFollowups = rand(1, 4);
      for ($i = 0; $i < $numFollowups; $i++) {
        $followupData = self::generateFollowup($clientId, $clientData['number'], $bot, $product, $saleId, $date, $i, $userId);
        ogDb::table(DB_TABLES['followups'])->insert($followupData);
        $stats['followups']++;
      }
    }

    return [
      'success' => true,
      'generated' => $stats,
      'period' => ['start' => $startDate, 'end' => $endDate]
    ];
  }

  // Distribución inteligente de fechas
  private static function generateDateDistribution($startDate, $endDate, $total) {
    $start = strtotime($startDate);
    $end = strtotime($endDate);
    $days = ($end - $start) / 86400 + 1;

    $distribution = [];
    $daysArray = [];

    // Crear array de días con pesos
    for ($i = 0; $i < $days; $i++) {
      $currentDate = date('Y-m-d', $start + ($i * 86400));
      $day = (int)date('d', strtotime($currentDate));

      // Picos en día 1 y 15
      $weight = 1;
      if ($day == 1 || $day == 15) {
        $weight = 3;
      } elseif ($day >= 2 && $day <= 5 || $day >= 16 && $day <= 20) {
        $weight = 2;
      }

      for ($w = 0; $w < $weight; $w++) {
        $daysArray[] = $currentDate;
      }
    }

    // Distribuir registros
    for ($i = 0; $i < $total; $i++) {
      $randomDate = $daysArray[array_rand($daysArray)];
      $distribution[] = $randomDate;
    }

    shuffle($distribution);
    return $distribution;
  }

  // Generar cliente
  private static function generateClient($countryCode, $date, $userId) {
    $nombre = self::$nombres[array_rand(self::$nombres)];
    $apellido1 = self::$apellidos[array_rand(self::$apellidos)];
    $apellido2 = self::$apellidos[array_rand(self::$apellidos)];

    $number = '593' . rand(900000000, 999999999);
    $timestamp = strtotime($date . ' ' . rand(8, 22) . ':' . rand(0, 59) . ':' . rand(0, 59));

    return [
      'user_id' => $userId,
      'number' => $number,
      'name' => "$nombre $apellido1 $apellido2",
      'email' => strtolower($nombre . '.' . $apellido1 . rand(100, 999) . '@gmail.com'),
      'device' => self::$devices[array_rand(self::$devices)],
      'country_code' => $countryCode,
      'total_purchases' => rand(1, 3),
      'amount_spent' => rand(5, 50) + (rand(0, 99) / 100),
      'status' => 1,
      'dc' => date('Y-m-d H:i:s', $timestamp),
      'tc' => $timestamp
    ];
  }

  // Calcular probabilidad
  private static function probability($percentage) {
    return rand(1, 100) <= $percentage;
  }

  // Generar venta
  private static function generateSale($clientData, $bot, $product, $date, $userId) {
    $timestamp = strtotime($date . ' ' . rand(8, 22) . ':' . rand(0, 59) . ':' . rand(0, 59));

    // Solo 7-25% de probabilidad de venta confirmada
    $conversionRate = rand(7, 25);
    $isConfirmed = self::probability($conversionRate);

    if ($isConfirmed) {
      $status = 'sale_confirmed';
    } else {
      $statuses = ['initiated', 'pending', 'cancelled'];
      $status = $statuses[array_rand($statuses)];
    }

    $amount = (float)($product['price'] ?? rand(3, 20));
    $billedAmount = $amount - (rand(0, 2) + (rand(0, 99) / 100));

    // 40% seguimiento, 60% directo
    $isFunnel = rand(1, 100) <= 40;
    $trackingFunnelId = $isFunnel ? substr(md5(rand()), 0, 10) : null;

    return [
      'user_id' => $userId,
      'sale_type' => 'main',
      'origin' => rand(0, 1) ? 'organic' : 'ad',
      'context' => 'whatsapp',
      'number' => $clientData['number'],
      'country_code' => $clientData['country_code'],
      'product_name' => $product['name'],
      'product_id' => $product['id'],
      'bot_id' => $bot['id'],
      'bot_type' => $bot['type'],
      'bot_mode' => $bot['mode'],
      'client_id' => null,
      'amount' => $amount,
      'billed_amount' => $status == 'sale_confirmed' ? $billedAmount : null,
      'process_status' => $status,
      'transaction_id' => $status == 'sale_confirmed' ? 'RECEIPT_' . $timestamp : null,
      'tracking_funnel_id' => $trackingFunnelId,
      'payment_date' => $status == 'sale_confirmed' ? date('Y-m-d H:i:s', $timestamp + rand(60, 3600)) : null,
      'payment_method' => $status == 'sale_confirmed' ? 'Recibo de pago' : null,
      'source_app' => rand(0, 1) ? 'facebook' : 'whatsapp',
      'device' => $clientData['device'],
      'force_welcome' => 0,
      'parent_sale_id' => 0,
      'is_downsell' => 0,
      'status' => 1,
      'dc' => date('Y-m-d H:i:s', $timestamp),
      'tc' => $timestamp
    ];
  }

  // Generar chat
  private static function generateChat($clientId, $clientNumber, $bot, $saleId, $date, $index) {
    $timestamp = strtotime($date . ' ' . rand(8, 22) . ':' . rand(0, 59) . ':' . rand(0, 59)) + ($index * 300);

    $types = ['S', 'B', 'P'];
    $formats = ['text', 'image', 'audio'];
    $type = $types[array_rand($types)];

    $messages = [
      'S' => ['Nueva venta iniciada', 'Venta confirmada', 'Producto entregado'],
      'B' => ['Hola! ¿En qué puedo ayudarte?', 'Gracias por tu compra', 'Te envío el link'],
      'P' => ['Hola', 'Quiero comprar', 'Adjunto comprobante', 'Gracias']
    ];

    return [
      'user_id' => $bot['user_id'] ?? 1,
      'bot_id' => $bot['id'],
      'bot_number' => $bot['number'],
      'client_id' => $clientId,
      'client_number' => $clientNumber,
      'sale_id' => $saleId,
      'type' => $type,
      'format' => $formats[array_rand($formats)],
      'message' => $messages[$type][array_rand($messages[$type])],
      'metadata' => json_encode(['generated' => true]),
      'dc' => date('Y-m-d H:i:s', $timestamp),
      'tc' => $timestamp
    ];
  }

  // Generar followup
  private static function generateFollowup($clientId, $clientNumber, $bot, $product, $saleId, $date, $index, $userId) {
    $timestamp = strtotime($date . ' ' . rand(8, 22) . ':' . rand(0, 59) . ':' . rand(0, 59));
    $futureTimestamp = $timestamp + (rand(1, 5) * 86400) + rand(0, 86400);

    $processed = [0, 0, 0, 1, 1, 2];

    return [
      'user_id' => $userId,
      'sale_id' => $saleId,
      'product_id' => $product['id'],
      'client_id' => $clientId,
      'bot_id' => $bot['id'],
      'context' => 'whatsapp',
      'number' => $clientNumber,
      'name' => 'seg-' . substr(md5(rand()), 0, 10),
      'instruction' => 'Seguimiento automático ' . ($index + 1),
      'processed' => $processed[array_rand($processed)],
      'text' => 'Seguimiento ' . ($index + 1),
      'source_url' => null,
      'future_date' => date('Y-m-d H:i:s', $futureTimestamp),
      'status' => 1,
      'special' => null,
      'dc' => date('Y-m-d H:i:s', $timestamp),
      'tc' => $timestamp
    ];
  }

  // Limpiar todos los datos faker
  static function clean() {
    $tables = ['followups', 'chats', 'sales', 'clients'];
    $deleted = [];

    foreach ($tables as $table) {
      $tableName = DB_TABLES[$table];
      $count = ogDb::table($tableName)->count();
      ogDb::table($tableName)->delete();
      $deleted[$table] = $count;
    }

    return ['success' => true, 'deleted' => $deleted];
  }


  // Generar métricas publicitarias
  static function generateAdMetrics($params) {
    $num = $params['num'] ?? 10;
    $startDate = $params['start_date'] ?? date('Y-m-01');
    $endDate = $params['end_date'] ?? date('Y-m-t');

    // Obtener primer usuario admin
    $adminUser = ogDb::table('users')
      ->where('role', 'admin')
      ->orderBy('id', 'ASC')
      ->first();

    if (!$adminUser) {
      return ['success' => false, 'error' => 'No hay usuarios admin en el sistema'];
    }

    $userId = $adminUser['id'];

    $products = ogDb::table(DB_TABLES['products'])
      ->where('context', 'infoproductws')
      ->where('status', 1)
      ->where('user_id', $userId)
      ->limit($num)
      ->get();

    if (empty($products)) {
      return ['success' => false, 'error' => 'No hay productos disponibles para este usuario'];
    }

    $generated = [
      'assets' => 0,
      'daily_metrics' => 0,
      'hourly_metrics' => 0
    ];

    foreach ($products as $product) {
      $assets = self::generateAssetsForProduct($product);
      $generated['assets'] += count($assets);

      foreach ($assets as $asset) {
        if ($asset['is_active'] == 0) continue;

        $dailyMetrics = self::generateDailyMetrics($asset, $startDate, $endDate);
        $generated['daily_metrics'] += count($dailyMetrics);

        $hourlyMetrics = self::generateHourlyMetrics($asset);
        $generated['hourly_metrics'] += count($hourlyMetrics);
      }
    }

    return [
      'success' => true,
      'generated' => $generated,
      'period' => ['start' => $startDate, 'end' => $endDate]
    ];
  }

  // Generar activos publicitarios para un producto
  private static function generateAssetsForProduct($product) {
    $numAssets = rand(2, 4);
    $assets = [];
    $userId = $product['user_id'] ?? 1; // Tomar user_id del producto o default 1

    // Siempre 1 campaign
    $campaignId = self::generateFbId();
    $assets[] = [
      'product_id' => $product['id'],
      'user_id' => $userId,
      'ad_platform' => 'facebook',
      'ad_asset_type' => 'campaign',
      'ad_asset_id' => $campaignId,
      'ad_asset_name' => 'Campaña ' . $product['name'],
      'is_active' => 1,
      'query_frequency' => 60,
      'dc' => date('Y-m-d H:i:s'),
      'tc' => time()
    ];
    ogDb::table('product_ad_assets')->insert($assets[0]);

    // 1-3 adsets
    $numAdsets = $numAssets - 1;
    $adsetTypes = ['Tráfico Frío', 'Retargeting', 'Lookalike', 'Intereses'];

    for ($i = 0; $i < $numAdsets; $i++) {
      $isActive = $i < 2 ? 1 : rand(0, 1);
      $adsetId = self::generateFbId();

      $asset = [
        'product_id' => $product['id'],
        'user_id' => $userId,
        'ad_platform' => 'facebook',
        'ad_asset_type' => 'adset',
        'ad_asset_id' => $adsetId,
        'ad_asset_name' => 'AdSet ' . $adsetTypes[$i] . ' - ' . $product['name'],
        'is_active' => $isActive,
        'query_frequency' => 60,
        'dc' => date('Y-m-d H:i:s'),
        'tc' => time()
      ];

      ogDb::table('product_ad_assets')->insert($asset);
      $assets[] = $asset;
    }

    return $assets;
  }

  // Generar ID de Facebook fake
  private static function generateFbId() {
    return (string)(rand(100000000000, 999999999999));
  }

  // Generar métricas diarias
  private static function generateDailyMetrics($asset, $startDate, $endDate) {
    $dates = self::getDateRange($startDate, $endDate);
    $metrics = [];

    foreach ($dates as $date) {
      $dayOfWeek = date('N', strtotime($date));

      // Factor de gasto según día de semana
      $spendFactor = 1.0;
      if ($dayOfWeek == 5) $spendFactor = 1.2;
      if ($dayOfWeek == 6) $spendFactor = 1.3;
      if ($dayOfWeek == 7) $spendFactor = 0.9;

      $baseSpend = rand(20, 150);
      $spend = round($baseSpend * $spendFactor, 2);

      $impressions = rand(10000, 30000);
      $ctr = rand(15, 30) / 10;
      $clicks = round($impressions * ($ctr / 100));

      // Funnel de conversión
      $pageViews = round($clicks * 0.7);
      $viewContent = round($pageViews * 0.3);
      $addToCart = round($viewContent * 0.4);
      $initiateCheckout = round($addToCart * 0.55);
      $purchase = round($initiateCheckout * 0.6);

      $reach = round($impressions * 0.8);
      $linkClicks = round($clicks * 0.87);

      $avgOrderValue = rand(15, 50);
      $purchaseValue = round($purchase * $avgOrderValue, 2);

      $cpm = round(($spend / $impressions) * 1000, 2);
      $cpc = $clicks > 0 ? round($spend / $clicks, 2) : 0;
      $conversionRate = $clicks > 0 ? round(($purchase / $clicks) * 100, 2) : 0;

      $metric = [
        'user_id' => $asset['user_id'],
        'product_id' => $asset['product_id'],
        'ad_platform' => $asset['ad_platform'],
        'ad_asset_type' => $asset['ad_asset_type'],
        'ad_asset_id' => $asset['ad_asset_id'],
        'ad_asset_name' => $asset['ad_asset_name'],
        'spend' => $spend,
        'impressions' => $impressions,
        'reach' => $reach,
        'clicks' => $clicks,
        'link_clicks' => $linkClicks,
        'page_views' => $pageViews,
        'view_content' => $viewContent,
        'add_to_cart' => $addToCart,
        'initiate_checkout' => $initiateCheckout,
        'add_payment_info' => round($initiateCheckout * 0.9),
        'purchase' => $purchase,
        'lead' => 0,
        'purchase_value' => $purchaseValue,
        'conversions' => $purchase,
        'results' => $purchase,
        'cpm' => $cpm,
        'cpc' => $cpc,
        'ctr' => $ctr,
        'conversion_rate' => $conversionRate,
        'metric_date' => $date,
        'generated_at' => date('Y-m-d H:i:s'),
        'data_source' => 'hourly_aggregation',
        'dc' => date('Y-m-d H:i:s'),
        'tc' => time()
      ];

      ogDb::table('ad_metrics_daily')->insert($metric);
      $metrics[] = $metric;
    }

    return $metrics;
  }

  // Generar métricas horarias
  private static function generateHourlyMetrics($asset) {
    $dates = [date('Y-m-d', strtotime('-1 day')), date('Y-m-d')];
    $metrics = [];

    foreach ($dates as $date) {
      $dailyMetric = ogDb::table('ad_metrics_daily')
        ->where('ad_asset_id', $asset['ad_asset_id'])
        ->where('metric_date', $date)
        ->first();

      if (!$dailyMetric) continue;

      for ($hour = 0; $hour < 24; $hour++) {
        $completion = self::getHourlyCompletion($hour);

        $metric = [
          'user_id' => $asset['user_id'],
          'product_id' => $asset['product_id'],
          'ad_platform' => $asset['ad_platform'],
          'ad_asset_type' => $asset['ad_asset_type'],
          'ad_asset_id' => $asset['ad_asset_id'],
          'ad_asset_name' => $asset['ad_asset_name'],
          'spend' => round($dailyMetric['spend'] * $completion, 2),
          'impressions' => round($dailyMetric['impressions'] * $completion),
          'reach' => round($dailyMetric['reach'] * $completion),
          'clicks' => round($dailyMetric['clicks'] * $completion),
          'link_clicks' => round($dailyMetric['link_clicks'] * $completion),
          'page_views' => round($dailyMetric['page_views'] * $completion),
          'view_content' => round($dailyMetric['view_content'] * $completion),
          'add_to_cart' => round($dailyMetric['add_to_cart'] * $completion),
          'initiate_checkout' => round($dailyMetric['initiate_checkout'] * $completion),
          'add_payment_info' => round($dailyMetric['add_payment_info'] * $completion),
          'purchase' => round($dailyMetric['purchase'] * $completion),
          'lead' => 0,
          'purchase_value' => round($dailyMetric['purchase_value'] * $completion, 2),
          'conversions' => round($dailyMetric['conversions'] * $completion),
          'results' => round($dailyMetric['results'] * $completion),
          'cpm' => $dailyMetric['cpm'],
          'cpc' => $dailyMetric['cpc'],
          'ctr' => $dailyMetric['ctr'],
          'conversion_rate' => $dailyMetric['conversion_rate'],
          'query_date' => $date,
          'query_hour' => $hour,
          'api_response_time' => rand(100, 500),
          'api_status' => 'success',
          'dc' => date('Y-m-d H:i:s'),
          'tc' => time()
        ];

        ogDb::table('ad_metrics_hourly')->insert($metric);
        $metrics[] = $metric;
      }
    }

    return $metrics;
  }

  // Porcentaje de completado según la hora
  private static function getHourlyCompletion($hour) {
    $pattern = [
      0 => 0.02, 1 => 0.04, 2 => 0.05, 3 => 0.06, 4 => 0.07, 5 => 0.08,
      6 => 0.10, 7 => 0.15, 8 => 0.22, 9 => 0.30, 10 => 0.38, 11 => 0.46,
      12 => 0.54, 13 => 0.62, 14 => 0.69, 15 => 0.75, 16 => 0.80, 17 => 0.84,
      18 => 0.88, 19 => 0.91, 20 => 0.94, 21 => 0.96, 22 => 0.98, 23 => 1.00
    ];
    return $pattern[$hour];
  }

  // Obtener rango de fechas
  private static function getDateRange($startDate, $endDate) {
    $dates = [];
    $current = strtotime($startDate);
    $end = strtotime($endDate);

    while ($current <= $end) {
      $dates[] = date('Y-m-d', $current);
      $current = strtotime('+1 day', $current);
    }

    return $dates;
  }

  // Limpiar métricas publicitarias
  static function cleanAdMetrics() {
    $tables = ['ad_metrics_hourly', 'ad_metrics_daily', 'product_ad_assets'];
    $deleted = [];

    foreach ($tables as $table) {
      $count = ogDb::table($table)->count();
      ogDb::table($table)->delete();
      $deleted[$table] = $count;
    }

    return ['success' => true, 'deleted' => $deleted];
  }

  // Obtener estadísticas de conversión por día (usando payment_date)
  static function getConversionStatsByDay($params) {
    $range = $params['range'] ?? 'last_7_days';

    $dates = ogApp()->handler('sales')::calculateDateRange($range);
    if (!$dates) {
      return ['success' => false, 'error' => 'Rango de fecha inválido'];
    }

    // Query principal: ventas confirmadas por fecha de pago
    $sql = "
      SELECT
        DATE(payment_date) as date,
        COUNT(*) as total_confirmed,
        SUM(CASE WHEN tracking_funnel_id IS NULL THEN 1 ELSE 0 END) as direct_sales,
        SUM(CASE WHEN tracking_funnel_id IS NOT NULL THEN 1 ELSE 0 END) as funnel_sales,
        SUM(amount) as total_amount
      FROM " . ogApp()->handler('sales')::$table . "
      WHERE process_status = 'sale_confirmed'
        AND payment_date >= ?
        AND payment_date <= ?
      GROUP BY DATE(payment_date)
      ORDER BY date ASC
    ";

    $confirmed = ogDb::raw($sql, [$dates['start'], $dates['end']]);

    // Query para chats iniciados (por dc, no payment_date)
    $sqlInitiated = "
      SELECT
        DATE(dc) as date,
        COUNT(*) as total_initiated
      FROM " . ogApp()->handler('sales')::$table . "
      WHERE dc >= ? AND dc <= ?
      GROUP BY DATE(dc)
      ORDER BY date ASC
    ";

    $initiated = ogDb::raw($sqlInitiated, [$dates['start'], $dates['end']]);

    // Combinar ambos resultados
    $statsMap = [];

    // Inicializar con chats iniciados
    foreach ($initiated as $row) {
      $statsMap[$row['date']] = [
        'date' => $row['date'],
        'initiated' => (int)$row['total_initiated'],
        'confirmed_total' => 0,
        'confirmed_direct' => 0,
        'confirmed_funnel' => 0,
        'total_amount' => 0
      ];
    }

    // Agregar ventas confirmadas (por payment_date)
    foreach ($confirmed as $row) {
      if (!isset($statsMap[$row['date']])) {
        $statsMap[$row['date']] = [
          'date' => $row['date'],
          'initiated' => 0,
          'confirmed_total' => 0,
          'confirmed_direct' => 0,
          'confirmed_funnel' => 0,
          'total_amount' => 0
        ];
      }

      $statsMap[$row['date']]['confirmed_total'] = (int)$row['total_confirmed'];
      $statsMap[$row['date']]['confirmed_direct'] = (int)$row['direct_sales'];
      $statsMap[$row['date']]['confirmed_funnel'] = (int)$row['funnel_sales'];
      $statsMap[$row['date']]['total_amount'] = (float)$row['total_amount'];
    }

    // Convertir a array ordenado
    $results = array_values($statsMap);
    usort($results, fn($a, $b) => strcmp($a['date'], $b['date']));

    return [
      'success' => true,
      'data' => $results,
      'period' => $dates
    ];
  }
}