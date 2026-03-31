<?php
class CheckoutHandler {

  private static $logMeta = ['module' => 'CheckoutHandler', 'layer' => 'app/handler'];

  /**
   * Retorna el contexto de checkout para el quiz.
   *
   * El quiz en metodoefectivo.cc llama este endpoint con el número de la
   * persona y opcionalmente el slug del producto. Si existe una venta
   * pendiente para ese número, devuelve bot_id, product_id, checkout_url
   * y type para que el quiz pueda construir el SCK al final.
   *
   * @param string      $number  Número de WhatsApp (con o sin prefijo de país)
   * @param string|null $slug    Slug del producto (ej: "superacion-infantil")
   * @return array
   */
  static function getContext($number, $slug = null) {
    try {
      $number = preg_replace('/[^0-9]/', '', $number);

      if (empty($number)) {
        return ['success' => false, 'found' => false, 'error' => 'Número inválido'];
      }

      // Si viene slug, intentar encontrar el producto para filtrar la búsqueda
      $productId = null;
      if ($slug) {
        $product = self::findProductBySlug($slug);
        $productId = isset($product['id']) ? (int)$product['id'] : null;
      }

      // Buscar venta pendiente por número
      // El número en sales tiene prefijo de país (ej: 593999...) igual que en WhatsApp
      $query = ogDb::table('sales')
        ->where('number', $number)
        ->where('process_status', 'initiated')
        ->where('status', 1)
        ->orderBy('dc', 'DESC');

      if ($productId) {
        $query->where('product_id', $productId);
      }

      $sale = $query->first();

      if (!$sale) {
        ogLog::debug('CheckoutHandler::getContext - Venta pendiente no encontrada', [
          'number'     => $number,
          'slug'       => $slug,
          'product_id' => $productId
        ], self::$logMeta);

        return ['success' => false, 'found' => false, 'error' => 'Venta pendiente no encontrada'];
      }

      // Cargar producto para obtener checkout_url y type
      $product = ogDb::table('products')
        ->where('id', (int)$sale['product_id'])
        ->where('status', 1)
        ->first();

      if (!$product) {
        return ['success' => false, 'found' => false, 'error' => 'Producto no encontrado'];
      }

      $config = is_string($product['config']) ? json_decode($product['config'], true) : ($product['config'] ?? []);
      $type   = $config['hotmart_type'] ?? 'p';

      // Buscar URL de checkout: primero en config JSON, luego en plantilla link_checkout
      $checkoutUrl = $config['hotmart_checkout_url'] ?? null;

      if (!$checkoutUrl) {
        $templates = $config['messages']['templates'] ?? [];
        foreach ($templates as $tpl) {
          if (($tpl['template_type'] ?? '') === 'link_checkout') {
            $checkoutUrl = trim($tpl['url'] ?? '') ?: trim($tpl['message'] ?? '');
            break;
          }
        }
      }

      if (!$checkoutUrl) {
        ogLog::warning('CheckoutHandler::getContext - URL de checkout no encontrada', [
          'product_id' => $product['id'],
          'product'    => $product['name']
        ], self::$logMeta);

        return ['success' => false, 'found' => false, 'error' => 'URL de checkout no configurada para este producto'];
      }

      ogLog::debug('CheckoutHandler::getContext - Contexto encontrado', [
        'number'     => $number,
        'sale_id'    => $sale['id'],
        'product_id' => $sale['product_id'],
        'bot_id'     => $sale['bot_id']
      ], self::$logMeta);

      return [
        'success'      => true,
        'found'        => true,
        'number'       => $sale['number'],
        'sale_id'      => (int)$sale['id'],
        'bot_id'       => (int)$sale['bot_id'],
        'product_id'   => (int)$sale['product_id'],
        'checkout_url' => $checkoutUrl,
        'type'         => $type
      ];

    } catch (Exception $e) {
      ogLog::error('CheckoutHandler::getContext - Error', [
        'error' => $e->getMessage()
      ], self::$logMeta);

      return [
        'success' => false,
        'found'   => false,
        'error'   => OG_IS_DEV ? $e->getMessage() : 'Error interno'
      ];
    }
  }

  /**
   * Busca un producto por su hotmart_slug dentro del campo config (JSON).
   * El slug se guarda en config como: {"hotmart_slug": "superacion-infantil", ...}
   *
   * @param string $slug
   * @return array|null
   */
  private static function findProductBySlug($slug) {
    $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($slug)));

    if (empty($slug)) return null;

    return ogDb::table('products')
      ->where('config', 'LIKE', '%"hotmart_slug":"' . $slug . '"%')
      ->where('status', 1)
      ->first();
  }
}
