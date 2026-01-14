<?php
// routeDiscovery - Descubrir todos los endpoints del sistema
class ogRouteDiscovery {

  // Obtener todos los endpoints del sistema
  static function getAllRoutes() {
    $routes = [];

    // 1. Rutas desde JSON (resources)
    $routes = array_merge($routes, self::getResourceRoutes());

    // 2. Rutas manuales (routes/)
    $routes = array_merge($routes, self::getManualRoutes());

    // 3. Rutas de extensiones
    $routes = array_merge($routes, self::getExtensionRoutes());

    // Ordenar por path
    usort($routes, function($a, $b) {
      return strcmp($a['path'], $b['path']);
    });

    return $routes;
  }

  // Obtener rutas desde archivos JSON
  // Obtener rutas desde archivos JSON
  private static function getResourceRoutes() {
    $routes = [];

    // Buscar en framework primero, luego en app
    $resourceDirs = [
      OG_FRAMEWORK_PATH . '/resources/schemas',
      ogApp()->getPath() . '/resources/schemas'
    ];

    foreach ($resourceDirs as $resourcesDir) {
      if (!is_dir($resourcesDir)) continue;

      foreach (scandir($resourcesDir) as $file) {
        if ($file === '.' || $file === '..' || !str_ends_with($file, '.json')) continue;

        $resourceName = str_replace('.json', '', $file);
        $configFile = $resourcesDir . '/' . $file;
        $config = json_decode(file_get_contents($configFile), true);

        if (!$config) continue;

        // Rutas CRUD estándar
        $crudRoutes = [
          'list'   => ['GET',    "/api/{$resourceName}",      'List all'],
          'show'   => ['GET',    "/api/{$resourceName}/{id}", 'Get by ID'],
          'create' => ['POST',   "/api/{$resourceName}",      'Create new'],
          'update' => ['PUT',    "/api/{$resourceName}/{id}", 'Update by ID'],
          'delete' => ['DELETE', "/api/{$resourceName}/{id}", 'Delete by ID']
        ];

        foreach ($crudRoutes as $key => $routeData) {
          list($method, $path, $description) = $routeData;

          $routeConfig = $config['routes'][$key] ?? [];

          // Si está deshabilitada, saltarla
          if (isset($routeConfig['enabled']) && $routeConfig['enabled'] === false) {
            continue;
          }

          // Obtener ejemplo si existe en la config
          $example = null;
          if (isset($routeConfig['example'])) {
            $example = is_string($routeConfig['example'])
              ? $routeConfig['example']
              : json_encode($routeConfig['example'], JSON_UNESCAPED_UNICODE);
          }

          $routes[] = [
            'method' => $method,
            'path' => $path,
            'description' => $description,
            'example' => $example,
            'middleware' => self::resolveMiddlewareForResourceRoute($resourceName, $key),
            'source' => "resource:{$resourceName}",
            'type' => 'crud'
          ];
        }
      }
    }

    return $routes;
  }
  // Obtener rutas manuales de routes/
  private static function getManualRoutes() {
    $routes = [];

    // Buscar en framework y app
    $apisDirs = [
      OG_FRAMEWORK_PATH . '/routes',
      ogApp()->getPath() . '/routes'
    ];

    foreach ($apisDirs as $apisDir) {
      if (!is_dir($apisDir)) continue;

      foreach (scandir($apisDir) as $file) {
        if ($file === '.' || $file === '..' || !str_ends_with($file, '.php')) continue;

        $module = str_replace('.php', '', $file);
        $filePath = $apisDir . '/' . $file;

        // Parsear el archivo PHP para extraer rutas
        $content = file_get_contents($filePath);
        $GLOBALS['__routeDiscovery_content'] = $content;
        $parsedRoutes = self::parsePhpRoutes($content, $module);

        $routes = array_merge($routes, $parsedRoutes);
      }
    }

    return $routes;
  }
  // Parsear archivo PHP para extraer rutas
  private static function parsePhpRoutes($content, $module) {
    $routes = [];
    $foundPaths = []; // Para evitar duplicados

    // Extraer grupos usando conteo de llaves
    $groups = self::extractGroups($content);

    // Procesar rutas dentro de cada grupo
    foreach ($groups as $group) {
      $prefix = $group['prefix'];
      $groupContent = $group['content'];

      // Buscar rutas dentro del grupo
      preg_match_all(
        '/\$router->(get|post|put|delete)\(\[?[\'\"]([^\'\"]+)[\'\"]\]?/',
        $groupContent,
        $innerMatches,
        PREG_SET_ORDER
      );

      foreach ($innerMatches as $match) {
        $method = strtoupper($match[1]);
        $relativePath = $match[2];
        $fullPath = $prefix . $relativePath;
        $routeKey = $method . ':' . $fullPath;

        // Evitar duplicados SOLO por path completo
        if (isset($foundPaths[$routeKey])) continue;
        $foundPaths[$routeKey] = true;

        // Extraer descripción del comentario antes de la ruta
        $description = self::extractDescriptionFromGroup($groupContent, $relativePath);

        // Extraer ejemplo del comentario @example
        $example = self::extractExample($groupContent, $relativePath);

        $routes[] = [
          'method' => $method,
          'path' => $fullPath,
          'description' => $description,
          'example' => $example,
          'middleware' => self::resolveMiddlewareForManualRoute($module, $method, $fullPath),
          'source' => "manual:{$module}",
          'type' => 'manual'
        ];
      }
    }

    // Buscar rutas directas (fuera de grupos)
    preg_match_all(
      '/\\$router->(get|post|put|delete)\\([\'\"]([^\'\"]+)[\'\"]/ ',
      $content,
      $directMatches,
      PREG_SET_ORDER
    );

    foreach ($directMatches as $match) {
      $method = strtoupper($match[1]);
      $path = $match[2];
      $routeKey = $method . ':' . $path;
      // Si ya existe una ruta con el mismo método y path (por ejemplo, con prefijo de grupo), NO agregar la versión sin prefijo
      if (isset($foundPaths[$routeKey])) continue;

      // Buscar si existe una ruta igual pero con algún prefijo de grupo
      $alreadyWithPrefix = false;
      foreach ($foundPaths as $existingKey => $v) {
        if (strpos($existingKey, $method . ':') === 0 && substr($existingKey, -strlen($path)) === $path && $existingKey !== $routeKey) {
          $alreadyWithPrefix = true;
          break;
        }
      }
      if ($alreadyWithPrefix) continue;
      $foundPaths[$routeKey] = true;

      $description = self::extractDescription($content, $path);

      // Extraer ejemplo del comentario @example
      $example = self::extractExample($content, $path);

      $routes[] = [
        'method' => $method,
        'path' => $path,
        'description' => $description,
        'example' => $example,
        'middleware' => self::resolveMiddlewareForManualRoute($module, $method, $path),
        'source' => "manual:{$module}",
        'type' => 'manual'
      ];
    }

    return $routes;
  }

  // Extraer grupos usando conteo de llaves para capturar correctamente el contenido completo
  private static function extractGroups($content) {
    $groups = [];
    $pattern = '/\$router->group\([\'\"]([^\'\"]+)[\'\"]/';
    if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
      foreach ($matches[0] as $index => $match) {
        $prefix = $matches[1][$index][0];
        $startPos = $match[1];
        $funcStart = strpos($content, '{', $startPos);
        if ($funcStart === false) continue;
        // Contar llaves para encontrar el final del grupo
        $braceCount = 1;
        $pos = $funcStart + 1;
        $length = strlen($content);
        while ($pos < $length && $braceCount > 0) {
          if ($content[$pos] === '{') $braceCount++;
          if ($content[$pos] === '}') $braceCount--;
          $pos++;
        }
        // Extraer el contenido del grupo
        $groupContent = substr($content, $funcStart + 1, $pos - $funcStart - 2);
        $groups[] = [
          'prefix' => $prefix,
          'content' => $groupContent
        ];
      }
    }
    return $groups;
  }

  // Extraer descripción del comentario
  private static function extractDescription($content, $path) {
    // Buscar comentario // antes de la ruta (solo la línea inmediata anterior)
    $escapedPath = preg_quote($path, '/');
    $pattern = '/\/\/\s*([^\n]+)\s*\n\s*\$router->[a-z]+\([^\)]*?' . $escapedPath . '/i';

    if (preg_match($pattern, $content, $match)) {
      $description = trim($match[1]);
      // Limpiar emojis y caracteres especiales del inicio
      $description = preg_replace('/^[^\w\s]+\s*/', '', $description);
      // Eliminar indicadores de método HTTP redundantes
      $description = preg_replace('/^(GET|POST|PUT|DELETE|PATCH)\s+\/\S+\s*-?\s*/i', '', $description);
      return trim($description);
    }

    return '';
  }

  // Extraer descripción dentro de un grupo
  private static function extractDescriptionFromGroup($groupContent, $path) {
    $escapedPath = preg_quote($path, '/');
    // Buscar el comentario inmediatamente antes de la ruta (solo la última línea de comentario)
    $pattern = '/\/\/\s*([^\n]+)\s*\n\s*\$router->[a-z]+\([^\)]*?' . $escapedPath . '/i';

    if (preg_match($pattern, $groupContent, $match)) {
      $description = trim($match[1]);
      // Limpiar emojis y caracteres especiales del inicio
      $description = preg_replace('/^[^\w\s]+\s*/', '', $description);
      // Eliminar indicadores de método HTTP redundantes (GET, POST, etc.)
      $description = preg_replace('/^(GET|POST|PUT|DELETE|PATCH)\s+\/\S+\s*-?\s*/i', '', $description);
      return trim($description);
    }

    return '';
  }

  // Extraer middleware de la ruta
  private static function extractMiddleware($content, $path) {
    $escapedPath = preg_quote($path, '/');
    // 1. Buscar middleware directo (array o string)
    $patternDirect = '/' . $escapedPath . '[^;]+->middleware\((\[.*?\]|[\'\"][^\'\"]+[\'\"])/s';
    if (stripos($path, 'user') !== false) {
      ogLog::debug('extractMiddleware: [USER] debug antes de patrón directo', [
        'content' => $content,
        'escapedPath' => $escapedPath,
        'path' => $path,
        'patternDirect' => $patternDirect
      ], ['module' => 'routeDiscovery']);
    }
    $matched = preg_match($patternDirect, $content, $match);
    if (stripos($path, 'user') !== false) {
      ogLog::debug('extractMiddleware: [USER] resultado preg_match', [
        'matched' => $matched,
        'match' => $match ?? null,
        'path' => $path
      ], ['module' => 'routeDiscovery']);
    }
    if ($matched) {
      $mwRaw = $match[1];
      if (stripos($path, 'user') !== false) {
        ogLog::debug('extractMiddleware: [USER] valor extraído', [
          'mwRaw' => $mwRaw,
          'path' => $path
        ], ['module' => 'routeDiscovery']);
      }
      // Si es array: ['json', 'auth']
      if (preg_match('/^\[.*\]$/s', $mwRaw)) {
        preg_match_all('/[\'\"]([^\'\"]+)[\'\"]/',$mwRaw, $allMatches);
        if (stripos($path, 'user') !== false) {
          ogLog::debug('extractMiddleware: [USER] array extraído', [
            'allMatches' => $allMatches[1],
            'path' => $path
          ], ['module' => 'routeDiscovery']);
        }
        return $allMatches[1];
      }
      // Si es string: 'auth' o "auth"
      $mw = trim($mwRaw, "'\" ");
      if (stripos($path, 'user') !== false) {
        ogLog::debug('extractMiddleware: [USER] string extraído', [
          'mw' => $mw,
          'path' => $path
        ], ['module' => 'routeDiscovery']);
      }
      return [$mw];
    }

    // 2. Buscar middleware por variable (ej: ->middleware($middleware))
    $patternVar = '/' . $escapedPath . '[^;]+->middleware\((\$[a-zA-Z0-9_]+)\)/s';
    if (preg_match($patternVar, $content, $matchVar)) {
      $varName = $matchVar[1];
      ogLog::debug('extractMiddleware: patrón variable', ['pattern' => $patternVar, 'match' => $matchVar, 'varName' => $varName, 'path' => $path], ['module' => 'routeDiscovery']);
      // Buscar definición de la variable (ej: $middleware = ...;)
      $varPattern = '/'.preg_quote($varName, '/').'\s*=\s*([^;]+);/';
      if (preg_match($varPattern, $content, $varMatch)) {
        $value = trim($varMatch[1]);
        ogLog::debug('extractMiddleware: valor de variable', ['varPattern' => $varPattern, 'varMatch' => $varMatch, 'value' => $value], ['module' => 'routeDiscovery']);
        // Si es array: ['auth'] o ["auth"]
        if (preg_match('/\[(.*?)\]/', $value, $arrMatch)) {
          $arr = explode(',', $arrMatch[1]);
          return array_map(function($v) {
            return trim($v, "'\" ");
          }, $arr);
        }
        // Si es string: 'auth' o "auth"
        if (preg_match('/^[\'"](.*?)[\'"]$/', $value, $strMatch)) {
          return [$strMatch[1]];
        }
      } else {
        ogLog::debug('extractMiddleware: variable no encontrada', ['varPattern' => $varPattern, 'varName' => $varName], ['module' => 'routeDiscovery']);
      }
    }
    ogLog::debug('extractMiddleware: sin middleware', ['path' => $path], ['module' => 'routeDiscovery']);
    return [];
  }

  // Extraer middleware dentro de un grupo
  private static function extractMiddlewareFromGroup($groupContent, $path) {
    $escapedPath = preg_quote($path, '/');
    $pattern = '/' . $escapedPath . '[^;]+->middleware\((\[.*?\]|[\'\"][^\'\"]+[\'\"])/s';
    if (preg_match($pattern, $groupContent, $match)) {
      $mwRaw = $match[1];
      // Si es array: ['json', 'auth']
      if (preg_match('/^\[.*\]$/s', $mwRaw)) {
        preg_match_all('/[\'\"]([^\'\"]+)[\'\"]/',$mwRaw, $allMatches);
        return $allMatches[1];
      }
      // Si es string: 'auth' o "auth"
      $mw = trim($mwRaw, "'\" ");
      return [$mw];
    }
    // Buscar middleware por variable (->middleware($middleware))
    $patternVar = '/' . $escapedPath . '[^;]+->middleware\((\$[a-zA-Z0-9_]+)\)/s';
    if (preg_match($patternVar, $groupContent, $matchVar)) {
      $varName = $matchVar[1];
      // Buscar definición de la variable dentro del grupo
      $varPattern = '/'.preg_quote($varName, '/').'\s*=\s*([^;]+);/';
      $found = false;
      $value = null;
      if (preg_match($varPattern, $groupContent, $varMatch)) {
        $value = trim($varMatch[1]);
        $found = true;
      } else if (isset($GLOBALS['__routeDiscovery_content'])) {
        // Buscar en el contenido completo del archivo si no está en el grupo
        if (preg_match($varPattern, $GLOBALS['__routeDiscovery_content'], $varMatch2)) {
          $value = trim($varMatch2[1]);
          $found = true;
        }
      }
      if ($found && $value !== null) {
        // Si es array: ['auth'] o ["auth"]
        if (preg_match('/\[(.*?)\]/', $value, $arrMatch)) {
          $arr = explode(',', $arrMatch[1]);
          return array_map(function($v) {
            return trim($v, "'\" ");
          }, $arr);
        }
        // Si es string: 'auth' o "auth"
        if (preg_match('/^[\'\"](.*?)[\'\"]$/', $value, $strMatch)) {
          return [$strMatch[1]];
        }
      }
    }
    return [];
  }

  // Obtener rutas de extensiones
  private static function getExtensionRoutes() {
    $routes = [];
    $extensionsDir = ogApp()->getPath() . '/extensions';

    if (!is_dir($extensionsDir)) return $routes;

    foreach (scandir($extensionsDir) as $extension) {
      if ($extension === '.' || $extension === '..') continue;

      $configFile = $extensionsDir . '/' . $extension . '/extension.json';
      if (!file_exists($configFile)) continue;

      $config = json_decode(file_get_contents($configFile), true);

      // Solo extensiones habilitados con backend
      if (!($config['enabled'] ?? false) || !($config['backend'] ?? false)) {
        continue;
      }

      $prefix = $config['routes_prefix'] ?? "/extension/{$extension}";

      // Rutas desde resources del extension
      $resourcesDir = $extensionsDir . '/' . $extension . '/resources';
      if (is_dir($resourcesDir)) {
        foreach (scandir($resourcesDir) as $file) {
          if ($file === '.' || $file === '..' || !str_ends_with($file, '.json')) continue;

          $resourceConfig = json_decode(file_get_contents($resourcesDir . '/' . $file), true);
          if (!$resourceConfig) continue;

          $resourceName = str_replace('.json', '', $file);

          // CRUD routes del extension
          $crudRoutes = [
            'list'   => ['GET',    "{$prefix}/{$resourceName}",      'List all'],
            'show'   => ['GET',    "{$prefix}/{$resourceName}/{id}", 'Get by ID'],
            'create' => ['POST',   "{$prefix}/{$resourceName}",      'Create new'],
            'update' => ['PUT',    "{$prefix}/{$resourceName}/{id}", 'Update by ID'],
            'delete' => ['DELETE', "{$prefix}/{$resourceName}/{id}", 'Delete by ID']
          ];

          foreach ($crudRoutes as $key => $routeData) {
            list($method, $path, $description) = $routeData;

            $routes[] = [
              'method' => $method,
              'path' => $path,
              'description' => $description,
              'middleware' => $resourceConfig['middleware'] ?? [],
                'middleware' => [],
              'type' => 'crud'
            ];
          }
        }
      }

      // Rutas manuales del extension
      $routesFile = $extensionsDir . '/' . $extension . '/routes/routes.php';
      if (file_exists($routesFile)) {
        $content = file_get_contents($routesFile);
        $GLOBALS['__routeDiscovery_content'] = $content;
        $parsedRoutes = self::parsePhpRoutes($content, "extension:{$extension}");

        // Agregar prefijo a las rutas
        foreach ($parsedRoutes as &$route) {
          if (!str_starts_with($route['path'], $prefix)) {
            $route['path'] = $prefix . $route['path'];
          }
          $route['source'] = "extension:{$extension}";
        }

        $routes = array_merge($routes, $parsedRoutes);
      }
    }

    return $routes;
  }

  // Agrupar rutas por módulo/recurso
  static function groupByResource($routes) {
    $grouped = [];

    foreach ($routes as $route) {
      $source = $route['source'];
      if (!isset($grouped[$source])) {
        $grouped[$source] = [];
      }
      $grouped[$source][] = $route;
    }

    return $grouped;
  }

  // Estadísticas
  static function getStats($routes) {
    $stats = [
      'total' => count($routes),
      'by_method' => [],
      'by_source' => [],
      'by_type' => [],
        'with_auth' => 0
    ];

    foreach ($routes as $route) {
      // Por método
      $method = $route['method'];
      $stats['by_method'][$method] = ($stats['by_method'][$method] ?? 0) + 1;

      // Por source
      $source = explode(':', $route['source'])[0];
      $stats['by_source'][$source] = ($stats['by_source'][$source] ?? 0) + 1;

      // Por tipo
      $type = $route['type'];
      $stats['by_type'][$type] = ($stats['by_type'][$type] ?? 0) + 1;


    }

    return $stats;
  }

    // Obtiene los middleware globales y específicos de cada ruta desde el schema
  private static function resolveMiddlewareForResourceRoute($resourceName, $routeKey) {
    $schemasDir = ogApp()->getPath() . '/resources/schemas';
    $schemaFile = $schemasDir . '/' . $resourceName . '.json';
    $middlewares = [];
    if (file_exists($schemaFile)) {
      $config = json_decode(file_get_contents($schemaFile), true);
      // Middleware global (cabecera)
      if (isset($config['middleware']) && is_array($config['middleware'])) {
        $middlewares = array_merge($middlewares, $config['middleware']);
      }
      // Middleware específico de la ruta
      if (isset($config['routes'][$routeKey]['middleware']) && is_array($config['routes'][$routeKey]['middleware'])) {
        $middlewares = array_merge($middlewares, $config['routes'][$routeKey]['middleware']);
      }
    }
    return $middlewares;
  }

  // Busca middlewares definidos en el archivo de rutas manuales para rutas tipo manual
  private static function resolveMiddlewareForManualRoute($module, $method, $path) {
    $apisDir = ogApp()->getPath() . '/routes';
    $filePath = $apisDir . '/' . $module . '.php';
    $middlewares = [];
    if (!file_exists($filePath)) return $middlewares;
    $content = file_get_contents($filePath);
    // Buscar coincidencia de ruta y método con middleware (path completo)
    $escapedPath = preg_quote($path, '/');
    $pattern = '/\\$router->' . strtolower($method) . '\\s*\\(\\s*[\'\"]' . $escapedPath . '[\'\"]\\s*[,\)](.+?)?->middleware\\(([^;]+)\\)/s';
    $found = false;
    if (preg_match($pattern, $content, $match)) {
      $mwRaw = trim($match[2]);
      // Si es variable: $middleware
      if (preg_match('/^\$([a-zA-Z0-9_]+)$/', $mwRaw, $varMatch)) {
        $varName = $varMatch[1];
        $middlewares = array_merge($middlewares, self::resolveMiddlewareVariable($module, $varName));
      } else if (preg_match('/^\s*\[.*\]\s*$/s', $mwRaw)) {
        $middlewares = array_merge($middlewares, self::parsePhpArrayString($mwRaw));
      } else {
        // Si es string: 'auth' o "auth"
        $val = trim($mwRaw, "'\" ");
        if ($val !== '') $middlewares[] = $val;
      }
      $found = true;
    }
    // Si no se encontró, intentar buscar usando el path relativo (sin prefijo de grupo)
    if (!$found && strpos($path, '/') !== false) {
      $parts = explode('/', $path);
      // Si el path tiene más de 2 partes (ej: /api/auth/login), probar con la última parte (ej: /login)
      if (count($parts) > 2) {
        $relativePath = '/' . end($parts);
        $escapedRelPath = preg_quote($relativePath, '/');
        $patternRel = '/\\$router->' . strtolower($method) . '\\s*\\(\\s*[\'\"]' . $escapedRelPath . '[\'\"]\\s*[,\)](.+?)?->middleware\\(([^;]+)\\)/s';
        if (preg_match($patternRel, $content, $matchRel)) {
          $mwRaw = trim($matchRel[2]);
          if (preg_match('/^\$([a-zA-Z0-9_]+)$/', $mwRaw, $varMatch)) {
            $varName = $varMatch[1];
            $middlewares = array_merge($middlewares, self::resolveMiddlewareVariable($module, $varName));
          } else if (preg_match('/^\s*\[.*\]\s*$/s', $mwRaw)) {
            $middlewares = array_merge($middlewares, self::parsePhpArrayString($mwRaw));
          } else {
            $val = trim($mwRaw, "'\" ");
            if ($val !== '') $middlewares[] = $val;
          }
        }
      }
    }
    return $middlewares;
  }

    // Resuelve el valor de una variable middleware definida en el archivo PHP
  private static function resolveMiddlewareVariable($module, $varName) {
    $apisDir = ogApp()->getPath() . '/routes';
    $filePath = $apisDir . '/' . $module . '.php';
    if (!file_exists($filePath)) return [];
    $content = file_get_contents($filePath);
    $varPattern = '/\$' . preg_quote($varName, '/') . '\s*=\s*([^;]+);/';
    if (preg_match($varPattern, $content, $match)) {
      $value = trim($match[1]);
      // Si es expresión ternaria: OG_IS_DEV ? [] : ['auth','other']
      if (preg_match('/\?\s*\[.*?\]\s*:\s*(\[.*?\])/', $value, $ternaryMatch)) {
        $arrayPart = $ternaryMatch[1];
        return self::parsePhpArrayString($arrayPart);
      }
      // Si es array: ['auth'] o ["auth"]
      if (preg_match('/\[.*\]/', $value)) {
        return self::parsePhpArrayString($value);
      }
      // Si es string: 'auth' o "auth"
      if (preg_match('/^[\'\"](.*?)[\'\"]$/', $value, $strMatch)) {
        $val = $strMatch[1];
        return $val !== '' ? [$val] : [];
      }
    } else {
    }
    return [];
  }

      // Utilidad: convierte array PHP de comillas simples a dobles y lo decodifica como JSON
      private static function parsePhpArrayString($arrayString) {
        // Reemplazar comillas simples por dobles solo en los valores del array
        $json = preg_replace_callback(
          "/'([^']*)'/",
          function($m) { return '"' . $m[1] . '"'; },
          $arrayString
        );
        $result = json_decode($json, true);
        return is_array($result) ? $result : [];
      }

  // Extraer ejemplo del comentario @example
  private static function extractExample($content, $path) {
    $escapedPath = preg_quote($path, '/');

    // Buscar la posición del router con este path
    $pattern = '/\$router->(get|post|put|delete)\s*\(\s*[\[\'\"]' . $escapedPath . '[\]\'\"]?/';

    if (!preg_match($pattern, $content, $match, PREG_OFFSET_CAPTURE)) {
      return null;
    }

    $routerPos = $match[0][1];

    // Buscar hacia atrás desde la posición del router (máximo 500 caracteres antes)
    $searchStart = max(0, $routerPos - 500);
    $beforeRouter = substr($content, $searchStart, $routerPos - $searchStart);

    // Buscar @example en el texto antes del router
    // Patrón 1: // @example {...}
    if (preg_match('/\/\/\s*@example\s+(\{[^\n]+\})\s*$/m', $beforeRouter, $exampleMatch)) {
      return trim($exampleMatch[1]);
    }

    // Patrón 2: /* @example ... */
    if (preg_match('/\/\*\s*@example\s+(.*?)\*\//s', $beforeRouter, $exampleMatch)) {
      return trim($exampleMatch[1]);
    }

    return null;
  }
}