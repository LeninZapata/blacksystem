# http - Cliente HTTP con cURL

Cliente HTTP simple para hacer requests a APIs externas.

## Uso

```php
// GET
$response = http::get('https://api.example.com/users');

// POST
$response = http::post('https://api.example.com/users', [
  'name' => 'John',
  'email' => 'john@example.com'
], [
  'headers' => ['Authorization: Bearer token123']
]);

// PUT, DELETE
http::put($url, $data, $options);
http::delete($url, $options);

// Response format
// [
//   'success' => true/false,
//   'data' => [...],
//   'httpCode' => 200,
//   'error' => null
// ]
```

Ver: `/framework/helpers/http.php`
