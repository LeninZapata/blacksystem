<?php
// app/routes/adAutoScale.php - Rutas para auto-escalado

$router->group('/api/adAutoScale', function($router) {

  // Ejecutar todas las reglas activas (CRON cada hora)
  // GET /api/adAutoScale/execute
  $router->get('/execute', function() {
    ogResponse::json(
      ogApp()->handler('AdAutoScale')::executeRules([])
    );
  })->middleware(['throttle:10,1']);

  // Ejecutar una regla específica (testing)
  // GET /api/adAutoScale/execute/{rule_id}
  $router->get('/execute/{rule_id}', function($ruleId) {
    ogResponse::json(
      ogApp()->handler('AdAutoScale')::executeRule(['rule_id' => $ruleId])
    );
  })->middleware(['auth', 'throttle:10,1']);

});