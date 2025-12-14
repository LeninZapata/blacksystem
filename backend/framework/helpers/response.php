<?php
// Response - Manejo de respuestas HTTP/JSON
class response {
  // Respuesta JSON
  static function json($data, $code = 200) {
    if ($code !== 200) {
      log::error('Response Error', ['code' => $code, 'data' => $data], ['module' => 'response']);
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    $opts = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (IS_DEV) $opts |= JSON_PRETTY_PRINT;
    echo json_encode($data, $opts);
    exit;
  }

  // Respuesta exitosa
  static function success($data = null, $msg = null, $code = 200) {
    $res = ['success' => true];
    if ($msg) $res['message'] = $msg;
    if ($data !== null) $res['data'] = $data;  // ✅ FIX: Cambiar if($data) por if($data !== null)
    self::json($res, $code);
  }

  // Respuesta de error
  static function error($error, $code = 400, $details = null) {
    $res = ['success' => false, 'error' => $error];
    if ($details) $res['details'] = $details;
    self::json($res, $code);
  }

  // Errores de validación
  static function validation($errors, $code = 400) {
    self::json(['success' => false, 'errors' => $errors], $code);
  }

  // Shortcuts HTTP
  static function notFound($msg = null) {
    self::json(['success' => false, 'error' => $msg ?? __('helper.response.not_found')], 404);
  }

  static function unauthorized($msg = null) {
    self::json(['success' => false, 'error' => $msg ?? __('helper.response.unauthorized')], 401);
  }

  static function forbidden($msg = null) {
    self::json(['success' => false, 'error' => $msg ?? __('helper.response.forbidden')], 403);
  }

  static function serverError($msg = null, $debug = null) {
    $res = ['success' => false, 'error' => $msg ?? __('helper.response.server_error')];
    if (IS_DEV && $debug) $res['debug'] = $debug;
    self::json($res, 500);
  }
}