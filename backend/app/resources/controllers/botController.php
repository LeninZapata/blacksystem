<?php
class botController extends controller {

  function __construct() {
    parent::__construct('bot');
  }

  // Override create para agregar timestamps y validar config
  function create() {
    $data = request::data();

    // Validar campo requerido
    if (!isset($data['name']) || empty($data['name'])) {
      log::error('BotController - Campo name requerido', $data, ['module' => 'bot']);
      response::json([
        'success' => false,
        'error' => __('bot.name_required')
      ], 200);
    }

    // Convertir config a JSON si es array
    if (isset($data['config']) && is_array($data['config'])) {
      $data['config'] = json_encode($data['config'], JSON_UNESCAPED_UNICODE);
    }

    // Timestamps (se manejan con triggers pero por compatibilidad)
    $data['dc'] = date('Y-m-d H:i:s');
    $data['ta'] = time();

    try {
      $id = db::table('bots')->insert($data);
      log::info('BotController - Bot creado', ['id' => $id, 'name' => $data['name']], ['module' => 'bot']);
      response::success(['id' => $id], __('bot.create.success'), 201);
    } catch (Exception $e) {
      log::error('BotController - Error SQL', ['message' => $e->getMessage()], ['module' => 'bot']);
      response::serverError(__('bot.create.error'), IS_DEV ? $e->getMessage() : null);
    }
  }

  // Override update para agregar timestamps y validar config
  function update($id) {
    $exists = db::table('bots')->find($id);
    if (!$exists) response::notFound(__('bot.not_found'));

    $data = request::data();

    // Convertir config a JSON si es array
    if (isset($data['config']) && is_array($data['config'])) {
      $data['config'] = json_encode($data['config'], JSON_UNESCAPED_UNICODE);
    }

    // Timestamps (se manejan con triggers pero por compatibilidad)
    $data['da'] = date('Y-m-d H:i:s');
    $data['tu'] = time();

    try {
      $affected = db::table('bots')->where('id', $id)->update($data);
      log::info('BotController - Bot actualizado', ['id' => $id], ['module' => 'bot']);
      response::success(['affected' => $affected], __('bot.update.success'));
    } catch (Exception $e) {
      log::error('BotController - Error SQL', ['message' => $e->getMessage()], ['module' => 'bot']);
      response::serverError(__('bot.update.error'), IS_DEV ? $e->getMessage() : null);
    }
  }

  // Override show para parsear config si es string JSON
  function show($id) {
    $data = db::table('bots')->find($id);
    if (!$data) response::notFound(__('bot.not_found'));

    // Parsear config si es string JSON
    if (isset($data['config']) && is_string($data['config'])) {
      $data['config'] = json_decode($data['config'], true);
    }

    response::success($data);
  }

  // Override list para parsear config
  function list() {
    $query = db::table('bots');

    // Filtros dinámicos
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

    // Asegurar que siempre sea array
    if (!is_array($data)) {
      log::warning('BotController - Data no es array, convirtiendo a []', ['data' => $data], ['module' => 'bot']);
      $data = [];
    }

    // Parsear config
    foreach ($data as &$bot) {
      if (isset($bot['config']) && is_string($bot['config'])) {
        $bot['config'] = json_decode($bot['config'], true);
      }
    }

    response::success($data);
  }

  // Override delete para logging
  function delete($id) {
    $bot = db::table('bots')->find($id);
    if (!$bot) response::notFound(__('bot.not_found'));

    try {
      $affected = db::table('bots')->where('id', $id)->delete();
      log::info('BotController - Bot eliminado', ['id' => $id, 'name' => $bot['name']], ['module' => 'bot']);
      response::success(['affected' => $affected], __('bot.delete.success'));
    } catch (Exception $e) {
      log::error('BotController - Error SQL', ['message' => $e->getMessage()], ['module' => 'bot']);
      response::serverError(__('bot.delete.error'), IS_DEV ? $e->getMessage() : null);
    }
  }
}