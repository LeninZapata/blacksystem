<?php
// Rutas específicas del extension webscraper
return function($router) {
  
  // Crear trabajo de scraping
  $router->post('/job/create', function() {
    $data = request::data();
    $ctrl = new scraperController();
    $ctrl->createJob($data);
  })->middleware(['auth', 'json']);

  // Ejecutar scraping
  $router->post('/job/{id}/run', function($id) {
    $ctrl = new scraperController();
    $ctrl->runJob($id);
  })->middleware('auth');

  // Obtener resultados
  $router->get('/job/{id}/results', function($id) {
    $ctrl = new scraperController();
    $ctrl->getResults($id);
  });

  // Analizar con IA
  $router->post('/analyze', function() {
    $data = request::data();
    
    // Usar servicio del extension
    $scraper = new puppeteerService();
    $content = $scraper->getContent($data['url']);
    
    // Usar servicio GENERAL del sistema
    $analysis = ai::complete("Analiza este contenido: $content");
    
    response::success(['analysis' => $analysis]);
  })->middleware('auth');
};