<?php
// routes/apis/bot.php
// Las rutas CRUD (create, update, delete, list, show) se auto-registran desde bot.json

$router->group('/api/bot', function($router) {

  // Aquí puedes agregar rutas personalizadas si las necesitas en el futuro
  // Ejemplo:
  // $router->get('/active', function() {
  //   $bots = db::table('bots')->where('status', 'active')->get();
  //   response::success($bots);
  // })->middleware('auth');

});

// ============================================
// NOTA: Las rutas CRUD se registran automáticamente desde bot.json:
// - GET    /api/bot           (list)    ← Auto-registrada
// - GET    /api/bot/{id}      (show)    ← Auto-registrada
// - POST   /api/bot           (create)  ← Auto-registrada
// - PUT    /api/bot/{id}      (update)  ← Auto-registrada
// - DELETE /api/bot/{id}      (delete)  ← Auto-registrada
// ============================================
