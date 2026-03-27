<?php
// Las rutas CRUD se auto-registran desde product.json

$router->group('/api/product', function($router) {
  // Listar productos con código de país: GET /api/product/with-country
  // Retorna id, name, display_name = "[CC] Nombre"
  $router->get('/with-country', function() {
    $userId = $GLOBALS['auth_user_id'] ?? null;
    $products = ProductHandler::getProductsWithCountry($userId);
    ogResponse::success($products);
  })->middleware(['auth']);

  // Productos que aparecen en ventas dentro de un rango: GET /api/product/sales-in-range
  // Params: bot_id, range, date (solo para custom_date)
  // Retorna productos (activos e inactivos) con sus datos para el resumen de stats
  $router->get('/sales-in-range', function() {
    $userId = $GLOBALS['auth_user_id'] ?? null;
    if (!$userId) {
      ogResponse::json(['success' => false, 'error' => __('auth.unauthorized')], 401);
    }

    $botId = ogRequest::query('bot_id');
    $range = ogRequest::query('range', 'today');
    $date  = ogRequest::query('date');

    if (!$botId) {
      ogResponse::json(['success' => false, 'error' => 'bot_id es requerido'], 400);
    }

    // Resolver rango de fechas usando el timezone del usuario (header X-User-Timezone)
    $userTz = ogApp()->helper('date')::getUserTimezone();
    if ($range === 'custom_date' && $date) {
      $dates = ogApp()->helper('date')::localDateToUtcRange($date, $userTz);
    } else {
      $dates = ogApp()->helper('date')::getDateRange($range, $userTz);
    }

    if (!$dates) {
      ogResponse::json(['success' => false, 'error' => 'Rango de fechas inválido'], 400);
    }

    // Fechas locales para query_date (ad_metrics_hourly usa fecha local, no UTC)
    $startDate = ($range === 'custom_date' && $date) ? $date : substr($dates['start'], 0, 10);
    $endDate   = ($range === 'custom_date' && $date) ? $date : substr($dates['end'],   0, 10);

    $sql = "
      SELECT DISTINCT
        p.id,
        p.name,
        p.env,
        p.status,
        p.sale_type_mode
      FROM " . ogDb::t('ad_metrics_hourly', true) . " h
      INNER JOIN " . ogDb::t('products', true) . " p ON h.product_id = p.id
      WHERE h.user_id = ?
        AND p.bot_id  = ?
        AND h.query_date BETWEEN ? AND ?
        AND p.context = 'infoproductws'
        AND p.sale_type_mode != 2
      ORDER BY p.status DESC, p.name ASC
    ";

    $products = ogDb::raw($sql, [$userId, (int)$botId, $startDate, $endDate]);
    ogResponse::success($products ?: []);

  })->middleware(['auth']);

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

  // ─────────────────────────────────────────────────────────────────────────
  // Validar Bienvenida: POST /api/product/validate-welcome
  // Body: { product_id: int, bot_id: int, fb_ad_copy: string, fb_welcome_text: string }
  // Verifica si los welcome_triggers del producto actual coinciden con el
  // fb_ad_copy o fb_welcome_text de otros productos del mismo bot, lo que
  // indicaría un conflicto de activación cruzada.
  // ─────────────────────────────────────────────────────────────────────────
  $router->post('/validate-welcome', function() {
    $logMeta = ['module' => 'route/product/validate-welcome', 'layer' => 'app/routes'];
    $data = ogRequest::data();

    $productId     = isset($data['product_id']) ? (int)$data['product_id'] : 0;
    $botId         = isset($data['bot_id'])      ? (int)$data['bot_id']    : 0;
    $fbAdCopy      = trim($data['fb_ad_copy']      ?? '');
    $fbWelcomeText = trim($data['fb_welcome_text'] ?? '');

    if (!$botId) {
      ogResponse::json(['success' => false, 'error' => 'bot_id es obligatorio'], 400);
      return;
    }

    if (!$productId) {
      ogResponse::json(['success' => false, 'error' => 'product_id es obligatorio'], 400);
      return;
    }

    // ── Obtener welcome_triggers del producto actual ────────────────────────
    $currentProductQuery = ogDb::table('products')
      ->where('id', $productId)
      ->where('bot_id', $botId);

    $currentProduct = $currentProductQuery->first();

    if (!$currentProduct) {
      ogResponse::json(['success' => false, 'error' => 'Producto no encontrado',
        'product_id' => $productId, 'bot_id' => $botId
      ], 201);
      return;
    }

    $currentConfig = is_string($currentProduct['config'])
      ? json_decode($currentProduct['config'], true)
      : ($currentProduct['config'] ?? []);

    $triggersRaw    = $currentConfig['welcome_triggers'] ?? '';
    $triggerPhrases = array_values(array_filter(array_map('trim', explode(',', $triggersRaw))));

    if (empty($triggerPhrases)) {
      ogResponse::success(['is_valid' => true, 'conflicts' => [], 'note' => 'Sin activadores configurados']);
      return;
    }

    // ── Obtener los demás productos del mismo bot ──────────────────────────
    $otherProducts = ogDb::table('products')
      ->where('bot_id', $botId)
      ->where('context', 'infoproductws')
      ->get();

    $str = ogApp()->helper('str');

    $conflicts = [];

    foreach ($otherProducts as $other) {
      if ((int)$other['id'] === $productId) continue;

      $otherConfig = is_string($other['config'])
        ? json_decode($other['config'], true)
        : ($other['config'] ?? []);

      $otherAdCopy      = $otherConfig['fb_ad_copy']      ?? '';
      $otherWelcomeText = $otherConfig['fb_welcome_text'] ?? '';

      if (empty($otherAdCopy) && empty($otherWelcomeText)) continue;

      foreach ($triggerPhrases as $phrase) {
        $matchedIn   = null;
        $matchedText = null;

        if (!empty($otherAdCopy) && $str::containsAllWords($phrase, $otherAdCopy)) {
          $matchedIn   = 'fb_ad_copy';
          $matchedText = $otherAdCopy;
        } elseif (!empty($otherWelcomeText) && $str::containsAllWords($phrase, $otherWelcomeText)) {
          $matchedIn   = 'fb_welcome_text';
          $matchedText = $otherWelcomeText;
        }

        if ($matchedIn) {
          // Extraer las palabras del trigger que aparecen en el texto (misma lógica que containsAllWords)
          $unwanted = ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','à'=>'a','è'=>'e','ì'=>'i','ò'=>'o',
                       'ù'=>'u','â'=>'a','ê'=>'e','î'=>'i','ô'=>'o','û'=>'u','ä'=>'a','ë'=>'e','ï'=>'i',
                       'ö'=>'o','ü'=>'u','ã'=>'a','õ'=>'o','ç'=>'c','ñ'=>'n'];
          $normalizeLocal = fn($t) => strtr(mb_strtolower($t, 'UTF-8'), $unwanted);
          $phraseNorm     = $normalizeLocal($phrase);
          $textNorm       = $normalizeLocal($matchedText);
          $words          = preg_split('/\s+/', $phraseNorm, -1, PREG_SPLIT_NO_EMPTY);
          $matchedWords   = array_values(array_filter($words, fn($w) => strpos($textNorm, $w) !== false));

          $conflicts[] = [
            'product_id'    => (int)$other['id'],
            'product_name'  => $other['name'],
            'trigger'       => $phrase,
            'matched_in'    => $matchedIn,
            'matched_text'  => $matchedText,
            'matched_words' => $matchedWords,
          ];
          break; // Un conflicto por trigger es suficiente
        }
      }
    }

    ogResponse::success([
      'is_valid'  => empty($conflicts),
      'conflicts' => $conflicts,
    ]);

  })->middleware(['auth', 'json']);
});