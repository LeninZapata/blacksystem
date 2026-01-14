<?php
return [
  'host' => ogIsLocalhost() ? 'localhost' : 'YOUR_PRODUCTION_HOST',
  'name' => ogIsLocalhost() ? 'your_local_db' : 'YOUR_PRODUCTION_DB',
  'user' => ogIsLocalhost() ? 'root' : 'YOUR_PRODUCTION_USER',
  'pass' => ogIsLocalhost() ? '' : 'YOUR_PRODUCTION_PASSWORD',
  'charset' => 'utf8mb4'
];