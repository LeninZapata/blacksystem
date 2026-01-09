<?php
class ClientStatsHandler {


  // Clientes nuevos por día (prospectos con conversión)
  static function getNewClientsByDay($params) {
    // Obtener user_id autenticado
    if (!isset($GLOBALS['auth_user_id'])) {
      return ['success' => false, 'error' => __('auth.unauthorized')];
    }
    $userId = $GLOBALS['auth_user_id'];

    $range = $params['range'] ?? 'last_7_days';
    $dates = ogApp()->helper('date')::getDateRange($range);

    if (!$dates) {
      return ['success' => false, 'error' => 'Rango inválido'];
    }

    // Query: Clientes nuevos por día
    $sqlClients = "
      SELECT
        DATE(dc) as date,
        COUNT(*) as new_clients
      FROM " . DB_TABLES['clients'] . "
      WHERE user_id = ?
        AND dc >= ? AND dc <= ?
        AND status = 1
      GROUP BY DATE(dc)
      ORDER BY date ASC
    ";

    $clientsData = ogDb::raw($sqlClients, [$userId, $dates['start'], $dates['end'] . ' 23:59:59' ]);

    // Query: Clientes que hicieron compra confirmada
    $sqlConverted = "
      SELECT
        DATE(c.dc) as date,
        COUNT(DISTINCT s.client_id) as converted_clients
      FROM " . DB_TABLES['clients'] . " c
      INNER JOIN " . DB_TABLES['sales'] . " s ON c.id = s.client_id
      WHERE c.user_id = ?
        AND c.dc >= ? AND c.dc <= ?
        AND c.status = 1
        AND s.process_status = 'sale_confirmed'
        AND s.status = 1
      GROUP BY DATE(c.dc)
      ORDER BY date ASC
    ";

    $convertedData = ogDb::raw($sqlConverted, [$userId, $dates['start'], $dates['end'] . ' 23:59:59' ]);

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