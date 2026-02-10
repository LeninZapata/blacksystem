<?php
class AdAutoScaleController extends ogController {
  private $logMeta = ['module' => 'AdAutoScaleController', 'layer' => 'app/resources'];

  function __construct() {
    parent::__construct('adAutoScale');
  }

  function create() {
    $data = ogRequest::data();

    if (isset($GLOBALS['auth_user_id'])) {
      $data['user_id'] = $GLOBALS['auth_user_id'];
    } else {
      ogResponse::json(['success' => false, 'error' => __('auth.unauthorized')], 401);
    }

    if (!isset($data['name']) || empty($data['name'])) {
      ogResponse::json(['success' => false, 'error' => __('adAutoScale.name_required')], 400);
    }

    if (!isset($data['ad_assets_id'])) {
      ogResponse::json(['success' => false, 'error' => __('adAutoScale.ad_assets_id_required')], 400);
    }

    $data['dc'] = date('Y-m-d H:i:s');
    $data['tc'] = time();

    if (isset($data['config']) && is_array($data['config'])) {
      $data['config'] = json_encode($data['config'], JSON_UNESCAPED_UNICODE);
    }

    try {
      $id = ogDb::t('ad_auto_scale')->insert($data);
      if ($id) {
        ogLog::success('create - Regla de escalado creada', ['id' => $id], $this->logMeta);
        ogResponse::success(['id' => $id], __('adAutoScale.create.success'));
      }
    } catch (Exception $e) {
      ogLog::error('create - Error', ['message' => $e->getMessage()], $this->logMeta);
      ogResponse::serverError(__('adAutoScale.create.error'), OG_IS_DEV ? $e->getMessage() : null);
    }
  }

  function update($id) {
    $exists = ogDb::t('ad_auto_scale')->find($id);
    if (!$exists) ogResponse::notFound(__('adAutoScale.not_found'));

    $data = ogRequest::data();
    $data['du'] = date('Y-m-d H:i:s');
    $data['tu'] = time();

    if (isset($data['config']) && is_array($data['config'])) {
      $data['config'] = json_encode($data['config'], JSON_UNESCAPED_UNICODE);
    }

    try {
      $affected = ogDb::t('ad_auto_scale')->where('id', $id)->update($data);
      ogLog::info('update - Regla de escalado actualizada', ['id' => $id], $this->logMeta);
      ogResponse::success(['affected' => $affected], __('adAutoScale.update.success'));
    } catch (Exception $e) {
      ogLog::error('update - Error', ['message' => $e->getMessage()], $this->logMeta);
      ogResponse::serverError(__('adAutoScale.update.error'), OG_IS_DEV ? $e->getMessage() : null);
    }
  }

  function show($id) {
    $data = ogDb::t('ad_auto_scale')->find($id);
    if (!$data) ogResponse::notFound(__('adAutoScale.not_found'));
    ogResponse::success($data);
  }

  function list() {
    // Construir query con LEFT JOIN especificando tabla.columna explícitamente
    $query = ogDb::t('ad_auto_scale')
      ->select([
        'ad_auto_scale.id',
        'ad_auto_scale.user_id',
        'ad_auto_scale.name',
        'ad_auto_scale.ad_assets_id',
        'ad_auto_scale.config',
        'ad_auto_scale.is_active',
        'ad_auto_scale.status',
        'ad_auto_scale.dc',
        'ad_auto_scale.du',
        'ad_auto_scale.tc',
        'ad_auto_scale.tu',
        'product_ad_assets.ad_asset_name as product_ad_asset_name'
      ])
      ->leftJoin('product_ad_assets', 'ad_auto_scale.ad_assets_id', '=', 'product_ad_assets.id');

    if (isset($GLOBALS['auth_user_id'])) {
      $query = $query->where('ad_auto_scale.user_id', $GLOBALS['auth_user_id']);
    } else {
      ogResponse::json(['success' => false, 'error' => __('auth.unauthorized')], 401);
    }

    foreach ($_GET as $key => $value) {
      if (in_array($key, ['page', 'per_page', 'sort', 'order'])) continue;
      $query = $query->where("ad_auto_scale.{$key}", $value);
    }

    $sort = ogRequest::query('sort', 'id');
    $order = ogRequest::query('order', 'DESC');
    $query = $query->orderBy("ad_auto_scale.{$sort}", $order);

    $page = ogRequest::query('page', 1);
    $perPage = ogRequest::query('per_page', 50);
    $data = $query->paginate($page, $perPage)->get();

    if (!is_array($data)) $data = [];
    ogResponse::success($data);
  }

  function delete($id) {
    $item = ogDb::t('ad_auto_scale')->find($id);
    if (!$item) ogResponse::notFound(__('adAutoScale.not_found'));

    try {
      $affected = ogDb::t('ad_auto_scale')->where('id', $id)->delete();
      ogLog::info('delete - Regla de escalado eliminada', ['id' => $id], $this->logMeta);
      ogResponse::success(['affected' => $affected], __('adAutoScale.delete.success'));
    } catch (Exception $e) {
      ogLog::error('delete - Error', ['message' => $e->getMessage()], $this->logMeta);
      ogResponse::serverError(__('adAutoScale.delete.error'), OG_IS_DEV ? $e->getMessage() : null);
    }
  }
}