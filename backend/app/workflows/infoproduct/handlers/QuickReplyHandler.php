<?php

class QuickReplyHandler {

  private static $logMeta = ['module' => 'QuickReplyHandler', 'layer' => 'app/workflows'];

  /**
   * Extrae el texto de un mensaje interactivo (botón pulsado).
   * El normalizador (facebookNormalizer / evolutionNormalizer) ya extrae
   * el título del botón en el campo 'text' del mensaje estandarizado,
   * por lo que basta leerlo directamente.
   */
  static function extractInteractiveText(array $message): string {
    return trim($message['text'] ?? '');
  }

  /**
   * Busca una plantilla quick_reply cuyo trigger coincida con el texto dado.
   * Comparación case-insensitive con trim.
   * Retorna el array de la plantilla o null si no hay coincidencia.
   */
  static function findMatch(string $text, array $chatData): ?array {
    $templates = $chatData['summary']['quick_reply_templates'] ?? [];
    if (empty($templates) || $text === '') return null;

    $normalized = mb_strtolower(trim($text));

    foreach ($templates as $item) {
      foreach (($item['triggers'] ?? []) as $trigger) {
        if (mb_strtolower(trim($trigger)) === $normalized) {
          ogLog::info('QuickReplyHandler::findMatch - Coincidencia encontrada', [
            'trigger'     => $trigger,
            'template_id' => $item['template']['template_id'] ?? ''
          ], self::$logMeta);
          return $item['template'];
        }
      }
    }

    return null;
  }

  /**
   * Envía la plantilla quick_reply usando chatApiService.
   * Soporta texto, media, botones y footer.
   */
  static function send(array $template, string $to, array $bot): void {
    $chatapi = ogApp()->service('chatApi');

    $text = $template['message'] ?? '';
    $url  = (!empty($template['url']) && ($template['type'] ?? 'text') !== 'text') ? $template['url'] : '';

    $chatapi::send($to, $text, $url, [
      'buttons' => $template['buttons'] ?? [],
      'footer'  => $template['footer']  ?? ''
    ]);

    ogLog::info('QuickReplyHandler::send - Plantilla enviada', [
      'to'            => $to,
      'template_id'   => $template['template_id'] ?? '',
      'template_type' => $template['template_type'] ?? ''
    ], self::$logMeta);
  }

  /**
   * Construye el array quick_reply_templates a partir de la lista de plantillas
   * de un producto. Se llama desde ChatHandler::rebuildFromDB.
   */
  static function buildFromTemplates(array $rawTemplates): array {
    $result = [];

    foreach ($rawTemplates as $tpl) {
      if (($tpl['template_type'] ?? '') !== 'quick_reply') continue;

      $rawTrigger = trim($tpl['quick_reply_trigger'] ?? '');
      if ($rawTrigger === '') continue;

      $triggers = array_values(array_filter(
        array_map('trim', explode('|', $rawTrigger))
      ));

      if (empty($triggers)) continue;

      $result[] = [
        'triggers' => $triggers,
        'template' => $tpl
      ];
    }

    return $result;
  }
}
