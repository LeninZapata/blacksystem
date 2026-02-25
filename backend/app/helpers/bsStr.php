<?php
// Helpers de manipulación de strings
class bsStr {

  /**
   * Decodifica patrones dinámicos en mensajes para evitar spam
   * Transforma:
   * - {pN} → N puntos (ej: {p3} → ...)
   * - {p} → 1-5 puntos aleatorios
   * - {e} → emoji aleatorio genérico
   * - {e-happy} → emoji feliz aleatorio
   * - {e-sad} → emoji triste aleatorio
   * - {e-arrow} → emoji de flecha aleatorio
   * - {e-think} → emoji pensativo aleatorio
   * - {e-like} → emoji de aprobación aleatorio
   *
   * @param string $message Mensaje con patrones a decodificar
   * @return string Mensaje con patrones reemplazados
   */
  public static function decodeMessagePatterns($message) {
    if (empty($message) || !is_string($message)) {
      return $message;
    }

    // Catálogos de emojis por categoría
    $emojiCatalogs = [
      'generic' => ['😊', '👍', '✨', '🙂', '😄', '👌', '💪', '🎉', '✅', '💯'],
      'happy' => ['😊', '😄', '😃', '🙂', '😁', '🥰', '😍', '🤗', '😆', '🥳'],
      'sad' => ['😢', '😞', '😔', '🥺', '😿', '💔', '😪', '😥'],
      'arrow' => ['→', '⬇️', '⬆️', '➡️', '⬅️', '↗️', '↘️', '⤵️', '⤴️', '🔽'],
      'think' => ['🤔', '💭', '🧐', '🤨', '💡', '🎯', '👀', '🔍', '🧠'],
      'like' => ['👍', '👏', '🙌', '💪', '✨', '⭐', '🌟', '💯', '🔥', '✅']
    ];

    // Reemplazar {pN} - N puntos específicos (ej: {p3} → ...)
    $message = preg_replace_callback('/\{p(\d+)\}/', function($matches) { $count = min((int)$matches[1], 10); // Máximo 10 puntos
      return str_repeat('.', $count);
    }, $message);

    // Reemplazar {p} - 1-5 puntos aleatorios
    $message = preg_replace_callback('/\{p\}/', function($matches) { $count = rand(1, 5);
      return str_repeat('.', $count);
    }, $message);

    // Reemplazar {e-categoria} - emoji de categoría específica
    $message = preg_replace_callback('/\{e-([a-z]+)\}/', function($matches) use ($emojiCatalogs) {
      $category = $matches[1];

      if (isset($emojiCatalogs[$category])) {
        $emojis = $emojiCatalogs[$category];
        return $emojis[array_rand($emojis)];
      }

      // Si no existe la categoría, usar genérico
      return $emojiCatalogs['generic'][array_rand($emojiCatalogs['generic'])];
    }, $message);

    // Reemplazar {e} - emoji genérico aleatorio
    $message = preg_replace_callback('/\{e\}/', function($matches) use ($emojiCatalogs) {
      return $emojiCatalogs['generic'][array_rand($emojiCatalogs['generic'])];
    }, $message);

    return $message;
  }
}