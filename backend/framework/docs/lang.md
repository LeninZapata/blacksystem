# lang - Sistema de internacionalización

Helper para traducciones multi-idioma con dot notation.

## Uso

```php
// Cargar idioma (se hace automáticamente en execute.php)
lang::load('es');

// Obtener traducción
__('auth.login.success');
// Output: "Inicio de sesión exitoso"

// Con variables
__('user.created', ['name' => 'Juan']);
// Output: "Usuario Juan creado exitosamente"

// Dot notation
__('services.ai.no_services_available');
__('middleware.auth.token_missing');
```

**Estructura:** `/framework/lang/es.php` (framework) + `/app/lang/es.php` (app)

Ver: `/framework/helpers/lang.php`
