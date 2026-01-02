<?php
class CredentialController extends ogController {
  // Nombre de la tabla asociada a este controlador
  protected static $table = DB_TABLES['credentials'];

  function __construct() {
    parent::__construct('credential');
  }

  function create() {
    $data = ogRequest::data();

    if (isset($GLOBALS['auth_user_id'])) {
      $data['user_id'] = $GLOBALS['auth_user_id'];
    } else {
      ogResponse::json(['success' => false, 'error' => __('auth.unauthorized')], 401);
    }

    if (!isset($data['name']) || empty($data['name'])) {
      ogResponse::json(['success' => false, 'error' => __('credential.name_required')], 200);
    }

    if (!isset($data['type']) || empty($data['type'])) {
      ogResponse::json(['success' => false, 'error' => __('credential.type_required')], 200);
    }

    if (isset($data['config']) && is_array($data['config'])) {
      $data['config'] = json_encode($data['config'], JSON_UNESCAPED_UNICODE);
    }

    $data['dc'] = date('Y-m-d H:i:s');
    $data['tc'] = time();

    try {
      $id = ogDb::table(self::$table)->insert($data);
      ogResponse::success(['id' => $id], __('credential.create.success'), 201);
    } catch (Exception $e) {
      ogResponse::serverError(__('credential.create.error'), OG_IS_DEV ? $e->getMessage() : null);
    }
  }

  function update($id) {
    $exists = ogDb::table(self::$table)->find($id);
    if (!$exists) ogResponse::notFound(__('credential.not_found'));

    $data = ogRequest::data();

    if (isset($data['config']) && is_array($data['config'])) {
      $data['config'] = json_encode($data['config'], JSON_UNESCAPED_UNICODE);
    }

    $data['du'] = date('Y-m-d H:i:s');
    $data['tu'] = time();

    try {
      $affected = ogDb::table(self::$table)->where('id', $id)->update($data);

      // Actualizar archivos JSON de todos los bots que usan esta credencial
      $botsUpdated = ogApp()->handler('credential')::updateBotsContext($id);

      ogLog::info('credentialController - Credencial actualizada', [
        'id' => $id,
        'bots_updated' => $botsUpdated
      ], ['module' => 'credential']);

      ogResponse::success([
        'affected' => $affected,
        'bots_updated' => $botsUpdated
      ], __('credential.update.success'));
    } catch (Exception $e) {
      ogResponse::serverError(__('credential.update.error'), OG_IS_DEV ? $e->getMessage() : null);
    }
  }

  function show($id) {
    $data = ogDb::table(self::$table)->find($id);
    if (!$data) ogResponse::notFound(__('credential.not_found'));

    if (isset($data['config']) && is_string($data['config'])) {
      $data['config'] = json_decode($data['config'], true);
    }

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

    foreach ($data as &$item) {
      if (isset($item['config']) && is_string($item['config'])) {
        $item['config'] = json_decode($item['config'], true);
      }
    }

    ogResponse::success($data);
  }

  function delete($id) {
    $item = ogDb::table(self::$table)->find($id);
    if (!$item) ogResponse::notFound(__('credential.not_found'));

    try {
      $affected = ogDb::table(self::$table)->where('id', $id)->delete();
      ogResponse::success(['affected' => $affected], __('credential.delete.success'));
    } catch (Exception $e) {
      ogResponse::serverError(__('credential.delete.error'), OG_IS_DEV ? $e->getMessage() : null);
    }
  }
}