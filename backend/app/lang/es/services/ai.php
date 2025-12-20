<?php
return [
  'not_configured' => 'AI no configurado',
  'no_services_available' => 'No hay servicios de IA disponibles',
  'no_services_for_task' => 'No hay servicios configurados para: {task}',
  'all_services_failed' => 'Todos los servicios de IA fallaron. Último error: {error}',
  'provider_not_supported' => 'Proveedor no soportado: {provider}',
  'class_not_found' => 'Clase no encontrada: {class}',

  'chat' => [
    'success' => 'Respuesta generada correctamente',
    'failed' => 'Error generando respuesta'
  ],

  'audio' => [
    'success' => 'Audio transcrito correctamente',
    'failed' => 'Error transcribiendo audio',
    'not_supported' => '{provider} no soporta transcripción de audio'
  ],

  'image' => [
    'success' => 'Imagen analizada correctamente',
    'failed' => 'Error analizando imagen',
    'not_supported' => '{provider} no soporta análisis de imagen'
  ]
];