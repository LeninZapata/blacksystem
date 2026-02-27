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
    $sort = ogRequest::query('sort', 'id');
    $order = ogRequest::query('order', 'DESC');
    $query = $query->orderBy($sort, $order);

    // Paginación
    $page = ogRequest::query('page', 1);
    $perPage = ogRequest::query('per_page', 50);
    $total = (clone $query)->count();
    $data = $query->paginate($page, $perPage)->get();

    if (!is_array($data)) $data = [];

    ogResponse::success([
      'data'     => $data,
      'total'    => $total,
      'page'     => (int)$page,
      'per_page' => (int)$perPage
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