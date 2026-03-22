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
    $userId        = $GLOBALS['auth_user_id'] ?? null;
    $botId         = ogRequest::query('bot_id', null);
    $productId     = ogRequest::query('product_id', null);
    $numberSearch  = trim(ogRequest::query('number_search', ''));
    $confirmedOnly = (int)ogRequest::query('confirmed_only', 0);
    $todayOnly     = (int)ogRequest::query('today_only', 0);
    $page          = (int)ogRequest::query('page', 1);
    $perPage       = (int)ogRequest::query('per_page', 50);
    $offset        = ($page - 1) * $perPage;

    // Timezone del usuario (necesaria para filtro hoy y para ventas confirmadas)
    $userTz     = ogApp()->helper('date')::getUserTimezone();
    $todayLocal = (new DateTime('now', new DateTimeZone($userTz)))->format('Y-m-d');
    $offsetSec  = (new DateTimeZone($userTz))->getOffset(new DateTime('now', new DateTimeZone('UTC')));

    // Construir cláusula WHERE combinando todos los filtros de fila
    $whereParts      = [];
    $wherePartsCount = [];
    $todayParams     = [];

    if ($todayOnly) {
      // Filtrar por payment_date de ventas confirmadas en zona local del usuario.
      // No usamos last_message_at porque los followups automáticos la actualizan
      // y harían aparecer clientes con ventas de días anteriores.
      // No usamos sales.dc porque ese es cuando llegó el prospecto, no cuando pagó.
      $whereParts[]      = "EXISTS (SELECT 1 FROM sales s_td WHERE s_td.client_id = c.id AND s_td.bot_id = cbm_lm.bot_id AND s_td.status = 1 AND s_td.process_status = 'sale_confirmed' AND s_td.payment_date IS NOT NULL AND DATE(DATE_ADD(s_td.payment_date, INTERVAL {$offsetSec} SECOND)) = ?)";
      $wherePartsCount[] = "EXISTS (SELECT 1 FROM sales s_td WHERE s_td.client_id = c.id AND s_td.bot_id = cbm.bot_id  AND s_td.status = 1 AND s_td.process_status = 'sale_confirmed' AND s_td.payment_date IS NOT NULL AND DATE(DATE_ADD(s_td.payment_date, INTERVAL {$offsetSec} SECOND)) = ?)";
      $todayParams       = [$todayLocal];
    }

    if ($numberSearch !== '') {
      $whereParts[]      = "c.number LIKE ?";
      $wherePartsCount[] = "c.number LIKE ?";
      $todayParams[]     = "%{$numberSearch}%";
    }

    $todayWhere      = !empty($whereParts)      ? "WHERE " . implode(" AND ", $whereParts)      : '';
    $todayWhereCount = !empty($wherePartsCount) ? "WHERE " . implode(" AND ", $wherePartsCount) : '';

    // Cláusula extra para filtro "solo producto"
    $productJoin      = $productId ? "INNER JOIN sales sp ON sp.client_id = c.id AND sp.bot_id = cbm_lm.bot_id AND sp.product_id = ? AND sp.status = 1" : '';
    $productJoinCount = $productId ? "INNER JOIN sales sp ON sp.client_id = c.id AND sp.bot_id = cbm.bot_id    AND sp.product_id = ? AND sp.status = 1" : '';
    $productParam     = $productId ? [(int)$productId] : [];

    // Cláusula extra para filtro "solo ventas confirmadas"
    $confirmedJoin  = $confirmedOnly ? "INNER JOIN sales sc ON sc.client_id = c.id AND sc.status = 1 AND sc.process_status = 'sale_confirmed'" : '';
    $confirmedGroup = ($confirmedOnly || $productId) ? "GROUP BY c.id, cbm_lm.bot_id, cbm_lm.meta_value, cbm_u.meta_value" : '';

    if ($botId) {
      // Filtro por bot específico
      $data = ogDb::raw(
        "SELECT c.*,
           cbm_lm.bot_id          AS chat_bot_id,
           cbm_lm.meta_value      AS last_message_at,
           COALESCE(CAST(cbm_u.meta_value AS UNSIGNED), 0) AS unread_count,
           (SELECT ch.type FROM chats ch
            WHERE ch.client_id = c.id AND ch.bot_id = cbm_lm.bot_id AND ch.status = 1
            ORDER BY ch.tc DESC, ch.id DESC LIMIT 1) AS last_msg_type,
           (SELECT ch.format FROM chats ch
            WHERE ch.client_id = c.id AND ch.bot_id = cbm_lm.bot_id AND ch.type = 'P' AND ch.status = 1
            ORDER BY ch.tc DESC, ch.id DESC LIMIT 1) AS last_client_message_format,
           (SELECT JSON_UNQUOTE(JSON_EXTRACT(ch.metadata, '$.action')) FROM chats ch
            WHERE ch.client_id = c.id AND ch.bot_id = cbm_lm.bot_id AND ch.type = 'S' AND ch.status = 1
            ORDER BY ch.tc DESC, ch.id DESC LIMIT 1) AS last_sys_msg_action
         FROM clients c
         INNER JOIN client_bot_meta cbm_lm
           ON cbm_lm.client_id = c.id AND cbm_lm.meta_key = 'last_message_at' AND cbm_lm.bot_id = ?
         LEFT JOIN client_bot_meta cbm_u
           ON cbm_u.client_id  = c.id AND cbm_u.bot_id  = cbm_lm.bot_id AND cbm_u.meta_key  = 'unread_count'
         {$productJoin}
         {$confirmedJoin}
         {$todayWhere}
         {$confirmedGroup}
         ORDER BY cbm_lm.meta_value DESC
         LIMIT ? OFFSET ?",
        array_merge([(int)$botId], $productParam, $todayParams, [$perPage, $offset])
      );

      $totalRow = ogDb::raw(
        "SELECT COUNT(DISTINCT c.id) as cnt FROM clients c
         INNER JOIN client_bot_meta cbm ON cbm.client_id = c.id AND cbm.meta_key = 'last_message_at' AND cbm.bot_id = ?
         {$productJoinCount}
         {$confirmedJoin}
         {$todayWhereCount}",
        array_merge([(int)$botId], $productParam, $todayParams)
      );
    } else {
      // Todos los bots del usuario — bots se une primero para poder filtrar por user_id
      $data = ogDb::raw(
        "SELECT c.*,
           cbm_lm.bot_id          AS chat_bot_id,
           cbm_lm.meta_value      AS last_message_at,
           COALESCE(CAST(cbm_u.meta_value AS UNSIGNED), 0) AS unread_count,
           (SELECT ch.type FROM chats ch
            WHERE ch.client_id = c.id AND ch.bot_id = cbm_lm.bot_id AND ch.status = 1
            ORDER BY ch.tc DESC, ch.id DESC LIMIT 1) AS last_msg_type,
           (SELECT ch.format FROM chats ch
            WHERE ch.client_id = c.id AND ch.bot_id = cbm_lm.bot_id AND ch.type = 'P' AND ch.status = 1
            ORDER BY ch.tc DESC, ch.id DESC LIMIT 1) AS last_client_message_format,
           (SELECT JSON_UNQUOTE(JSON_EXTRACT(ch.metadata, '$.action')) FROM chats ch
            WHERE ch.client_id = c.id AND ch.bot_id = cbm_lm.bot_id AND ch.type = 'S' AND ch.status = 1
            ORDER BY ch.tc DESC, ch.id DESC LIMIT 1) AS last_sys_msg_action
         FROM clients c
         INNER JOIN client_bot_meta cbm_lm ON cbm_lm.client_id = c.id AND cbm_lm.meta_key = 'last_message_at'
         INNER JOIN bots b ON b.id = cbm_lm.bot_id AND b.user_id = ?
         LEFT JOIN client_bot_meta cbm_u
           ON cbm_u.client_id  = c.id AND cbm_u.bot_id  = cbm_lm.bot_id AND cbm_u.meta_key  = 'unread_count'
         {$productJoin}
         {$confirmedJoin}
         {$todayWhere}
         {$confirmedGroup}
         ORDER BY cbm_lm.meta_value DESC
         LIMIT ? OFFSET ?",
        array_merge([(int)$userId], $productParam, $todayParams, [$perPage, $offset])
      );

      $totalRow = ogDb::raw(
        "SELECT COUNT(DISTINCT c.id) as cnt FROM clients c
         INNER JOIN client_bot_meta cbm ON cbm.client_id = c.id AND cbm.meta_key = 'last_message_at'
         INNER JOIN bots b ON b.id = cbm.bot_id AND b.user_id = ?
         {$productJoinCount}
         {$confirmedJoin}
         {$todayWhereCount}",
        array_merge([(int)$userId], $productParam, $todayParams)
      );
    }
    $total = $totalRow[0]['cnt'] ?? 0;

    if (!is_array($data)) $data = [];

    if (!empty($data)) {
      // Todas las ventas activas por cliente+bot (para mostrar estado individual)
      $ids          = array_column($data, 'id');
      $placeholders = implode(',', array_fill(0, count($ids), '?'));
      $allSales = ogDb::raw(
        "SELECT s.client_id, s.bot_id, s.product_name, s.process_status,
                COALESCE(s.billed_amount, s.amount) AS final_amount
         FROM sales s
         WHERE s.client_id IN ($placeholders) AND s.status = 1
         ORDER BY s.client_id, s.bot_id, s.id ASC",
        $ids
      );
      $salesMap = [];
      foreach ($allSales as $row) {
        $key = $row['client_id'] . '_' . $row['bot_id'];
        $salesMap[$key][] = [
          'product_name'   => $row['product_name'],
          'process_status' => $row['process_status'],
          'amount'         => $row['process_status'] === 'sale_confirmed' ? (float)$row['final_amount'] : null
        ];
      }
      foreach ($data as &$client) {
        $client['sales'] = $salesMap[$client['id'] . '_' . $client['chat_bot_id']] ?? [];
      }
      unset($client);

      // Ventas confirmadas por cliente+bot, separadas en: hoy (local) vs anteriores
      $confirmedSales = ogDb::raw(
        "SELECT client_id, bot_id,
           SUM(CASE WHEN DATE(DATE_ADD(payment_date, INTERVAL {$offsetSec} SECOND)) = ?
                    THEN COALESCE(billed_amount, amount) ELSE 0 END) AS today_amount,
           SUM(CASE WHEN DATE(DATE_ADD(payment_date, INTERVAL {$offsetSec} SECOND)) < ?
                    THEN COALESCE(billed_amount, amount) ELSE 0 END) AS prev_amount
         FROM sales
         WHERE client_id IN ($placeholders) AND status = 1 AND process_status = 'sale_confirmed'
         GROUP BY client_id, bot_id",
        array_merge([$todayLocal, $todayLocal], $ids)
      );
      $confirmedMap = [];
      foreach ($confirmedSales as $row) {
        $key = $row['client_id'] . '_' . $row['bot_id'];
        $confirmedMap[$key] = [
          'today_amount' => $row['today_amount'] > 0 ? (float)$row['today_amount'] : null,
          'prev_amount'  => $row['prev_amount']  > 0 ? (float)$row['prev_amount']  : null,
        ];
      }
      foreach ($data as &$client) {
        $entry = $confirmedMap[$client['id'] . '_' . $client['chat_bot_id']] ?? null;
        $client['today_amount'] = $entry['today_amount'] ?? null;
        $client['prev_amount']  = $entry['prev_amount']  ?? null;
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