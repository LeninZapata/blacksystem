<?php
class WebhookHandler {

  /**
   * Detectar tipo de servicio del webhook (opcional - para casos edge)
   * Normalmente NO se usa porque el método del controller ya indica el tipo
   *
   * Ejemplo de uso:
   * - Un webhook genérico que puede ser cualquier cosa
   * - Un endpoint catch-all que no sabe qué recibirá
   */
  static function detectService($rawData) {
    // Extraer primer elemento si viene como array
    $data = is_array($rawData) && isset($rawData[0]) ? $rawData[0] : $rawData;

    // Detectar ChatAPI (WhatsApp, Telegram, etc)
    if (isset($data['body']['event']) && isset($data['body']['instance'])) {
      return 'ogChatApi';
    }

    // Detectar Email webhook (ej: SendGrid, Postmark)
    if (isset($data['event']) && in_array($data['event'], ['delivered', 'bounce', 'open', 'click'])) {
      return 'email';
    }

    // Detectar SMS webhook (ej: Twilio)
    if (isset($data['MessageSid']) || (isset($data['From']) && isset($data['Body']))) {
      return 'sms';
    }

    // Detectar Payment webhook (ej: Stripe)
    if (isset($data['type']) && strpos($data['type'], 'payment') !== false) {
      return 'payment';
    }

    return null;
  }

  // Validar estructura básica del webhook
  static function validate($rawData) {
    if (empty($rawData)) {
      return ['valid' => false, 'error' => 'Webhook vacío'];
    }

    if (!is_array($rawData)) {
      return ['valid' => false, 'error' => 'Webhook debe ser un array'];
    }

    return ['valid' => true];
  }
}