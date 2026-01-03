<?php
// DB - Query Builder minimalista
class ogDb {
  protected static $instance = null;

  // Inicializar
  protected static function init() {
    if (!self::$instance) {
      self::$instance = new ogDbBuilder(DB_HOST, DB_NAME, DB_USER, DB_PASS);
    }
    return self::$instance;
  }

  // Shortcut
  static function table($table) {
    return self::init()->table($table);
  }

  // Raw SQL - Corregido para funcionar con array vacío
  static function raw($sql, $bindings = null) {
    // Si bindings es null, devolver ogRawExpr (para usar en UPDATE/INSERT como valor)
    // Si bindings es array (incluso vacío []), ejecutar query
    return is_null($bindings) ? new ogRawExpr($sql) : self::init()->raw($sql, $bindings);
  }
}

// Query Builder
class ogDbBuilder {
  protected $conn, $table, $columns = ['*'], $wheres = [], $joins = [], $orders = [],
            $groups = [], $havings = [], $limit, $offset, $values = [], $distinct = false;

  // Constructor
  function __construct($host, $db, $user, $pass) {
    try {
      $this->conn = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
      ]);
    } catch (PDOException $e) {
      throw new Exception("DB Error: " . $e->getMessage());
    }
  }

  // Tabla
  function table($table) {
    $i = clone $this;
    $i->table = $table;
    return $i;
  }

  // Select
  function select($cols = ['*']) {
    $i = clone $this;
    $i->columns = is_array($cols) ? $cols : func_get_args();
    return $i;
  }

  function distinct() {
    $i = clone $this;
    $i->distinct = true;
    return $i;
  }

  // Where
  // En la clase ogDbBuilder, modifica el método where():
  function where($col, $op = null, $val = null, $bool = 'AND') {
    $i = clone $this;
    if (is_array($col)) {
      foreach ($col as $k => $v) $i = $i->where($k, '=', $v);
      return $i;
    }

    // Solo almacenar el nombre de la columna, SIN backticks
    $columnName = $col;

    if ($val === null) {
      $val = $op;
      $op = '=';
    }

    $i->wheres[] = [
      'type' => 'basic',
      'column' => $columnName,
      'operator' => $op,
      'value' => $val,
      'boolean' => $bool
    ];
    return $i;
  }

  function orWhere($col, $op = null, $val = null) {
    return $this->where($col, $op, $val, 'OR');
  }

  function whereIn($col, $vals, $bool = 'AND') {
    $i = clone $this;
    $i->wheres[] = ['type' => 'in', 'column' => $col, 'values' => $vals, 'boolean' => $bool];
    return $i;
  }

  function whereNotIn($col, $vals) {
    $i = clone $this;
    $i->wheres[] = ['type' => 'notIn', 'column' => $col, 'values' => $vals, 'boolean' => 'AND'];
    return $i;
  }

  function whereNull($col) {
    $i = clone $this;
    $i->wheres[] = ['type' => 'null', 'column' => $col, 'boolean' => 'AND'];
    return $i;
  }

  function whereNotNull($col) {
    $i = clone $this;
    $i->wheres[] = ['type' => 'notNull', 'column' => $col, 'boolean' => 'AND'];
    return $i;
  }

  function whereBetween($col, $vals) {
    $i = clone $this;
    $i->wheres[] = ['type' => 'between', 'column' => $col, 'values' => $vals, 'boolean' => 'AND'];
    return $i;
  }

  // WhereFilters dinámico
  function whereFilters($filters, $bool = 'AND') {
    $i = clone $this;
    if (empty($filters)) return $i;

    foreach ($filters as $k => $f) {
      if (is_string($k)) {
        $i = $i->where($k, '=', $f, $bool);
      } elseif (is_array($f) && count($f) >= 2) {
        $campo = $f[0];
        $op = count($f) === 2 ? '=' : strtoupper(trim($f[1]));
        $val = count($f) === 2 ? $f[1] : ($f[2] ?? null);

        switch ($op) {
          case 'IN': if (is_array($val)) $i = $i->whereIn($campo, $val, $bool); break;
          case 'NOT IN': case 'NOTIN': if (is_array($val)) $i = $i->whereNotIn($campo, $val); break;
          case 'NULL': case 'IS NULL': $i = $i->whereNull($campo); break;
          case 'NOT NULL': case 'IS NOT NULL': case 'NOTNULL': $i = $i->whereNotNull($campo); break;
          case 'BETWEEN': if (is_array($val) && count($val) === 2) $i = $i->whereBetween($campo, $val); break;
          case 'LIKE': $i = $i->where($campo, 'LIKE', $val, $bool); break;
          default: $i = $i->where($campo, $op, $val, $bool);
        }
      }
    }
    return $i;
  }

  // Joins
  function join($table, $first, $op = null, $second = null, $type = 'INNER') {
    $i = clone $this;
    if ($op === null) { $op = '='; $second = $first; }
    $i->joins[] = compact('type', 'table', 'first', 'op', 'second');
    return $i;
  }

  function leftJoin($table, $first, $op = null, $second = null) {
    return $this->join($table, $first, $op, $second, 'LEFT');
  }

  function rightJoin($table, $first, $op = null, $second = null) {
    return $this->join($table, $first, $op, $second, 'RIGHT');
  }

  // Order, Group
  function orderBy($col, $dir = 'ASC') {
    $i = clone $this;
    $i->orders[] = ['column' => $col, 'direction' => strtoupper($dir)];
    return $i;
  }

  function groupBy(...$cols) {
    $i = clone $this;
    $i->groups = array_merge($i->groups, $cols);
    return $i;
  }

  function having($col, $op, $val) {
    $i = clone $this;
    $i->havings[] = compact('col', 'op', 'val');
    return $i;
  }

  // Limit, Offset
  function limit($limit) {
    $i = clone $this;
    $i->limit = $limit;
    return $i;
  }

  function offset($offset) {
    $i = clone $this;
    $i->offset = $offset;
    return $i;
  }

  function take($n) { return $this->limit($n); }
  function skip($n) { return $this->offset($n); }

  // Paginación
  function paginate($page = 1, $perPage = 15) {
    return $this->offset(($page - 1) * $perPage)->limit($perPage);
  }

  // Ejecución
  function get() {
    [$sql, $binds] = $this->compileSelect();
    return $this->exec($sql, $binds)->fetchAll();
  }

  function first() {
    $res = $this->limit(1)->get();
    return $res[0] ?? null;
  }

  function find($id) {
    return $this->where('id', $id)->first();
  }

  function count() {
    $i = clone $this;
    $i->columns = ['COUNT(*) as count'];
    $res = $i->first();
    return (int)($res['count'] ?? 0);
  }

  function exists() {
    return $this->count() > 0;
  }

  function pluck($col) {
    $res = $this->select([$col])->get();
    return array_column($res, $col);
  }

  function value($col) {
    $res = $this->select([$col])->first();
    return $res[$col] ?? null;
  }

  function chunk($size, $callback) {
    $page = 1;
    do {
      $res = $this->paginate($page++, $size)->get();
      if (empty($res)) break;
      if ($callback($res) === false) break;
    } while (count($res) === $size);
  }

  // Insert
  function insert($vals) {
    return is_array(reset($vals)) ? $this->insertBatch($vals) : $this->insertSingle($vals);
  }

  protected function insertSingle($vals) {
    $cols = array_keys($vals);
    $places = array_fill(0, count($cols), '?');
    $sql = sprintf("INSERT INTO `%s` (`%s`) VALUES (%s)",
      $this->table, implode('`, `', $cols), implode(', ', $places));
    $this->exec($sql, array_values($vals));
    return $this->conn->lastInsertId();
  }

  protected function insertBatch($recs) {
    if (empty($recs)) return 0;
    $cols = array_keys($recs[0]);
    $vals = [];
    $rows = [];
    foreach ($recs as $rec) {
      $rows[] = '(' . implode(', ', array_fill(0, count($cols), '?')) . ')';
      $vals = array_merge($vals, array_values($rec));
    }
    $sql = sprintf("INSERT INTO `%s` (`%s`) VALUES %s",
      $this->table, implode('`, `', $cols), implode(', ', $rows));
    return $this->exec($sql, $vals)->rowCount();
  }

  // Update
  function update($vals) {
    $sets = [];
    $binds = [];
    foreach ($vals as $col => $val) {
      if ($val instanceof ogRawExpr) {
        $sets[] = "`$col` = $val";
      } else {
        $sets[] = "`$col` = ?";
        $binds[] = $val;
      }
    }
    [$whereSql, $whereBinds] = $this->compileWheres();
    $binds = array_merge($binds, $whereBinds);
    $sql = "UPDATE `{$this->table}` SET " . implode(', ', $sets);
    if ($whereSql) $sql .= " WHERE $whereSql";
    return $this->exec($sql, $binds)->rowCount();
  }

  // Delete
  function delete() {
    [$whereSql, $binds] = $this->compileWheres();
    $sql = "DELETE FROM `{$this->table}`";
    if ($whereSql) $sql .= " WHERE $whereSql";
    return $this->exec($sql, $binds)->rowCount();
  }

  // Compilar SELECT
  protected function compileSelect() {
    $sql = $this->distinct ? 'SELECT DISTINCT ' : 'SELECT ';
    $sql .= $this->columns === ['*'] ? '*' : implode(', ', $this->columns);
    $sql .= " FROM {$this->table}";

    if ($this->joins) {
      foreach ($this->joins as $j) {
        $sql .= " {$j['type']} JOIN `{$j['table']}` ON `{$j['first']}` {$j['op']} `{$j['second']}`";
      }
    }

    [$whereSql, $binds] = $this->compileWheres();
    if ($whereSql) $sql .= " WHERE $whereSql";

    if ($this->groups) $sql .= ' GROUP BY ' . implode(', ', $this->groups);

    if ($this->havings) {
      $havs = [];
      foreach ($this->havings as $h) {
        $havs[] = "`{$h['col']}` {$h['op']} ?";
        $binds[] = $h['val'];
      }
      $sql .= ' HAVING ' . implode(' AND ', $havs);
    }

    if ($this->orders) {
      $ords = array_map(fn($o) => "{$o['column']} {$o['direction']}", $this->orders);
      $sql .= ' ORDER BY ' . implode(', ', $ords);
    }

    if ($this->limit) {
      $sql .= " LIMIT {$this->limit}";
      if ($this->offset) $sql .= " OFFSET {$this->offset}";
    }

    return [$sql, $binds];
  }

  // Compilar WHEREs
  protected function compileWheres() {
    if (empty($this->wheres)) return ['', []];
    $sql = [];
    $binds = [];

    foreach ($this->wheres as $i => $w) {
      $bool = $i === 0 ? '' : "{$w['boolean']} ";
      $column = $w['column'];

      // Determinar si necesita backticks
      $colName = $this->formatColumnName($column);

      switch ($w['type']) {
        case 'basic':
          $sql[] = $bool . "{$colName} {$w['operator']} ?";
          $binds[] = $w['value'];
          break;
        case 'in':
          $places = implode(', ', array_fill(0, count($w['values']), '?'));
          $sql[] = $bool . "{$colName} IN ($places)";
          $binds = array_merge($binds, $w['values']);
          break;
        case 'notIn':
          $places = implode(', ', array_fill(0, count($w['values']), '?'));
          $sql[] = $bool . "{$colName} NOT IN ($places)";
          $binds = array_merge($binds, $w['values']);
          break;
        case 'null':
          $sql[] = $bool . "{$colName} IS NULL";
          break;
        case 'notNull':
          $sql[] = $bool . "{$colName} IS NOT NULL";
          break;
        case 'between':
          $sql[] = $bool . "{$colName} BETWEEN ? AND ?";
          $binds[] = $w['values'][0];
          $binds[] = $w['values'][1];
          break;
      }
    }
    return [implode(' ', $sql), $binds];
  }

  // Helpers
  function toSql() {
    [$sql] = $this->compileSelect();
    return $sql;
  }


  // Método auxiliar para formatear nombres de columna
  protected function formatColumnName($column) {
    if ($column instanceof ogRawExpr) {
      return (string)$column;
    }

    // Si ya tiene backticks o es función SQL, dejar tal cual
    if (strpos($column, '`') !== false ||
      strpos($column, '(') !== false ||
      strpos($column, ')') !== false ||
      strpos($column, '.') !== false) {
      return $column;
    }

    // Agregar backticks a nombres simples
    return "`{$column}`";
  }

  // SQL con valores interpolados (para debugging)
  function getSql() {
    [$sql, $binds] = $this->compileSelect();
    foreach ($binds as $val) {
      $r = is_null($val) ? 'NULL' : (is_numeric($val) ? $val : "'" . addslashes($val) . "'");
      $sql = preg_replace('/\?/', $r, $sql, 1);
    }
    return $sql;
  }

  function raw($sql, $binds = []) {
    return $this->exec($sql, $binds)->fetchAll();
  }

  // Ejecutar
  protected function exec($sql, $binds = []) {
    try {
      if (OG_IS_DEV) {
        $interpolated = $sql;
        foreach ($binds as $val) {
          if (is_array($val) || is_object($val)) {
            $val = json_encode($val, JSON_UNESCAPED_UNICODE);
          }
          $r = is_null($val) ? 'NULL' : (is_numeric($val) ? $val : "'" . addslashes($val) . "'");
          $interpolated = preg_replace('/\?/', $r, $interpolated, 1);
        }
      }
      $stmt = $this->conn->prepare($sql);
      $stmt->execute($binds);
      return $stmt;
    } catch (PDOException $e) {
      throw new Exception("SQL Error: {$e->getMessage()} | Query: $sql");
    }
  }

  // Transacciones
  function transaction($callback) {
    $this->conn->beginTransaction();
    try {
      $res = $callback($this);
      $this->conn->commit();
      return $res;
    } catch (Exception $e) {
      $this->conn->rollBack();
      throw $e;
    }
  }

  function getConnection() {
    return $this->conn;
  }

}

// Raw Expression
class ogRawExpr {
  protected $expr;
  function __construct($expr) { $this->expr = $expr; }
  function __toString() { return $this->expr; }
}