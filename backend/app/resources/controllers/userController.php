<?php
class userController extends controller {

  function __construct() {
    parent::__construct('user');
  }

  // Override create para hashear contraseña
  function create() {
    $data = request::data();

    // Validar campos requeridos
    if (!isset($data['user']) || !isset($data['pass']) || !isset($data['role'])) {
      log::error('UserController - Campos faltantes', $data, ['module' => 'user']);
      response::json([
        'success' => false,
        'error' => __('user.fields_required')
      ], 200);
    }

    // Validar email si existe
    if (isset($data['email']) && !empty($data['email'])) {
      if (!validation::email($data['email'])) {
        response::json([
          'success' => false,
          'error' => __('user.email_invalid')
        ], 200);
      }
    }

    // Hashear contraseña
    $data['pass'] = password_hash($data['pass'], PASSWORD_BCRYPT);

    // Convertir config a JSON si es array
    if (isset($data['config']) && is_array($data['config'])) {
      $data['config'] = json_encode($data['config'], JSON_UNESCAPED_UNICODE);
    }

    // Timestamps
    $data['dc'] = date('Y-m-d H:i:s');
    $data['tc'] = time();

    // Validar user único
    if (db::table('user')->where('user', $data['user'])->exists()) {
      log::warning('UserController - Usuario ya existe', ['user' => $data['user']], ['module' => 'user']);
      response::json([
        'success' => false,
        'error' => __('user.already_exists')
      ], 200);
    }

    // Validar email único si se proporciona
    if (isset($data['email']) && !empty($data['email'])) {
      if (db::table('user')->where('email', $data['email'])->exists()) {
        log::warning('UserController - Email ya existe', ['email' => $data['email']], ['module' => 'user']);
        response::json([
          'success' => false,
          'error' => __('user.email_exists')
        ], 200);
      }
    }

    try {
      $id = db::table('user')->insert($data);
      log::info('UserController - Usuario creado', ['id' => $id], ['module' => 'user']);
      response::success(['id' => $id], __('user.create.success'), 201);
    } catch (Exception $e) {
      log::error('UserController - Error SQL', ['message' => $e->getMessage()], ['module' => 'user']);
      response::serverError(__('user.create.error'), IS_DEV ? $e->getMessage() : null);
    }
  }

  // Override update para hashear contraseña si se proporciona
  function update($id) {
    $exists = db::table('user')->find($id);
    if (!$exists) response::notFound(__('user.not_found'));

    $data = request::data();

    // Hashear contraseña si se proporciona
    if (isset($data['pass']) && !empty($data['pass'])) {
      $data['pass'] = password_hash($data['pass'], PASSWORD_BCRYPT);
    } else {
      unset($data['pass']);
    }

    // Validar email si existe
    if (isset($data['email']) && !empty($data['email'])) {
      if (!validation::email($data['email'])) {
        response::json([
          'success' => false,
          'error' => __('user.email_invalid')
        ], 200);
      }
    }

    // Convertir config a JSON si es array
    if (isset($data['config']) && is_array($data['config'])) {
      $data['config'] = json_encode($data['config'], JSON_UNESCAPED_UNICODE);
    }

    // Validar user único (excepto el actual)
    if (isset($data['user'])) {
      $query = db::table('user')->where('user', $data['user'])->where('id', '!=', $id);
      if ($query->exists()) {
        response::json([
          'success' => false,
          'error' => __('user.already_exists')
        ], 200);
      }
    }

    // Validar email único (excepto el actual)
    if (isset($data['email']) && !empty($data['email'])) {
      $query = db::table('user')->where('email', $data['email'])->where('id', '!=', $id);
      if ($query->exists()) {
        response::json([
          'success' => false,
          'error' => __('user.email_exists')
        ], 200);
      }
    }

    // Timestamps
    $data['du'] = date('Y-m-d H:i:s');
    $data['tu'] = time();

    $affected = db::table('user')->where('id', $id)->update($data);

    // INVALIDAR SESIONES si se modificó el config (permisos)
    $cleaned = 0;
    if (isset($data['config'])) {
      // ⚠️ NO invalidar sesión del usuario autenticado actual (quien está editando)
      $currentUserId = $GLOBALS['auth_user_id'] ?? null;

      if ($currentUserId && $currentUserId == $id) {
        // Si el admin se está editando a sí mismo, NO invalidar su sesión
        log::info("Usuario {$id} se editó a sí mismo, no se invalida su sesión", null, ['module' => 'user']);
      } else {
        // Invalidar todas las sesiones del usuario editado
        $cleaned = sessionCleanup::cleanByUserId($id);
        log::info("Sesiones invalidadas para user_id={$id}: {$cleaned} sesiones eliminadas", null, ['module' => 'user']);
      }
    }

    response::success([
      'affected' => $affected,
      'sessions_invalidated' => $cleaned
    ], __('user.update.success'));
  }

  // Override show para no devolver la contraseña
  function show($id) {
    $data = db::table('user')->find($id);
    if (!$data) response::notFound(__('user.not_found'));

    // Parsear config si es string JSON
    if (isset($data['config']) && is_string($data['config'])) {
      $data['config'] = json_decode($data['config'], true);
    }

    unset($data['pass']);
    response::success($data);
  }

  // Override list para no devolver contraseñas
  function list() {
    $query = db::table('user');

    // Filtros dinámicos
    foreach ($_GET as $key => $value) {
      if (in_array($key, ['page', 'per_page', 'sort', 'order'])) continue;
      if ($key === 'pass') continue;
      $query = $query->where($key, $value);
    }

    // Ordenamiento
    $sort = request::query('sort', 'id');
    $order = request::query('order', 'ASC');
    $query = $query->orderBy($sort, $order);

    // Paginación
    $page = request::query('page', 1);
    $perPage = request::query('per_page', 50);
    $data = $query->paginate($page, $perPage)->get();

    // Remover contraseñas y parsear config
    foreach ($data as &$user) {
      unset($user['pass']);
      if (isset($user['config']) && is_string($user['config'])) {
        $user['config'] = json_decode($user['config'], true);
      }
    }

    response::success($data);
  }
}