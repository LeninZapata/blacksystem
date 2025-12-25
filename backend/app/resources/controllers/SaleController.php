<?php
class SaleController extends controller {

  private static $table = DB_TABLES['sales'];

  function __construct() {
    parent::__construct('sale');
  }

  function create() {
    $data = request::data();

    if (!isset($data['amount']) || empty($data['amount'])) {
      response::json(['success' => false, 'error' => __('sale.amount_required')], 200);
    }

    $data['dc'] = date('Y-m-d H:i:s');
    $data['tc'] = time();
    $data['sale_type'] = $data['sale_type'] ?? 'main';
    $data['context'] = $data['context'] ?? 'whatsapp';
    $data['process_status'] = $data['process_status'] ?? 'initiated';
    $data['force_welcome'] = $data['force_welcome'] ?? 0;
    $data['parent_sale_id'] = $data['parent_sale_id'] ?? 0;
    $data['is_downsell'] = $data['is_downsell'] ?? 0;

    try {
      $id = db::table(self::$table)->insert($data);
      response::success(['id' => $id], __('sale.create.success'), 201);
    } catch (Exception $e) {
      response::serverError(__('sale.create.error'), IS_DEV ? $e->getMessage() : null);
    }
  }

  function update($id) {
    $exists = db::table(self::$table)->find($id);
    if (!$exists) response::notFound(__('sale.not_found'));

    $data = request::data();
    $data['du'] = date('Y-m-d H:i:s');
    $data['tu'] = time();

    try {
      $affected = db::table(self::$table)->where('id', $id)->update($data);
      response::success(['affected' => $affected], __('sale.update.success'));
    } catch (Exception $e) {
      response::serverError(__('sale.update.error'), IS_DEV ? $e->getMessage() : null);
    }
  }

  function show($id) {
    $data = db::table(self::$table)->find($id);
    if (!$data) response::notFound(__('sale.not_found'));
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
    if (!$item) response::notFound(__('sale.not_found'));

    try {
      $affected = db::table(self::$table)->where('id', $id)->delete();
      response::success(['affected' => $affected], __('sale.delete.success'));
    } catch (Exception $e) {
      response::serverError(__('sale.delete.error'), IS_DEV ? $e->getMessage() : null);
    }
  }
}