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

// Paginación
$users = db::table('user')->paginate(1, 20)->get();

// Relaciones
db::table('user')
  ->join('client', 'user.id', '=', 'client.user_id')
  ->where('user.status', 'active')
  ->get();

// Debug - Ver SQL que se ejecutará (con valores escapados)
$sql = db::table('user')
  ->where('role', 'admin')
  ->where('status', 'active')
  ->getSql();
// Output: SELECT * FROM `user` WHERE `role` = 'admin' AND `status` = 'active'
```

Ver: `/framework/helpers/db.php`