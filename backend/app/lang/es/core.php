<?php
// app/lang/es/core.php - Actualizado con service
return [
  'resource' => [
    'not_found' => 'Recurso no encontrado'
  ],
  'controller' => [
    'resource_not_found' => 'Recurso \'{resource}\' no encontrado',
    'not_found' => '{resource} no encontrado',
    'created' => '{resource} creado',
    'updated' => '{resource} actualizado',
    'deleted' => '{resource} eliminado',
    'field_exists' => '{field} ya existe'
  ],
  'extension' => [
    'not_found' => 'Extensión \'{extension}\' no encontrada',
    'disabled' => 'Extensión \'{extension}\' está deshabilitada',
    'no_backend' => 'Extensión \'{extension}\' no tiene backend'
  ],
  'router' => [
    'not_found' => 'Ruta no encontrada',
    'method_not_found' => 'Método {method} no existe en las rutas',
    'middleware_not_found' => 'Middleware \'{middleware}\' no encontrado',
    'middleware_file_not_found' => 'Archivo de middleware \'{middleware}.php\' no encontrado',
    'middleware_class_not_found' => 'Clase middleware \'{middleware}\' no encontrada',
    'invalid_handler' => 'Handler inválido',
    'controller_not_found' => 'Controlador \'{controller}\' no encontrado',
    'method_not_found_in_controller' => 'Método \'{method}\' no existe en el controlador \'{controller}\''
  ],
  'autoload' => [
    'class_not_found' => 'Clase \'{class}\' no encontrada',
    'class_not_found_message' => 'La clase <code>{class}</code> no pudo ser encontrada',
    'error_title' => 'Error del Sistema',
    'server_error_title' => 'Error del Servidor',
    'server_error_message' => 'Ha ocurrido un error interno. Por favor, contacte al administrador.'
  ],
  'service' => [
    'integration' => [
      'method_required' => 'El segundo parámetro debe ser el nombre del método (ej: \'detect\', \'normalize\')',
      'data_required' => 'Se requieren datos para detectar provider',
      'provider_not_detected' => 'No se pudo detectar el provider para categoría \'{category}\'',
      'method_not_implemented' => 'Método \'{method}\' no implementado. Usa \'detect\' o la clase directa del servicio',
      'file_not_found' => 'Archivo no encontrado: {file}',
      'class_not_found' => 'Clase no encontrada: {class}',
      'method_not_exists' => 'Método \'{method}\' no existe en {class}'
    ]
  ]
];