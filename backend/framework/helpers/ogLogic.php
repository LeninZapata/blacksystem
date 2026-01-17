<?php

class OgLogic
{
  private static $customOps = [];

  private static function getOp($logic)
  {
    return array_keys($logic)[0];
  }

  private static function getValues($logic, $fixUnary = true)
  {
    $op = self::getOp($logic);
    $values = $logic[$op];

    if ($fixUnary && (!is_array($values) || self::isLogic($values))) {
      $values = [$values];
    }
    return $values;
  }

  private static function isLogic($array)
  {
    return (
      is_array($array) &&
      count($array) === 1 &&
      is_string(self::getOp($array))
    );
  }

  private static function truthy($logic)
  {
    if ($logic === "0") return true;
    return (bool)$logic;
  }

  public static function apply($logic = [], $data = [])
  {
    if (is_object($logic)) {
      $logic = (array)$logic;
    }

    if (!self::isLogic($logic)) {
      if (is_array($logic)) {
        return array_map(function ($l) use ($data) {
          return self::apply($l, $data);
        }, $logic);
      }
      return $logic;
    }

    $ops = [
      '==' => function ($a, $b) { return $a == $b; },
      '===' => function ($a, $b) { return $a === $b; },
      '!=' => function ($a, $b) { return $a != $b; },
      '!==' => function ($a, $b) { return $a !== $b; },
      '>' => function ($a, $b) { return $a > $b; },
      '>=' => function ($a, $b) { return $a >= $b; },
      '<' => function ($a, $b, $c = null) {
        if ($c === null) return $a < $b;
        return ($a < $b) && ($b < $c);
      },
      '<=' => function ($a, $b, $c = null) {
        if ($c === null) return $a <= $b;
        return ($a <= $b) && ($b <= $c);
      },
      '%' => function ($a, $b) { return $a % $b; },
      '!!' => function ($a) { return self::truthy($a); },
      '!' => function ($a) { return !self::truthy($a); },
      'log' => function ($a) {
        error_log($a);
        return $a;
      },
      'var' => function ($a = null, $default = null) use ($data) {
        if ($a === null || $a === "") return $data;
        
        foreach (explode('.', $a) as $prop) {
          if ((is_array($data) || $data instanceof ArrayAccess) && isset($data[$prop])) {
            $data = $data[$prop];
          } elseif (is_object($data) && isset($data->{$prop})) {
            $data = $data->{$prop};
          } else {
            return $default;
          }
        }
        return $data;
      },
      'missing' => function () use ($data) {
        $values = func_get_args();
        if (!self::isLogic($values) && isset($values[0]) && is_array($values[0])) {
          $values = $values[0];
        }

        $missing = [];
        foreach ($values as $dataKey) {
          $value = self::apply(['var' => $dataKey], $data);
          if ($value === null || $value === "") {
            array_push($missing, $dataKey);
          }
        }
        return $missing;
      },
      'missing_some' => function ($min, $opts) use ($data) {
        $areMissing = self::apply(['missing' => $opts], $data);
        if (count($opts) - count($areMissing) >= $min) {
          return [];
        }
        return $areMissing;
      },
      'in' => function ($a, $b) {
        if (is_array($b)) return in_array($a, $b);
        if (is_string($b)) return strpos($b, $a) !== false;
        return false;
      },
      'cat' => function () {
        return implode("", func_get_args());
      },
      'max' => function () {
        return max(func_get_args());
      },
      'min' => function () {
        return min(func_get_args());
      },
      '+' => function () {
        return array_sum(func_get_args());
      },
      '-' => function ($a, $b = null) {
        if ($b === null) return -$a;
        return $a - $b;
      },
      '/' => function ($a, $b) {
        return $a / $b;
      },
      '*' => function () {
        return array_reduce(func_get_args(), function ($a, $b) {
          return $a * $b;
        }, 1);
      },
      'merge' => function () {
        return array_reduce(func_get_args(), function ($a, $b) {
          return array_merge((array)$a, (array)$b);
        }, []);
      },
      'substr' => function () {
        return call_user_func_array('substr', func_get_args());
      }
    ];

    $op = self::getOp($logic);
    $values = self::getValues($logic);

    // Operadores con evaluación lazy
    if ($op === 'if' || $op == '?:') {
      for ($i = 0; $i < count($values) - 1; $i += 2) {
        if (self::truthy(self::apply($values[$i], $data))) {
          return self::apply($values[$i + 1], $data);
        }
      }
      if (count($values) === $i + 1) {
        return self::apply($values[$i], $data);
      }
      return null;
    } elseif ($op === 'and') {
      foreach ($values as $value) {
        $current = self::apply($value, $data);
        if (!self::truthy($current)) {
          return $current;
        }
      }
      return $current;
    } elseif ($op === 'or') {
      foreach ($values as $value) {
        $current = self::apply($value, $data);
        if (self::truthy($current)) {
          return $current;
        }
      }
      return $current;
    } elseif ($op === "filter") {
      $scopedData = self::apply($values[0], $data);
      $scopedLogic = $values[1];

      if (!$scopedData || !is_array($scopedData)) return [];

      return array_values(
        array_filter($scopedData, function ($datum) use ($scopedLogic) {
          return self::truthy(self::apply($scopedLogic, $datum));
        })
      );
    } elseif ($op === "map") {
      $scopedData = self::apply($values[0], $data);
      $scopedLogic = $values[1];

      if (!$scopedData || !is_array($scopedData)) return [];

      return array_map(
        function ($datum) use ($scopedLogic) {
          return self::apply($scopedLogic, $datum);
        },
        $scopedData
      );
    } elseif ($op === "reduce") {
      $scopedData = self::apply($values[0], $data);
      $scopedLogic = $values[1];
      $initial = isset($values[2]) ? self::apply($values[2], $data) : null;

      if (!$scopedData || !is_array($scopedData)) return $initial;

      return array_reduce(
        $scopedData,
        function ($acc, $curr) use ($scopedLogic) {
          return self::apply(
            $scopedLogic,
            ['current' => $curr, 'accumulator' => $acc]
          );
        },
        $initial
      );
    } elseif ($op === "all") {
      $scopedData = self::apply($values[0], $data);
      $scopedLogic = $values[1];

      if (!$scopedData || !is_array($scopedData)) return false;

      $filtered = array_filter($scopedData, function ($datum) use ($scopedLogic) {
        return self::truthy(self::apply($scopedLogic, $datum));
      });
      return count($filtered) === count($scopedData);
    } elseif ($op === "none") {
      $filtered = self::apply(['filter' => $values], $data);
      return count($filtered) === 0;
    } elseif ($op === "some") {
      $filtered = self::apply(['filter' => $values], $data);
      return count($filtered) > 0;
    }

    if (isset(self::$customOps[$op])) {
      $operation = self::$customOps[$op];
    } elseif (isset($ops[$op])) {
      $operation = $ops[$op];
    } else {
      throw new Exception("Operador no reconocido: $op");
    }

    // Recursión
    $values = array_map(function ($value) use ($data) {
      return self::apply($value, $data);
    }, $values);

    return call_user_func_array($operation, $values);
  }

  public static function addOp($name, $callable)
  {
    self::$customOps[$name] = $callable;
  }

  public static function usesData($logic)
  {
    if (is_object($logic)) {
      $logic = (array)$logic;
    }
    $collection = [];

    if (self::isLogic($logic)) {
      $op = array_keys($logic)[0];
      $values = (array)$logic[$op];

      if ($op === "var") {
        $collection[] = $values[0];
      } else {
        foreach ($values as $value) {
          $collection = array_merge($collection, self::usesData($value));
        }
      }
    }

    return array_unique($collection);
  }
}