<?php
class ClientController extends controller {
  private static $table = DB_TABLES['clients'];

  function __construct() {
    parent::__construct('client');
  }

  function create() {
    $data = request::data();

    if (!isset($data['number']) || empty($data['number'])) {
      response::json(['success' => false, 'error' => __('client.number_required')], 200);
    }

    if (!isset($data['country_code']) || empty($data['country_code'])) {
      response::json(['success' => false, 'error' => __('client.country_code_required')], 200);
    }

    // Verificar si el número ya existe
    $exists = db::table(self::$table)->where('number', $data['number'])->first();
    if ($exists) {
      response::json(['success' => false, 'error' => __('client.number_exists')], 200);
    }

    $data['dc'] = date('Y-m-d H:i:s');
    $data['tc'] = time();
    $data['status'] = $data['status'] ?? 1;
    $data['total_purchases'] = $data['total_purchases'] ?? 0;
    $data['amount_spent'] = $data['amount_spent'] ?? 0.00;

    try {
      $id = db::table(self::$table)->insert($data);
      response::success(['id' => $id], __('client.create.success'), 201);
    } catch (Exception $e) {
      response::serverError(__('client.create.error'), IS_DEV ? $e->getMessage() : null);
    }
  }

  function update($id) {
    $exists = db::table(self::$table)->find($id);
    if (!$exists) response::notFound(__('client.not_found'));

    $data = request::data();

    // No permitir actualizar número si ya existe en otro cliente
    if (isset($data['number'])) {
      $duplicate = db::table(self::$table)
        ->where('number', $data['number'])
        ->where('id', '!=', $id)
        ->first();

      if ($duplicate) {
        response::json(['success' => false, 'error' => __('client.number_exists')], 200);
      }
    }

    $data['du'] = date('Y-m-d H:i:s');
    $data['tu'] = time();

    try {
      $affected = db::table(self::$table)->where('id', $id)->update($data);
      response::success(['affected' => $affected], __('client.update.success'));
    } catch (Exception $e) {
      response::serverError(__('client.update.error'), IS_DEV ? $e->getMessage() : null);
    }
  }

  function show($id) {
    $data = db::table(self::$table)->find($id);
    if (!$data) response::notFound(__('client.not_found'));
    response::success($data);
  }

  function list() {
    $query = db::table(self::$table);

    // Filtros
    foreach ($_GET as $key => $value) {
      if (in_array($key, ['page', 'per_page', 'sort', 'order'])) continue;
      $query = $query->where($key, $value);
    }

    // Ordenamiento
    $sort = request::query('sort', 'id');
    $order = request::query('order', 'DESC');
    $query = $query->orderBy($sort, $order);

    // Paginación
    $page = request::query('page', 1);
    $perPage = request::query('per_page', 50);
    $data = $query->paginate($page, $perPage)->get();

    if (!is_array($data)) $data = [];

    response::success($data);
  }

  function delete($id) {
    $item = db::table(self::$table)->find($id);
    if (!$item) response::notFound(__('client.not_found'));

    try {
      $affected = db::table(self::$table)->where('id', $id)->delete();
      response::success(['affected' => $affected], __('client.delete.success'));
    } catch (Exception $e) {
      response::serverError(__('client.delete.error'), IS_DEV ? $e->getMessage() : null);
    }
  }
}