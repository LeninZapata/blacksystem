<?php
// framework/lang/es/middleware.php
return [
  'throttle_limit_exceeded' => 'Límite de peticiones excedido. Intenta de nuevo más tarde.',
  'throttle' => [
    'too_many_requests' => 'Demasiadas peticiones. Intenta en {retry_after} segundos',
    'limit_exceeded' => 'Límite de peticiones excedido',
  ],

  'auth' => [
    'token_missing' => 'Token de autenticación no proporcionado (desde framework/middleware)',
    'token_invalid' => 'Token de autenticación inválido',
    'token_expired' => 'Token de autenticación expirado',
    'token_not_found' => 'Token no encontrado',
    'session_corrupted' => 'Sesión corrupta',
    'token_mismatch' => 'Token no coincide',
    'token_expired_deleted' => 'Token expirado eliminado',
    'unauthorized' => 'No autorizado (desde Auth)',
    'session_required' => 'Sesión requerida',
    'session_expired' => 'Sesión expirada',
    'php_version_required' => 'Se requiere PHP :required o superior. Versión actual: :current',
  ],

  'json' => [
    'invalid_json' => 'JSON inválido en el cuerpo de la petición',
    'parse_error' => 'Error al parsear JSON',
    'content_type_required' => 'Content-Type: application/json requerido',
  ],

  'cors' => [
    'origin_not_allowed' => 'Origen no permitido',
    'method_not_allowed' => 'Método no permitido',
  ],

  'log' => [
    'request_logged' => 'Petición registrada',
  ],

  'dev' => [
    'access_denied' => 'Acceso denegado. Esta ruta solo está disponible en localhost',
  ],

];