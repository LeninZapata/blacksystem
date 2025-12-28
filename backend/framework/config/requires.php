<?php
// Cargar helpers necesarios del framework

// Cargar ogApp primero (Singleton principal)
require_once OG_FRAMEWORK_PATH . '/core/ogApp.php';

// Helpers críticos que se usan en TODAS las requests
require_once OG_FRAMEWORK_PATH . '/helpers/ogLang.php';
require_once OG_FRAMEWORK_PATH . '/helpers/ogLog.php';
require_once OG_FRAMEWORK_PATH . '/helpers/ogResponse.php';
require_once OG_FRAMEWORK_PATH . '/helpers/ogRequest.php';
require_once OG_FRAMEWORK_PATH . '/helpers/ogDb.php';

// Core classes necesarias para routing
require_once OG_FRAMEWORK_PATH . '/core/ogController.php';

// Configuración de debug
require_once OG_FRAMEWORK_PATH . '/config/debug.php';

// Cargar clase ogApplication
require_once OG_FRAMEWORK_PATH . '/core/ogApplication.php';