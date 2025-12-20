<?php
// app/lang/es/services/webhook.php
return [
  'sender_not_found' => 'Número de remitente no encontrado',
  'bot_not_found' => 'Bot {number} no encontrado',
  'workflow_not_configured' => 'Bot {number} no tiene workflow configurado',
  'workflow_file_not_found' => 'Archivo de workflow \'{file}\' no encontrado',
  'webhook_processed' => 'Webhook procesado correctamente',
  'processing_error' => 'Error al procesar webhook',
  
  'validation' => [
    'empty' => 'Webhook vacío',
    'invalid_format' => 'Webhook debe ser un array',
    'provider_not_detected' => 'No se pudo detectar el provider del webhook'
  ],
  
  'detect' => [
    'chatapi' => 'Webhook de ChatAPI detectado',
    'email' => 'Webhook de Email detectado',
    'sms' => 'Webhook de SMS detectado',
    'payment' => 'Webhook de Payment detectado',
    'unknown' => 'Tipo de webhook desconocido'
  ]
];