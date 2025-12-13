<?php
// clientHandlers - Handlers personalizados para client
class clientHandlers {

  // Eliminar todos los datos del cliente
  static function deleteAllData($params) {
    $id = $params['id'];

    // Eliminar en cascada
    $sales = db::table('sale')->where('client_id', $id)->delete();
    $chats = db::table('chat')->where('client_id', $id)->delete();
    db::table('client')->where('id', $id)->delete();

    return [
      'success' => true,
      'message' => __('client.delete_all.success'),
      'deleted' => [
        'client' => 1,
        'sales' => $sales,
        'chats' => $chats
      ]
    ];
  }

  // Buscar por número
  static function getByNumber($params) {
    $number = $params['number'];

    $client = db::table('client')
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

    $clients = db::table('client')
      ->select(['id', 'name', 'number', 'amount_spent', 'total_purchases'])
      ->orderBy('amount_spent', 'DESC')
      ->limit($limit)
      ->get();

    return ['success' => true, 'data' => $clients];
  }
}