<?php
$is_localhost = ogIsLocalhost();
// Configuración de base de datos del proyecto
return [
  'host' => $is_localhost ? 'localhost' : 'localhost',
  'name' => $is_localhost ? 'blacksystem' : 'kviocppc_blacksystem',
  'user' => $is_localhost ? 'root' : 'kviocppc_blacksystem_admin',
  'pass' => $is_localhost ? '' : 'Lenin266*',
  'charset' => 'utf8mb4'
];