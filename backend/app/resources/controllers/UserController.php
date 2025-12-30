<?php

class UserController extends ogController {
  // Nombre de la tabla asociada a este controlador
  protected static $table = DB_TABLES['users'];

  // Elimina todas las sesiones de un usuario por archivos en /sessions
  static function invalidateSessions($userId) {
    $sessionsDir = STORAGE_PATH . '/sessions/';
    if (!is_dir($sessionsDir)) return 0;
    $pattern = $sessionsDir . "*_{$userId}_*.json";
    $files = glob($pattern);
    $cleaned = 0;
    foreach ($files as $file) {
      try {
        unlink($file);
        $cleaned++;
      } catch (Exception $e) {
        ogLog::error(__('user.session.cleanup_error'), $e->getMessage(), self::$logMeta);
      }
    }
    if ($cleaned > 0) {
      ogLog::info(__('user.session.cleaned_user', ['cleaned' => $cleaned, 'userId' => $userId]), null, self::$logMeta);
    }
    return $cleaned;
  }

  private static $logMeta = ['module' => 'user', 'layer' => 'app'];

  function __construct() {
    parent::__construct('user');
  }

  // Override create para hashear contraseña
  function create() {
    $data = ogRequest::data();

    // Validar campos requeridos
    if (!isset($data['user']) || !isset($data['pass']) || !isset($data['role'])) {
      ogResponse::error(__('user.fields_required'), 400);
    }

    // Validar email (formato + unicidad)
    if (isset($data['email']) && !empty($data['email'])) {
      $this->validateEmail($data['email'], self::$table);
    }

    // Validar user único
    $this->ValidateUnique(self::$table, 'user', $data['user'], 'user.already_exists');

    // Hashear contraseña
    $data['pass'] = password_hash($data['pass'], PASSWORD_BCRYPT);

    // Convertir config a JSON si es array
    if (isset($data['config']) && is_array($data['config'])) {
      $data['config'] = json_encode($data['config'], JSON_UNESCAPED_UNICODE);
    }

    // Timestamps
    $data['dc'] = date('Y-m-d H:i:s');
    $data['tc'] = time();

    try {
      $id = ogDb::table(self::$table)->insert($data);
      ogLog::info('Usuario creado', ['id' => $id], self::$logMeta);
      ogResponse::success(['id' => $id], __('user.create.success'), 201);
    } catch (Exception $e) {
      ogLog::error('Error al crear usuario', ['error' => $e->getMessage()], self::$logMeta);
      ogResponse::serverError(__('user.create.error'), OG_IS_DEV ? $e->getMessage() : null);
    }
  }

  // Override update para hashear contraseña si se proporciona
  function update($id) {
    $exists = ogDb::table(self::$table)->find($id);
    if (!$exists) ogResponse::notFound(__('user.not_found'));

    $data = ogRequest::data();

    // Hashear contraseña si se proporciona
    if (isset($data['pass']) && !empty($data['pass'])) {
      $data['pass'] = password_hash($data['pass'], PASSWORD_BCRYPT);
    } else {
      unset($data['pass']);
    }

    // Validar email (formato + unicidad)
    if (isset($data['email']) && !empty($data['email'])) {
      $this->validateEmail($data['email'], self::$table, $id);
    }

    // Validar user único (excepto el actual)
    if (isset($data['user'])) {
      $this->validateUniqueExcept(self::$table, 'user', $data['user'], $id, 'user.already_exists');
    }

    // Convertir config a JSON si es array
    if (isset($data['config']) && is_array($data['config'])) {
      $data['config'] = json_encode($data['config'], JSON_UNESCAPED_UNICODE);
    }

    // Timestamps
    $data['du'] = date('Y-m-d H:i:s');
    $data['tu'] = time();

    $affected = ogDb::table(self::$table)->where('id', $id)->update($data);

    // INVALIDAR SESIONES si se modificó el config (permisos)
    $cleaned = 0;
    if (isset($data['config'])) {
      // ⚠️ NO invalidar sesión del usuario autenticado actual (quien está editando)
      $currentUserId = $GLOBALS['auth_user_id'] ?? null;
      if ($currentUserId && $currentUserId == $id) {
        ogLog::info("Usuario {$id} se editó a sí mismo, no se invalida su sesión", null, self::$logMeta);
      } else {
        $cleaned = self::invalidateSessions($id);
      }
    }

    ogResponse::success([
      'affected' => $affected,
      'sessions_invalidated' => $cleaned
    ], __('user.update.success'));
  }

  // Override show para no devolver la contraseña
  function show($id) {
    $data = ogDb::table(self::$table)->find($id);
    if (!$data) ogResponse::notFound(__('user.not_found'));

    // Parsear config si es string JSON
    if (isset($data['config']) && is_string($data['config'])) {
      $data['config'] = json_decode($data['config'], true);
    }

    unset($data['pass']);
    ogResponse::success($data);
  }

  // Override list para no devolver contraseñas
  function list() {
    $query = ogDb::table(self::$table);

    // Filtros dinámicos
    foreach ($_GET as $key => $value) {
      if (in_array($key, ['page', 'per_page', 'sort', 'order'])) continue;
      if ($key === 'pass') continue;
      $query = $query->where($key, $value);
    }

    // Ordenamiento
    $sort = ogRequest::query('sort', 'id');
    $order = ogRequest::query('order', 'ASC');
    $query = $query->orderBy($sort, $order);

    // Paginación
    $page = ogRequest::query('page', 1);
    $perPage = ogRequest::query('per_page', 50);
    $data = $query->paginate($page, $perPage)->get();

    // Remover contraseñas y parsear config
    foreach ($data as &$user) {
      unset($user['pass']);
      if (isset($user['config']) && is_string($user['config'])) {
        $user['config'] = json_decode($user['config'], true);
      }
    }

    ogResponse::success($data);
  }
}
