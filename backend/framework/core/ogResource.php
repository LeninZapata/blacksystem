<?php
// ogResource - Helper para trabajar con recursos
class ogResource {
  private $config, $table;

  function __construct($resourceName) {
    // Buscar schema: app → middle → framework
    $configFile = ogApp()->getPath() . "/resources/schemas/{$resourceName}.json";
    if (!file_exists($configFile)) {
      $configFile = OG_FRAMEWORK_PATH . "/../middle/resources/schemas/{$resourceName}.json";
    }
    if (!file_exists($configFile)) {
      $configFile = OG_FRAMEWORK_PATH . "/resources/schemas/{$resourceName}.json";
    }

    if (!file_exists($configFile)) {
      throw new Exception("Resource schema not found: {$resourceName}");
    }

    $this->config = json_decode(file_get_contents($configFile), true);
    $this->table = $this->config['table'];
  }

  // GET - Obtener por ID o condiciones
  function get($idOrConditions = null) {
    $query = ogDb::table($this->table);

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
    $query = ogDb::table($this->table);
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
    return ogDb::table($this->table)->where($field, $operator, $value);
  }

  // INSERT - Crear
  function insert($data) {
    if ($this->config['timestamps'] ?? false) {
      $data['created_at'] = date('Y-m-d H:i:s');
    }else{
      $data['dc'] = date('Y-m-d H:i:s');
      $data['tc'] = time();
    }
    return ogDb::table($this->table)->insert($data);
  }

  // UPDATE - Actualizar
  function update($idOrConditions, $data) {
    $query = ogDb::table($this->table);

    if ($this->config['timestamps'] ?? false) {
      $data['updated_at'] = date('Y-m-d H:i:s');
    }else {
      $data['du'] = date('Y-m-d H:i:s');
      $data['tu'] = time();
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
    $query = ogDb::table($this->table);

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
    $query = ogDb::table($this->table);
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
function ogResource($name) {
  return new ogResource($name);
}