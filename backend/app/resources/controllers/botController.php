<?php
class BotController extends controller {
  // Nombre de la tabla asociada a este controlador
  protected static $table = DB_TABLES['bots'];

  function __construct() {
    parent::__construct('bot');
  }

  function create() {
    $data = request::data();

    if (isset($GLOBALS['auth_user_id'])) {
      $data['user_id'] = $GLOBALS['auth_user_id'];
    } else {
      response::json(['success' => false, 'error' => __('auth.unauthorized')], 401);
    }

    if (!isset($data['name']) || empty($data['name'])) {
      log::error('BotController - Campo name requerido', $data, ['module' => 'bot']);
      response::json(['success' => false, 'error' => __('bot.name_required')], 200);
    }

    // Agregar timezone a config desde country_code
    if (!isset($data['config']) || !is_array($data['config'])) {
      $data['config'] = [];
    }

    $countryCode = $data['country_code'] ?? 'EC';
    $countryData = country::get($countryCode);
    $data['config']['timezone'] = $countryData['timezone'] ?? 'America/Guayaquil';

    if (isset($data['config']) && is_array($data['config'])) {
      $data['config'] = json_encode($data['config'], JSON_UNESCAPED_UNICODE);
    }

    $data['dc'] = date('Y-m-d H:i:s');
    $data['ta'] = time();

    try {
      $id = db::table(self::$table)->insert($data);
      log::info('BotController - Bot creado', ['id' => $id, 'name' => $data['name']], ['module' => 'bot']);
      
      if (isset($data['number'])) {
        $data['id'] = $id;
        BotHandlers::saveContextFile($data, 'create');
      }
      
      response::success(['id' => $id], __('bot.create.success'), 201);
    } catch (Exception $e) {
      log::error('BotController - Error SQL', ['message' => $e->getMessage()], ['module' => 'bot']);
      response::serverError(__('bot.create.error'), IS_DEV ? $e->getMessage() : null);
    }
  }

  function update($id) {
    $exists = db::table(self::$table)->find($id);
    if (!$exists) response::notFound(__('bot.not_found'));

    $data = request::data();

    // Detectar si cambió el número
    $oldNumber = $exists['number'] ?? null;
    $newNumber = $data['number'] ?? $oldNumber;
    $numberChanged = $oldNumber && $newNumber && $oldNumber !== $newNumber;

    // Parsear config si viene como string
    if (isset($data['config']) && is_string($data['config'])) {
      $data['config'] = json_decode($data['config'], true);
    }

    // Agregar timezone a config desde country_code
    if (!isset($data['config']) || !is_array($data['config'])) {
      $data['config'] = [];
    }

    $countryCode = $data['country_code'] ?? $exists['country_code'] ?? 'EC';
    $countryData = country::get($countryCode);
    $data['config']['timezone'] = $countryData['timezone'] ?? 'America/Guayaquil';

    if (isset($data['config']) && is_array($data['config'])) {
      $data['config'] = json_encode($data['config'], JSON_UNESCAPED_UNICODE);
    }

    $data['da'] = date('Y-m-d H:i:s');
    $data['tu'] = time();

    try {
      $affected = db::table(self::$table)->where('id', $id)->update($data);
      log::info('BotController - Bot actualizado', [
        'id' => $id,
        'number_changed' => $numberChanged,
        'old_number' => $oldNumber,
        'new_number' => $newNumber
      ], ['module' => 'bot']);
      
      if ($affected > 0) {
        $botData = db::table(self::$table)->find($id);
        if ($botData) {
          // Pasar oldNumber solo si cambió
          BotHandlers::saveContextFile($botData, 'update', $numberChanged ? $oldNumber : null);
        }
      }
      
      response::success(['affected' => $affected], __('bot.update.success'));
    } catch (Exception $e) {
      log::error('BotController - Error SQL', ['message' => $e->getMessage()], ['module' => 'bot']);
      response::serverError(__('bot.update.error'), IS_DEV ? $e->getMessage() : null);
    }
  }

  function show($id) {
    $data = db::table(self::$table)->find($id);
    if (!$data) response::notFound(__('bot.not_found'));

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

    if (!is_array($data)) {
      log::warning('BotController - Data no es array, convirtiendo a []', ['data' => $data], ['module' => 'bot']);
      $data = [];
    }

    foreach ($data as &$bot) {
      if (isset($bot['config']) && is_string($bot['config'])) {
        $bot['config'] = json_decode($bot['config'], true);
      }
    }

    response::success($data);
  }

  function delete($id) {
    $bot = db::table(self::$table)->find($id);
    if (!$bot) response::notFound(__('bot.not_found'));

    try {
      $affected = db::table(self::$table)->where('id', $id)->delete();
      log::info('BotController - Bot eliminado', ['id' => $id, 'name' => $bot['name']], ['module' => 'bot']);
      response::success(['affected' => $affected], __('bot.delete.success'));
    } catch (Exception $e) {
      log::error('BotController - Error SQL', ['message' => $e->getMessage()], ['module' => 'bot']);
      response::serverError(__('bot.delete.error'), IS_DEV ? $e->getMessage() : null);
    }
  }
}