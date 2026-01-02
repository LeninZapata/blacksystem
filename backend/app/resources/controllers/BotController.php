<?php
class BotController extends ogController {
  // Nombre de la tabla asociada a este controlador
  protected static $table = DB_TABLES['bots'];

  function __construct() {
    parent::__construct('bot');
  }

  function create() {
    $data = ogRequest::data();

    if (isset($GLOBALS['auth_user_id'])) {
      $data['user_id'] = $GLOBALS['auth_user_id'];
    } else {
      ogResponse::json(['success' => false, 'error' => __('auth.unauthorized')], 401);
    }

    if (!isset($data['name']) || empty($data['name'])) {
      ogLog::error('BotController - Campo name requerido', $data, ['module' => 'bot']);
      ogResponse::json(['success' => false, 'error' => __('bot.name_required')], 200);
    }

    // Agregar timezone a config desde country_code
    if (!isset($data['config']) || !is_array($data['config'])) {
      $data['config'] = [];
    }

    $countryCode = $data['country_code'] ?? 'EC';
    $countryData = ogApp()->helper('country')::get($countryCode);
    $data['config']['timezone'] = $countryData['timezone'] ?? 'America/Guayaquil';

    if (isset($data['config']) && is_array($data['config'])) {
      $data['config'] = json_encode($data['config'], JSON_UNESCAPED_UNICODE);
    }

    $data['dc'] = date('Y-m-d H:i:s');
    $data['ta'] = time();

    try {
      $id = ogDb::table(self::$table)->insert($data);
      ogLog::info('BotController - Bot creado', ['id' => $id, 'name' => $data['name']], ['module' => 'bot']);

      if (isset($data['number'])) {
        $data['id'] = $id;
        ogApp()->handler('bot')::saveContextFile($data, 'create');
      }

      ogResponse::success(['id' => $id], __('bot.create.success'), 201);
    } catch (Exception $e) {
      ogLog::error('BotController - Error SQL', ['message' => $e->getMessage()], ['module' => 'bot']);
      ogResponse::serverError(__('bot.create.error'), OG_IS_DEV ? $e->getMessage() : null);
    }
  }

  function update($id) {
    $exists = ogDb::table(self::$table)->find($id);
    if (!$exists) ogResponse::notFound(__('bot.not_found'));

    $data = ogRequest::data();

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
    $countryData = ogApp()->helper('country')::get($countryCode);
    $data['config']['timezone'] = $countryData['timezone'] ?? 'America/Guayaquil';

    if (isset($data['config']) && is_array($data['config'])) {
      $data['config'] = json_encode($data['config'], JSON_UNESCAPED_UNICODE);
    }

    $data['da'] = date('Y-m-d H:i:s');
    $data['tu'] = time();

    try {
      $affected = ogDb::table(self::$table)->where('id', $id)->update($data);
      ogLog::info('BotController - Bot actualizado', [
        'id' => $id,
        'number_changed' => $numberChanged,
        'old_number' => $oldNumber,
        'new_number' => $newNumber
      ], ['module' => 'bot']);

      if ($affected > 0) {
        $botData = ogDb::table(self::$table)->find($id);
        if ($botData) {
          // Pasar oldNumber solo si cambió
          ogApp()->handler('bot')::saveContextFile($botData, 'update', $numberChanged ? $oldNumber : null);
        }
      }

      ogResponse::success(['affected' => $affected], __('bot.update.success'));
    } catch (Exception $e) {
      ogLog::error('BotController - Error SQL', ['message' => $e->getMessage()], ['module' => 'bot']);
      ogResponse::serverError(__('bot.update.error'), OG_IS_DEV ? $e->getMessage() : null);
    }
  }

  function show($id) {
    $data = ogDb::table(self::$table)->find($id);
    if (!$data) ogResponse::notFound(__('bot.not_found'));

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

    if (!is_array($data)) {
      ogLog::warning('BotController - Data no es array, convirtiendo a []', ['data' => $data], ['module' => 'bot']);
      $data = [];
    }

    foreach ($data as &$bot) {
      if (isset($bot['config']) && is_string($bot['config'])) {
        $bot['config'] = json_decode($bot['config'], true);
      }
    }

    ogResponse::success($data);
  }

  function delete($id) {
    $bot = ogDb::table(self::$table)->find($id);
    if (!$bot) ogResponse::notFound(__('bot.not_found'));

    try {
      $affected = ogDb::table(self::$table)->where('id', $id)->delete();
      ogLog::info('BotController - Bot eliminado', ['id' => $id, 'name' => $bot['name']], ['module' => 'bot']);
      ogResponse::success(['affected' => $affected], __('bot.delete.success'));
    } catch (Exception $e) {
      ogLog::error('BotController - Error SQL', ['message' => $e->getMessage()], ['module' => 'bot']);
      ogResponse::serverError(__('bot.delete.error'), OG_IS_DEV ? $e->getMessage() : null);
    }
  }
}