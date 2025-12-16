<?php
// Workflow: Infoproduct v2
// Variables disponibles: $data, $bot, $botNumber

$from = str_replace('@s.whatsapp.net', '', $data['key']['remoteJid'] ?? '');
$message = $data['message']['conversation'] ?? $data['message']['extendedTextMessage']['text'] ?? '';

// Bloque 1: Bienvenida
if (in_array(strtolower($message), ['hola', 'start', 'comenzar'])) {
  chatApiService::send($botNumber, $from, '¡Bienvenido! ¿En qué puedo ayudarte?');
  return;
}

// Bloque 2: Consulta con AI
if (strpos($message, '?') !== false) {
  // Aquí llamarías al servicio de AI
  // $response = ai::ask($message);
  chatApiService::send($botNumber, $from, "Recibí tu pregunta: {$message}");
  return;
}

// Bloque 3: Respuesta por defecto
chatApiService::send($botNumber, $from, 'No entendí tu mensaje. Escribe "hola" para comenzar.');