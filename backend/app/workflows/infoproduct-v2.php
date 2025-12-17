<?php
// Workflow: Infoproduct v2
// Este archivo se ejecuta después de que webhookController valida y prepara los datos

// Variables disponibles (pasadas desde webhookController):
// - $webhook: Objeto serviceHelper con datos normalizados
// - $sender: Array con info del remitente
// - $message: Array con info del mensaje
// - $bot: Array con configuración del bot

// ==================== EXTRAER DATOS ====================
$from = $sender['number'];  // Número del remitente (sin @s.whatsapp.net)
$name = $sender['name'];    // Nombre del remitente
$text = $message['text'];   // Texto del mensaje
$messageType = $message['type']; // Tipo: conversation, image, video, etc

// Contexto (FB Ads tracking, etc)
$context = $webhook->extractContext();
$isFromFBAds = !empty($context['source']) && $context['source'] === 'FB_Ads';

// ==================== LOG (opcional) ====================
log::info("workflow:infoproduct - Mensaje recibido", [
  'from' => $from,
  'name' => $name,
  'text' => substr($text, 0, 50),
  'type' => $messageType,
  'fb_ads' => $isFromFBAds
], ['module' => 'workflow']);

// ==================== LÓGICA DEL WORKFLOW ====================
/*
// Bloque 1: Bienvenida
if (in_array(strtolower($text), ['hola', 'start', 'comenzar', 'menu'])) {
  
  // Enviar indicador de escritura
  chatapi::sendPresence($from, 'composing', 1500);
  
  // Enviar mensaje de bienvenida
  $welcomeMsg = "¡Hola {$name}! 👋\n\n";
  $welcomeMsg .= "Bienvenido a nuestro servicio de infoproductos.\n\n";
  $welcomeMsg .= "¿En qué puedo ayudarte?\n";
  $welcomeMsg .= "1️⃣ Ver catálogo\n";
  $welcomeMsg .= "2️⃣ Hacer una pregunta\n";
  $welcomeMsg .= "3️⃣ Soporte";
  
  $response = chatapi::send($from, $welcomeMsg);
  
  if ($response['success']) {
    log::info("workflow:infoproduct - Bienvenida enviada", ['to' => $from], ['module' => 'workflow']);
  } else {
    log::error("workflow:infoproduct - Error enviando bienvenida", ['error' => $response['error']], ['module' => 'workflow']);
  }
  
  return;
}

// Bloque 2: Catálogo
if (in_array($text, ['1', 'catalogo', 'catálogo', 'productos'])) {
  
  chatapi::sendPresence($from, 'composing', 2000);
  
  $catalogMsg = "📚 *Nuestro Catálogo*\n\n";
  $catalogMsg .= "🎓 *Kit Grafismo Fonético* - $3\n";
  $catalogMsg .= "Aprende a leer hasta 10X más rápido\n\n";
  $catalogMsg .= "📖 *Pack Educativo Premium* - $5\n";
  $catalogMsg .= "3 cursos + material extra\n\n";
  $catalogMsg .= "Escribe el nombre del producto para más info.";
  
  chatapi::send($from, $catalogMsg);
  
  return;
}

// Bloque 3: Producto específico (Grafismo Fonético)
if (stripos($text, 'grafismo') !== false || stripos($text, 'fonetico') !== false) {
  
  chatapi::sendPresence($from, 'composing', 2000);
  
  $productMsg = "🎓 *Kit Grafismo Fonético*\n\n";
  $productMsg .= "✅ Método American Accelerated Literacy\n";
  $productMsg .= "✅ Cartilla de grafismo y pronunciación\n";
  $productMsg .= "✅ Escritura en cursiva e imprenta\n";
  $productMsg .= "✅ Video instructivo paso a paso\n";
  $productMsg .= "✅ Compatible con TEA, TDAH\n\n";
  $productMsg .= "💰 *Precio: $3*\n\n";
  $productMsg .= "🎁 + 3 regalos secretos incluidos\n\n";
  $productMsg .= "Escribe *COMPRAR* para adquirirlo.";
  
  // Enviar imagen del producto (opcional)
  $imageUrl = "https://ejemplo.com/producto-grafismo.jpg";
  chatapi::send($from, $productMsg, $imageUrl);
  
  return;
}

// Bloque 4: Proceso de compra
if (strtolower($text) === 'comprar') {
  
  chatapi::sendPresence($from, 'composing', 1500);
  
  $checkoutMsg = "🛒 *Proceso de Compra*\n\n";
  $checkoutMsg .= "Para completar tu compra:\n\n";
  $checkoutMsg .= "1️⃣ Realiza el pago de $3\n";
  $checkoutMsg .= "2️⃣ Envía tu comprobante\n";
  $checkoutMsg .= "3️⃣ Recibe tu producto al instante\n\n";
  $checkoutMsg .= "💳 Métodos de pago:\n";
  $checkoutMsg .= "- PayPal\n";
  $checkoutMsg .= "- Transferencia bancaria\n\n";
  $checkoutMsg .= "¿Cómo deseas pagar?";
  
  chatapi::send($from, $checkoutMsg);
  
  return;
}

// Bloque 5: Consulta con pregunta (?)
if (strpos($text, '?') !== false) {
  
  chatapi::sendPresence($from, 'composing', 2000);
  
  // TODO: Aquí integrarías el servicio de AI
  // $aiResponse = ai::ask($text, $bot['config']['apis']['agent'][0]);
  
  $responseMsg = "📝 Recibí tu pregunta:\n\n";
  $responseMsg .= "_{$text}_\n\n";
  $responseMsg .= "En este momento nuestro equipo está procesando tu consulta. ";
  $responseMsg .= "Te responderemos en breve.\n\n";
  $responseMsg .= "Mientras tanto, escribe *MENU* para ver otras opciones.";
  
  chatapi::send($from, $responseMsg);
  
  return;
}

// Bloque 6: Soporte
if (in_array(strtolower($text), ['soporte', 'ayuda', 'help', '3'])) {
  
  chatapi::sendPresence($from, 'composing', 1000);
  
  $supportMsg = "🆘 *Soporte Técnico*\n\n";
  $supportMsg .= "Estamos aquí para ayudarte.\n\n";
  $supportMsg .= "Puedes:\n";
  $supportMsg .= "• Escribir tu consulta\n";
  $supportMsg .= "• Llamar al: +593-XXX-XXXX\n";
  $supportMsg .= "• Email: soporte@ejemplo.com\n\n";
  $supportMsg .= "Horario: Lun-Vie 9am-6pm";
  
  chatapi::send($from, $supportMsg);
  
  return;
}

// Bloque 7: Tracking de FB Ads
if ($isFromFBAds && empty($text)) {
  // Usuario llegó desde anuncio de Facebook pero no escribió nada aún
  
  chatapi::sendPresence($from, 'composing', 1500);
  
  $adsMsg = "¡Hola! 👋\n\n";
  $adsMsg .= "Veo que llegaste desde nuestro anuncio. ";
  $adsMsg .= "¿Te gustaría saber más sobre nuestro *Kit Grafismo Fonético*?\n\n";
  $adsMsg .= "Escribe *SÍ* para más información.";
  
  chatapi::send($from, $adsMsg);
  
  // Log para analytics
  log::info("workflow:infoproduct - Usuario de FB Ads", [
    'from' => $from,
    'ad_source' => $context['sourceApp'],
    'external_reply' => !empty($context['externalAdReply'])
  ], ['module' => 'workflow', 'category' => 'fb-ads']);
  
  return;
}

// Bloque 8: Respuesta por defecto (no entendido)
chatapi::sendPresence($from, 'composing', 1000);

$defaultMsg = "🤔 No entendí tu mensaje.\n\n";
$defaultMsg .= "Escribe *MENU* para ver las opciones disponibles.";

chatapi::send($from, $defaultMsg);

// Log para analizar mensajes no entendidos
log::warning("workflow:infoproduct - Mensaje no reconocido", [
  'from' => $from,
  'text' => substr($text, 0, 100)
], ['module' => 'workflow']); */