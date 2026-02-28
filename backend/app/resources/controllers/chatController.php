<?php
class ChatController extends ogController {

  function __construct() {
    parent::__construct('chat');
  }

  function create() {
    $data = ogRequest::data();

    if (!isset($data['bot_id']) || empty($data['bot_id'])) {
      ogResponse::json(['success' => false, 'error' => __('chat.bot_id_required')], 200);
    }

    if (!isset($data['client_id']) || empty($data['client_id'])) {
      ogResponse::json(['success' => false, 'error' => __('chat.client_id_required')], 200);
    }

    if (!isset($data['bot_number']) || empty($data['bot_number'])) {
      ogResponse::json(['success' => false, 'error' => __('chat.bot_number_required')], 200);
    }

    if (!isset($data['client_number']) || empty($data['client_number'])) {
      ogResponse::json(['success' => false, 'error' => __('chat.client_number_required')], 200);
    }

    // Convertir metadata a JSON si es array
    if (isset($data['metadata']) && is_array($data['metadata'])) {
      $data['metadata'] = json_encode($data['metadata'], JSON_UNESCAPED_UNICODE);
    }

    $data['dc'] = date('Y-m-d H:i:s');
    $data['tc'] = time();
    $data['type'] = $data['type'] ?? 'P';
    $data['format'] = $data['format'] ?? 'text';

    try {
      $id = ogDb::t('chats')->insert($data);
      ogResponse::success(['id' => $id], __('chat.create.success'), 201);
    } catch (Exception $e) {
      ogResponse::serverError(__('chat.create.error'), OG_IS_DEV ? $e->getMessage() : null);
    }
  }

  function update($id) {
    $exists = ogDb::t('chats')->find($id);
    if (!$exists) ogResponse::notFound(__('chat.not_found'));

    $data = ogRequest::data();

    // Convertir metadata a JSON si es array
    if (isset($data['metadata']) && is_array($data['metadata'])) {
      $data['metadata'] = json_encode($data['metadata'], JSON_UNESCAPED_UNICODE);
    }

    $data['du'] = date('Y-m-d H:i:s');
    $data['tu'] = time();

    try {
      $affected = ogDb::t('chats')->where('id', $id)->update($data);
      ogResponse::success(['affected' => $affected], __('chat.update.success'));
    } catch (Exception $e) {
      ogResponse::serverError(__('chat.update.error'), OG_IS_DEV ? $e->getMessage() : null);
    }
  }

  function show($id) {
    $data = ogDb::t('chats')->find($id);
    if (!$data) ogResponse::notFound(__('chat.not_found'));

    // Decodificar metadata si es string
    if (isset($data['metadata']) && is_string($data['metadata'])) {
      $data['metadata'] = json_decode($data['metadata'], true);
    }

    ogResponse::success($data);
  }

  function list() {
    $query = ogDb::t('chats');

    // Filtrar por bots del usuario autenticado (no por user_id del chat, que puede variar por proceso)
    if (isset($GLOBALS['auth_user_id'])) {
      $botIds = ogDb::t('bots')->where('user_id', $GLOBALS['auth_user_id'])->select('id')->get();
      $botIds = array_column($botIds ?? [], 'id');
      if (!empty($botIds)) {
        $query = $query->whereIn('bot_id', $botIds);
      }
    }

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

    // Decodificar metadata en cada registro
    foreach ($data as &$item) {
      if (isset($item['metadata']) && is_string($item['metadata'])) {
        $item['metadata'] = json_decode($item['metadata'], true);
      }
    }

    ogResponse::success($data);
  }

  function delete($id) {
    $item = ogDb::t('chats')->find($id);
    if (!$item) ogResponse::notFound(__('chat.not_found'));

    try {
      $affected = ogDb::t('chats')->where('id', $id)->delete();
      ogResponse::success(['affected' => $affected], __('chat.delete.success'));
    } catch (Exception $e) {
      ogResponse::serverError(__('chat.delete.error'), OG_IS_DEV ? $e->getMessage() : null);
    }
  }
}