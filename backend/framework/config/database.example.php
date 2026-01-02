<?php
return [
  'host' => ogSystem::isLocalhost() ? 'localhost' : 'YOUR_PRODUCTION_HOST',
  'name' => ogSystem::isLocalhost() ? 'your_local_db' : 'YOUR_PRODUCTION_DB',
  'user' => ogSystem::isLocalhost() ? 'root' : 'YOUR_PRODUCTION_USER',
  'pass' => ogSystem::isLocalhost() ? '' : 'YOUR_PRODUCTION_PASSWORD',
  'charset' => 'utf8mb4'
];