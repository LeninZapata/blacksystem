<?php

$router->group('/api/country', function($router) {

  $router->get(['/all',''], function() {
    $countries = ogApp()->helper('country')::all();

    $region = ogApp()->helper('request')::query('region');
    if ($region) {
      $countries = array_filter($countries, fn($c) => $c['region'] === strtolower($region));
    }

    $codes = ogApp()->helper('request')::query('codes');
    if ($codes) {
      $codeArray = explode(',', $codes);
      $countries = array_filter($countries, fn($c, $code) => in_array($code, $codeArray), ARRAY_FILTER_USE_BOTH);
    }

    $sort = ogApp()->helper('request')::query('sort', 'name');
    $order = ogApp()->helper('request')::query('order', 'asc');

    $sorted = $countries;
    uasort($sorted, function($a, $b) use ($sort, $order) {
      $cmp = $a[$sort] <=> $b[$sort];
      return $order === 'desc' ? -$cmp : $cmp;
    });

    $result = [];
    foreach ($sorted as $code => $data) {
      $result[] = array_merge(['code' => $code], $data);
    }

    ogApp()->helper('response')::success($result);
  });

  $router->get('/{code}', function($code) {
    $data = ogApp()->helper('country')::get($code);

    if (!$data) {
      ogApp()->helper('response')::notFound(__('country.not_found'));
    }

    $includeTime = ogApp()->helper('request')::query('time');
    if ($includeTime) {
      $data['current_time'] = ogApp()->helper('country')::now($code);
    }

    $data['code'] = strtoupper($code);

    ogApp()->helper('response')::success($data);
  });

  // @example {"datetime": "2025-01-12 10:00:00", "from": "EC", "to": "ES"}
  $router->post('/convert', function() {
    $data = ogApp()->helper('request')::data();

    if (!isset($data['datetime']) || !isset($data['from']) || !isset($data['to'])) {
      ogApp()->helper('response')::json(['success' => false, 'error' => __('country.convert.missing_params')], 400);
    }

    $result = ogApp()->helper('country')::convert($data['datetime'], $data['from'], $data['to']);

    if (!$result) {
      ogApp()->helper('response')::json(['success' => false, 'error' => __('country.convert.error')], 400);
    }

    ogApp()->helper('response')::success(['converted_time' => $result]);
  })->middleware('json');

});

// Ejemplos de uso:
// GET /api/country/all
// GET /api/country/all?region=america
// GET /api/country/all?region=europa&sort=name&order=desc
// GET /api/country/all?codes=EC,CO,PE
// GET /api/country/EC
// GET /api/country/EC?time=1
// POST /api/country/convert {"datetime":"2025-12-14 10:00:00","from":"EC","to":"ES"}