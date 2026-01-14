<?php
// Cargar helpers y core del framework
$helpers = ['lang', 'log', 'response', 'request', 'db', 'cache'];
$cores = ['app', 'controller', 'application'];
foreach ($helpers as $helper) require_once OG_FRAMEWORK_PATH . '/helpers/og' . ucfirst($helper) . '.php';
foreach ($cores as $core) require_once OG_FRAMEWORK_PATH . '/core/og' . ucfirst($core) . '.php';