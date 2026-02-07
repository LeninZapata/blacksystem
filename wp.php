<?php
/**
 * Plugin Name: Backsystem
 * Plugin URI: https://factorysaas.com
 * Plugin ID: blacksystem
 * Plugin prefix: bs
 * Description: Sistema de ventas automatizado.
 * Version: 0.4
 * Author: Lenin Zapata
 * Author URI: https://blacksystem.site
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Detectar entorno
$isWP = defined('ABSPATH') && function_exists('add_action');

// Definir paths según entorno
$thePluginPath = $isWP ? plugin_dir_path(__FILE__) : __DIR__;
$thePluginUrl  = $isWP ? plugin_dir_url(__FILE__) : '';

// Definir ABSPATH si no está definido (para wp and standalone)
if (!defined('ABSPATH')) {  define('ABSPATH', $thePluginPath . '/');  }

// Cargar funciones auxiliares de nivel 1
require_once __DIR__ . '/funcs.php';

// Obtener datos del plugin
$pluginData = get_file_data(__FILE__, [  'Plugin Name', 'Version', 'Text Domain',  'Description', 'Author', 'Requires PHP',  'Plugin ID', 'Plugin prefix']);

// Si es una petición API en modo standalone, cargar bootstrap y terminar
if (!$isWP && isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/api/') !== false) {
  // Cargar bootstrap (maneja todo incluyendo api.php)
  require_once $thePluginPath . '/backend/bootstrap.php';
  exit; // Importante: detener ejecución después de procesar API
}

// Si es WordPress, registrar hooks normalmente
if ($isWP) {
  // Cargar bootstrap para WordPress
  require_once $thePluginPath . '/backend/bootstrap.php';

  // Aquí irían tus hooks de WordPress si los necesitas
  // add_action('init', function() { ... });
}