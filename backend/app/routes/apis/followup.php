<?php
// routes/apis/followup.php

$router->group('/api/followup', function($router) {

  // Listar pendientes o procesar - GET /api/followup/pending
  // GET /api/followup/pending?list=1 -> Solo listar
  // GET /api/followup/pending -> Procesar y enviar
  $router->get('/pending', function() {
    $list = ogRequest::query('list', null);

    $data = FollowupHandlers::getPending();
    $botsConfig = $data['bots_config'];
    $followups = $data['followups'];

    ogLog::info("CRON Followup - Iniciando proceso", [
      'total_followups' => count($followups),
      'total_bots' => count($botsConfig)
    ], ['module' => 'followup']);

    // Solo listar
    if ($list) {
      ogResponse::json([
        'success' => true,
        'total' => count($followups),
        'bots' => count($botsConfig),
        'followups' => $followups
      ]);
      return;
    }

    // Procesar y enviar
    $sent = 0;
    $failed = 0;
    $upsellsExecuted = 0;

    foreach ($followups as $fup) {
      $botId = $fup['bot_id'];

      // Validar que existe config del bot
      if (!isset($botsConfig[$botId])) {
        ogLog::warning("Bot config no encontrado", ['bot_id' => $botId, 'followup_id' => $fup['id']], ['module' => 'followup']);
        $failed++;
        continue;
      }

      $botData = $botsConfig[$botId];

      try {
        // DETECTAR FOLLOWUP ESPECIAL: Upsell
        if (!empty($fup['special']) && $fup['special'] === 'upsell') {
          ogLog::info("Followup especial detectado: UPSELL", [
            'followup_id' => $fup['id'],
            'product_id' => $fup['product_id'],
            'number' => $fup['number']
          ], ['module' => 'followup']);

          // Configurar ogChatApi con el bot específico
          ogChatApi::setConfig($botData);

          // Ejecutar proceso de upsell
          $upsellResult = UpsellHandlers::executeUpsell($fup, $botData);

          if ($upsellResult['success']) {
            ogLog::info("Upsell ejecutado exitosamente", [
              'followup_id' => $fup['id'],
              'new_sale_id' => $upsellResult['new_sale_id'],
              'upsell_product_id' => $upsellResult['upsell_product_id']
            ], ['module' => 'followup']);

            // Marcar followup especial como procesado
            FollowupHandlers::markProcessed($fup['id']);
            $upsellsExecuted++;
            $sent++;
          } else {
            ogLog::error("Error ejecutando upsell", [
              'followup_id' => $fup['id'],
              'error' => $upsellResult['error'] ?? 'unknown'
            ], ['module' => 'followup']);
            $failed++;
          }

          sleep(2);
          continue;
        }

        // FOLLOWUP NORMAL: Enviar mensaje
        ogLog::info("Procesando followup normal", [
          'followup_id' => $fup['id'],
          'number' => $fup['number'],
          'tracking_id' => $fup['name'] ?? 'N/A'
        ], ['module' => 'followup']);

        // Configurar ogChatApi con el bot específico
        ogChatApi::setConfig($botData);

        // Enviar mensaje
        $sourceUrl = $fup['source_url'] === null ? '' : $fup['source_url'];
        $result = ogChatApi::send($fup['number'], $fup['text'], $sourceUrl);

        if ($result['success']) {
          // Marcar como procesado
          FollowupHandlers::markProcessed($fup['id']);

          // Preparar mensaje corto (primeros 20 caracteres)
          $shortMessage = mb_strlen($fup['text']) > 20 
            ? mb_substr($fup['text'], 0, 20) . '...' 
            : $fup['text'];

          // Preparar metadata completo
          $metadata = [
            'followup_id' => $fup['id'],
            'action' => 'followup_sent'
          ];

          // Agregar tracking_id si existe
          if (!empty($fup['name'])) {
            $metadata['tracking_id'] = $fup['name'];
          }

          // Agregar instruction si existe
          if (!empty($fup['instruction'])) {
            $metadata['instruction'] = $fup['instruction'];
          }

          // Registrar en chat
          ChatHandlers::register(
            $fup['bot_id'],
            null,
            $fup['client_id'],
            $fup['number'],
            $shortMessage,
            'S',
            'text',
            $metadata,
            $fup['sale_id']
          );

          ChatHandlers::addMessage([
            'number' => $fup['number'],
            'bot_id' => $fup['bot_id'],
            'client_id' => $fup['client_id'],
            'sale_id' => $fup['sale_id'],
            'message' => $shortMessage,
            'format' => 'text',
            'metadata' => $metadata
          ], 'S');

          ogLog::info("Followup enviado exitosamente", [
            'followup_id' => $fup['id'],
            'number' => $fup['number']
          ], ['module' => 'followup']);

          $sent++;
        } else {
          ogLog::error("Error enviando followup", [
            'followup_id' => $fup['id'],
            'error' => $result['error'] ?? 'unknown'
          ], ['module' => 'followup']);
          $failed++;
        }

        sleep(2);

      } catch (Exception $e) {
        ogLog::error("Error procesando followup", [
          'followup_id' => $fup['id'],
          'error' => $e->getMessage()
        ], ['module' => 'followup']);
        $failed++;
      }
    }

    ogLog::info("CRON Followup - Proceso finalizado", [
      'total' => count($followups),
      'sent' => $sent,
      'failed' => $failed,
      'upsells_executed' => $upsellsExecuted
    ], ['module' => 'followup']);

    ogResponse::json([
      'success' => true,
      'total' => count($followups),
      'bots' => count($botsConfig),
      'sent' => $sent,
      'failed' => $failed,
      'upsells_executed' => $upsellsExecuted
    ]);

  })->middleware(['throttle:20,1']);

  // Cancelar followups por venta - PUT /api/followup/cancel/{sale_id}
  $router->put('/cancel/{sale_id}', function($sale_id) {
    $affected = FollowupHandlers::cancelBySale($sale_id);

    ogLog::info("Followups cancelados por venta", [
      'sale_id' => $sale_id,
      'affected' => $affected
    ], ['module' => 'followup']);

    ogResponse::json([
      'success' => true,
      'affected' => $affected
    ]);
  })->middleware(['auth', 'throttle:100,1']);

});