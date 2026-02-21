<?php
// app/routes/adAutoScale.php - Rutas para auto-escalado

$router->group('/api/adAutoScale', function($router) {

  // Ejecutar reglas con time_range="today" (CRON HORARIO - cada hora)
  // GET /api/adAutoScale/execute
  $router->get('/execute', function() {
    ogResponse::json(
      ogApp()->handler('AdAutoScale')::executeRules([])
    );
  })->middleware(['throttle:10,1']);

  // Ejecutar reglas históricas (CRON DIARIO - 2-3 AM)
  // GET /api/adAutoScale/execute-daily
  $router->get('/execute-daily', function() {
    ogResponse::json(
      ogApp()->handler('AdAutoScale')::executeDailyRules([])
    );
  })->middleware(['throttle:10,1']);

  // Ejecutar una regla específica (testing)
  // GET /api/adAutoScale/execute/{rule_id}
  $router->get('/execute/{rule_id}', function($ruleId) {
    ogResponse::json(
      ogApp()->handler('AdAutoScale')::executeRule(['rule_id' => $ruleId])
    );
  })->middleware(['auth', 'throttle:10,1']);

  // ============================================
  // ESTADÍSTICAS
  // ============================================

  // Obtener cambios de presupuesto por activo
  // GET /api/adAutoScale/stats/budget-changes?asset_id=11&range=last_7_days
  $router->get('/stats/budget-changes', function() {
    ogResponse::json(
      ogApp()->handler('AdAutoScaleStats')::getBudgetChanges([
        'asset_id' => ogRequest::query('asset_id'),
        'range' => ogRequest::query('range', 'today'),
        'user_id' => ogRequest::query('user_id') // Solo para testing sin auth
      ])
    );
  })->middleware(['auth', 'throttle:100,1']);

  // Obtener cambios de presupuesto agrupados por día
  // GET /api/adAutoScale/stats/budget-changes-daily?asset_id=11&range=last_7_days
  $router->get('/stats/budget-changes-daily', function() {
    ogResponse::json(
      ogApp()->handler('AdAutoScaleStats')::getBudgetChangesByDay([
        'asset_id' => ogRequest::query('asset_id'),
        'range' => ogRequest::query('range', 'last_7_days'),
        'user_id' => ogRequest::query('user_id') // Solo para testing sin auth
      ])
    );
  })->middleware(['auth', 'throttle:100,1']);

  // Obtener reseteos de presupuesto por día
  // GET /api/adAutoScale/stats/budget-resets-daily?asset_id=14&range=last_7_days
  $router->get('/stats/budget-resets-daily', function() {
    ogResponse::json(
      ogApp()->handler('AdAutoScaleStats')::getBudgetResetsByDay([
        'asset_id' => ogRequest::query('asset_id'),
        'range' => ogRequest::query('range', 'last_7_days'),
        'user_id' => ogRequest::query('user_id') // Solo para testing sin auth
      ])
    );
  })->middleware(['auth', 'throttle:100,1']);

  // Resetear presupuestos diarios (CRON cada hora)
  // GET /api/adAutoScale/reset-budgets
  $router->get('/reset-budgets', function() {
    ogResponse::json(
      ogApp()->handler('AdAutoScale')::resetDailyBudgets([])
    );
  })->middleware(['throttle:10,1']);

  // Ajustar presupuesto manualmente
  // POST /api/adAutoScale/adjust-budget
  $router->post('/adjust-budget', function() {
    $data = ogRequest::data();
    ogResponse::json(
      ogApp()->handler('AdAutoScale')::adjustBudget($data)
    );
  })->middleware(['auth', 'json', 'throttle:20,1']);

  // Clonar una regla de escala
  // POST /api/adAutoScale/clone
  $router->post('/clone', function() {
    $data = ogRequest::data();
    ogResponse::json(
      ogApp()->handler('AdAutoScale')::clone($data)
    );
  })->middleware(['auth', 'json', 'throttle:20,1']);

});