<?php
// Las rutas CRUD se auto-registran desde product.json

$router->group('/api/product', function($router) {
  // Clonar producto: POST /api/product/clone
  // Body: { product_id: X, target_user_id: Y }
  $router->post('/clone', function() {
    ogApp()->controller('product')->clone();
  })->middleware(['auth', 'json']);

  // ─────────────────────────────────────────────────────────────────────────
  // Bienvenida Forzada: POST /api/product/force-welcome
  // Body: { bot_id: int, product_id: int, phone: string }
  // Ejecuta el flujo de bienvenida de un producto a un número dado sin pasar
  // por la detección de anuncio ni el webhook de Evolution/WhatsApp.
  // ─────────────────────────────────────────────────────────────────────────
  $router->post('/force-welcome', function() {
    $logMeta = ['module' => 'route/product/force-welcome', 'layer' => 'app/routes'];
    $data    = ogRequest::data();

    $botId     = $data['bot_id']     ?? null;
    $productId = $data['product_id'] ?? null;
    $rawPhone  = $data['phone']      ?? null;

    // ── Validar parámetros obligatorios ────────────────────────────────────
    if (!$botId || !$productId || !$rawPhone) {
      ogResponse::json(['success' => false, 'error' => 'bot_id, product_id y phone son obligatorios'], 400);
    }

    // ── Cargar bot desde DB ────────────────────────────────────────────────
    $botRow = ogDb::table('bots')
      ->where('id', (int)$botId)
      ->where('status', 1)
      ->first();

    if (!$botRow) {
      ogResponse::json(['success' => false, 'error' => 'Bot no encontrado o inactivo'], 404);
    }

    // ── Cargar producto desde DB ───────────────────────────────────────────
    $productRow = ogDb::table('products')
      ->where('id', (int)$productId)
      ->where('bot_id', (int)$botId)
      ->where('status', 1)
      ->first();

    if (!$productRow) {
      ogResponse::json(['success' => false, 'error' => 'Producto no encontrado, inactivo o no pertenece al bot'], 404);
    }

    // ── Normalizar teléfono ────────────────────────────────────────────────
    $countryPrefixes = [
      'EC' => '593', 'PE' => '51',  'CO' => '57',  'MX' => '52',
      'AR' => '54',  'BR' => '55',  'CL' => '56',  'VE' => '58',
      'BO' => '591', 'PY' => '595', 'UY' => '598', 'GT' => '502',
      'HN' => '504', 'SV' => '503', 'NI' => '505', 'CR' => '506',
      'PA' => '507', 'DO' => '1',   'CU' => '53',  'US' => '1',
      'ES' => '34',
    ];

    $countryCode = strtoupper(trim($botRow['country_code'] ?? 'EC'));
    $prefix      = $countryPrefixes[$countryCode] ?? '593';
    $digits      = preg_replace('/\D/', '', $rawPhone);

    if (strlen($digits) === 0) {
      ogResponse::json(['success' => false, 'error' => 'El número de teléfono no contiene dígitos válidos'], 400);
    }

    // Normalizar prefijo
    if (strpos($digits, '00' . $prefix) === 0) {
      $normalized = substr($digits, 2);
    } elseif (strpos($digits, $prefix) === 0) {
      $normalized = $digits;
    } elseif (strpos($digits, '0') === 0) {
      $normalized = $prefix . substr($digits, 1);
    } else {
      $normalized = $prefix . $digits;
    }

    if (strlen($normalized) < strlen($prefix) + 7) {
      ogResponse::json(['success' => false, 'error' => 'Número de teléfono demasiado corto'], 400);
    }

    ogLog::info('force-welcome - Payload validado', [
      'bot_id'     => $botRow['id'],
      'product_id' => $productRow['id'],
      'phone'      => $normalized
    ], $logMeta);

    // ── Construir estructuras mínimas ──────────────────────────────────────
    $bot = [
      'id'           => (int)$botRow['id'],
      'user_id'      => (int)$botRow['user_id'],
      'name'         => $botRow['name'],
      'number'       => $botRow['number'],
      'country_code' => $botRow['country_code'],
      'type'         => $botRow['type'],
      'mode'         => $botRow['mode'],
      'status'       => $botRow['status'],
      'config'       => $botRow['config'] ?? null,
    ];

    $person = [
      'number'   => $normalized,
      'name'     => 'unknown',
      'platform' => 'forced',
    ];

    // ── Ejecutar flujo de bienvenida forzada ───────────────────────────────
    try {
      $path = ogApp()->getPath();

      // Cargar dependencias del handler (igual que resolveHandler en WebhookController)
      require_once $path . '/workflows/core/events/ActionDispatcher.php';
      require_once $path . '/workflows/core/events/ActionRegistry.php';
      require_once $path . '/workflows/core/events/ActionHandler.php';
      require_once $path . '/workflows/core/support/MessageClassifier.php';
      require_once $path . '/workflows/core/support/MessageBuffer.php';
      require_once $path . '/workflows/core/validators/ConversationValidator.php';
      require_once $path . '/workflows/core/validators/WelcomeValidator.php';
      require_once $path . '/workflows/infoproduct/actions/DoesNotWantProductAction.php';
      require_once $path . '/workflows/versions/infoproduct-v2.php';

      $handler = new InfoproductV2Handler();
      $handler->forceWelcome($bot, $person, (int)$productId);

      ogResponse::success([
        'message'    => 'Bienvenida forzada ejecutada correctamente',
        'bot_id'     => $bot['id'],
        'product_id' => (int)$productId,
        'phone'      => $normalized,
      ]);

    } catch (Exception $e) {
      ogLog::error('force-welcome - Error crítico', ['error' => $e->getMessage()], $logMeta);
      ogResponse::serverError('Error ejecutando bienvenida forzada', $e->getMessage() ?? null);
    }

  })->middleware(['auth', 'json']);
});