<?php
$is_localhost = ogSystem::isLocalhost();
// Configuración de base de datos del proyecto
return [
  'host' => $is_localhost ? 'localhost' : '',
  'name' => $is_localhost ? 'blacksystem' : '',
  'user' => $is_localhost ? 'root' : '',
  'pass' => $is_localhost ? '' : '',
  'charset' => 'utf8mb4'
];