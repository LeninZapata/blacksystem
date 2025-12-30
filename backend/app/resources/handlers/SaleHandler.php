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
}