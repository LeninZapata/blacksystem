<?php
class ProductController extends ogController {
  protected static $table = DB_TABLES['products'];
  private $logMeta = ['module' => 'ProductControlleroduct', 'layer' => 'app/resources'];

  function __construct() {
    parent::__construct('product');
  }

  function create() {
    $data = ogRequest::data();

    if (isset($GLOBALS['auth_user_id'])) {
      $data['user_id'] = $GLOBALS['auth_user_id'];
    } else {
      ogResponse::json(['success' => false, 'error' => __('auth.unauthorized')], 401);
    }

    if (!isset($data['name']) || empty($data['name'])) {
      ogResponse::json(['success' => false, 'error' => __('product.name_required')], 200);
    }

    $data['dc'] = date('Y-m-d H:i:s');
    $data['ta'] = time();

    if (isset($data['config']) && is_array($data['config'])) {
      $data['config'] = json_encode($data['config'], JSON_UNESCAPED_UNICODE);
    }

    // ogLog::debug('create - Datos para crear producto', $data, $this->logMeta);

    try {
      $id = ogDb::table(self::$table)->insert($data);

      if (isset($data['context'])) {
        $data['id'] = $id;
        ogApp()->loadHandler('ProductHandler');
        ProductHandler::handleByContext($data, 'create');
      }
      if( $id ){
        ogLog::success('create - Producto creado', [ 'id' => $id ],  $this->logMeta);
        ogResponse::success(['id' => $id], __('product.create.success') );
      }
    } catch (Exception $e) {
      ogLog::error('create - Error SQL al crear producto', ['message' => $e->getMessage()],  $this->logMeta);
      ogResponse::serverError(__('product.create.error'), OG_IS_DEV ? $e->getMessage() : null);
    }
  }

  function update($id) {
    $exists = ogDb::table(self::$table)->find($id);
    if (!$exists) ogResponse::notFound(__('product.not_found'));

    $data = ogRequest::data();

    // Detectar si cambió el bot_id
    $oldBotId = $exists['bot_id'] ?? null;
    $newBotId = $data['bot_id'] ?? $oldBotId;
    $botChanged = $oldBotId && $newBotId && $oldBotId !== $newBotId;

    $data['da'] = date('Y-m-d H:i:s');
    $data['tu'] = time();

    if (isset($data['config']) && is_array($data['config'])) {
      $data['config'] = json_encode($data['config'], JSON_UNESCAPED_UNICODE);
    }

    try {
      $affected = ogDb::table(self::$table)->where('id', $id)->update($data);
      ogLog::info('update - Producto actualizado', [ 'id' => $id, 'bot_changed' => $botChanged, 'old_bot_id' => $oldBotId, 'new_bot_id' => $newBotId ],  $this->logMeta);

      if ($affected > 0 && isset($data['context'])) {
        $data['id'] = $id;
        ogApp()->loadHandler('ProductHandler');
        ProductHandler::handleByContext($data, 'update', $botChanged ? $oldBotId : null);
      }

      ogResponse::success(['affected' => $affected], __('product.update.success'));
    } catch (Exception $e) {
      ogResponse::serverError(__('product.update.error'), OG_IS_DEV ? $e->getMessage() : null);
    }
  }

  function show($id) {
    $data = ogDb::table(self::$table)->find($id);
    if (!$data) ogResponse::notFound(__('product.not_found'));

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
    if (!$item) ogResponse::notFound(__('product.not_found'));

    $botId = $item['bot_id'] ?? null;
    $context = $item['context'] ?? null;

    try {
      // Eliminar archivos asociados si es infoproduct
      if ($context === 'infoproductws' && $botId) {
        ogApp()->loadHandler('ProductHandler');
        $filesDeletion = ProductHandler::deleteProductFiles($id, $botId);

        ogLog::info('delete - Archivos eliminados', [ 'product_id' => $id, 'files_deleted' => count($filesDeletion['deleted'] ?? []), 'errors' => count($filesDeletion['errors'] ?? []) ],  $this->logMeta);
      }

      // Eliminar registro de BD
      $affected = ogDb::table(self::$table)->where('id', $id)->delete();

      ogResponse::success([
        'affected' => $affected,
        'files_deleted' => isset($filesDeletion) ? count($filesDeletion['deleted'] ?? []) : 0
      ], __('product.delete.success'));

    } catch (Exception $e) {
      ogLog::error('productController::delete - Error', [
        'product_id' => $id,
        'error' => $e->getMessage()
      ], ['module' => 'product']);

      ogResponse::serverError(__('product.delete.error'), OG_IS_DEV ? $e->getMessage() : null);
    }
  }
}