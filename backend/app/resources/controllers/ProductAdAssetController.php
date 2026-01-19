<?php
class ProductAdAssetController extends ogController {
  private $logMeta = ['module' => 'ProductAdAssetController', 'layer' => 'app/resources'];

  function __construct() {
    parent::__construct('productAdAsset');
  }

  function create() {
    $data = ogRequest::data();

    if (isset($GLOBALS['auth_user_id'])) {
      $data['user_id'] = $GLOBALS['auth_user_id'];
    } else {
      ogResponse::json(['success' => false, 'error' => __('auth.unauthorized')], 401);
    }

    if (!isset($data['product_id']) || !isset($data['ad_asset_id'])) {
      ogResponse::json(['success' => false, 'error' => 'product_id y ad_asset_id son requeridos'], 400);
    }

    $data['dc'] = date('Y-m-d H:i:s');
    $data['tc'] = time();

    try {

      // Validar que si auto_reset_budget está activo, tenga base_daily_budget
      if (isset($data['auto_reset_budget']) && $data['auto_reset_budget'] == 1) {
        if (!isset($data['base_daily_budget']) || $data['base_daily_budget'] <= 0) {
          ogResponse::json([
            'success' => false,
            'error' => 'Si "Auto-Reset" está activo, debe especificar un "Presupuesto Diario Base" mayor a 0'
          ], 400);
        }

        if (!isset($data['reset_time']) || empty($data['reset_time'])) {
          ogResponse::json([
            'success' => false,
            'error' => 'Si "Auto-Reset" está activo, debe especificar una "Hora de Reset"'
          ], 400);
        }

        if (!isset($data['timezone']) || empty($data['timezone'])) {
          ogResponse::json([
            'success' => false,
            'error' => 'Si "Auto-Reset" está activo, debe especificar una "Zona Horaria"'
          ], 400);
        }
      }

      $id = ogDb::t('product_ad_assets')->insert($data);
      if ($id) {
        ogLog::success('create - Asset publicitario creado', ['id' => $id], $this->logMeta);
        ogResponse::success(['id' => $id], 'Asset publicitario creado correctamente');
      }
    } catch (Exception $e) {
      ogLog::error('create - Error', ['message' => $e->getMessage()], $this->logMeta);
      ogResponse::serverError('Error al crear asset publicitario', OG_IS_DEV ? $e->getMessage() : null);
    }
  }

  function update($id) {
    $exists = ogDb::t('product_ad_assets')->find($id);
    if (!$exists) ogResponse::notFound('Asset publicitario no encontrado');

    $data = ogRequest::data();
    $data['du'] = date('Y-m-d H:i:s');
    $data['tu'] = time();

    try {

            // Validar que si auto_reset_budget está activo, tenga base_daily_budget
      if (isset($data['auto_reset_budget']) && $data['auto_reset_budget'] == 1) {
        if (!isset($data['base_daily_budget']) || $data['base_daily_budget'] <= 0) {
          ogResponse::json([
            'success' => false,
            'error' => 'Si "Auto-Reset" está activo, debe especificar un "Presupuesto Diario Base" mayor a 0'
          ], 400);
        }

        if (!isset($data['reset_time']) || empty($data['reset_time'])) {
          ogResponse::json([
            'success' => false,
            'error' => 'Si "Auto-Reset" está activo, debe especificar una "Hora de Reset"'
          ], 400);
        }

        if (!isset($data['timezone']) || empty($data['timezone'])) {
          ogResponse::json([
            'success' => false,
            'error' => 'Si "Auto-Reset" está activo, debe especificar una "Zona Horaria"'
          ], 400);
        }
      }

      $affected = ogDb::t('product_ad_assets')->where('id', $id)->update($data);
      ogLog::info('update - Asset publicitario actualizado', ['id' => $id], $this->logMeta);
      ogResponse::success(['affected' => $affected], 'Asset publicitario actualizado correctamente');
    } catch (Exception $e) {
      ogLog::error('update - Error', ['message' => $e->getMessage()], $this->logMeta);
      ogResponse::serverError('Error al actualizar asset publicitario', OG_IS_DEV ? $e->getMessage() : null);
    }
  }

  function show($id) {
    $data = ogDb::t('product_ad_assets')->find($id);
    if (!$data) ogResponse::notFound('Asset publicitario no encontrado');
    ogResponse::success($data);
  }

  function list() {
    $query = ogDb::t('product_ad_assets');

    // Filtrar por user_id autenticado
    if (isset($GLOBALS['auth_user_id'])) {
      $query = $query->where('user_id', $GLOBALS['auth_user_id']);
    } else {
      ogResponse::json(['success' => false, 'error' => __('auth.unauthorized')], 401);
    }

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
    $item = ogDb::t('product_ad_assets')->find($id);
    if (!$item) ogResponse::notFound('Asset publicitario no encontrado');

    try {
      $affected = ogDb::t('product_ad_assets')->where('id', $id)->delete();
      ogLog::info('delete - Asset publicitario eliminado', ['id' => $id], $this->logMeta);
      ogResponse::success(['affected' => $affected], 'Asset publicitario eliminado correctamente');
    } catch (Exception $e) {
      ogLog::error('delete - Error', ['message' => $e->getMessage()], $this->logMeta);
      ogResponse::serverError('Error al eliminar asset publicitario', OG_IS_DEV ? $e->getMessage() : null);
    }
  }
}