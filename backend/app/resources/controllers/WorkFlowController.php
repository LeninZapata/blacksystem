<?php
class WorkFlowController extends ogController {

  function __construct() {
    parent::__construct('workFlow');
  }

  function create() {
    $data = ogRequest::data();

    if (isset($GLOBALS['auth_user_id'])) {
      $data['user_id'] = $GLOBALS['auth_user_id'];
    } else {
      ogResponse::json(['success' => false, 'error' => __('auth.unauthorized')], 401);
    }

    if (!isset($data['name']) || empty($data['name'])) {
      //ogResponse::json(['success' => false, 'error' => __('workFlow.name_required')], 400);
      ogResponse::error(__('workFlow.name_required'), 400);
    }


    $data['dc'] = date('Y-m-d H:i:s');
    $data['tc'] = time();

    try {
      $id = ogDb::t('work_flows')->insert($data);
      ogResponse::success(['id' => $id], __('workFlow.create.success'), 201);
    } catch (Exception $e) {
      ogResponse::serverError(__('workFlow.create.error'), OG_IS_DEV ? $e->getMessage() : null);
    }
  }

  function update($id) {
    $exists = ogDb::t('work_flows')->find($id);
    if (!$exists) ogResponse::notFound(__('workFlow.not_found'));

    $data = ogRequest::data();

    $data['du'] = date('Y-m-d H:i:s');
    $data['tu'] = time();

    try {
      $affected = ogDb::t('work_flows')->where('id', $id)->update($data);

      // Actualizar archivos JSON de todos los bots que usan este workflow
      //WorkflowHandler::updateBotsContext($id);
      ogApp()->handler('workFlow')::updateBotsContext($id);

      ogResponse::success(['affected' => $affected], __('workFlow.update.success'));
    } catch (Exception $e) {
      ogResponse::serverError(__('workFlow.update.error'), OG_IS_DEV ? $e->getMessage() : null);
    }
  }

  function show($id) {
    $data = ogDb::t('work_flows')->find($id);
    if (!$data) ogResponse::notFound(__('workFlow.not_found'));

    ogResponse::success($data);
  }

  function list() {
    ogLog::debug('WorkFlowController.list - Iniciando listado de work_flows', ['table' => 'work_flows'], ['module' => 'WorkFlowController', 'layer' => 'app/resources']);
    $query = ogDb::t('work_flows');

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
    $item = ogDb::t('work_flows')->find($id);
    if (!$item) ogResponse::notFound(__('workFlow.not_found'));

    try {
      $affected = ogDb::t('work_flows')->where('id', $id)->delete();
      ogResponse::success(['affected' => $affected], __('workFlow.delete.success'));
    } catch (Exception $e) {
      ogResponse::serverError(__('workFlow.delete.error'), OG_IS_DEV ? $e->getMessage() : null);
    }
  }
}
