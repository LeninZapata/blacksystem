<?php
class chatController extends controller {

  private static $table = 'chats';

  function __construct() {
    parent::__construct('chat');
  }

  function create() {
    $data = request::data();

    if (!isset($data['bot_id']) || empty($data['bot_id'])) {
      response::json(['success' => false, 'error' => __('chat.bot_id_required')], 200);
    }

    if (!isset($data['client_id']) || empty($data['client_id'])) {
      response::json(['success' => false, 'error' => __('chat.client_id_required')], 200);
    }

    if (!isset($data['bot_number']) || empty($data['bot_number'])) {
      response::json(['success' => false, 'error' => __('chat.bot_number_required')], 200);
    }

    if (!isset($data['client_number']) || empty($data['client_number'])) {
      response::json(['success' => false, 'error' => __('chat.client_number_required')], 200);
    }

    // Convertir metadata a JSON si es array
    if (isset($data['metadata']) && is_array($data['metadata'])) {
      $data['metadata'] = json_encode($data['metadata'], JSON_UNESCAPED_UNICODE);
    }

    $data['dc'] = date('Y-m-d H:i:s');
    $data['tc'] = time();
    $data['type'] = $data['type'] ?? 'P';
    $data['format'] = $data['format'] ?? 'text';
    $data['sale_id'] = $data['sale_id'] ?? 0;

    try {
      $id = db::table(self::$table)->insert($data);
      response::success(['id' => $id], __('chat.create.success'), 201);
    } catch (Exception $e) {
      response::serverError(__('chat.create.error'), IS_DEV ? $e->getMessage() : null);
    }
  }

  function update($id) {
    $exists = db::table(self::$table)->find($id);
    if (!$exists) response::notFound(__('chat.not_found'));

    $data = request::data();

    // Convertir metadata a JSON si es array
    if (isset($data['metadata']) && is_array($data['metadata'])) {
      $data['metadata'] = json_encode($data['metadata'], JSON_UNESCAPED_UNICODE);
    }

    $data['du'] = date('Y-m-d H:i:s');
    $data['tu'] = time();

    try {
      $affected = db::table(self::$table)->where('id', $id)->update($data);
      response::success(['affected' => $affected], __('chat.update.success'));
    } catch (Exception $e) {
      response::serverError(__('chat.update.error'), IS_DEV ? $e->getMessage() : null);
    }
  }

  function show($id) {
    $data = db::table(self::$table)->find($id);
    if (!$data) response::notFound(__('chat.not_found'));

    // Decodificar metadata si es string
    if (isset($data['metadata']) && is_string($data['metadata'])) {
      $data['metadata'] = json_decode($data['metadata'], true);
    }

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

    // Decodificar metadata en cada registro
    foreach ($data as &$item) {
      if (isset($item['metadata']) && is_string($item['metadata'])) {
        $item['metadata'] = json_decode($item['metadata'], true);
      }
    }

    response::success($data);
  }

  function delete($id) {
    $item = db::table(self::$table)->find($id);
    if (!$item) response::notFound(__('chat.not_found'));

    try {
      $affected = db::table(self::$table)->where('id', $id)->delete();
      response::success(['affected' => $affected], __('chat.delete.success'));
    } catch (Exception $e) {
      response::serverError(__('chat.delete.error'), IS_DEV ? $e->getMessage() : null);
    }
  }
}