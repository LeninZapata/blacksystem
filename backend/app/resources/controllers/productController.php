<?php
class productController extends controller {

  function __construct() {
    parent::__construct('product');
  }

  function create() {
    $data = request::data();
    
    if (isset($GLOBALS['auth_user_id'])) {
      $data['user_id'] = $GLOBALS['auth_user_id'];
    } else {
      response::json(['success' => false, 'error' => __('auth.unauthorized')], 401);
    }
    
    if (!isset($data['name']) || empty($data['name'])) {
      response::json(['success' => false, 'error' => __('product.name_required')], 200);
    }

    $data['dc'] = date('Y-m-d H:i:s');
    $data['ta'] = time();

    if (isset($data['config']) && is_array($data['config'])) {
      $data['config'] = json_encode($data['config'], JSON_UNESCAPED_UNICODE);
    }

    log::debug('productController - Datos para crear producto', $data, ['module' => 'product']);
    
    try {
      $id = db::table('products')->insert($data);
      
      if (isset($data['context'])) {
        $data['id'] = $id;
        productHandler::handleByContext($data, 'create');
      }
      
      response::success(['id' => $id], __('product.create.success'), 201);
    } catch (Exception $e) {
      log::error('productController - Error SQL al crear producto', ['message' => $e->getMessage()], ['module' => 'product']);
      response::serverError(__('product.create.error'), IS_DEV ? $e->getMessage() : null);
    }
  }

  function update($id) {
    $exists = db::table('products')->find($id);
    if (!$exists) response::notFound(__('product.not_found'));

    $data = request::data();

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
      $affected = db::table('products')->where('id', $id)->update($data);
      
      log::info('productController - Producto actualizado', [
        'id' => $id,
        'bot_changed' => $botChanged,
        'old_bot_id' => $oldBotId,
        'new_bot_id' => $newBotId
      ], ['module' => 'product']);
      
      if ($affected > 0 && isset($data['context'])) {
        $data['id'] = $id;
        // Pasar oldBotId solo si cambió
        productHandler::handleByContext($data, 'update', $botChanged ? $oldBotId : null);
      }
      
      response::success(['affected' => $affected], __('product.update.success'));
    } catch (Exception $e) {
      response::serverError(__('product.update.error'), IS_DEV ? $e->getMessage() : null);
    }
  }

  function show($id) {
    $data = db::table('products')->find($id);
    if (!$data) response::notFound(__('product.not_found'));

    response::success($data);
  }

  function list() {
    $query = db::table('products');
    
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
    $item = db::table('products')->find($id);
    if (!$item) response::notFound(__('product.not_found'));

    try {
      $affected = db::table('products')->where('id', $id)->delete();
      response::success(['affected' => $affected], __('product.delete.success'));
    } catch (Exception $e) {
      response::serverError(__('product.delete.error'), IS_DEV ? $e->getMessage() : null);
    }
  }
}