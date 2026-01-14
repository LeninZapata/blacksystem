<?php
class FakerHandler {

  // Datos reutilizables
  private static $names = ['Carlos', 'María', 'José', 'Ana', 'Luis', 'Carmen', 'Miguel', 'Rosa', 'Pedro', 'Laura', 'Jorge', 'Isabel', 'Diego', 'Patricia', 'Fernando', 'Lucía', 'Roberto', 'Sofía', 'Manuel', 'Elena'];

  private static $surnames = ['García', 'Rodríguez', 'Martínez', 'López', 'González', 'Pérez', 'Sánchez', 'Ramírez', 'Torres', 'Flores', 'Rivera', 'Gómez', 'Díaz', 'Cruz', 'Morales', 'Reyes'];

  private static $devices = ['android', 'iphone', 'web'];

  // Obtener admin user (cache en memoria)
  private static function getAdminUserId() {
    return ogApp()->helper('cache')::memoryRemember('faker_admin_user', function() {
      $admin = ogDb::t('users')->where('role', 'admin')->orderBy('id', 'ASC')->first();
      return $admin ? $admin['id'] : null;
    });
  }

  // Calcular probabilidad (0-100)
  private static function probability($chance) {
    return rand(1, 100) <= $chance;
  }

  // Distribuir registros por fecha (aleatoria y realista)
  private static function distributeDates($total, $startDate, $endDate) {
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);
    $days = (int)$start->diff($end)->days + 1;

    if ($days <= 0) return [];

    // Generar pesos aleatorios para cada día (pueden ser 0)
    $weights = [];
    for ($i = 0; $i < $days; $i++) {
      // 30% probabilidad de día sin registros
      $weights[$i] = rand(0, 100) < 30 ? 0 : rand(1, 10);
    }

    $totalWeight = array_sum($weights);
    if ($totalWeight == 0) $totalWeight = 1; // Evitar división por 0

    // Distribuir según pesos
    $distribution = [];
    $assigned = 0;

    for ($i = 0; $i < $days; $i++) {
      $date = clone $start;
      $date->modify("+{$i} days");

      if ($i == $days - 1) {
        // Último día: asignar el remainder
        $count = $total - $assigned;
      } else {
        // Distribuir proporcionalmente
        $count = round(($weights[$i] / $totalWeight) * $total);
      }

      if ($count > 0) {
        $distribution[$date->format('Y-m-d')] = $count;
        $assigned += $count;
      }
    }

    return $distribution;
  }

  // ==========================================
  // GENERAR CHATS
  // ==========================================

  static function generateChats($params) {
    $userId = self::getAdminUserId();
    if (!$userId) {
      return ['success' => false, 'error' => 'No hay usuarios admin'];
    }

    // Obtener clientes existentes del usuario
    $clients = ogDb::t('clients')->where('user_id', $userId)->get();
    if (empty($clients)) {
      return ['success' => false, 'error' => 'No hay clientes. Genera clientes primero.'];
    }

    // Obtener bots del usuario
    $bots = ogDb::t('bots')->where('user_id', $userId)->where('status', 1)->get();
    if (empty($bots)) {
      return ['success' => false, 'error' => 'No hay bots activos'];
    }

    $inserted = 0;
    $formats = ['text', 'audio', 'image'];
    $messages = [
      'P' => ['Hola', 'Buenos días', 'Me interesa', 'Cuánto cuesta?', 'Tienes disponible?', 'Ok perfecto', 'Gracias'],
      'B' => ['Hola! En qué puedo ayudarte?', 'Claro, te cuento', 'El precio es...', 'Sí tenemos', 'Con gusto', 'A la orden']
    ];

    foreach ($clients as $client) {
      $bot = $bots[array_rand($bots)];
      $clientTimestamp = strtotime($client['dc']);

      // 1-3 mensajes por cliente
      $numMessages = rand(1, 3);

      for ($i = 0; $i < $numMessages; $i++) {
        // Alternar entre P y B
        $type = $i % 2 == 0 ? 'P' : 'B';

        // Timestamp: mismo día del cliente + minutos aleatorios
        $timestamp = $clientTimestamp + ($i * rand(60, 300));

        ogDb::t('chats')->insert([
          'user_id' => $userId,
          'bot_id' => $bot['id'],
          'bot_number' => $bot['number'],
          'client_id' => $client['id'],
          'client_number' => $client['number'],
          'sale_id' => null,
          'type' => $type,
          'format' => $formats[array_rand($formats)],
          'message' => $messages[$type][array_rand($messages[$type])],
          'metadata' => null,
          'status' => 1,
          'dc' => date('Y-m-d H:i:s', $timestamp),
          'tc' => $timestamp
        ]);

        $inserted++;
      }
    }

    return [
      'success' => true,
      'generated' => ['chats' => $inserted],
      'clients_processed' => count($clients)
    ];
  }

  // ==========================================
  // GENERAR VENTAS
  // ==========================================

  static function generateSales($params) {
    $num = $params['num'] ?? 50;
    $startDate = $params['start_date'] ?? date('Y-m-01');
    $endDate = $params['end_date'] ?? date('Y-m-t');
    $userId = self::getAdminUserId();

    if (!$userId) {
      return ['success' => false, 'error' => 'No hay usuarios admin'];
    }

    // Obtener clientes existentes
    $clients = ogDb::t('clients')->where('user_id', $userId)->get();
    if (empty($clients)) {
      return ['success' => false, 'error' => 'No hay clientes. Genera clientes primero.'];
    }

    // Obtener bots
    $bots = ogDb::t('bots')->where('user_id', $userId)->where('status', 1)->get();
    if (empty($bots)) {
      return ['success' => false, 'error' => 'No hay bots activos'];
    }

    // Obtener productos
    $products = ogDb::t('products')->where('user_id', $userId)->get();
    if (empty($products)) {
      return ['success' => false, 'error' => 'No hay productos'];
    }

    $distribution = self::distributeDates($num, $startDate, $endDate);
    $inserted = 0;
    $origins = ['organic', 'ad', 'offer'];
    $paymentMethods = ['Recibo de pago', 'hotmart', 'stripe', 'paypal'];
    $sourceApps = ['facebook', 'whatsapp', 'instagram'];

    foreach ($distribution as $date => $count) {
      for ($i = 0; $i < $count; $i++) {
        $client = $clients[array_rand($clients)];
        $bot = $bots[array_rand($bots)];
        $product = $products[array_rand($products)];

        $timestamp = strtotime($date . ' ' . rand(8, 22) . ':' . rand(0, 59) . ':' . rand(0, 59));

        // Estados: 70% initiated, 25% sale_confirmed, 5% cancelled
        $rand = rand(1, 100);
        if ($rand <= 70) {
          $processStatus = 'initiated';
          $paymentDate = null;
          $transactionId = null;
          $paymentMethod = null;
          $billedAmount = null;
        } elseif ($rand <= 95) {
          $processStatus = 'sale_confirmed';
          $paymentDate = date('Y-m-d H:i:s', $timestamp + rand(60, 3600));
          $transactionId = 'RECEIPT_' . $timestamp . '_' . rand(100, 999);
          $paymentMethod = $paymentMethods[array_rand($paymentMethods)];
          $billedAmount = (float)$product['price'] - (rand(0, 50) / 100);
        } else {
          $processStatus = 'cancelled';
          $paymentDate = null;
          $transactionId = null;
          $paymentMethod = null;
          $billedAmount = null;
        }

        // tracking_funnel_id: 40% probabilidad de tener valor (remarketing)
        $trackingFunnelId = self::probability(40) ? 'FUNNEL_' . substr(md5(uniqid()), 0, 10) : null;

        ogDb::t('sales')->insert([
          'user_id' => $userId,
          'sale_type' => 'main',
          'origin' => $origins[array_rand($origins)],
          'context' => 'whatsapp',
          'number' => $client['number'],
          'country_code' => $client['country_code'],
          'product_name' => $product['name'],
          'product_id' => $product['id'],
          'bot_id' => $bot['id'],
          'bot_type' => $bot['type'],
          'bot_mode' => $bot['mode'],
          'client_id' => $client['id'],
          'amount' => (float)$product['price'],
          'billed_amount' => $billedAmount,
          'process_status' => $processStatus,
          'transaction_id' => $transactionId,
          'tracking_funnel_id' => $trackingFunnelId,
          'parent_transaction_id' => null,
          'payment_date' => $paymentDate,
          'payment_method' => $paymentMethod,
          'source_app' => $sourceApps[array_rand($sourceApps)],
          'source_url' => rand(0, 1) ? 'https://fb.me/' . substr(md5(rand()), 0, 10) : null,
          'device' => $client['device'],
          'force_welcome' => 0,
          'parent_sale_id' => 0,
          'is_downsell' => 0,
          'status' => 1,
          'dc' => date('Y-m-d H:i:s', $timestamp),
          'tc' => $timestamp
        ]);

        $inserted++;
      }
    }

    return [
      'success' => true,
      'generated' => ['sales' => $inserted],
      'period' => ['start' => $startDate, 'end' => $endDate]
    ];
  }

  // ==========================================
  // GENERAR CLIENTES
  // ==========================================

  static function generateClients($params) {
    $num = $params['num'] ?? 50;
    $startDate = $params['start_date'] ?? date('Y-m-01');
    $endDate = $params['end_date'] ?? date('Y-m-t');
    $userId = self::getAdminUserId();

    if (!$userId) {
      return ['success' => false, 'error' => 'No hay usuarios admin'];
    }

    $distribution = self::distributeDates($num, $startDate, $endDate);
    $inserted = 0;

    foreach ($distribution as $date => $count) {
      for ($i = 0; $i < $count; $i++) {
        $name = self::$names[array_rand(self::$names)];
        $surname = self::$surnames[array_rand(self::$surnames)];
        $fullName = $name . ' ' . $surname;
        $number = '593' . rand(900000000, 999999999);

        // Verificar si ya existe
        if (ogDb::t('clients')->where('number', $number)->first()) continue;

        $timestamp = strtotime($date . ' ' . rand(8, 22) . ':' . rand(0, 59) . ':' . rand(0, 59));

        ogDb::t('clients')->insert([
          'user_id' => $userId,
          'number' => $number,
          'name' => $fullName,
          'email' => rand(0, 1) ? strtolower(str_replace(' ', '', $name)) . rand(1, 999) . '@gmail.com' : null,
          'device' => self::$devices[array_rand(self::$devices)],
          'country_code' => 'EC',
          'total_purchases' => 0,
          'amount_spent' => 0.00,
          'status' => 1,
          'dc' => date('Y-m-d H:i:s', $timestamp),
          'tc' => $timestamp
        ]);

        $inserted++;
      }
    }

    return [
      'success' => true,
      'generated' => ['clients' => $inserted],
      'period' => ['start' => $startDate, 'end' => $endDate]
    ];
  }

  // ==========================================
  // LIMPIAR (GENERAL)
  // ==========================================

  static function clean($type) {
    $userId = self::getAdminUserId();
    if (!$userId) return ['success' => false, 'error' => 'No hay usuarios admin'];

    $deleted = 0;

    switch ($type) {
      case 'clients':
        $deleted = ogDb::t('clients')->where('user_id', $userId)->delete();
        break;

      case 'chats':
        $deleted = ogDb::t('chats')->where('user_id', $userId)->delete();
        break;

      case 'sales':
        $deleted = ogDb::t('sales')->where('user_id', $userId)->delete();
        break;

      // Aquí se agregarán más casos: followups, etc.

      default:
        return ['success' => false, 'error' => 'Tipo inválido'];
    }

    return [
      'success' => true,
      'deleted' => [$type => $deleted]
    ];
  }

  // Wrapper para mantener compatibilidad
  static function cleanClients() {
    return self::clean('clients');
  }

  static function cleanChats() {
    return self::clean('chats');
  }

  static function cleanSales() {
    return self::clean('sales');
  }
}