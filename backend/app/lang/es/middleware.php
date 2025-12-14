<?php
// app/lang/es/middleware.php
return [
  'auth' => [
    'token_missing' => 'Token no proporcionado (desde middleware)',
    'token_invalid' => 'Token inválido',
    'token_expired' => 'Token expirado',
    'token_not_found' => 'Token no encontrado',
    'session_corrupted' => 'Sesión corrupta',
    'token_mismatch' => 'Token no coincide',
    'token_expired_deleted' => 'Token expirado eliminado'
  ],
  'json' => [
    'content_type_required' => 'Content-Type debe ser application/json',
    'invalid_json' => 'JSON inválido: {error}'
  ],
  'throttle' => [
    'too_many_requests' => 'Demasiadas peticiones. Límite: {max} requests por {minutes} minuto(s)'
  ]
];