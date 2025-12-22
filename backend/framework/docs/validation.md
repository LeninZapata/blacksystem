# validation - Validación de datos

Helper para validar datos de entrada.

## Uso

```php
// Email
validation::email('user@example.com'); // true/false

// Campos requeridos
$result = validation::required($data, ['user', 'pass', 'email']);
// Returns: ['valid' => bool, 'errors' => [...]]

// Teléfono, URL, numérico
validation::phone('+593987654321');
validation::url('https://example.com');
validation::numeric('123');
validation::range(50, 1, 100);

// Sanitización
$clean = validation::sanitizeText($input);
$email = validation::sanitizeEmail($input);
```

Ver: `/framework/helpers/validation.php`
