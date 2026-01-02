<?php
class ClientHandler {
  private static $table = DB_TABLES['clients'];
  private static $logMeta = ['module' => 'ClientHandler', 'layer' => 'app/resources'];

  // Eliminar todos los datos del cliente en cascada
  static function deleteAllData($params) {

    $id = $params['id'];
    $number = $params['number'];

    try {

      $chats = ogDb::table('chats')->where('client_number', $number)->delete();
      $followups = ogDb::table('followups')->where('number', $number)->delete();
      $sales = ogDb::table('sales')->where('number', $number)->delete();
      ogDb::table(self::$table)->where('number', $number)->delete();

      // Cargar ogFile bajo demanda
      $file = ogApp()->helper('file');

      // Eliminar todos los archivos chat del cliente
      $deletedFiles = $file->deletePattern(CHATS_STORAGE_PATH . "/chat_{$number}_bot_*.json");

      return [
        'success' => true,
        'message' => __('client.delete_all.success'),
        'deleted' => [
          'client' => 1,
          'sales' => $sales,
          'chats' => $chats,
          'followups' => $followups,
          'files' => $deletedFiles
        ]
      ];
    } catch (Exception $e) {
      return [
        'success' => false,
        'error' => __('client.delete_all.error'),
        'details' => OG_IS_DEV ? $e->getMessage() : null
      ];
    }
  }

  // Eliminar todos los datos del cliente por número
  static function deleteAllDataByNumber($params) {
    $number = $params['number'];

    // Buscar cliente por número
    $client = ogDb::table(self::$table)->where('number', $number)->first();
    if (!$client) {
      return ['success' => false, 'error' => __('client.not_found')];
    }

    return self::deleteAllData(['id' => $client['id'] , 'number' => $number ]);
  }

  // Buscar cliente por número
  static function getByNumber($params) {
    $number = $params['number'];

    $client = ogDb::table(self::$table)
      ->where('number', $number)
      ->first();

    if (!$client) {
      return ['success' => false, 'error' => __('client.not_found')];
    }

    return ['success' => true, 'data' => $client];
  }

  // Top clientes por monto gastado

  static function topClients($params) {
    $limit = ogRequest::query('limit', 10);

    $clients = ogDb::table(self::$table)
      ->select(['id', 'name', 'number', 'email', 'amount_spent', 'total_purchases'])
      ->orderBy('amount_spent', 'DESC')
      ->limit($limit)
      ->get();

    return ['success' => true, 'data' => $clients];
  }

  // Registrar o actualizar cliente
  // Si existe, actualiza última interacción
  // Si no existe, lo crea
  static function registerOrUpdate($number, $name, $countryCode, $device = null, $userId = null) {
    try {
      // Buscar cliente existente
      $existing = ogDb::table(self::$table)
        ->where('number', $number)
        ->first();

      if ($existing) {
        // Actualizar cliente existente
        $updateData = [
          'name' => $name,
          'device' => $device,
          'du' => date('Y-m-d H:i:s'),
          'tu' => time()
        ];

        ogDb::table(self::$table)
          ->where('id', $existing['id'])
          ->update($updateData);

        ogLog::info("registerOrUpdate - Cliente actualizado", [
          'client_id' => $existing['id'],
          'number' => $number
        ], self::$logMeta);

        return [
          'success' => true,
          'client_id' => $existing['id'],
          'action' => 'updated'
        ];
      }

      // Crear nuevo cliente
      $clientData = [
        'user_id' => $userId, // AGREGAR USER_ID
        'number' => $number,
        'name' => $name,
        'country_code' => $countryCode,
        'device' => $device,
        'total_purchases' => 0,
        'amount_spent' => 0.00,
        'status' => 1,
        'dc' => date('Y-m-d H:i:s'),
        'tc' => time()
      ];

      $clientId = ogDb::table(self::$table)->insert($clientData);

      ogLog::info("registerOrUpdate - Cliente creado", [
        'client_id' => $clientId,
        'number' => $number,
        'user_id' => $userId
      ], self::$logMeta);

      return [
        'success' => true,
        'client_id' => $clientId,
        'action' => 'created'
      ];

    } catch (Exception $e) {
      ogLog::error("registerOrUpdate - Error", [
        'error' => $e->getMessage(),
        'number' => $number
      ], self::$logMeta);

      return [
        'success' => false,
        'error' => $e->getMessage()
      ];
    }
  }

  // Incrementar total de compras y monto gastado
  static function incrementPurchase($clientId, $amount) {
    $client = ogDb::table(self::$table)->find($clientId);

    if (!$client) {
      return ['success' => false, 'error' => __('client.not_found')];
    }

    $data = [
      'total_purchases' => ($client['total_purchases'] ?? 0) + 1,
      'amount_spent' => ($client['amount_spent'] ?? 0) + $amount,
      'du' => date('Y-m-d H:i:s'),
      'tu' => time()
    ];

    try {
      ogDb::table(self::$table)->where('id', $clientId)->update($data);

      return [
        'success' => true,
        'data' => [
          'total_purchases' => $data['total_purchases'],
          'amount_spent' => $data['amount_spent']
        ]
      ];
    } catch (Exception $e) {
      return [
        'success' => false,
        'error' => __('client.update.error'),
        'details' => OG_IS_DEV ? $e->getMessage() : null
      ];
    }
  }

  // Buscar clientes por email
  static function getByEmail($params) {
    $email = $params['email'];

    $client = ogDb::table(self::$table)
      ->where('email', $email)
      ->first();

    if (!$client) {
      return ['success' => false, 'error' => __('client.not_found')];
    }

    return ['success' => true, 'data' => $client];
  }

  // Obtener estadísticas de clientes
  static function getStats($params) {
    $stats = [
      'total_clients' => ogDb::table(self::$table)->count(),
      'active_clients' => ogDb::table(self::$table)->where('status', 1)->count(),
      'inactive_clients' => ogDb::table(self::$table)->where('status', 0)->count(),
      'total_purchases' => ogDb::table(self::$table)->sum('total_purchases'),
      'total_revenue' => ogDb::table(self::$table)->sum('amount_spent')
    ];

    return ['success' => true, 'data' => $stats];
  }
}