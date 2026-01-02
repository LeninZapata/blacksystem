<?php
// routes/apis/faker.php

$router->group('/api/faker', function($router) {

  // ==========================================
  // CLIENTES
  // ==========================================

  // Generar clientes fake - GET /api/faker/clients?num=50
  $router->get('/clients', function() {
    $params = [
      'num' => ogRequest::query('num', 50)
    ];
    ogResponse::json(ogApp()->handler('faker')::generateClients($params));
  })->middleware(OG_IS_DEV ? [] : ['auth']);

  // Limpiar clientes fake - DELETE /api/faker/clients
  $router->delete('/clients', function() {
    ogResponse::json(ogApp()->handler('faker')::cleanClients());
  })->middleware(OG_IS_DEV ? [] : ['auth']);

  // ==========================================
  // CHATS
  // ==========================================

  // Generar chats fake - GET /api/faker/chats
  $router->get('/chats', function() {
    ogResponse::json(ogApp()->handler('faker')::generateChats([]));
  })->middleware(OG_IS_DEV ? [] : ['auth']);

  // Limpiar chats fake - DELETE /api/faker/chats
  $router->delete('/chats', function() {
    ogResponse::json(ogApp()->handler('faker')::cleanChats());
  })->middleware(OG_IS_DEV ? [] : ['auth']);

  // ==========================================
  // VENTAS
  // ==========================================

  // Generar ventas fake - GET /api/faker/sales?num=50
  $router->get('/sales', function() {
    $params = [
      'num' => ogRequest::query('num', 50),
      'start_date' => ogRequest::query('start_date', date('Y-m-01')),
      'end_date' => ogRequest::query('end_date', date('Y-m-t'))
    ];
    ogResponse::json(ogApp()->handler('faker')::generateSales($params));
  })->middleware(OG_IS_DEV ? [] : ['auth']);

  // Limpiar ventas fake - DELETE /api/faker/sales
  $router->delete('/sales', function() {
    ogResponse::json(ogApp()->handler('faker')::cleanSales());
  })->middleware(OG_IS_DEV ? [] : ['auth']);

});

// ============================================
// ENDPOINTS DISPONIBLES:
//
// CLIENTES:
// - GET    /api/faker/clients              -> Genera clientes (num, start_date, end_date)
// - DELETE /api/faker/clients              -> Limpia clientes
//
// CHATS:
// - GET    /api/faker/chats                -> Genera chats (1-3 por cliente)
// - DELETE /api/faker/chats                -> Limpia chats
//
// VENTAS:
// - GET    /api/faker/sales                -> Genera ventas (num, start_date, end_date)
// - DELETE /api/faker/sales                -> Limpia ventas
//
// EJEMPLOS:
// - /api/faker/clients?num=100             -> 100 clientes distribuidos
// - /api/faker/chats                       -> Chats para todos los clientes
// - /api/faker/sales?num=50                -> 50 ventas distribuidas
// ============================================