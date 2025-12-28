<?php
class SaleController extends ogController {

  private static $table = DB_TABLES['sales'];

  function __construct() {
    parent::__construct('sale');
  }

  function create() {
    $data = ogRequest::data();

    if (!isset($data['amount']) || empty($data['amount'])) {
      ogResponse::json(['success' => false, 'error' => __('sale.amount_required')], 200);
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
      $id = ogDb::table(self::$table)->insert($data);
      ogResponse::success(['id' => $id], __('sale.create.success'), 201);
    } catch (Exception $e) {
      ogResponse::serverError(__('sale.create.error'), OG_IS_DEV ? $e->getMessage() : null);
    }
  }

  function update($id) {
    $exists = ogDb::table(self::$table)->find($id);
    if (!$exists) ogResponse::notFound(__('sale.not_found'));

    $data = ogRequest::data();
    $data['du'] = date('Y-m-d H:i:s');
    $data['tu'] = time();

    try {
      $affected = ogDb::table(self::$table)->where('id', $id)->update($data);
      ogResponse::success(['affected' => $affected], __('sale.update.success'));
    } catch (Exception $e) {
      ogResponse::serverError(__('sale.update.error'), OG_IS_DEV ? $e->getMessage() : null);
    }
  }

  function show($id) {
    $data = ogDb::table(self::$table)->find($id);
    if (!$data) ogResponse::notFound(__('sale.not_found'));
    ogResponse::success($data);
  }

  function list() {
    $query = ogDb::table(self::$table);

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
    $data = $query->paginate($page, $perPage)->get();

    if (!is_array($data)) $data = [];

    ogResponse::success($data);
  }

  function delete($id) {
    $item = ogDb::table(self::$table)->find($id);
    if (!$item) ogResponse::notFound(__('sale.not_found'));

    try {
      $affected = ogDb::table(self::$table)->where('id', $id)->delete();
      ogResponse::success(['affected' => $affected], __('sale.delete.success'));
    } catch (Exception $e) {
      ogResponse::serverError(__('sale.delete.error'), OG_IS_DEV ? $e->getMessage() : null);
    }
  }
}