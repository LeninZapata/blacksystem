<?php
// routes/cleanup.php - Endpoints de limpieza de carpetas

$router->group('/api/cleanup', function($router) {

  $logMeta = ['module' => 'cleanup', 'layer' => 'app'];

  // Eliminar carpeta del sistema
  $router->get('/{path:.*}', function($path) use ($logMeta) {
    // Validación de seguridad: no permitir ..
    if (strpos($path, '..') !== false) {
      ogLog::warning("Intento de acceso no autorizado con path: {$path}", null, $logMeta);
      ogResponse::json(['success' => false, 'error' => __('api.cleanup.path_invalid')], 403);
      return;
    }

    // Normalizar path (remover slashes duplicados)
    $path = preg_replace('#/+#', '/', $path);
    $path = trim($path, '/');

    // Validar que empiece con storage/ o shared/
    if (strpos($path, 'storage/') !== 0 && strpos($path, 'shared/') !== 0) {
      ogResponse::json([
        'success' => false,
        'error' => __('api.cleanup.only_storage_shared'),
        'received' => $path,
        'expected' => 'storage/{folder} o shared/{folder}'
      ], 400);
      return;
    }

    // Determinar ruta base según prefijo
    if (strpos($path, 'storage/') === 0) {
      // storage/cache -> backend/app/storage/cache
      $relativePath = substr($path, 8); // remover "storage/"
      $fullPath = ogApp()->getPath('storage') . '/' . $relativePath;
      $base = 'storage';
    } else {
      // shared/bots -> base/shared/bots
      $relativePath = substr($path, 7); // remover "shared/"
      $fullPath = ogApp()->getPath('shared') . '/' . $relativePath;
      $base = 'shared';
    }

    // Validar que no sea la carpeta raíz de storage o shared
    if ($relativePath === '' || $relativePath === '/' || trim($relativePath) === '') {
      ogResponse::json([
        'success' => false,
        'error' => __('api.cleanup.missing_subfolder'),
        'received' => $path,
        'hint' => 'Debe especificar una subcarpeta, ej: storage/cache o shared/bots'
      ], 400);
      return;
    }

    // Validar que la carpeta existe
    if (!is_dir($fullPath)) {
      ogResponse::json([
        'success' => false,
        'error' => __('api.cleanup.folder_not_found'),
        'path' => $path,
        'full_path' => $fullPath
      ], 404);
      return;
    }

    // Contar archivos antes de eliminar
    $fileCount = 0;
    $dirCount = 0;

    $countFiles = function($dir) use (&$countFiles, &$fileCount, &$dirCount) {
      if (!is_dir($dir)) return;

      $items = scandir($dir);
      foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;

        $itemPath = $dir . '/' . $item;
        if (is_dir($itemPath)) {
          $dirCount++;
          $countFiles($itemPath);
        } else {
          $fileCount++;
        }
      }
    };

    $countFiles($fullPath);

    // Función recursiva para eliminar
    $removeDirectory = function($dir) use (&$removeDirectory) {
      if (!is_dir($dir)) return false;

      $items = scandir($dir);
      foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;

        $itemPath = $dir . '/' . $item;
        if (is_dir($itemPath)) {
          $removeDirectory($itemPath);
        } else {
          unlink($itemPath);
        }
      }

      return rmdir($dir);
    };

    try {
      $success = $removeDirectory($fullPath);

      if ($success) {
        ogLog::info("Carpeta eliminada: {$path}", [
          'files' => $fileCount,
          'directories' => $dirCount,
          'base' => $base
        ], $logMeta);

        ogResponse::success([
          'path' => $path,
          'files_deleted' => $fileCount,
          'directories_deleted' => $dirCount,
          'total_items' => $fileCount + $dirCount
        ], __('api.cleanup.deleted_successfully', ['path' => $path]));
      } else {
        ogLog::error("No se pudo eliminar la carpeta: {$path}", null, $logMeta);
        ogResponse::serverError(__('api.cleanup.error_deleting'));
      }
    } catch (Exception $e) {
      ogLog::error("Error eliminando carpeta: {$path}", $e->getMessage(), $logMeta);
      ogResponse::serverError(__('api.cleanup.error_deleting'), OG_IS_DEV ? $e->getMessage() : null);
    }
  });

});
