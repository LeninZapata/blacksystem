<?php
// Controller - Controlador basado en schemas JSON
class controller {
  private $config, $table, $resource;

  function __construct($resourceName) {
    $configFile = APP_PATH . "/resources/schemas/{$resourceName}.json";

    if (!file_exists($configFile)) {
      response::error(__('core.controller.resource_not_found', ['resource' => $resourceName]), 404);
    }

    $this->config = json_decode(file_get_contents($configFile), true);
    $this->table = $this->config['table'];
    $this->resource = $resourceName;

    // Cargar handlers personalizados si existen
    $handlerFile = APP_PATH . "/resources/handlers/{$resourceName}Handlers.php";
    if (file_exists($handlerFile)) require_once $handlerFile;
  }

  // LIST - Obtener todos
  function list() {
    $query = db::table($this->table);

    // Filtros dinámicos desde query params
    foreach ($_GET as $key => $value) {
      if (in_array($key, ['page', 'per_page', 'sort', 'order'])) continue;
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

    response::success($data);
  }

  // SHOW - Obtener uno
  function show($id) {
    $data = db::table($this->table)->find($id);
    if (!$data) response::notFound(__('core.controller.not_found', ['resource' => $this->resource]));
    response::success($data);
  }

  // CREATE - Crear
  function create() {
    $data = request::data();

    // Validar campos requeridos
    $required = $this->getRequiredFields();
    $validation = validation::required($data, $required);
    if (!$validation['valid']) response::validation($validation['errors']);

    // Validar campos únicos
    $this->validateUnique($data);

    // Timestamps
    if ($this->config['timestamps'] ?? false) {
      $data['created_at'] = date('Y-m-d H:i:s');
    }

    $id = db::table($this->table)->insert($data);
    response::success(['id' => $id], __('core.controller.created', ['resource' => $this->resource]), 201);
  }

  // UPDATE - Actualizar
  function update($id) {
    $exists = db::table($this->table)->find($id);
    if (!$exists) response::notFound(__('core.controller.not_found', ['resource' => $this->resource]));

    $data = request::data();

    // Validar campos únicos (excepto el registro actual)
    $this->validateUnique($data, $id);

    // Timestamps
    if ($this->config['timestamps'] ?? false) {
      $data['updated_at'] = date('Y-m-d H:i:s');
    }

    $affected = db::table($this->table)->where('id', $id)->update($data);
    response::success(['affected' => $affected], __('core.controller.updated', ['resource' => $this->resource]));
  }

  // DELETE - Eliminar
  function delete($id) {
    $exists = db::table($this->table)->find($id);
    if (!$exists) response::notFound(__('core.controller.not_found', ['resource' => $this->resource]));

    $affected = db::table($this->table)->where('id', $id)->delete();
    response::success(['affected' => $affected], __('core.controller.deleted', ['resource' => $this->resource]));
  }

  // Obtener campos requeridos del schema
  private function getRequiredFields() {
    $required = [];
    foreach ($this->config['fields'] ?? [] as $field) {
      if ($field['required'] ?? false) {
        $required[] = $field['name'];
      }
    }
    return $required;
  }

  // Validar campos únicos
  private function validateUnique($data, $excludeId = null) {
    foreach ($this->config['fields'] ?? [] as $field) {
      if (($field['unique'] ?? false) && isset($data[$field['name']])) {
        $query = db::table($this->table)->where($field['name'], $data[$field['name']]);

        // Excluir el registro actual en updates
        if ($excludeId) $query = $query->where('id', '!=', $excludeId);

        if ($query->exists()) {
          response::error(__('core.controller.field_exists', ['field' => $field['name']]), 400);
        }
      }
    }
  }
}