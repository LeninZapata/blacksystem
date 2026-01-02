<?php
class ChatStatsHandler {
  
  // Rangos de fecha (mismo que ClientStatsHandler)
  private static function getDateRange($range) {
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
        break;
      case 'last_10_days':
        $start->modify('-9 days')->setTime(0, 0, 0);
        break;
      case 'last_15_days':
        $start->modify('-14 days')->setTime(0, 0, 0);
        break;
      case 'this_week':
        $start->modify('monday this week')->setTime(0, 0, 0);
        break;
      case 'this_month':
        $start->modify('first day of this month')->setTime(0, 0, 0);
        $end->modify('last day of this month')->setTime(23, 59, 59);
        break;
      case 'last_30_days':
        $start->modify('-29 days')->setTime(0, 0, 0);
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
      'end' => $end->format('Y-m-d H:i:s')
    ];
  }

  // Chats iniciados vs Mensajes (P y B)
  static function getChatsVsMessages($params) {
    $range = $params['range'] ?? 'last_7_days';
    $dates = self::getDateRange($range);
    
    if (!$dates) {
      return ['success' => false, 'error' => 'Rango inválido'];
    }

    // Query: Chats iniciados por día (contar clientes únicos)
    $sqlChatsInitiated = "
      SELECT 
        DATE(dc) as date,
        COUNT(DISTINCT client_id) as chats_initiated
      FROM " . DB_TABLES['chats'] . "
      WHERE dc >= ? AND dc <= ?
        AND status = 1
      GROUP BY DATE(dc)
      ORDER BY date ASC
    ";

    $chatsInitiated = ogDb::raw($sqlChatsInitiated, [$dates['start'], $dates['end']]);

    // Query: Mensajes entre prospecto y bot (tipo P y B)
    $sqlMessages = "
      SELECT 
        DATE(dc) as date,
        COUNT(*) as total_messages
      FROM " . DB_TABLES['chats'] . "
      WHERE dc >= ? AND dc <= ?
        AND status = 1
        AND type IN ('P', 'B')
      GROUP BY DATE(dc)
      ORDER BY date ASC
    ";

    $messages = ogDb::raw($sqlMessages, [$dates['start'], $dates['end']]);

    // Combinar resultados
    $statsMap = [];
    
    // Inicializar con chats iniciados
    foreach ($chatsInitiated as $row) {
      $statsMap[$row['date']] = [
        'date' => $row['date'],
        'chats_initiated' => (int)$row['chats_initiated'],
        'total_messages' => 0
      ];
    }

    // Agregar mensajes
    foreach ($messages as $row) {
      if (!isset($statsMap[$row['date']])) {
        $statsMap[$row['date']] = [
          'date' => $row['date'],
          'chats_initiated' => 0,
          'total_messages' => 0
        ];
      }
      
      $statsMap[$row['date']]['total_messages'] = (int)$row['total_messages'];
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
}