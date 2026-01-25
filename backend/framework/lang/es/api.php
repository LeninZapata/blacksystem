<?php
// framework/lang/es/api.php
return [
  'error' => 'Error en la solicitud',
  'success' => 'Operación exitosa',
  'invalid_json' => 'JSON inválido',
  'invalid_json_response' => 'Respuesta JSON inválida',
  'unauthorized' => 'No autorizado (desde API)',
  'forbidden' => 'Acceso prohibido',
  'too_many_requests' => 'Demasiadas peticiones',
  'server_error' => 'Error interno del servidor (desde API): {message}',
  'php_warning_detected' => 'Error o advertencia de PHP detectado',
  'not_found' => 'Recurso no encontrado',
  'bad_request' => 'Solicitud incorrecta',
  'created' => 'Recurso creado exitosamente',
  'updated' => 'Recurso actualizado exitosamente',
  'deleted' => 'Recurso eliminado exitosamente',
  'invalid_parameters' => 'Parámetros inválidos',
  'cleanup' => [
    'path_invalid' => 'Path no válido',
    'only_storage_shared' => 'Solo se permiten rutas storage/ o shared/',
    'missing_subfolder' => 'Debe especificar una subcarpeta a eliminar',
    'folder_not_found' => 'Carpeta no encontrada',
    'cannot_delete_root' => 'No se puede eliminar la carpeta raíz',
    'deleted_successfully' => 'Carpeta {path} eliminada correctamente',
    'error_deleting' => 'Error al eliminar la carpeta'
  ],
  'session'=> [
    'invalidated' => '{count} sesiones API invalidadas'
  ]
];
