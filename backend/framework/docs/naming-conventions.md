# Convenciones de nombres

## Clases

### Framework (utility classes)
- **lowercase** → Helpers y Core: `db`, `log`, `str`, `request`, `response`
- **camelCase** → Compound helpers: `logReader`, `sessionCleanup`, `routeDiscovery`

### Aplicación (instanciables)
- **PascalCase** → Controllers, Handlers, Models: `UserController`, `AuthHandler`, `ClientHandler`

**Ejemplos:**

```php
// Framework (utility)
db::table('user')->get();
log::info('mensaje');
str::normalize('text');

// App (instanciables)  
$ctrl = new UserController();
AuthHandler::login($params);
```

## Archivos

- **camelCase.php** → Framework core/helpers: `db.php`, `logReader.php`
- **PascalCase.php** → App resources: `UserController.php`, `AuthHandler.php`
- **kebab-case.json** → Configs y schemas: `user.json`, `database-example.php`

## Métodos

- **camelCase** → Todos los métodos: `getUserById()`, `validateEmail()`, `sendMessage()`

## Variables

- **camelCase** → Variables locales: `$userId`, `$totalAmount`
- **SCREAMING_SNAKE_CASE** → Constantes: `DB_HOST`, `SESSION_TTL`, `IS_DEV`
