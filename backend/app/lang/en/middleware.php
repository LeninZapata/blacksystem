<?php
// app/lang/en/middleware.php
return [
  'auth' => [
    'token_missing' => 'Token not provided',
    'token_invalid' => 'Invalid token',
    'token_expired' => 'Token expired',
    'token_not_found' => 'Token not found',
    'session_corrupted' => 'Corrupted session',
    'token_mismatch' => 'Token mismatch',
    'token_expired_deleted' => 'Expired token deleted'
  ],
  'json' => [
    'content_type_required' => 'Content-Type must be application/json',
    'invalid_json' => 'Invalid JSON: {error}'
  ],
  'throttle' => [
    'too_many_requests' => 'Too many requests. Limit: {max} requests per {minutes} minute(s)'
  ]
];