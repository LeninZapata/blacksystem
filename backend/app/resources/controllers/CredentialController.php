<?php
class CredentialController extends controller {
  // Nombre de la tabla asociada a este controlador
  protected static $table = DB_TABLES['credentials'];

  function __construct() {
    parent::__construct('credential');
  }

  function create() {
    $data = request::data();

    if (isset($GLOBALS['auth_user_id'])) {
      $data['user_id'] = $GLOBALS['auth_user_id'];
    } else {
      response::json(['success' => false, 'error' => __('auth.unauthorized')], 401);
    }
    
    if (!isset($data['name']) || empty($data['name'])) {
      response::json(['success' => false, 'error' => __('credential.name_required')], 200);
    }

    if (!isset($data['type']) || empty($data['type'])) {
      response::json(['success' => false, 'error' => __('credential.type_required')], 200);
    }

    if (isset($data['config']) && is_array($data['config'])) {
      $data['config'] = json_encode($data['config'], JSON_UNESCAPED_UNICODE);
    }

    $data['dc'] = date('Y-m-d H:i:s');
    $data['ta'] = time();

    try {
      $id = db::table(self::$table)->insert($data);
      response::success(['id' => $id], __('credential.create.success'), 201);
    } catch (Exception $e) {
      response::serverError(__('credential.create.error'), IS_DEV ? $e->getMessage() : null);
    }
  }

  function update($id) {
    $exists = db::table(self::$table)->find($id);
    if (!$exists) response::notFound(__('credential.not_found'));

    $data = request::data();

    if (isset($data['config']) && is_array($data['config'])) {
      $data['config'] = json_encode($data['config'], JSON_UNESCAPED_UNICODE);
    }

    $data['da'] = date('Y-m-d H:i:s');
    $data['tu'] = time();

    try {
      $affected = db::table(self::$table)->where('id', $id)->update($data);
      
      // Actualizar archivos JSON de todos los bots que usan esta credencial
      $botsUpdated = CredentialHandlers::updateBotsContext($id);
      
      log::info('credentialController - Credencial actualizada', [
        'id' => $id,
        'bots_updated' => $botsUpdated
      ], ['module' => 'credential']);
      
      response::success([
        'affected' => $affected,
        'bots_updated' => $botsUpdated
      ], __('credential.update.success'));
    } catch (Exception $e) {
      response::serverError(__('credential.update.error'), IS_DEV ? $e->getMessage() : null);
    }
  }

  function show($id) {
    $data = db::table(self::$table)->find($id);
    if (!$data) response::notFound(__('credential.not_found'));

    if (isset($data['config']) && is_string($data['config'])) {
      $data['config'] = json_decode($data['config'], true);
    }

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

    foreach ($data as &$item) {
      if (isset($item['config']) && is_string($item['config'])) {
        $item['config'] = json_decode($item['config'], true);
      }
    }

    response::success($data);
  }

  function delete($id) {
    $item = db::table(self::$table)->find($id);
    if (!$item) response::notFound(__('credential.not_found'));

    try {
      $affected = db::table(self::$table)->where('id', $id)->delete();
      response::success(['affected' => $affected], __('credential.delete.success'));
    } catch (Exception $e) {
      response::serverError(__('credential.delete.error'), IS_DEV ? $e->getMessage() : null);
    }
  }
}