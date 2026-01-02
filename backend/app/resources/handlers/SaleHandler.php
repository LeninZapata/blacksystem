<?php
class SaleHandler {

  private static $table = DB_TABLES['sales'];
  private static $logMeta = [ 'module' => 'SaleHandler', 'layer' => 'app/handler' ];

  // Crear venta simple
  static function create($data) {
    if (!isset($data['amount'])) {
      return ['success' => false, 'error' => 'create - ' . __('sale.amount_required')];
    }

    $data['dc'] = date('Y-m-d H:i:s');
    $data['tc'] = time();
    $data['sale_type'] = $data['sale_type'] ?? 'main';
    $data['context'] = $data['context'] ?? 'whatsapp';
    $data['process_status'] = $data['process_status'] ?? 'initiated';
    $data['force_welcome'] = $data['force_welcome'] ?? 0;
    $data['parent_sale_id'] = $data['parent_sale_id'] ?? 0;
    $data['is_downsell'] = $data['is_downsell'] ?? 0;

    try {
      $id = ogDb::table(self::$table)->insert($data);
      return [
        'success' => true,
        'sale_id' => $id,
        'data' => array_merge($data, ['id' => $id])
      ];
    } catch (Exception $e) {
      return [
        'success' => false,
        'error' => __('sale.create.error'),
        'details' => OG_IS_DEV ? $e->getMessage() : null
      ];
    }
  }

  // Obtener ventas por cliente
  static function getByClient($params) {
    $clientId = $params['client_id'];
    $limit = ogRequest::query('limit', 50);

    $sales = ogDb::table(self::$table)
      ->where('client_id', $clientId)
      ->orderBy('dc', 'DESC')
      ->limit($limit)
      ->get();

    return ['success' => true, 'data' => $sales ?? []];
  }

  // Obtener ventas por bot
  static function getByBot($params) {
    $botId = $params['bot_id'];
    $limit = ogRequest::query('limit', 50);

    $sales = ogDb::table(self::$table)
      ->where('bot_id', $botId)
      ->orderBy('dc', 'DESC')
      ->limit($limit)
      ->get();

    return ['success' => true, 'data' => $sales ?? []];
  }

  // Obtener ventas por producto
  static function getByProduct($params) {
    $productId = $params['product_id'];
    $limit = ogRequest::query('limit', 50);

    $sales = ogDb::table(self::$table)
      ->where('product_id', $productId)
      ->orderBy('dc', 'DESC')
      ->limit($limit)
      ->get();

    return ['success' => true, 'data' => $sales ?? []];
  }

  // Obtener ventas por estado
  static function getByStatus($params) {
    $status = $params['status'];
    $limit = ogRequest::query('limit', 50);

    $sales = ogDb::table(self::$table)
      ->where('process_status', $status)
      ->orderBy('dc', 'DESC')
      ->limit($limit)
      ->get();

    return ['success' => true, 'data' => $sales ?? []];
  }

  // Actualizar estado de venta
  static function updateStatus($saleId, $status) {
    $sale = ogDb::table(self::$table)->find($saleId);

    if (!$sale) {
      return ['success' => false, 'error' => __('sale.not_found')];
    }

    $data = [
      'process_status' => $status,
      'du' => date('Y-m-d H:i:s'),
      'tu' => time()
    ];

    try {
      ogDb::table(self::$table)->where('id', $saleId)->update($data);
      return ['success' => true, 'data' => $data];
    } catch (Exception $e) {
      return [
        'success' => false,
        'error' => __('sale.update.error'),
        'details' => OG_IS_DEV ? $e->getMessage() : null
      ];
    }
  }

  // Registrar pago
  static function registerPayment($saleId, $transactionId, $paymentMethod, $paymentDate = null) {
    $sale = ogDb::table(self::$table)->find($saleId);

    if (!$sale) {
      return ['success' => false, 'error' => __('sale.not_found')];
    }

    $data = [
      'transaction_id' => $transactionId,
      'payment_method' => $paymentMethod,
      'payment_date' => $paymentDate ?? date('Y-m-d H:i:s'),
      'process_status' => 'sale_confirmed',
      'du' => date('Y-m-d H:i:s'),
      'tu' => time()
    ];

    try {
      ogDb::table(self::$table)->where('id', $saleId)->update($data);
      return ['success' => true, 'data' => $data];
    } catch (Exception $e) {
      return [
        'success' => false,
        'error' => __('sale.update.error'),
        'details' => OG_IS_DEV ? $e->getMessage() : null
      ];
    }
  }

  // Obtener estadísticas de ventas
  static function getStats($params) {
    $botId = $params['bot_id'] ?? null;

    $query = ogDb::table(self::$table);
    if ($botId) {
      $query = $query->where('bot_id', $botId);
    }

    $stats = [
      'total_sales' => $query->count(),
      'total_amount' => $query->sum('amount'),
      'by_status' => [
        'initiated' => ogDb::table(self::$table)->where('process_status', 'initiated')->count(),
        'pending' => ogDb::table(self::$table)->where('process_status', 'pending')->count(),
        'sale_confirmed' => ogDb::table(self::$table)->where('process_status', 'sale_confirmed')->count(),
        'cancelled' => ogDb::table(self::$table)->where('process_status', 'cancelled')->count()
      ],
      'by_type' => [
        'main' => ogDb::table(self::$table)->where('sale_type', 'main')->count(),
        'ob' => ogDb::table(self::$table)->where('sale_type', 'ob')->count(),
        'us' => ogDb::table(self::$table)->where('sale_type', 'us')->count()
      ]
    ];

    return ['success' => true, 'data' => $stats];
  }

  // Obtener ventas con upsells/order bumps
  static function getWithRelated($params) {
    $saleId = $params['sale_id'];

    $mainSale = ogDb::table(self::$table)->find($saleId);
    if (!$mainSale) {
      return ['success' => false, 'error' => __('sale.not_found')];
    }

    // Buscar order bumps y upsells relacionados
    $related = ogDb::table(self::$table)
      ->where('parent_sale_id', $saleId)
      ->get();

    return [
      'success' => true,
      'data' => [
        'main_sale' => $mainSale,
        'related_sales' => $related ?? []
      ]
    ];
  }

  // Buscar venta por transaction_id
  static function getByTransactionId($params) {
    $transactionId = $params['transaction_id'];

    $sale = ogDb::table(self::$table)
      ->where('transaction_id', $transactionId)
      ->first();

    if (!$sale) {
      return ['success' => false, 'error' => __('sale.not_found')];
    }

    return ['success' => true, 'data' => $sale];
  }

  // Eliminar ventas por cliente
  static function deleteByClient($params) {
    $clientId = $params['client_id'];

    try {
      $affected = ogDb::table(self::$table)->where('client_id', $clientId)->delete();
      return [
        'success' => true,
        'message' => __('sale.delete_all.success'),
        'deleted' => $affected
      ];
    } catch (Exception $e) {
      return [
        'success' => false,
        'error' => __('sale.delete_all.error'),
        'details' => OG_IS_DEV ? $e->getMessage() : null
      ];
    }
  }

   // Estadísticas agrupadas por día
  static function getStatsByDay($params) {
    $range = $params['range'] ?? 'last_7_days';

    $dates = self::calculateDateRange($range);
    if (!$dates) {
      return ['success' => false, 'error' => 'Rango de fecha inválido'];
    }

    $sql = "
      SELECT
        DATE(dc) as date,
        SUM(CASE WHEN process_status = 'initiated' THEN 1 ELSE 0 END) as total_sales,
        SUM(CASE WHEN process_status = 'sale_confirmed' THEN 1 ELSE 0 END) as confirmed_sales,
        SUM(CASE WHEN process_status = 'sale_confirmed' THEN amount ELSE 0 END) as total_amount
      FROM " . self::$table . "
      WHERE dc >= ? AND dc <= ?
      GROUP BY DATE(dc)
      ORDER BY date ASC
    ";

    $results = ogDb::raw($sql, [$dates['start'], $dates['end']]);

    return [
      'success' => true,
      'data' => $results,
      'period' => $dates
    ];
  }

  // Calcular rango de fechas
  public static function calculateDateRange($range) {
    $now = new DateTime();
    $start = clone $now;
    $end = clone $now;

    switch ($range) {
      case 'today':
        $start->setTime(0, 0, 0);
        $end->setTime(23, 59, 59);
        break;

      case 'yesterday':
        $start->modify('-1 day')->setTime(0, 0, 0);
        $end->modify('-1 day')->setTime(23, 59, 59);
        break;

      case 'last_7_days':
        $start->modify('-6 days')->setTime(0, 0, 0);
        $end->setTime(23, 59, 59);
        break;

      case 'last_10_days':
        $start->modify('-9 days')->setTime(0, 0, 0);
        $end->setTime(23, 59, 59);
        break;

      case 'last_15_days':
        $start->modify('-14 days')->setTime(0, 0, 0);
        $end->setTime(23, 59, 59);
        break;

      case 'this_week':
        $start->modify('monday this week')->setTime(0, 0, 0);
        $end->setTime(23, 59, 59);
        break;

      case 'this_month':
        $start->modify('first day of this month')->setTime(0, 0, 0);
        $end->setTime(23, 59, 59);
        break;

      case 'last_30_days':
        $start->modify('-29 days')->setTime(0, 0, 0);
        $end->setTime(23, 59, 59);
        break;

      case 'last_month':
        $start->modify('first day of last month')->setTime(0, 0, 0);
        $end->modify('last day of last month')->setTime(23, 59, 59);
        break;

      default:
        return null;
    }

    return [
      'start' => $start->format('Y-m-d H:i:s'),
      'end' => $end->format('Y-m-d H:i:s'),
      'range' => $range
    ];
}

  // Ventas por producto
  static function getStatsByProduct($params) {
    $range = $params['range'] ?? 'last_7_days';

    $dates = self::calculateDateRange($range);
    if (!$dates) {
      return ['success' => false, 'error' => 'Rango de fecha inválido'];
    }

    $sql = "
      SELECT
        product_id,
        product_name,
        COUNT(*) as total_sales,
        SUM(CASE WHEN process_status = 'sale_confirmed' THEN 1 ELSE 0 END) as confirmed_sales,
        SUM(CASE WHEN process_status = 'sale_confirmed' THEN amount ELSE 0 END) as total_amount
      FROM " . self::$table . "
      WHERE dc >= ? AND dc <= ?
      GROUP BY product_id, product_name
      ORDER BY confirmed_sales DESC
    ";

    $results = ogDb::raw($sql, [$dates['start'], $dates['end']]);

    return [
      'success' => true,
      'data' => $results,
      'period' => $dates
    ];
  }

  // Mensajes nuevos por día (ventas con estado initiated)
  static function getNewMessagesByDay($params) {
    $range = $params['range'] ?? 'last_7_days';

    $dates = self::calculateDateRange($range);
    if (!$dates) {
      return ['success' => false, 'error' => 'Rango de fecha inválido'];
    }

    $sql = "
      SELECT
        DATE(dc) as date,
        COUNT(*) as new_messages
      FROM " . self::$table . "
      WHERE dc >= ? AND dc <= ?
      AND process_status = 'initiated'
      GROUP BY DATE(dc)
      ORDER BY date ASC
    ";

    $results = ogDb::raw($sql, [$dates['start'], $dates['end']]);

    return [
      'success' => true,
      'data' => $results,
      'period' => $dates
    ];
  }

  // Obtener estadísticas de conversión por día (usando payment_date)
  static function getConversionStatsByDay($params) {
    $range = $params['range'] ?? 'last_7_days';

    $dates = self::calculateDateRange($range);
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
      FROM " . self::$table . "
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
      FROM " . self::$table . "
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
