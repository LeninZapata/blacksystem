<?php
class promptBuilder {

  // Construir prompt con estructura de caché ephemeral (4 bloques)
  static function buildWithCache($bot, $chat, $aiText, $promptSystem) {
    $messages = [];

    // BLOQUE 1: SYSTEM PROMPT (CACHEABLE)
    $messages[] = [
      'role' => 'system',
      'content' => self::buildSystemPrompt($promptSystem),
      'cache_control' => ['type' => 'ephemeral']
    ];

    // BLOQUE 2: PRODUCTOS EN CONVERSACIÓN (CACHEABLE)
    $messages[] = [
      'role' => 'assistant',
      'content' => self::buildProductsBlock($chat),
      'cache_control' => ['type' => 'ephemeral']
    ];

    // BLOQUE 3: HISTORIAL DE MENSAJES (CACHEABLE)
    $messages[] = [
      'role' => 'assistant',
      'content' => self::buildHistoryBlock($chat),
      'cache_control' => ['type' => 'ephemeral']
    ];

    // BLOQUE 4: MENSAJE ACTUAL + DATOS DINÁMICOS (NO CACHEABLE)
    $messages[] = [
      'role' => 'user',
      'content' => self::buildCurrentMessage($aiText, $chat)
    ];

    log::info('Prompt con caché construido', [
      'total_bloques' => count($messages),
      'cacheables' => 3,
      'dinamicos' => 1
    ], ['module' => 'prompt']);

    return $messages;
  }

  // BLOQUE 1: System prompt
  private static function buildSystemPrompt($promptSystem) {
    $systemPrompt = $promptSystem;
    
    $prompt = "# PROMPT DEL SISTEMA\n\n";
    $prompt .= $systemPrompt . "\n\n";

    return $prompt;
  }

  // BLOQUE 2: Productos en conversación
  private static function buildProductsBlock($chat) {
    $currentSale = $chat['current_sale'] ?? null;
    $summary = $chat['summary'] ?? [];

    $saleTypeMap = [
      'main' => 'Producto Principal',
      'ob' => 'Order Bump',
      'us' => 'Upsell'
    ];

    $prompt = "# PRODUCTOS EN CONVERSACIÓN\n\n";

    if ($currentSale) {
      $saleType = $currentSale['sale_type'] ?? 'main';
      $saleTypeName = $saleTypeMap[$saleType] ?? $saleType;

      $prompt .= "## VENTA ACTUAL:\n";
      $prompt .= "**Producto:** {$currentSale['product_name']}\n";
      $prompt .= "**Estado:** {$currentSale['sale_status']}\n";
      $prompt .= "**Tipo:** {$saleTypeName}\n\n";
    }

    if (!empty($summary['purchased_products'])) {
      $prompt .= "## PRODUCTOS COMPRADOS:\n";
      foreach ($summary['purchased_products'] as $prod) {
        $prompt .= "- {$prod}\n";
      }
      $prompt .= "\n";
    }

    if (!empty($summary['upsells_offered'])) {
      $prompt .= "## UPSELLS OFRECIDOS:\n";
      foreach ($summary['upsells_offered'] as $up) {
        $prompt .= "- {$up}\n";
      }
      $prompt .= "\n";
    }

    $prompt .= "**Resumen:**\n";
    $prompt .= "- Ventas completadas: " . ($summary['completed_sales'] ?? 0) . "\n";
    $prompt .= "- Ventas en proceso: " . ($summary['sales_in_process'] ?? 0) . "\n";
    $prompt .= "- Valor total: $" . ($summary['total_value'] ?? 0) . "\n";

    return $prompt;
  }

  // BLOQUE 3: Historial de mensajes
  private static function buildHistoryBlock($chat) {
    $messages = $chat['messages'] ?? [];
    $totalMessages = count($messages);

    $prompt = "# HISTORIAL DE CONVERSACIÓN\n\n";
    $prompt .= "**Total de mensajes:** {$totalMessages}\n\n";

    if ($totalMessages > 0) {
      $prompt .= "---\n\n";

      foreach ($messages as $msg) {
        $date = $msg['date'] ?? 'N/A';
        $type = $msg['type'] ?? 'text';
        $format = $msg['format'] ?? 'text';
        $message = $msg['message'] ?? '';
        $metadata = $msg['metadata'] ?? [];

        // Determinar quién envió el mensaje
        $sender = ($type === 'P') ? 'Cliente' : (($type === 'B') ? 'Bot' : 'Sistema');

        $prompt .= "**{$sender}** ({$date})";

        // Agregar información de metadata si existe
        if (!empty($metadata)) {
          $prompt .= " (";

          if (isset($metadata['action'])) {
            $prompt .= "Acción: {$metadata['action']}";
          }

          if (isset($metadata['product_name'])) {
            $prompt .= ", Producto: {$metadata['product_name']}";
          }

          $prompt .= ")";
        }

        $prompt .= "\n";
        $prompt .= "Mensaje: {$message}\n\n";
        $prompt .= "---\n\n";
      }
    } else {
      $prompt .= "*No hay mensajes previos en el historial*\n\n";
    }

    return $prompt;
  }

  // BLOQUE 4: Mensaje actual + datos dinámicos
  private static function buildCurrentMessage($aiText, $chat) {
    $prompt = "## INFORMACIÓN DINÁMICA DE LA CONVERSACIÓN:\n\n";

    // Datos temporales
    $lastActivity = $chat['last_activity'] ?? 'N/A';
    $prompt .= "**Última actividad:** {$lastActivity}\n";
    $prompt .= "**Hora actual:** " . date('Y-m-d H:i:s') . "\n\n";

    $prompt .= "---\n\n";
    $prompt .= "## ÚLTIMO MENSAJE DEL CLIENTE (RESPONDE A ESTO):\n\n";
    $prompt .= $aiText . "\n\n";

    $prompt .= "---\n\n";
    $prompt .= "**INSTRUCCIONES FINALES:**\n";
    $prompt .= "- Responde de forma natural y conversacional\n";
    $prompt .= "- Usa el contexto del historial para personalizar tu respuesta\n";
    $prompt .= "- Mantén el tono definido en el prompt del sistema\n";
    $prompt .= "- Responde en formato JSON como se indicó arriba\n";

    return $prompt;
  }
}