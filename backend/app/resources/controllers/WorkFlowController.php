<?php
class WorkFlowController extends controller {
  // Nombre de la tabla asociada a este controlador
  protected static $table = DB_TABLES['work_flows'];

  function __construct() {
    parent::__construct('workFlow');
  }

  function create() {
    $data = request::data();

    if (!isset($data['name']) || empty($data['name'])) {
      response::json(['success' => false, 'error' => __('workFlow.name_required')], 200);
    }

    if (!isset($data['user_id']) || empty($data['user_id'])) {
      response::json(['success' => false, 'error' => __('workFlow.user_id_required')], 200);
    }

    $data['dc'] = date('Y-m-d H:i:s');
    $data['ta'] = time();

    try {
      $id = db::table(self::$table)->insert($data);
      response::success(['id' => $id], __('workFlow.create.success'), 201);
    } catch (Exception $e) {
      response::serverError(__('workFlow.create.error'), IS_DEV ? $e->getMessage() : null);
    }
  }

  function update($id) {
    $exists = db::table(self::$table)->find($id);
    if (!$exists) response::notFound(__('workFlow.not_found'));

    $data = request::data();

    $data['da'] = date('Y-m-d H:i:s');
    $data['tu'] = time();

    try {
      $affected = db::table(self::$table)->where('id', $id)->update($data);

      // Actualizar archivos JSON de todos los bots que usan este workflow
      workflowHandlers::updateBotsContext($id);

      response::success(['affected' => $affected], __('workFlow.update.success'));
    } catch (Exception $e) {
      response::serverError(__('workFlow.update.error'), IS_DEV ? $e->getMessage() : null);
    }
  }

  function show($id) {
    $data = db::table(self::$table)->find($id);
    if (!$data) response::notFound(__('workFlow.not_found'));

    response::success($data);
  }

  function list() {
    $query = db::table(self::$table);

    foreach ($_GET as $key => $value) {
      if (in_array($key, ['page', 'per_page', 'sort', 'order'])) continue;
      $query = $query->where($key, $value);
    }

    $sort = request::query('sort', 'id');
    $order = request::query('order', 'DESC');
    $query = $query->orderBy($sort, $order);

    $page = request::query('page', 1);
    $perPage = request::query('per_page', 50);
    $data = $query->paginate($page, $perPage)->get();

    if (!is_array($data)) $data = [];

    response::success($data);
  }

  function delete($id) {
    $item = db::table(self::$table)->find($id);
    if (!$item) response::notFound(__('workFlow.not_found'));

    try {
      $affected = db::table(self::$table)->where('id', $id)->delete();
      response::success(['affected' => $affected], __('workFlow.delete.success'));
    } catch (Exception $e) {
      response::serverError(__('workFlow.delete.error'), IS_DEV ? $e->getMessage() : null);
    }
  }
}
