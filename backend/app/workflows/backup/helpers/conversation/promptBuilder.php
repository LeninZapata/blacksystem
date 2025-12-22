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
    $messages = $chat['messages'] ?? [];

    $prompt = "";

    // Extraer productos únicos de los mensajes start_sale
    $productosEnConversacion = [];
    foreach ($messages as $msg) {
      $metadata = $msg['metadata'] ?? [];
      $action = $metadata['action'] ?? null;

      if ($action === 'start_sale') {
        $productId = $metadata['product_id'] ?? null;
        $productName = $metadata['product_name'] ?? 'Desconocido';
        $productDescription = $metadata['description'] ?? '';
        $productInstructions = $metadata['instructions'] ?? '';
        $productPrice = $metadata['price'] ?? '0.00';
        
        // Obtener templates desde archivo JSON externo
        $templatesFile = SHARED_PATH . '/bots/infoproduct/messages/template_' . $productId . '.json';
        $templates = file::getJson($templatesFile) ?? [];

        if ($productId && !isset($productosEnConversacion[$productId])) {
          $productosEnConversacion[$productId] = [
            'name' => $productName,
            'description' => $productDescription,
            'instructions' => $productInstructions,
            'price' => $productPrice,
            'templates' => $templates
          ];
        }
      }
    }

    // Mostrar productos en conversación con formato detallado
    if (!empty($productosEnConversacion)) {
      $prompt .= "\n### PRODUCTOS EN CONVERSACIÓN:\n";
      $prompt .= "*(Estos son los productos que se han iniciado en esta conversación)*\n\n";

      foreach ($productosEnConversacion as $prodId => $prodData) {
        $prompt .= "════════════════════════════════════════\n";
        $prompt .= "**Producto ID {$prodId}: {$prodData['name']}**\n";
        $prompt .= "════════════════════════════════════════\n\n";

        $prompt .= "**Precio:** \${$prodData['price']}\n\n";

        $prompt .= "--- DESCRIPCIÓN ---\n";
        if (!empty($prodData['description'])) {
          $prompt .= $prodData['description'] . "\n";
        } else {
          $prompt .= "*(Sin descripción)*\n";
        }
        $prompt .= "--- FIN DESCRIPCIÓN ---\n\n";

        if (!empty($prodData['instructions'])) {
          $prompt .= "--- INSTRUCCIONES INTERNAS ---\n";
          $prompt .= $prodData['instructions'] . "\n";
          $prompt .= "--- FIN INSTRUCCIONES ---\n\n";
        }

        // Templates disponibles
        if (!empty($prodData['templates'])) {
          $prompt .= "### PLANTILLAS DISPONIBLES del Producto: {$prodData['name']} (ID: {$prodId})\n\n";

          $plantillasTexto = [];
          $recursosMultimedia = [];

          foreach ($prodData['templates'] as $template) {
            $type = $template['template_type'] ?? 'unknown';
            $texto = $template['message'] ?? '';
            $url = $template['url'] ?? '';

            // Si es link_media, solo agregar a multimedia
            if ($type === 'link_media') {
              if (!empty($url)) {
                $recursosMultimedia[] = ['url' => $url, 'descripcion' => $texto];
              }
              continue;
            }

            // Para link_review y link_payment_method, concatenar texto + URL si existe
            if (($type === 'link_review' || $type === 'link_payment_method') && !empty($url)) {
              $texto .= "\n" . $url;
            }

            // Agregar a plantillas de texto (incluye link_product, link_review, link_payment_method, etc)
            $plantillasTexto[] = [
              'index' => count($plantillasTexto) + 1,
              'tipo' => $type,
              'texto' => $texto,
              'template_id' => $template['template_id'] ?? ''
            ];

            // Agregar a multimedia si tiene URL y NO es review ni payment_method
            if (!empty($url) && $type !== 'link_review' && $type !== 'link_payment_method') {
              $recursosMultimedia[] = ['url' => $url, 'descripcion' => $texto];
            }
          }

          // Mostrar plantillas de texto
          if (!empty($plantillasTexto)) {
            foreach ($plantillasTexto as $plantilla) {
              $templateIdLabel = !empty($plantilla['template_id']) ? " ({$plantilla['template_id']})" : '';
              $prompt .= "- Plantilla " . $plantilla['index'] . ": {$plantilla['tipo']}{$templateIdLabel}\n";
              $prompt .= "  Contenido: '" . $plantilla['texto'] . "'\n\n";
            }
          } else {
            $prompt .= "*No hay plantillas de texto configuradas*\n\n";
          }

          // Mostrar recursos multimedia
          if (!empty($recursosMultimedia)) {
            $prompt .= "\n### RECURSOS MULTIMEDIA DISPONIBLES PARA EL PRODUCTO {$prodData['name']} (ID {$prodId}):\n";
            foreach ($recursosMultimedia as $mediaIndex => $media) {
              $mediaNum = $mediaIndex + 1;
              $prompt .= "- **Media {$mediaNum}**: {$media['url']}";
              if (!empty($media['descripcion'])) {
                $prompt .= " (" . $media['descripcion'] . ")";
              }
              $prompt .= "\n";
            }
            $prompt .= "\n";
          }
        }

        $prompt .= "\n";
      }
    }

    // Información de venta actual
    if (is_array($currentSale) && !empty($currentSale)) {
      $saleType = $currentSale['sale_type'] ?? 'main';
      $saleTypeMap = ['main' => 'Principal', 'ob' => 'Order Bump', 'us' => 'Upsell'];
      $saleTypeName = $saleTypeMap[$saleType] ?? $saleType;

      $prompt .= "\n### VENTA ACTUAL:\n";
      $prompt .= "- ID de venta: " . ($currentSale['sale_id'] ?? 'N/A') . "\n";
      $prompt .= "- Producto: " . ($currentSale['product_name'] ?? 'N/A') . " (ID: " . ($currentSale['product_id'] ?? 'N/A') . ")\n";
      $prompt .= "- Estado: " . ($currentSale['sale_status'] ?? 'N/A') . "\n";
      $prompt .= "- Tipo: {$saleTypeName}\n\n";
    } elseif (!empty($chat['last_sale'])) {
      $prompt .= "\n### ÚLTIMA VENTA (ID: {$chat['last_sale']}):\n";
      $prompt .= "- Estado: Completada\n\n";
    }

    // Resumen de ventas
    if (!empty($summary)) {
      $prompt .= "### RESUMEN DE VENTAS:\n";
      $prompt .= "- Ventas completadas: " . ($summary['completed_sales'] ?? 0) . "\n";
      $prompt .= "- Ventas en proceso: " . ($summary['sales_in_process'] ?? 0) . "\n";
      $prompt .= "- Valor total: $" . ($summary['total_value'] ?? 0) . "\n";

      if (!empty($summary['purchased_products'])) {
        $prompt .= "\n**Productos comprados:**\n";
        foreach ($summary['purchased_products'] as $prod) {
          $prompt .= "- {$prod}\n";
        }
      }

      if (!empty($summary['upsells_offered'])) {
        $prompt .= "\n**Upsells ofrecidos:**\n";
        foreach ($summary['upsells_offered'] as $up) {
          $prompt .= "- {$up}\n";
        }
      }
    }

    return $prompt;
  }

  // BLOQUE 3: Historial de mensajes
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
        $formato = $msg['format'] ?? 'text';
        $mensaje = $msg['message'] ?? '';
        $metadata = $msg['metadata'] ?? [];

        // Tipo de emisor con emojis
        $emisor = match($type) {
          'P' => '👤 CLIENTE',
          'B' => '🤖 BOT',
          'S' => '⚙️ SISTEMA',
          default => strtoupper($type)
        };

        $prompt .= "**[Mensaje #{$msgNum}]** [{$fecha}] [{$emisor}]\n";

        // Agregar metadata relevante
        if (!empty($metadata)) {
          $prompt .= "(Metadatos:\n";

          // Start sale
          if (isset($metadata['action']) && $metadata['action'] === 'start_sale') {
            $prompt .= "*[Inicio de venta]*\n";
            if (isset($metadata['product_name'])) {
              $prompt .= "*Producto:* {$metadata['product_name']}\n";
            }
            if (isset($metadata['price'])) {
              $prompt .= "*Precio inicial:* \${$metadata['price']}\n";
            }
          }

          // Acción general
          if (isset($metadata['action']) && $metadata['action'] !== 'start_sale') {
            $prompt .= "*Acción:* {$metadata['action']}\n";
          }

          // Sale ID
          if (isset($metadata['sale_id'])) {
            $prompt .= "*Sale ID:* {$metadata['sale_id']}\n";
          }

          // Product info (para otros mensajes)
          if (isset($metadata['product_id']) && $metadata['action'] !== 'start_sale') {
            $prompt .= "*Producto ID:* {$metadata['product_id']}\n";
          }

          // Caption de imagen/video
          if (isset($metadata['caption'])) {
            $prompt .= "*Caption:* {$metadata['caption']}\n";
          }

          // Transcripción de audio
          if (isset($metadata['transcripcion'])) {
            $prompt .= "*[Audio transcrito]*\n";
          }

          // Instrucciones internas (solo para followup)
          if (isset($metadata['instructions']) && isset($metadata['action']) && $metadata['action'] == 'followup') {
            $prompt .= "*Instrucción interna:* {$metadata['instructions']}\n";
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