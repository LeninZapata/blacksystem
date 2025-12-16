<?php
// Webhook de WhatsApp (Evolution API)
$router->post('/api/webhook/whatsapp', 'webhook@whatsapp')->middleware(['json']);

// Webhooks futuros (Telegram, etc)
// $router->post('/api/webhook/telegram', 'webhook@telegram')->middleware(['json']);