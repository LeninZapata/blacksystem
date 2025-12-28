<?php
class WorkFlowController extends ogController {
  // Nombre de la tabla asociada a este controlador
  protected static $table = DB_TABLES['work_flows'];

  function __construct() {
    parent::__construct('workFlow');
  }

  function create() {
    $data = ogRequest::data();

    if (!isset($data['name']) || empty($data['name'])) {
      ogResponse::json(['success' => false, 'error' => __('workFlow.name_required')], 200);
    }

    if (!isset($data['user_id']) || empty($data['user_id'])) {
      ogResponse::json(['success' => false, 'error' => __('workFlow.user_id_required')], 200);
    }

    $data['dc'] = date('Y-m-d H:i:s');
    $data['ta'] = time();

    try {
      $id = ogDb::table(self::$table)->insert($data);
      ogResponse::success(['id' => $id], __('workFlow.create.success'), 201);
    } catch (Exception $e) {
      ogResponse::serverError(__('workFlow.create.error'), OG_IS_DEV ? $e->getMessage() : null);
    }
  }

  function update($id) {
    $exists = ogDb::table(self::$table)->find($id);
    if (!$exists) ogResponse::notFound(__('workFlow.not_found'));

    $data = ogRequest::data();

    $data['da'] = date('Y-m-d H:i:s');
    $data['tu'] = time();

    try {
      $affected = ogDb::table(self::$table)->where('id', $id)->update($data);

      // Actualizar archivos JSON de todos los bots que usan este workflow
      workflowHandlers::updateBotsContext($id);

      ogResponse::success(['affected' => $affected], __('workFlow.update.success'));
    } catch (Exception $e) {
      ogResponse::serverError(__('workFlow.update.error'), OG_IS_DEV ? $e->getMessage() : null);
    }
  }

  function show($id) {
    $data = ogDb::table(self::$table)->find($id);
    if (!$data) ogResponse::notFound(__('workFlow.not_found'));

    ogResponse::success($data);
  }

  function list() {
    $query = ogDb::table(self::$table);

    foreach ($_GET as $key => $value) {
      if (in_array($key, ['page', 'per_page', 'sort', 'order'])) continue;
      $query = $query->where($key, $value);
    }

    $sort = ogRequest::query('sort', 'id');
    $order = ogRequest::query('order', 'DESC');
    $query = $query->orderBy($sort, $order);

    $page = ogRequest::query('page', 1);
    $perPage = ogRequest::query('per_page', 50);
    $data = $query->paginate($page, $perPage)->get();

    if (!is_array($data)) $data = [];

    ogResponse::success($data);
  }

  function delete($id) {
    $item = ogDb::table(self::$table)->find($id);
    if (!$item) ogResponse::notFound(__('workFlow.not_found'));

    try {
      $affected = ogDb::table(self::$table)->where('id', $id)->delete();
      ogResponse::success(['affected' => $affected], __('workFlow.delete.success'));
    } catch (Exception $e) {
      ogResponse::serverError(__('workFlow.delete.error'), OG_IS_DEV ? $e->getMessage() : null);
    }
  }
}
