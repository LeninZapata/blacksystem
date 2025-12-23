<?php

class PromptBuilder {

  static function buildWithCache($bot, $chat, $aiText, $promptSystem) {
    $messages = [];

    $messages[] = [
      'role' => 'system',
      'content' => self::buildSystemPrompt($promptSystem),
      'cache_control' => ['type' => 'ephemeral']
    ];

    $messages[] = [
      'role' => 'assistant',
      'content' => self::buildProductsBlock($chat),
      'cache_control' => ['type' => 'ephemeral']
    ];

    $messages[] = [
      'role' => 'assistant',
      'content' => self::buildHistoryBlock($chat),
      'cache_control' => ['type' => 'ephemeral']
    ];

    $messages[] = [
      'role' => 'user',
      'content' => self::buildCurrentMessage($aiText, $chat)
    ];

    return $messages;
  }

  private static function buildSystemPrompt($promptSystem) {
    return "# PROMPT DEL SISTEMA\n\n" . $promptSystem . "\n\n";
  }

  private static function buildProductsBlock($chat) {
    $currentSale = $chat['current_sale'] ?? null;
    $summary = $chat['summary'] ?? [];
    $messages = $chat['messages'] ?? [];

    $prompt = "";

    // Recolectar productos en conversación y ventas confirmadas
    $productosEnConversacion = [];
    $ventasConfirmadas = [];

    foreach ($messages as $msg) {
      $metadata = $msg['metadata'] ?? [];
      $action = $metadata['action'] ?? null;

      // Recolectar productos cuando inicia venta
      if ($action === 'start_sale') {
        $productId = $metadata['product_id'] ?? null;
        $productName = $metadata['product_name'] ?? 'Desconocido';
        $productDescription = $metadata['description'] ?? '';
        $productInstructions = $metadata['instructions'] ?? '';
        $productPrice = $metadata['price'] ?? '0.00';
        $saleId = $metadata['sale_id'] ?? null;

        $templatesFile = SHARED_PATH . '/bots/infoproduct/messages/template_' . $productId . '.json';
        $templates = file::getJson($templatesFile) ?? [];

        if ($productId && !isset($productosEnConversacion[$productId])) {
          $productosEnConversacion[$productId] = [
            'name' => $productName,
            'description' => $productDescription,
            'instructions' => $productInstructions,
            'price' => $productPrice,
            'templates' => $templates,
            'sale_id' => $saleId
          ];
        }
      }

      // Recolectar ventas confirmadas
      if ($action === 'sale_confirmed') {
        $saleId = $metadata['sale_id'] ?? null;
        $receiptData = $metadata['receipt_data'] ?? [];
        $amountPaid = $receiptData['amount_found'] ?? '0.00';
        $fecha = $msg['date'] ?? 'N/A';

        if ($saleId) {
          $ventasConfirmadas[$saleId] = [
            'amount_paid' => $amountPaid,
            'date' => $fecha
          ];
        }
      }
    }

    // Construir sección de productos comprados
    if (!empty($ventasConfirmadas)) {
      $prompt .= "\n### PRODUCTOS COMPRADOS:\n\n";

      foreach ($productosEnConversacion as $prodId => $prodData) {
        $saleId = $prodData['sale_id'] ?? null;

        if ($saleId && isset($ventasConfirmadas[$saleId])) {
          $venta = $ventasConfirmadas[$saleId];
          $precioOfrecido = $prodData['price'];
          $precioPagado = $venta['amount_paid'];
          $fecha = $venta['date'];

          $prompt .= "- {$prodData['name']} (ID: {$prodId}) | Precio ofrecido: \${$precioOfrecido} | Precio pagado: \${$precioPagado} | Fecha: {$fecha} | Tipo: Principal\n";
        }
      }

      $prompt .= "\n";
    }

    // Construir sección de productos disponibles
    if (!empty($productosEnConversacion)) {
      $prompt .= "### PRODUCTOS EN CONVERSACIÓN:\n";

      foreach ($productosEnConversacion as $prodId => $prodData) {
        $prompt .= "═══════════════════════════════════════\n";
        $prompt .= "**Producto ID {$prodId}: {$prodData['name']}**\n";
        $prompt .= "═══════════════════════════════════════\n\n";
        $prompt .= "**Precio:** \${$prodData['price']}\n\n";
        $prompt .= "--- DESCRIPCIÓN ---\n";
        $prompt .= !empty($prodData['description']) ? $prodData['description'] . "\n" : "*(Sin descripción)*\n";
        $prompt .= "--- FIN DESCRIPCIÓN ---\n\n";

        if (!empty($prodData['instructions'])) {
          $prompt .= "--- INSTRUCCIONES INTERNAS ---\n";
          $prompt .= $prodData['instructions'] . "\n";
          $prompt .= "--- FIN INSTRUCCIONES ---\n\n";
        }

        if (!empty($prodData['templates'])) {
          $prompt .= "### PLANTILLAS DISPONIBLES del Producto: {$prodData['name']} (ID: {$prodId})\n\n";

          foreach ($prodData['templates'] as $index => $template) {
            $type = $template['template_type'] ?? 'unknown';
            $texto = $template['message'] ?? '';
            $url = $template['url'] ?? '';

            if ($type === 'link_media') {
              continue;
            }

            if (($type === 'link_review' || $type === 'link_payment_method') && !empty($url)) {
              $texto .= "\n" . $url;
            }

            $templateIdLabel = !empty($template['template_id']) ? " ({$template['template_id']})" : '';
            $prompt .= "- Plantilla " . ($index + 1) . ": {$type}{$templateIdLabel}\n";
            $prompt .= "  Contenido: '" . $texto . "'\n\n";
          }
        }

        $prompt .= "\n";
      }
    }

    if (is_array($currentSale) && !empty($currentSale)) {
      $saleType = $currentSale['sale_type'] ?? 'main';
      $saleTypeMap = ['main' => 'Principal', 'ob' => 'Order Bump', 'us' => 'Upsell'];
      $saleTypeName = $saleTypeMap[$saleType] ?? $saleType;

      $prompt .= "\n### VENTA ACTUAL:\n";
      $prompt .= "- ID de venta: " . ($currentSale['sale_id'] ?? 'N/A') . "\n";
      $prompt .= "- Producto: " . ($currentSale['product_name'] ?? 'N/A') . " (ID: " . ($currentSale['product_id'] ?? 'N/A') . ")\n";
      $prompt .= "- Estado: " . ($currentSale['sale_status'] ?? 'N/A') . "\n";
      $prompt .= "- Tipo: {$saleTypeName}\n\n";
    }

    return $prompt;
  }

  private static function buildHistoryBlock($chat) {
    $messages = $chat['messages'] ?? [];
    $totalMessages = count($messages);

    $prompt = "## HISTORIAL COMPLETO DE LA CONVERSACIÓN:\n\n";
    $prompt .= "**Total de mensajes previos:** {$totalMessages}\n\n";

    if ($totalMessages > 0) {
      $prompt .= "---\n\n";

      foreach ($messages as $index => $msg) {
        $msgNum = $index + 1;
        $fecha = $msg['date'] ?? 'N/A';
        $type = $msg['type'] ?? 'N/A';
        $mensaje = $msg['message'] ?? '';
        $metadata = $msg['metadata'] ?? [];

        $emisor = match($type) {
          'P', 'prospect' => '👤 CLIENTE',
          'B', 'bot' => '🤖 BOT',
          'S', 'system' => '⚙️ SISTEMA',
          default => strtoupper($type)
        };

        $prompt .= "**[Mensaje #{$msgNum}]** [{$fecha}] [{$emisor}]\n";

        if (!empty($metadata)) {
          $prompt .= "(Metadatos:\n";

          if (isset($metadata['action'])) {
            if ($metadata['action'] === 'start_sale') {
              $prompt .= "*[Inicio de venta]*\n";
              if (isset($metadata['product_name'])) {
                $prompt .= "*Producto:* {$metadata['product_name']}\n";
              }
              if (isset($metadata['price'])) {
                $prompt .= "*Precio inicial:* \${$metadata['price']}\n";
              }
            } else {
              $prompt .= "*Acción:* {$metadata['action']}\n";
            }
          }

          if (isset($metadata['sale_id'])) {
            $prompt .= "*Sale ID:* {$metadata['sale_id']}\n";
          }

          $prompt .= ")\n";
        }

        $prompt .= "Mensaje: {$mensaje}\n\n";
        $prompt .= "---\n\n";
      }
    } else {
      $prompt .= "*No hay mensajes previos en el historial*\n\n";
    }

    return $prompt;
  }

  private static function buildCurrentMessage($aiText, $chat) {
    $lastActivity = $chat['last_activity'] ?? 'N/A';
    
    $prompt = "## INFORMACIÓN DINÁMICA DE LA CONVERSACIÓN:\n\n";
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