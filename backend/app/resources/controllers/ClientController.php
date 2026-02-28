<?php
class ClientController extends ogController {

  function __construct() {
    parent::__construct('client');
  }

  function create() {
    $data = ogRequest::data();

    if (!isset($data['number']) || empty($data['number'])) {
      ogResponse::json(['success' => false, 'error' => __('client.number_required')], 200);
    }

    if (!isset($data['country_code']) || empty($data['country_code'])) {
      ogResponse::json(['success' => false, 'error' => __('client.country_code_required')], 200);
    }

    // Verificar si el número ya existe
    $exists = ogDb::t('clients')->where('number', $data['number'])->first();
    if ($exists) {
      ogResponse::json(['success' => false, 'error' => __('client.number_exists')], 200);
    }

    $data['dc'] = date('Y-m-d H:i:s');
    $data['tc'] = time();
    $data['status'] = $data['status'] ?? 1;
    $data['total_purchases'] = $data['total_purchases'] ?? 0;
    $data['amount_spent'] = $data['amount_spent'] ?? 0.00;

    try {
      $id = ogDb::t('clients')->insert($data);
      ogResponse::success(['id' => $id], __('client.create.success'), 201);
    } catch (Exception $e) {
      ogResponse::serverError(__('client.create.error'), OG_IS_DEV ? $e->getMessage() : null);
    }
  }

  function update($id) {
    $exists = ogDb::t('clients')->find($id);
    if (!$exists) ogResponse::notFound(__('client.not_found'));

    $data = ogRequest::data();

    // No permitir actualizar número si ya existe en otro cliente
    if (isset($data['number'])) {
      $duplicate = ogDb::t('clients')
        ->where('number', $data['number'])
        ->where('id', '!=', $id)
        ->first();

      if ($duplicate) {
        ogResponse::json(['success' => false, 'error' => __('client.number_exists')], 200);
      }
    }

    $data['du'] = date('Y-m-d H:i:s');
    $data['tu'] = time();

    try {
      $affected = ogDb::t('clients')->where('id', $id)->update($data);
      ogResponse::success(['affected' => $affected], __('client.update.success'));
    } catch (Exception $e) {
      ogResponse::serverError(__('client.update.error'), OG_IS_DEV ? $e->getMessage() : null);
    }
  }

  function show($id) {
    $data = ogDb::t('clients')->find($id);
    if (!$data) ogResponse::notFound(__('client.not_found'));
    ogResponse::success($data);
  }

  function list() {
    $query = ogDb::t('clients');

    // Filtrar por user_id autenticado
    if (isset($GLOBALS['auth_user_id'])) {
      $query = $query->where('user_id', $GLOBALS['auth_user_id']);
    }/* else {
      ogResponse::json(['success' => false, 'error' => __('auth.unauthorized')], 401);
    }*/

    // Filtros
    foreach ($_GET as $key => $value) {
      if (in_array($key, ['page', 'per_page', 'sort', 'order'])) continue;
      $query = $query->where($key, $value);
    }

    // Ordenamiento
    $sort = ogRequest::query('sort', 'last_message_at');
    $order = ogRequest::query('order', 'DESC');
    $query = $query->orderBy($sort, $order);

    // Paginación
    $page = ogRequest::query('page', 1);
    $perPage = ogRequest::query('per_page', 50);
    $total = (clone $query)->count();
    $data = $query->paginate($page, $perPage)->get();

    if (!is_array($data)) $data = [];

    // Obtener último product_name de sales para cada cliente (1 sola query)
    if (!empty($data)) {
      $ids = array_column($data, 'id');
      $placeholders = implode(',', array_fill(0, count($ids), '?'));
      $lastProducts = ogDb::raw(
        "SELECT s.client_id, s.product_name
         FROM sales s
         INNER JOIN (
           SELECT client_id, MAX(id) as max_id
           FROM sales
           WHERE client_id IN ($placeholders)
           GROUP BY client_id
         ) sub ON s.id = sub.max_id",
        $ids
      );
      $productMap = [];
      foreach ($lastProducts as $row) {
        $productMap[$row['client_id']] = $row['product_name'];
      }
      foreach ($data as &$client) {
        $client['last_product_name'] = $productMap[$client['id']] ?? null;
      }
      unset($client);
    }

    ogResponse::success([
      'data'     => $data,
      'total'    => $total,
      'page'     => (int)$page,
      'per_page' => (int)$perPage
    ]);
  }

  // Lista de clientes para el panel de chat, con last_message_at/unread_count desde client_bot_meta
  function listChat() {
    $userId  = $GLOBALS['auth_user_id'] ?? null;
    $botId   = ogRequest::query('bot_id', null);   // opcional: filtra por bot
    $page    = (int)ogRequest::query('page', 1);
    $perPage = (int)ogRequest::query('per_page', 50);
    $offset  = ($page - 1) * $perPage;

    if ($botId) {
      // Filtro por bot específico
      $data = ogDb::raw(
        "SELECT c.*,
           cbm_lm.bot_id          AS chat_bot_id,
           cbm_lm.meta_value      AS last_message_at,
           cbm_lc.meta_value      AS last_client_message_at,
           COALESCE(CAST(cbm_u.meta_value AS UNSIGNED), 0) AS unread_count
         FROM clients c
         INNER JOIN client_bot_meta cbm_lm
           ON cbm_lm.client_id = c.id AND cbm_lm.meta_key = 'last_message_at' AND cbm_lm.bot_id = ?
         LEFT JOIN client_bot_meta cbm_lc
           ON cbm_lc.client_id = c.id AND cbm_lc.bot_id = cbm_lm.bot_id AND cbm_lc.meta_key = 'last_client_message_at'
         LEFT JOIN client_bot_meta cbm_u
           ON cbm_u.client_id  = c.id AND cbm_u.bot_id  = cbm_lm.bot_id AND cbm_u.meta_key  = 'unread_count'
         ORDER BY cbm_lm.meta_value DESC
         LIMIT ? OFFSET ?",
        [(int)$botId, $perPage, $offset]
      );

      $totalRow = ogDb::raw(
        "SELECT COUNT(*) as cnt FROM clients c
         INNER JOIN client_bot_meta cbm ON cbm.client_id = c.id AND cbm.meta_key = 'last_message_at' AND cbm.bot_id = ?",
        [(int)$botId]
      );
    } else {
      // Todos los bots del usuario — bots se une primero para poder filtrar por user_id
      $data = ogDb::raw(
        "SELECT c.*,
           cbm_lm.bot_id          AS chat_bot_id,
           cbm_lm.meta_value      AS last_message_at,
           cbm_lc.meta_value      AS last_client_message_at,
           COALESCE(CAST(cbm_u.meta_value AS UNSIGNED), 0) AS unread_count
         FROM clients c
         INNER JOIN client_bot_meta cbm_lm ON cbm_lm.client_id = c.id AND cbm_lm.meta_key = 'last_message_at'
         INNER JOIN bots b ON b.id = cbm_lm.bot_id AND b.user_id = ?
         LEFT JOIN client_bot_meta cbm_lc
           ON cbm_lc.client_id = c.id AND cbm_lc.bot_id = cbm_lm.bot_id AND cbm_lc.meta_key = 'last_client_message_at'
         LEFT JOIN client_bot_meta cbm_u
           ON cbm_u.client_id  = c.id AND cbm_u.bot_id  = cbm_lm.bot_id AND cbm_u.meta_key  = 'unread_count'
         ORDER BY cbm_lm.meta_value DESC
         LIMIT ? OFFSET ?",
        [(int)$userId, $perPage, $offset]
      );

      $totalRow = ogDb::raw(
        "SELECT COUNT(*) as cnt FROM clients c
         INNER JOIN client_bot_meta cbm ON cbm.client_id = c.id AND cbm.meta_key = 'last_message_at'
         INNER JOIN bots b ON b.id = cbm.bot_id AND b.user_id = ?",
        [(int)$userId]
      );
    }
    $total = $totalRow[0]['cnt'] ?? 0;

    if (!is_array($data)) $data = [];

    // Último producto por cliente+bot
    if (!empty($data)) {
      $ids          = array_column($data, 'id');
      $placeholders = implode(',', array_fill(0, count($ids), '?'));
      $lastProducts = ogDb::raw(
        "SELECT s.client_id, s.bot_id, s.product_name
         FROM sales s
         INNER JOIN (
           SELECT client_id, bot_id, MAX(id) as max_id
           FROM sales
           WHERE client_id IN ($placeholders) AND status = 1
           GROUP BY client_id, bot_id
         ) sub ON s.id = sub.max_id",
        $ids
      );
      $productMap = [];
      foreach ($lastProducts as $row) $productMap[$row['client_id'] . '_' . $row['bot_id']] = $row['product_name'];
      foreach ($data as &$client) {
        $client['last_product_name'] = $productMap[$client['id'] . '_' . $client['chat_bot_id']] ?? null;
      }
      unset($client);
    }

    ogResponse::success([
      'data'     => $data,
      'total'    => (int)$total,
      'page'     => $page,
      'per_page' => $perPage
    ]);
  }

  function delete($id) {
    $client = ogDb::t('clients')->find($id);
    if (!$client) ogResponse::notFound(__('client.not_found'));

    try {
      $affected = ogDb::t('clients')->where('id', $id)->delete();
      ogResponse::success(['affected' => $affected], __('client.delete.success'));
    } catch (Exception $e) {
      ogResponse::serverError(__('client.delete.error'), OG_IS_DEV ? $e->getMessage() : null);
    }
  }
}