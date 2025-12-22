<?php
// Configuración de base de datos del proyecto
return [
  'host' => isLocalhost() ? 'localhost' : '',
  'name' => isLocalhost() ? 'blacksystem' : '',
  'user' => isLocalhost() ? 'root' : '',
  'pass' => isLocalhost() ? '' : '',
  'charset' => 'utf8mb4'
];