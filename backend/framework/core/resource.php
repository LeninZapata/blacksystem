<?php
// Resource - Helper para trabajar con recursos
class resource {
  private $config, $table;

  function __construct($resourceName) {
    $configFile = APP_PATH . "/resources/{$resourceName}.json";
    $this->config = json_decode(file_get_contents($configFile), true);
    $this->table = $this->config['table'];
  }

  // GET - Obtener por ID o condiciones
  function get($idOrConditions = null) {
    $query = db::table($this->table);

    if ($idOrConditions === null) return $query->get();
    if (is_numeric($idOrConditions)) return $query->find($idOrConditions);

    if (is_array($idOrConditions)) {
      foreach ($idOrConditions as $field => $value) {
        $query = $query->where($field, $value);
      }
      return $query->get();
    }

    return null;
  }

  // FIRST - Obtener el primero
  function first($conditions = []) {
    $query = db::table($this->table);
    foreach ($conditions as $field => $value) {
      $query = $query->where($field, $value);
    }
    return $query->first();
  }

  // WHERE - Query builder fluido
  function where($field, $operator = '=', $value = null) {
    if ($value === null) {
      $value = $operator;
      $operator = '=';
    }
    return db::table($this->table)->where($field, $operator, $value);
  }

  // INSERT - Crear
  function insert($data) {
    if ($this->config['timestamps'] ?? false) {
      $data['created_at'] = date('Y-m-d H:i:s');
    }
    return db::table($this->table)->insert($data);
  }

  // UPDATE - Actualizar
  function update($idOrConditions, $data) {
    $query = db::table($this->table);

    if ($this->config['timestamps'] ?? false) {
      $data['updated_at'] = date('Y-m-d H:i:s');
    }

    if (is_numeric($idOrConditions)) {
      $query = $query->where('id', $idOrConditions);
    } elseif (is_array($idOrConditions)) {
      foreach ($idOrConditions as $field => $value) {
        $query = $query->where($field, $value);
      }
    }

    return $query->update($data);
  }

  // DELETE - Eliminar
  function delete($idOrConditions) {
    $query = db::table($this->table);

    if (is_numeric($idOrConditions)) {
      $query = $query->where('id', $idOrConditions);
    } elseif (is_array($idOrConditions)) {
      foreach ($idOrConditions as $field => $value) {
        $query = $query->where($field, $value);
      }
    }

    return $query->delete();
  }

  // COUNT - Contar
  function count($conditions = []) {
    $query = db::table($this->table);
    foreach ($conditions as $field => $value) {
      $query = $query->where($field, $value);
    }
    return $query->count();
  }

  // EXISTS - Verificar existencia
  function exists($conditions) {
    return $this->count($conditions) > 0;
  }
}

// Helper global
function resource($name) {
  return new resource($name);
}