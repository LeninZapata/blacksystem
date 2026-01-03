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

  // Actividad de mensajes (total, chats nuevos, seguimientos)
  static function getMessagesActivity($params) {
    $range = $params['range'] ?? 'last_7_days';
    $dates = self::getDateRange($range);
    
    if (!$dates) {
      return ['success' => false, 'error' => 'Rango inválido'];
    }

    // Query: Chats nuevos por día (clientes únicos)
    $sqlChats = "
      SELECT 
        DATE(dc) as date,
        COUNT(DISTINCT client_id) as new_chats
      FROM " . DB_TABLES['chats'] . "
      WHERE dc >= ? AND dc <= ?
        AND status = 1
      GROUP BY DATE(dc)
      ORDER BY date ASC
    ";

    $chatsData = ogDb::raw($sqlChats, [$dates['start'], $dates['end']]);

    // Query: Seguimientos programados por día
    $sqlFollowups = "
      SELECT 
        DATE(future_date) as date,
        COUNT(*) as followups_scheduled
      FROM " . DB_TABLES['followups'] . "
      WHERE future_date >= ? AND future_date <= ?
        AND status = 1
      GROUP BY DATE(future_date)
      ORDER BY date ASC
    ";

    $followupsData = ogDb::raw($sqlFollowups, [$dates['start'], $dates['end']]);

    // Combinar resultados
    $statsMap = [];
    
    // Inicializar con chats
    foreach ($chatsData as $row) {
      $statsMap[$row['date']] = [
        'date' => $row['date'],
        'new_chats' => (int)$row['new_chats'],
        'followups_scheduled' => 0
      ];
    }

    // Agregar seguimientos
    foreach ($followupsData as $row) {
      if (!isset($statsMap[$row['date']])) {
        $statsMap[$row['date']] = [
          'date' => $row['date'],
          'new_chats' => 0,
          'followups_scheduled' => 0
        ];
      }
      
      $statsMap[$row['date']]['followups_scheduled'] = (int)$row['followups_scheduled'];
    }

    // Calcular total de mensajes
    $results = array_map(function($row) {
      $row['total_messages'] = $row['new_chats'] + $row['followups_scheduled'];
      return $row;
    }, array_values($statsMap));

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