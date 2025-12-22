<?php
// framework/lang/es/core.php
return [
  'autoload' => [
    'class_not_found' => 'Clase no encontrada: {class}',
    'class_not_found_message' => 'La clase <code>{class}</code> no fue encontrada.',
    'error_title' => 'Error de Autoload',
    'server_error_title' => 'Error del Servidor',
    'server_error_message' => 'Ocurrió un error interno. Por favor contacte al administrador.',
  ],
  
  'router' => [
    'not_found' => 'Ruta no encontrada: {path}',
    'method_not_found' => 'Método {method} no permitido',
    'controller_not_found' => 'Controlador no encontrado: {controller}',
    'method_not_found_in_controller' => 'Método {method} no encontrado en {controller}',
    'invalid_handler' => 'Handler inválido',
    'middleware_not_found' => 'Middleware no encontrado: {middleware}',
    'middleware_file_not_found' => 'Archivo de middleware no encontrado: {middleware}',
    'middleware_class_not_found' => 'Clase de middleware no encontrada: {middleware}',
  ],
  
  'controller' => [
    'resource_not_found' => 'Recurso no encontrado: {resource}',
    'not_found' => '{resource} no encontrado',
    'created' => '{resource} creado exitosamente',
    'updated' => '{resource} actualizado exitosamente',
    'deleted' => '{resource} eliminado exitosamente',
    'field_exists' => 'El campo {field} ya existe',
  ],
  
  'extension' => [
    'not_found' => 'Extensión no encontrada: {extension}',
    'disabled' => 'Extensión deshabilitada: {extension}',
    'no_backend' => 'Extensión sin backend: {extension}',
    'loaded' => 'Extensión cargada: {extension}',
  ],
  
  'resource' => [
    'not_found' => 'Recurso no encontrado',
    'config_missing' => 'Configuración de recurso faltante',
  ],

  'service' => [
    'not_found' => 'Servicio no encontrado: {category}',
    'class_not_found' => 'Clase no existe: {class}',
    'method_not_found' => 'Método no existe: {class}::{method}',
  ],
];
