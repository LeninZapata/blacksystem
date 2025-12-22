<?php
// routeDiscovery - Descubrir todos los endpoints del sistema
class routeDiscovery {

  // Obtener todos los endpoints del sistema
  static function getAllRoutes() {
    $routes = [];

    // 1. Rutas desde JSON (resources)
    $routes = array_merge($routes, self::getResourceRoutes());

    // 2. Rutas manuales (routes/apis/)
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
  private static function getResourceRoutes() {
    $routes = [];
    $resourcesDir = BACKEND_PATH . '/resources';

    if (!is_dir($resourcesDir)) return $routes;

    foreach (scandir($resourcesDir) as $file) {
      if ($file === '.' || $file === '..' || !str_ends_with($file, '.json')) continue;

      $resourceName = str_replace('.json', '', $file);
      $configFile = $resourcesDir . '/' . $file;
      $config = json_decode(file_get_contents($configFile), true);

      if (!$config) continue;

      $globalMw = $config['middleware'] ?? [];

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

        $routeMw = array_merge($globalMw, $routeConfig['middleware'] ?? []);

        $routes[] = [
          'method' => $method,
          'path' => $path,
          'description' => $description,
          'middleware' => $routeMw,
          'source' => "resource:{$resourceName}",
          'type' => 'crud'
        ];
      }
    }

    return $routes;
  }

  // Obtener rutas manuales de routes/apis/
  private static function getManualRoutes() {
    $routes = [];
    $apisDir = ROUTES_PATH . '/apis';

    if (!is_dir($apisDir)) return $routes;

    foreach (scandir($apisDir) as $file) {
      if ($file === '.' || $file === '..' || !str_ends_with($file, '.php')) continue;

      $module = str_replace('.php', '', $file);
      $filePath = $apisDir . '/' . $file;

      // Parsear el archivo PHP para extraer rutas
      $content = file_get_contents($filePath);
      $parsedRoutes = self::parsePhpRoutes($content, $module);

      $routes = array_merge($routes, $parsedRoutes);
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
        '/\$router->(get|post|put|delete)\(\[?[\'"]([^\'"]+)[\'"]\]?/',
        $groupContent,
        $innerMatches,
        PREG_SET_ORDER
      );

      foreach ($innerMatches as $match) {
        $method = strtoupper($match[1]);
        $relativePath = $match[2];
        $fullPath = $prefix . $relativePath;
        $routeKey = $method . ':' . $fullPath;

        // Evitar duplicados
        if (isset($foundPaths[$routeKey])) continue;
        $foundPaths[$routeKey] = true;
        
        // También marcar el path relativo para evitar que se procese como ruta directa
        $foundPaths[$method . ':' . $relativePath] = true;

        // Extraer descripción del comentario antes de la ruta
        $description = self::extractDescriptionFromGroup($groupContent, $relativePath);

        // Extraer middleware
        $middleware = self::extractMiddlewareFromGroup($groupContent, $relativePath);

        $routes[] = [
          'method' => $method,
          'path' => $fullPath,
          'description' => $description,
          'middleware' => $middleware,
          'source' => "manual:{$module}",
          'type' => 'manual'
        ];
      }
    }

    // Buscar rutas directas (fuera de grupos)
    preg_match_all(
      '/\$router->(get|post|put|delete)\([\'"]([^\'"]+)[\'"]/',
      $content,
      $directMatches,
      PREG_SET_ORDER
    );

    foreach ($directMatches as $match) {
      $method = strtoupper($match[1]);
      $path = $match[2];
      
      // Solo agregar si no fue procesada como parte de un grupo
      $routeKey = $method . ':' . $path;
      if (isset($foundPaths[$routeKey])) continue;
      
      $foundPaths[$routeKey] = true;

      $description = self::extractDescription($content, $path);
      $middleware = self::extractMiddleware($content, $path);

      $routes[] = [
        'method' => $method,
        'path' => $path,
        'description' => $description,
        'middleware' => $middleware,
        'source' => "manual:{$module}",
        'type' => 'manual'
      ];
    }

    return $routes;
  }

  // Extraer grupos usando conteo de llaves para capturar correctamente el contenido completo
  private static function extractGroups($content) {
    $groups = [];
    $pattern = '/\$router->group\([\'"]([^\'"]+)[\'"]/';
    
    if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
      foreach ($matches[0] as $index => $match) {
        $prefix = $matches[1][$index][0];
        $startPos = $match[1];
        
        // Encontrar el inicio de la función (después del '{')
        $funcStart = strpos($content, '{', $startPos);
        if ($funcStart === false) continue;
        
        // Contar llaves para encontrar el final del grupo
        $braceCount = 1;
        $pos = $funcStart + 1;
        $length = strlen($content);
        
        while ($pos < $length && $braceCount > 0) {
          $char = $content[$pos];
          if ($char === '{') {
            $braceCount++;
          } elseif ($char === '}') {
            $braceCount--;
          }
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
    $pattern = '/' . $escapedPath . '[^;]+->middleware\(\[?[\'"]([^\'"]+)[\'"]/s';
    
    if (preg_match($pattern, $content, $match)) {
      $mw = str_replace(['[', ']', "'", '"', ' '], '', $match[1]);
      return array_filter(explode(',', $mw));
    }
    
    return [];
  }

  // Extraer middleware dentro de un grupo
  private static function extractMiddlewareFromGroup($groupContent, $path) {
    $escapedPath = preg_quote($path, '/');
    $pattern = '/' . $escapedPath . '[^;]+->middleware\(\[?[\'"]([^\'"]+)[\'"]/s';
    
    if (preg_match($pattern, $groupContent, $match)) {
      $mw = str_replace(['[', ']', "'", '"', ' '], '', $match[1]);
      return array_filter(explode(',', $mw));
    }
    
    return [];
  }

  // Obtener rutas de extensiones
  private static function getExtensionRoutes() {
    $routes = [];
    $extensionsDir = EXTENSIONS_PATH;

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
              'source' => "extension:{$extension}",
              'type' => 'crud'
            ];
          }
        }
      }

      // Rutas manuales del extension
      $routesFile = $extensionsDir . '/' . $extension . '/routes/routes.php';
      if (file_exists($routesFile)) {
        $content = file_get_contents($routesFile);
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
      'with_auth' => 0,
      'with_middleware' => 0
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

      // Con auth
      if (in_array('auth', $route['middleware'])) {
        $stats['with_auth']++;
      }

      // Con middleware
      if (!empty($route['middleware'])) {
        $stats['with_middleware']++;
      }
    }

    return $stats;
  }
}