# db - Query Builder Minimalista

Query builder fluido para MySQL con soporte de relaciones, paginación y transacciones.

## Uso básico

```php
// Select
$users = db::table('user')->where('role', 'admin')->get();
$user = db::table('user')->find(1);

// Insert
$id = db::table('user')->insert(['user' => 'john', 'pass' => '...']);

// Update
db::table('user')->where('id', 1)->update(['email' => 'new@mail.com']);

// Delete
db::table('user')->where('id', 1)->delete();

// WhereIn - Buscar por múltiples valores
$users = db::table('user')->whereIn('id', [1, 5, 10, 15])->get();
$products = db::table('product')->whereIn('category', ['electronics', 'books'])->get();

// WhereFilters - Filtros dinámicos avanzados (★ MUY ÚTIL)
// Soporta: =, >, <, >=, <=, LIKE, IN, NOT IN, NULL, NOT NULL, BETWEEN
$filters = [
  ['status', '=', 'active'],           // Simple
  ['age', '>=', 18],                   // Mayor o igual
  ['name', 'LIKE', '%john%'],          // Búsqueda parcial
  ['role', 'IN', ['admin', 'editor']], // Múltiples valores
  ['deleted_at', 'NULL'],              // Es nulo
  ['price', 'BETWEEN', [100, 500]]     // Rango
];
$users = db::table('user')->whereFilters($filters)->get();

// WhereFilters con array asociativo (key => value)
$filters = [
  'status' => 'active',
  'country' => 'EC'
];
$users = db::table('user')->whereFilters($filters)->get();

// Paginación
$users = db::table('user')->paginate(1, 20)->get();

// Relaciones
db::table('user')
  ->join('client', 'user.id', '=', 'client.user_id')
  ->where('user.status', 'active')
  ->get();
```

## Métodos útiles (shortcuts)

```php
// first() - Obtener primer resultado
$user = db::table('user')->where('email', 'john@example.com')->first();
// Returns: ['id' => 1, 'email' => 'john@example.com', ...] o null

// find() - Buscar por ID
$user = db::table('user')->find(5);
// Equivalente a: where('id', 5)->first()

// count() - Contar registros
$total = db::table('user')->where('status', 'active')->count();
// Returns: 42

// exists() - Verificar si existe
$exists = db::table('user')->where('email', 'test@example.com')->exists();
// Returns: true/false

// pluck() - Obtener array de una columna
$emails = db::table('user')->where('role', 'admin')->pluck('email');
// Returns: ['admin1@example.com', 'admin2@example.com', ...]

// value() - Obtener un solo valor
$name = db::table('user')->where('id', 1)->value('name');
// Returns: 'John Doe'

// take() / skip() - Aliases de limit/offset
$users = db::table('user')->skip(10)->take(5)->get();
// Equivalente a: offset(10)->limit(5)

// distinct() - Valores únicos
$countries = db::table('user')->distinct()->pluck('country');
```

## Debug

```php
// getSql() - Ver SQL que se ejecutará (con valores escapados)
$sql = db::table('user')
  ->where('role', 'admin')
  ->where('status', 'active')
  ->getSql();
// Output: SELECT * FROM `user` WHERE `role` = 'admin' AND `status` = 'active'

// toSql() - Ver SQL sin valores (con placeholders ?)
$sql = db::table('user')->where('id', 1)->toSql();
// Output: SELECT * FROM `user` WHERE `id` = ?
```

Ver: `/framework/helpers/db.php`