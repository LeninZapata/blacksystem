<?php
// Configuración de base de datos según entorno

define('DB_HOST', isLocalhost() ? 'localhost' : 'localhost');
define('DB_NAME', isLocalhost() ? 'blacksystem' : 'kviocppc_blacksystem');
define('DB_USER', isLocalhost() ? 'root' : 'kviocppc_blacksystem_admin');
define('DB_PASS', isLocalhost() ? '' : 'Lenin266*');
define('DB_CHARSET', 'utf8mb4');
