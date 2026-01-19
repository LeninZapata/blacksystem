<?php
// routes/apis/credential.php
// Las rutas CRUD se auto-registran desde credential.json

$router->group('/api/credential', function($router) {
  
  // Obtener timezones de países de América + España
  // GET /api/credential/timezones
  $router->get('/timezones', function() {
    $countries = ogApp()->helper('country')::all();
    $timezones = [];
    
    foreach ($countries as $code => $country) {
      // Filtrar solo América y España
      if ($country['region'] === 'america' || $code === 'ES') {
        $timezones[] = [
          'value' => $country['timezone'],
          'label' => "{$country['name']} ({$country['offset']})",
          'country_code' => $code
        ];
      }
    }
    
    // Ordenar por offset (zona horaria)
    usort($timezones, function($a, $b) {
      return strcmp($a['value'], $b['value']);
    });
    
    ogResponse::success($timezones);
  })->middleware(['throttle:100,1']);

});