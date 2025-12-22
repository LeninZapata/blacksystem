<?php
// framework/lang/es/services/ai.php
return [
  'no_services_available' => 'No hay servicios de IA disponibles',
  'no_services_for_task' => 'No hay servicios de IA disponibles para la tarea: {task}',
  'all_services_failed' => 'Todos los servicios de IA fallaron. Último error: {error}',
  'provider_not_supported' => 'Proveedor de IA no soportado: {provider}',
  'class_not_found' => 'Clase de proveedor no encontrada: {class}',
  
  // Errores comunes de providers
  'api_key_missing' => 'API key no configurada para {provider}',
  'invalid_response' => 'Respuesta inválida del proveedor de IA',
  'http_error' => 'Error HTTP al comunicarse con el proveedor de IA',
  'timeout' => 'Tiempo de espera agotado al comunicarse con el proveedor de IA',
  
  // Tareas específicas
  'chat_completion_failed' => 'Error en chat completion',
  'transcription_failed' => 'Error en transcripción de audio',
  'image_analysis_failed' => 'Error en análisis de imagen',
  'not_supported' => 'Esta funcionalidad no está soportada por {provider}',
];
