<?php
// routes/apis/followup.php
$router->group('/api/followup', function($router) {

  // Listar pendientes o procesar - GET /api/followup/pending
  // GET /api/followup/pending?list=1 -> Solo listar
  // GET /api/followup/pending -> Procesar y enviar
  $router->get('/pending', function() {

    set_time_limit(600);
    ini_set('max_execution_time', 600);
    // en el comando de CRON poner esto
    // wget --timeout=600 --tries=1 -q -O - https://url.com/api/followup/pending /dev/null 2>&1

    $logMeta = ['module' => 'followup', 'layer' => 'app/routes'];
    $list = ogRequest::query('list', null);

    ogApp()->loadHandler('followup');
    $data = ogApp()->handler('followup')::getPending();
    $botsConfig = $data['bots_config'];
    $followups = $data['followups'];

    ogLog::info("CRON Followup - Iniciando proceso", [], $logMeta);

    // Solo listar
    if ($list) {
      ogLog::info("CRON Followup - Listando followups pendientes sin procesar", [ 'total_followups' => count($followups), 'total_bots' => count($botsConfig) ], $logMeta);
      ogResponse::json([ 'success' => true, 'total' => count($followups), 'bots' => count($botsConfig), 'followups' => $followups ]);
      return;
    }

    // Procesar y enviar
    $sent = 0;
    $failed = 0;
    $upsellsExecuted = 0;

    $chatapi = ogApp()->service('chatApi');
    ogApp()->loadHandler('chat');
    ogApp()->loadHandler('upsell');
    foreach ($followups as $fup) {
      $botId = $fup['bot_id'];

      // Validar que existe config del bot
      if (!isset($botsConfig[$botId])) {
        ogLog::warning("CRON Followup - Bot config no encontrado", ['bot_id' => $botId, 'followup_id' => $fup['id']], ['module' => 'followup']);
        $failed++;
        continue;
      }

      $botData = $botsConfig[$botId];

      try {
        // DETECTAR FOLLOWUP ESPECIAL: Upsell
        if (!empty($fup['special']) && $fup['special'] === 'upsell') {
          ogLog::info("CRON Followup - Followup especial detectado: UPSELL", [ 'followup_id' => $fup['id'], 'product_id' => $fup['product_id'], 'number' => $fup['number'] ], $logMeta);

          // VALIDACIÓN 1: Verificar si ya existe venta upsell para este followup
          $existingUpsell = ogDb::table(DB_TABLES['sales'])
            ->where('client_id', $fup['client_id'])
            ->where('parent_sale_id', $fup['sale_id'])
            ->where('origin', 'upsell')
            ->where('bot_id', $fup['bot_id'])
            ->where('status', 1)
            ->first();

          if ($existingUpsell) {
            ogLog::warning("CRON Followup - Upsell duplicado detectado, marcando followup como procesado", [
              'followup_id' => $fup['id'],
              'existing_sale_id' => $existingUpsell['id'],
              'number' => $fup['number']
            ], $logMeta);

            // Marcar followup como procesado para evitar loop
            ogApp()->handler('followup')::markProcessed($fup['id']);
            $failed++;
            sleep(rand(2, 4));
            continue;
          }

          // Configurar ogChatApi con el bot específico
          $chatapi::setConfig($botData);

          // Ejecutar proceso de upsell
          $upsellResult = UpsellHandler::executeUpsell($fup, $botData);

          // CRÍTICO: Marcar followup como procesado SIEMPRE para evitar loop infinito
          ogApp()->handler('followup')::markProcessed($fup['id']);

          if ($upsellResult['success']) {
            ogLog::info("CRON Followup - Upsell ejecutado exitosamente", [ 'followup_id' => $fup['id'], 'new_sale_id' => $upsellResult['new_sale_id'], 'upsell_product_id' => $upsellResult['upsell_product_id'] ], $logMeta);
            $upsellsExecuted++;
            $sent++;
          } else {
            ogLog::error("CRON Followup - Error ejecutando upsell (followup marcado como procesado para evitar loop)", [ 'followup_id' => $fup['id'], 'error' => $upsellResult['error'] ?? 'unknown' ], $logMeta);
            $failed++;
          }

          sleep(rand(2, 4));
          continue;
        }

        // FOLLOWUP NORMAL: Enviar mensaje
        ogLog::info("CRON Followup - Procesando followup normal", [ 'followup_id' => $fup['id'], 'number' => $fup['number'], 'tracking_id' => $fup['name'] ?? 'N/A' ], $logMeta);

        // Configurar ogChatApi con el bot específico
        // ogLog::info("CRON Followup - Configurando Chat API para el bot", $botData, $logMeta);
        $chatapi::setConfig($botData);

        // Preparar texto y source_url
        $messageText = $fup['text'] ?? '';
        $sourceUrl = $fup['source_url'] ?? '';

        // Enviar mensaje (puede ser solo texto, solo media, o ambos)
        $result = $chatapi::send($fup['number'], $messageText, $sourceUrl);

        if ($result['success']) {
          // Marcar como procesado
          ogApp()->handler('followup')::markProcessed($fup['id']);

          // Preparar mensaje corto para el registro
          if (!empty($messageText)) {
            $shortMessage = mb_strlen($messageText) > 20
              ? mb_substr($messageText, 0, 20) . '...'
              : $messageText;
          } else if (!empty($sourceUrl)) {
            // Si solo tiene media, usar descripción
            $shortMessage = '[Media enviado]';
          } else {
            $shortMessage = '[Followup enviado]';
          }

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
          ChatHandler::register(
            $fup['bot_id'],
            $fup['bot_number'],
            $fup['client_id'],
            $fup['number'],
            $shortMessage,
            'S',
            'text',
            $metadata,
            $fup['sale_id']
          );

          ChatHandler::addMessage([
            'number' => $fup['number'],
            'bot_id' => $fup['bot_id'],
            'client_id' => $fup['client_id'],
            'sale_id' => $fup['sale_id'],
            'message' => $shortMessage,
            'format' => 'text',
            'metadata' => $metadata
          ], 'S');

          ogLog::info("CRON Followup - Followup enviado exitosamente", [ 'followup_id' => $fup['id'], 'number' => $fup['number'] ], $logMeta);
          $sent++;
        } else {
          ogLog::error("CRON Followup - Error enviando followup", [ 'followup_id' => $fup['id'], 'error' => $result['error'] ?? 'unknown' ], $logMeta);
          $failed++;
        }

        sleep(rand(2, 4));

      } catch (Exception $e) {
        ogLog::error("CRON Followup - Error procesando followup", [ 'followup_id' => $fup['id'], 'error' => $e->getMessage() ], $logMeta);
        $failed++;
      }
    }

    ogLog::info("CRON Followup - Proceso finalizado", [ 'total' => count($followups), 'sent' => $sent, 'failed' => $failed, 'upsells_executed' => $upsellsExecuted ], $logMeta);

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
    $affected = ogApp()->handler('followup')::cancelBySale($sale_id);
    ogLog::info("Followups cancelados por venta", ['sale_id'=>$sale_id,'affected'=>$affected], ['module'=>'followup']);
    ogResponse::json(['success'=>true,'affected'=>$affected]);
  })->middleware(['auth', 'throttle:100,1']);

  // Estadísticas por día - GET /api/followup/stats/by-day?range=last_7_days
  $router->get('/stats/by-day', function() {
    ogResponse::json( ogApp()->handler('followup')::getStatsByDay(['range' =>  ogRequest::query('range', 'last_7_days') ]) );
  })->middleware(['auth', 'throttle:100,1']);

});