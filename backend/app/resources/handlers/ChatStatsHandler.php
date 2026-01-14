<?php
class ChatStatsHandler {
  
  // Actividad de mensajes (total, chats nuevos, seguimientos)
  static function getMessagesActivity($params) {
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

    // Query: Chats nuevos por día (clientes únicos)
    $sqlChats = "
      SELECT 
        DATE(dc) as date,
        COUNT(DISTINCT client_id) as new_chats
      FROM " . ogDb::t('chats', true) . "
      WHERE user_id = ?
        AND dc >= ? AND dc <= ?
        AND status = 1
      GROUP BY DATE(dc)
      ORDER BY date ASC
    ";

    $chatsData = ogDb::raw($sqlChats, [$userId, $dates['start'], $dates['end'] . ' 23:59:59' ]);

    // Query: Seguimientos programados por día
    $sqlFollowups = "
      SELECT 
        DATE(future_date) as date,
        COUNT(*) as followups_scheduled
      FROM " . ogDb::t('followups', true) . "
      WHERE user_id = ?
        AND future_date >= ? AND future_date <= ?
        AND status = 1
      GROUP BY DATE(future_date)
      ORDER BY date ASC
    ";

    $followupsData = ogDb::raw($sqlFollowups, [$userId, $dates['start'], $dates['end'] . ' 23:59:59' ]);

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