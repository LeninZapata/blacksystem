# response - Manejo estandarizado de respuestas JSON

Helper para enviar respuestas HTTP/JSON consistentes.

## Uso

```php
// Éxito
response::success(['user' => $user], 'Usuario creado', 201);
// Output: {"success":true, "message":"Usuario creado", "data":{...}}

// Error
response::error('Usuario no encontrado', 404);
// Output: {"success":false, "error":"Usuario no encontrado"}

// Validación
response::validation(['email' => 'Email inválido']);

// Shortcuts
response::notFound();
response::unauthorized();
response::forbidden();
response::serverError('Error interno', $debug);
```

Ver: `/framework/helpers/response.php`
