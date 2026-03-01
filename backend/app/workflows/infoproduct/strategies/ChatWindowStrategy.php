<?php

/**
 * ChatWindowStrategy
 *
 * Centraliza la lógica de la ventana de conversación de WhatsApp Cloud API (Facebook).
 * - Calcula max_send_at según origen (ad=72h, organic=24h)
 * - Persiste open_chat en client_bot_meta
 * - Usado por SendWelcomeAction (antes de que open_chat exista en BD)
 *   y por WelcomeStrategy::openChatWindow (para persistirlo tras crear la venta)
 *
 * REGLAS META:
 * - Ad (click-to-WhatsApp): siempre abre ventana nueva de 72h (aunque haya una activa)
 * - Organic (cliente escribe solo): 24h solo si la ventana anterior ya expiró
 * - Cliente responde durante conversación activa: 24h solo si expiró
 */
class ChatWindowStrategy {

  // Horas de ventana según origen
  const HOURS_AD      = 72;
  const HOURS_ORGANIC = 24;

  /**
   * Detecta si el contexto proviene de un anuncio de Meta.
   */
  static function isAd(array $context): bool {
    return ($context['is_fb_ads'] ?? false) === true
      || !empty($context['source_app'])
      || ($context['source'] ?? null) === 'FB_Ads'
      || ($context['type']   ?? null) === 'conversion';
  }

  /**
   * Indica si el bot usa WhatsApp Cloud API (Facebook).
   */
  static function isFacebookProvider(array $botConfig): bool {
    $type = $botConfig['apis']['chat'][0]['config']['type_value'] ?? null;
    return $type === 'whatsapp-cloud-api';
  }

  /**
   * Calcula la fecha máxima de la ventana de conversación.
   * Retorna null si el bot no usa Facebook (Evolution API u otros no tienen restricción).
   */
  static function calcMaxSendAt(array $botConfig, array $context): ?string {
    if (!self::isFacebookProvider($botConfig)) return null;

    $hours = self::isAd($context) ? self::HOURS_AD : self::HOURS_ORGANIC;
    return date('Y-m-d H:i:s', time() + ($hours * 3600));
  }

  /**
   * Persiste open_chat en client_bot_meta al inicio de una conversación (bienvenida).
   *
   * - Si viene de anuncio (ad): SIEMPRE sobreescribe — Meta da 72h nuevas por el click.
   * - Si es orgánico: solo crea/actualiza si la ventana anterior ya expiró.
   *
   * Llamar después de que el cliente ya exista en BD.
   */
  static function persist(int $clientId, int $botId, array $botConfig, array $context): void {
    if (!self::isFacebookProvider($botConfig)) return;

    $isAd   = self::isAd($context);
    $hours  = $isAd ? self::HOURS_AD : self::HOURS_ORGANIC;
    $now    = date('Y-m-d H:i:s');
    $nowTs  = time();
    $expiry = date('Y-m-d H:i:s', $nowTs + ($hours * 3600));

    $existing = ogDb::raw(
      "SELECT meta_value FROM client_bot_meta
       WHERE client_id = ? AND bot_id = ? AND meta_key = 'open_chat'
       ORDER BY meta_value DESC LIMIT 1",
      [$clientId, $botId]
    );
    $existingExpiry = $existing[0]['meta_value'] ?? null;

    // Ad: siempre actualizar (Meta abre ventana nueva por el click)
    // Organic: solo si no existe o ya expiró
    $shouldUpdate = $isAd || !$existingExpiry || strtotime($existingExpiry) < $nowTs;

    if (!$shouldUpdate) {
      ogLog::info("ChatWindowStrategy::persist - Ventana activa, no se sobreescribe", [
        'client_id'       => $clientId,
        'bot_id'          => $botId,
        'existing_expiry' => $existingExpiry,
        'origin'          => 'organic'
      ], ['module' => 'ChatWindowStrategy', 'layer' => 'app/workflows']);
      return;
    }

    if ($existingExpiry) {
      ogDb::raw(
        "UPDATE client_bot_meta SET meta_value = ?, tc = ?
         WHERE client_id = ? AND bot_id = ? AND meta_key = 'open_chat'",
        [$expiry, $nowTs, $clientId, $botId]
      );
    } else {
      ogDb::raw(
        "INSERT INTO client_bot_meta (client_id, bot_id, meta_key, meta_value, dc, tc)
         VALUES (?, ?, 'open_chat', ?, ?, ?)",
        [$clientId, $botId, $expiry, $now, $nowTs]
      );
    }

    ogLog::info("ChatWindowStrategy::persist - Ventana abierta", [
      'client_id'  => $clientId,
      'bot_id'     => $botId,
      'origin'     => $isAd ? 'ad' : 'organic',
      'hours'      => $hours,
      'expires_at' => $expiry
    ], ['module' => 'ChatWindowStrategy', 'layer' => 'app/workflows']);
  }

  /**
   * Refresca open_chat cuando el cliente responde durante una conversación activa.
   * Solo abre 24h nuevas si la ventana anterior ya expiró.
   * (Nunca reinicia una ventana todavía activa)
   */
  static function refresh(int $clientId, int $botId): void {
    $now   = date('Y-m-d H:i:s');
    $nowTs = time();

    $existing = ogDb::raw(
      "SELECT meta_value FROM client_bot_meta
       WHERE client_id = ? AND bot_id = ? AND meta_key = 'open_chat' LIMIT 1",
      [$clientId, $botId]
    );
    $existingExpiry = $existing[0]['meta_value'] ?? null;

    // Solo actualizar si no existe o ya expiró
    $shouldUpdate = !$existingExpiry || strtotime($existingExpiry) < $nowTs;

    if (!$shouldUpdate) {
      ogLog::info("ChatWindowStrategy::refresh - Ventana activa, no se renueva", [
        'client_id'       => $clientId,
        'bot_id'          => $botId,
        'existing_expiry' => $existingExpiry
      ], ['module' => 'ChatWindowStrategy', 'layer' => 'app/workflows']);
      return;
    }

    $expiry = date('Y-m-d H:i:s', $nowTs + (self::HOURS_ORGANIC * 3600)); // +24h

    if ($existingExpiry) {
      ogDb::raw(
        "UPDATE client_bot_meta SET meta_value = ?, tc = ?
         WHERE client_id = ? AND bot_id = ? AND meta_key = 'open_chat'",
        [$expiry, $nowTs, $clientId, $botId]
      );
    } else {
      ogDb::raw(
        "INSERT INTO client_bot_meta (client_id, bot_id, meta_key, meta_value, dc, tc)
         VALUES (?, ?, 'open_chat', ?, ?, ?)",
        [$clientId, $botId, $expiry, $now, $nowTs]
      );
    }

    ogLog::info("ChatWindowStrategy::refresh - Ventana renovada +24h", [
      'client_id'  => $clientId,
      'bot_id'     => $botId,
      'expires_at' => $expiry
    ], ['module' => 'ChatWindowStrategy', 'layer' => 'app/workflows']);
  }
}
