<?php
class ClientHandler {
  private static $logMeta = ['module' => 'ClientHandler', 'layer' => 'app/resources'];

  // Eliminar todos los datos del cliente en cascada
  // $params['ids']   => array de client_id (cuando viene de deleteAllDataByNumber)
  // $params['id']    => client_id único (cuando viene de deleteAllData por ID)
  // $params['number'] => número de teléfono
  static function deleteAllData($params) {

    $number = $params['number'];
    // Soportar tanto id único como múltiples ids
    $ids = $params['ids'] ?? (isset($params['id']) ? [$params['id']] : []);

    try {

      $chats     = ogDb::t('chats')->where('client_number', $number)->delete();
      $followups = ogDb::t('followups')->where('number', $number)->delete();

      // Eliminar payments vinculados a las ventas de este número antes de borrar las ventas
      $saleRows = ogDb::t('sales')->where('number', $number)->get();
      if (!empty($saleRows)) {
        $saleIds      = array_column($saleRows, 'id');
        $placeholders = implode(',', array_fill(0, count($saleIds), '?'));
        ogDb::raw("DELETE FROM payment WHERE sale_id IN ({$placeholders})", $saleIds);
      }

      $sales     = ogDb::t('sales')->where('number', $number)->delete();
      ogDb::t('clients')->where('number', $number)->delete();

      // Eliminar client_bot_meta para todos los client_id del número
      $metaDeleted = 0;
      if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $metaDeleted  = ogDb::raw(
          "DELETE FROM client_bot_meta WHERE client_id IN ({$placeholders})",
          $ids
        );
      }

      // Cargar ogFile bajo demanda
      $file = ogApp()->helper('file');

      // Eliminar todos los archivos chat del cliente
      $deletedFiles = $file->deletePattern(ogApp()->getPath('storage/json/chats') . "/chat_{$number}_bot_*.json");

      return [
        'success' => true,
        'message' => __('client.delete_all.success'),
        'deleted' => [
          'clients'          => count($ids) ?: 1,
          'client_bot_meta'  => $metaDeleted,
          'sales'            => $sales,
          'chats'            => $chats,
          'followups'        => $followups,
          'files'            => $deletedFiles
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

    // Buscar TODOS los clientes con ese número (puede haber varios client_id, uno por bot)
    $clients = ogDb::t('clients')->where('number', $number)->get();

    if (empty($clients)) {
      // Limpiar residuos aunque no exista el cliente en BD
      $exist = ogApp()->helper('file')->deletePattern(ogApp()->getPath('storage/json/chats') . "/chat_{$number}_bot_*.json");
      if ($exist) {
      }
      $exist = ogDb::t('chats')->where('client_number', $number)->delete();
      if ($exist) {
        ogLog::debug('deleteAllDataByNumber - Chats residual eliminado de BD', ['number' => $number], self::$logMeta);
      }
      $exist = ogDb::t('followups')->where('number', $number)->delete();
      if ($exist) {
        ogLog::debug('deleteAllDataByNumber - Followups residual eliminado de BD', ['number' => $number], self::$logMeta);
      }
      return ['success' => false, 'error' => __('client.not_found')];
    }

    // Recolectar todos los client_id del número para limpiar client_bot_meta
    $clientIds = array_column($clients, 'id');

    return self::deleteAllData(['ids' => $clientIds, 'number' => $number]);
  }

  // Obtener todos los datos del cliente por número (chats, followups, sales)
  static function getAllDataByNumber($params) {
    $number = $params['number'];

    try {
      // Buscar cliente
      $client = ogDb::t('clients')->where('number', $number)->first();
      if (!$client) {
        // Limpiar archivos basura de chat si existen
        $deletedFiles = ogApp()->helper('file')->deletePattern(ogApp()->getPath('storage/json/chats') . "/chat_{$number}_bot_*.json");
        if ($deletedFiles) {
          ogLog::debug('getAllDataByNumber - Cliente no encontrado, archivos JSON eliminados', ['number' => $number, 'files_deleted' => $deletedFiles], self::$logMeta);
        }
        return ['success' => false, 'error' => __('client.not_found')];
      }

      // Obtener todos los datos relacionados
      $chats = ogDb::t('chats')->where('client_number', $number)->get();
      $followups = ogDb::t('followups')->where('number', $number)->get();
      $sales = ogDb::t('sales')->where('number', $number)->get();

      // Obtener archivos de chat
      $chatFiles = [];
      $chatPath = ogApp()->getPath('storage/json/chats');
      $pattern = "chat_{$number}_bot_*.json";
      $files = glob($chatPath . '/' . $pattern);

      if ($files) {
        foreach ($files as $file) {
          $chatData = json_decode(file_get_contents($file), true);
          if ($chatData) {
            $chatFiles[] = [
              'file' => basename($file),
              'messages_count' => count($chatData['messages'] ?? [])
            ];
          }
        }
      }

      return [
        'success' => true,
        'data' => [
          'client' => $client,
          'stats' => [
            'total_sales' => count($sales),
            'total_chats' => count($chats),
            'total_followups' => count($followups),
            'total_files' => count($chatFiles)
          ],
          'sales' => $sales,
          'chats' => $chats,
          'followups' => $followups,
          'chat_files' => $chatFiles
        ]
      ];
    } catch (Exception $e) {
      return [
        'success' => false,
        'error' => __('client.get_all_data.error'),
        'details' => OG_IS_DEV ? $e->getMessage() : null
      ];
    }
  }

  // Buscar cliente por número
  static function getByNumber($params) {
    $number = $params['number'];

    $client = ogDb::t('clients')
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

    $clients = ogDb::t('clients')
      ->select(['id', 'name', 'number', 'email', 'amount_spent', 'total_purchases'])
      ->orderBy('amount_spent', 'DESC')
      ->limit($limit)
      ->get();

    return ['success' => true, 'data' => $clients];
  }

  // Registrar o actualizar cliente
  // Si existe, actualiza última interacción
  // Si no existe, lo crea
  // $bsuid: Business-Scoped User ID de Meta (desde marzo 2026)
  //         Se usa como identificador alternativo cuando el número no está disponible
  static function registerOrUpdate($number, $name, $countryCode, $device = null, $userId = null, $bsuid = null) {
    try {
      // Buscar cliente existente: primero por número, luego por bsuid si no hay número
      $existing = null;
      if ($number) {
        $query = ogDb::t('clients')->where('number', $number);
        if ($userId) $query = $query->where('user_id', $userId);
        $existing = $query->first();
      }
      if (!$existing && $bsuid) {
        $query = ogDb::t('clients')->where('bsuid', $bsuid);
        if ($userId) $query = $query->where('user_id', $userId);
        $existing = $query->first();
      }

      if (!$existing && !$number && !$bsuid) {
        ogLog::error("registerOrUpdate - Sin number ni bsuid, no se puede identificar al cliente", [], self::$logMeta);
        return ['success' => false, 'error' => 'no_identifier'];
      }

      if ($existing) {
        // Actualizar cliente existente
        $updateData = [
          'device' => $device,
          'du' => date('Y-m-d H:i:s'),
          'tu' => time()
        ];
        if (!empty(trim((string)$name))) {
          $updateData['name'] = $name;
        }
        // Guardar bsuid si llega y aún no estaba registrado
        if ($bsuid && empty($existing['bsuid'])) {
          $updateData['bsuid'] = $bsuid;

          // Merge: si existe otro registro creado solo con ese bsuid (sin número),
          // reasignar sus datos al cliente actual y eliminar el duplicado
          $bsuidQuery = ogDb::t('clients')->where('bsuid', $bsuid)->where('id', '!=', $existing['id']);
          if ($userId) $bsuidQuery = $bsuidQuery->where('user_id', $userId);
          $duplicate = $bsuidQuery->first();

          if ($duplicate) {
            $keepId    = $existing['id'];
            $removeId  = $duplicate['id'];

            // Reasignar registros vinculados al cliente duplicado
            ogDb::t('sales')->where('client_id', $removeId)->update(['client_id' => $keepId]);
            ogDb::t('chats')->where('client_id', $removeId)->update(['client_id' => $keepId]);
            ogDb::t('followups')->where('client_id', $removeId)->update(['client_id' => $keepId]);
            ogDb::raw(
              "DELETE FROM client_bot_meta WHERE client_id = ?",
              [$removeId]
            );
            ogDb::t('clients')->where('id', $removeId)->delete();

            ogLog::info('registerOrUpdate - Merge de clientes: bsuid-only fusionado con number-client', [
              'kept_id'     => $keepId,
              'removed_id'  => $removeId,
              'number'      => $number,
              'bsuid'       => $bsuid
            ], self::$logMeta);
          }
        }
        // Si llegó el número y el cliente fue encontrado solo por bsuid, guardarlo
        if ($number && empty($existing['number'])) {
          $updateData['number'] = $number;
        }

        ogDb::t('clients')
          ->where('id', $existing['id'])
          ->update($updateData);

        return [
          'success' => true,
          'client_id' => $existing['id'],
          'action' => 'updated'
        ];
      }

      // Crear nuevo cliente
      $clientData = [
        'user_id' => $userId,
        'number' => $number,
        'bsuid' => $bsuid,
        'name' => $name,
        'country_code' => $countryCode,
        'device' => $device,
        'total_purchases' => 0,
        'amount_spent' => 0.00,
        'status' => 1,
        'dc' => date('Y-m-d H:i:s'),
        'tc' => time()
      ];

      $clientId = ogDb::t('clients')->insert($clientData);

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
    $client = ogDb::t('clients')->find($clientId);

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
      ogDb::t('clients')->where('id', $clientId)->update($data);

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

    $client = ogDb::t('clients')
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
      'total_clients' => ogDb::t('clients')->count(),
      'active_clients' => ogDb::t('clients')->where('status', 1)->count(),
      'inactive_clients' => ogDb::t('clients')->where('status', 0)->count(),
      'total_purchases' => ogDb::t('clients')->sum('total_purchases'),
      'total_revenue' => ogDb::t('clients')->sum('amount_spent')
    ];

    return ['success' => true, 'data' => $stats];
  }
}