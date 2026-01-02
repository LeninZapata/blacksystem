<?php
// Configuración de base de datos del proyecto
return [
  'host' => ogSystem::isLocalhost() ? 'localhost' : '',
  'name' => ogSystem::isLocalhost() ? 'blacksystem' : '',
  'user' => ogSystem::isLocalhost() ? 'root' : '',
  'pass' => ogSystem::isLocalhost() ? '' : '',
  'charset' => 'utf8mb4'
];