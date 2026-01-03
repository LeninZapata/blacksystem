<?php
class ClientStatsHandler {
  
  // Rangos de fecha soportados
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

  // Clientes nuevos por día (prospectos con conversión)
  static function getNewClientsByDay($params) {
    $range = $params['range'] ?? 'last_7_days';
    $dates = self::getDateRange($range);
    
    if (!$dates) {
      return ['success' => false, 'error' => 'Rango inválido'];
    }

    // Query: Clientes nuevos por día
    $sqlClients = "
      SELECT 
        DATE(dc) as date,
        COUNT(*) as new_clients
      FROM " . DB_TABLES['clients'] . "
      WHERE dc >= ? AND dc <= ?
        AND status = 1
      GROUP BY DATE(dc)
      ORDER BY date ASC
    ";

    $clientsData = ogDb::raw($sqlClients, [$dates['start'], $dates['end']]);

    // Query: Clientes que hicieron compra confirmada
    $sqlConverted = "
      SELECT 
        DATE(c.dc) as date,
        COUNT(DISTINCT s.client_id) as converted_clients
      FROM " . DB_TABLES['clients'] . " c
      INNER JOIN " . DB_TABLES['sales'] . " s ON c.id = s.client_id
      WHERE c.dc >= ? AND c.dc <= ?
        AND c.status = 1
        AND s.process_status = 'sale_confirmed'
        AND s.status = 1
      GROUP BY DATE(c.dc)
      ORDER BY date ASC
    ";

    $convertedData = ogDb::raw($sqlConverted, [$dates['start'], $dates['end']]);

    // Combinar resultados
    $statsMap = [];
    
    foreach ($clientsData as $row) {
      $statsMap[$row['date']] = [
        'date' => $row['date'],
        'new_clients' => (int)$row['new_clients'],
        'converted_clients' => 0
      ];
    }

    foreach ($convertedData as $row) {
      if (isset($statsMap[$row['date']])) {
        $statsMap[$row['date']]['converted_clients'] = (int)$row['converted_clients'];
      }
    }

    $results = array_values($statsMap);

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