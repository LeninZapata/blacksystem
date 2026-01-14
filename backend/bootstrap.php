<?php
// Inicialización compartida

// Validar versión de PHP requerida (antes de cargar cualquier cosa)
$phpValid = ogValidatePhpVersion($pluginData['RequiresPHP'] ?? '8.1', $isWP);

// Si falla en WordPress, no continuar carga
if (!$phpValid && $isWP) {
  return;
}

$pluginName    = $isWP ? $pluginData['PluginID'] : 'default';
$appPath       = $thePluginPath . '/backend/app';

// Load framework
require_once $appPath . '/config/init.php';

// Registrar instancia de la aplicación
ogApp($pluginName, $appPath, $isWP);

// Las rutas de app se cargan DESPUÉS de crear ogApplication
// HOOKS WORDPRESS (solo si está en WordPress)
if ($isWP) {

  // Registrar rewrite rules
  /*function factory_saas_rewrite_rules() {
    add_rewrite_rule(
      '^api/factory/(.*)$',
      'index.php?factory_api_route=$matches[1]',
      'top'
    );
  }
  add_action('init', 'factory_saas_rewrite_rules');

  // Registrar query var
  function factory_saas_query_vars($vars) {
    $vars[] = 'factory_api_route';
    return $vars;
  }
  add_filter('query_vars', 'factory_saas_query_vars');

  // Interceptar requests
  function factory_saas_template_redirect() {
    $route = get_query_var('factory_api_route');

    if ($route !== '') {
      $_SERVER['REQUEST_URI'] = '/api/' . $route;

      // Cargar y ejecutar api.php
      require_once FACTORY_BACKEND_PATH . '/api.php';
      exit;
    }
  }
  add_action('template_redirect', 'factory_saas_template_redirect', 1);

  // Flush rewrite rules
  register_activation_hook(FACTORY_PLUGIN_PATH . 'factory-saas-api.php', function() {
    factory_saas_rewrite_rules();
    flush_rewrite_rules();
  });

  register_deactivation_hook(FACTORY_PLUGIN_PATH . 'factory-saas-api.php', function() {
    flush_rewrite_rules();
  });*/

} else {
  // STANDALONE: Ejecutar api.php directamente
  // Si estamos en standalone (llamado desde wp-file.php vía .htaccess)
  // y la URL es /api/*, ejecutar api.php
  if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/api/') !== false) {
    require_once $thePluginPath . '/backend/api.php';
    exit;
  }
}