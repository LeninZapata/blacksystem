# controller - Controlador base con CRUD automático

Controlador genérico que implementa CRUD completo basado en schemas JSON.

## Uso

```php
// Crear controller personalizado
class UserController extends controller {
  function __construct() {
    parent::__construct('user'); // Carga user.json
  }
  
  // Override métodos si necesitas lógica custom
  function create() {
    $data = request::data();
    // Lógica personalizada...
    parent::create(); // O llamar al padre
  }
}
```

## Métodos disponibles

- `list()` - Listar con paginación y filtros
- `show($id)` - Obtener por ID
- `create()` - Crear nuevo
- `update($id)` - Actualizar
- `delete($id)` - Eliminar

Ver: `/framework/core/controller.php`
