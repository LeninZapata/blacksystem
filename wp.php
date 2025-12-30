<?php
/**
 * Plugin Name: Blacksystem
 * Plugin URI: https://Blacksystem.com
 * Plugin ID: factory-saas
 * Description: API REST para  Blacksystem - Framework híbrido standalone/WordPress
 * Version: 1.0.0
 * Author: Factory Team
 * Author URI: https://Blacksystem.com
 * Text Domain: factory-saas
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 8.1
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// ========================================
// FUNCIÓN GET_FILE_DATA (compatible standalone/WordPress)
// ========================================

if (!function_exists('get_file_data')) {
  /**
   * Obtener datos del header del archivo (compatibilidad standalone)
   *
   * @param string $file Ruta del archivo
   * @param array $headers Headers a extraer
   * @return array Datos extraídos
   */
  function get_file_data($file, $headers = []) {
    $fileData = [];

    if (!file_exists($file)) {
      return array_fill_keys($headers, '');
    }

    // Leer primeras 8KB del archivo
    $fp = fopen($file, 'r');
    $fileContent = fread($fp, 8192);
    fclose($fp);

    // Mapeo de headers comunes
    $headerMap = [
      'Plugin Name' => 'Name',
      'Plugin URI' => 'PluginURI',
      'Description' => 'Description',
      'Version' => 'Version',
      'Author' => 'Author',
      'Author URI' => 'AuthorURI',
      'Text Domain' => 'TextDomain',
      'Domain Path' => 'DomainPath',
      'Requires at least' => 'RequiresWP',
      'Requires PHP' => 'RequiresPHP',
      'License' => 'License',
      'License URI' => 'LicenseURI',
      'Plugin ID' => 'PluginID'
    ];

    // Extraer cada header
    foreach ($headers as $header) {
      $key = $headerMap[$header] ?? $header;

      // Buscar patrón: Header: Value
      $pattern = '/^[ \t\/*#@]*' . preg_quote($header, '/') . ':(.*)$/mi';

      if (preg_match($pattern, $fileContent, $match)) {
        $fileData[$key] = trim(preg_replace('/\s*(?:\*\/|\?>).*/', '', $match[1]));
      } else {
        $fileData[$key] = '';
      }
    }

    return $fileData;
  }
}

// ========================================
// OBTENER DATOS DEL PLUGIN
// ========================================

$pluginData = get_file_data(__FILE__, [
  'Plugin Name',
  'Version',
  'Text Domain',
  'Description',
  'Author',
  'Requires PHP',
  'Plugin ID'
]);

// Variables disponibles
/*$pluginName = $pluginData['Name'];           // 'Factory SaaS API'
$pluginVersion = $pluginData['Version'];     // '1.0.0'
$pluginSlug = $pluginData['TextDomain'];     // 'factory-saas'
$pluginDescription = $pluginData['Description'];
$pluginAuthor = $pluginData['Author'];
$pluginRequiresPHP = $pluginData['RequiresPHP'];*/
$pluginID = $pluginData['PluginID'];         // 'factory-saas'

// ========================================
// WORDPRESS CHECK
// ========================================

if (!defined('ABSPATH')) {
  // Standalone mode
  define('ABSPATH', __DIR__ . '/');
}

// ========================================
// CARGAR BOOTSTRAP
// ========================================

// Cargar bootstrap (maneja todo)
require_once __DIR__ . '/backend/bootstrap.php';