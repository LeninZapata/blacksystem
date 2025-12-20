<?php
class clientHandlers {
  private static $table = 'clients';

  // Eliminar todos los datos del cliente en cascada
  static function deleteAllData($params) {

    $id = $params['id'];
    $number = $params['number'];

    try {

      $sales = db::table('sales')->where('client_id', $id)->delete();
      $chats = db::table('chats')->where('client_id', $id)->delete();
      db::table(self::$table)->where('id', $id)->delete();

      // Eliminar todos los archivos chat del cliente
      $deletedFiles = file::deletePattern(SHARED_PATH . "/chats/infoproduct/chat_{$number}_bot_*.json");

      return [
        'success' => true,
        'message' => __('client.delete_all.success'),
        'deleted' => [
          'client' => 1,
          'sales' => $sales,
          'chats' => $chats,
          'files' => $deletedFiles
        ]
      ];
    } catch (Exception $e) {
      return [
        'success' => false,
        'error' => __('client.delete_all.error'),
        'details' => IS_DEV ? $e->getMessage() : null
      ];
    }
  }

  // Eliminar todos los datos del cliente por número
  static function deleteAllDataByNumber($params) {
    $number = $params['number'];

    // Buscar cliente por número
    $client = db::table(self::$table)->where('number', $number)->first();
    if (!$client) {
      return ['success' => false, 'error' => __('client.not_found')];
    }

    return self::deleteAllData(['id' => $client['id'] , 'number' => $number ]);
  }

  // Buscar cliente por número
  static function getByNumber($params) {
    $number = $params['number'];

    $client = db::table(self::$table)
      ->where('number', $number)
      ->first();

    if (!$client) {
      return ['success' => false, 'error' => __('client.not_found')];
    }

    return ['success' => true, 'data' => $client];
  }

  // Top clientes por monto gastado

  static function topClients($params) {
    $limit = request::query('limit', 10);

    $clients = db::table(self::$table)
      ->select(['id', 'name', 'number', 'email', 'amount_spent', 'total_purchases'])
      ->orderBy('amount_spent', 'DESC')
      ->limit($limit)
      ->get();

    return ['success' => true, 'data' => $clients];
  }

  // Registrar o actualizar cliente
  // Si existe, actualiza última interacción
  // Si no existe, lo crea
  static function registerOrUpdate($number, $name = null, $countryCode = null, $device = null) {
    // Buscar cliente existente
    $client = db::table(self::$table)->where('number', $number)->first();

    if ($client) {
      // Actualizar última interacción
      $data = [
        'du' => date('Y-m-d H:i:s'),
        'tu' => time()
      ];

      // Actualizar nombre si se proporciona y no existe
      if ($name && empty($client['name'])) {
        $data['name'] = $name;
      }

      // Actualizar device si se proporciona
      if ($device) {
        $data['device'] = $device;
      }

      db::table(self::$table)->where('id', $client['id'])->update($data);

      return [
        'success' => true,
        'client_id' => $client['id'],
        'action' => 'updated',
        'data' => array_merge($client, $data)
      ];
    }

    // Crear nuevo cliente
    $data = [
      'number' => $number,
      'name' => $name,
      'country_code' => $countryCode ?? 'EC',
      'device' => $device,
      'status' => 1,
      'total_purchases' => 0,
      'amount_spent' => 0.00,
      'dc' => date('Y-m-d H:i:s'),
      'tc' => time()
    ];

    try {
      $id = db::table(self::$table)->insert($data);

      return [
        'success' => true,
        'client_id' => $id,
        'action' => 'created',
        'data' => array_merge($data, ['id' => $id])
      ];
    } catch (Exception $e) {
      return [
        'success' => false,
        'error' => __('client.create.error'),
        'details' => IS_DEV ? $e->getMessage() : null
      ];
    }
  }

  // Incrementar total de compras y monto gastado
  static function incrementPurchase($clientId, $amount) {
    $client = db::table(self::$table)->find($clientId);
    
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
      db::table(self::$table)->where('id', $clientId)->update($data);

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
        'details' => IS_DEV ? $e->getMessage() : null
      ];
    }
  }

  // Buscar clientes por email
  static function getByEmail($params) {
    $email = $params['email'];

    $client = db::table(self::$table)
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
      'total_clients' => db::table(self::$table)->count(),
      'active_clients' => db::table(self::$table)->where('status', 1)->count(),
      'inactive_clients' => db::table(self::$table)->where('status', 0)->count(),
      'total_purchases' => db::table(self::$table)->sum('total_purchases'),
      'total_revenue' => db::table(self::$table)->sum('amount_spent')
    ];

    return ['success' => true, 'data' => $stats];
  }
}