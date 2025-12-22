<?php
return [
  'host' => isLocalhost() ? 'localhost' : 'YOUR_PRODUCTION_HOST',
  'name' => isLocalhost() ? 'your_local_db' : 'YOUR_PRODUCTION_DB',
  'user' => isLocalhost() ? 'root' : 'YOUR_PRODUCTION_USER',
  'pass' => isLocalhost() ? '' : 'YOUR_PRODUCTION_PASSWORD',
  'charset' => 'utf8mb4'
];