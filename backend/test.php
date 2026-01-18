<?php
/**
 * TEST DE REGLAS DE AUTO-ESCALADO
 * Versión HTML - Se ve bien en navegador
 */

// Cargar bootstrap
require_once __DIR__ . '/../wp.php';
require_once __DIR__ . '/bootstrap.php';

// Configuración
$RULE_ID = 1;

// ============================================
// ESCENARIOS MOCK CON DIFERENTES ROAS
// ============================================
$mockScenarios = [
  // Escenario 1: ROAS NEGATIVO (pérdida)
  [
    'name' => 'ROAS Negativo (Sin ventas)',
    'spend' => 15.00,
    'sales' => 0.00,
    'results' => 45,
    'impressions' => 8000,
    'reach' => 7200,
    'clicks' => 180,
    'ctr' => 2.25,
    'cpc' => 0.083,
    'cpm' => 1.875
  ],
  
  // Escenario 2: ROAS BAJO (0.5 - pérdida)
  [
    'name' => 'ROAS Bajo 0.5 (Pérdida 50%)',
    'spend' => 20.00,
    'sales' => 10.00,
    'results' => 35,
    'impressions' => 6000,
    'reach' => 5500,
    'clicks' => 120,
    'ctr' => 2.0,
    'cpc' => 0.166,
    'cpm' => 3.33
  ],
  
  // Escenario 3: ROAS BAJO-MEDIO (1.2 - casi break-even)
  [
    'name' => 'ROAS 1.2 (Casi Break-even)',
    'spend' => 10.00,
    'sales' => 12.00,
    'results' => 28,
    'impressions' => 5000,
    'reach' => 4600,
    'clicks' => 95,
    'ctr' => 1.9,
    'cpc' => 0.105,
    'cpm' => 2.0
  ],
  
  // Escenario 4: ROAS MEDIO (2.0 - rentable)
  [
    'name' => 'ROAS 2.0 (Rentable)',
    'spend' => 8.00,
    'sales' => 16.00,
    'results' => 25,
    'impressions' => 4500,
    'reach' => 4100,
    'clicks' => 88,
    'ctr' => 1.95,
    'cpc' => 0.091,
    'cpm' => 1.78
  ],
  
  // Escenario 5: ROAS BUENO (3.5 - muy rentable)
  [
    'name' => 'ROAS 3.5 (Muy Rentable)',
    'spend' => 5.00,
    'sales' => 17.50,
    'results' => 20,
    'impressions' => 3800,
    'reach' => 3500,
    'clicks' => 72,
    'ctr' => 1.89,
    'cpc' => 0.069,
    'cpm' => 1.32
  ],
  
  // Escenario 6: ROAS EXCELENTE (5.0 - excepcional)
  [
    'name' => 'ROAS 5.0 (Excepcional)',
    'spend' => 6.00,
    'sales' => 30.00,
    'results' => 18,
    'impressions' => 3200,
    'reach' => 3000,
    'clicks' => 65,
    'ctr' => 2.03,
    'cpc' => 0.092,
    'cpm' => 1.875
  ],
  
  // Escenario 7: ROAS MUY ALTO (8.0 - viral)
  [
    'name' => 'ROAS 8.0 (Viral)',
    'spend' => 4.00,
    'sales' => 32.00,
    'results' => 15,
    'impressions' => 2500,
    'reach' => 2300,
    'clicks' => 52,
    'ctr' => 2.08,
    'cpc' => 0.077,
    'cpm' => 1.6
  ],
  
  // Escenario 8: ROAS BAJO + MUCHOS RESULTADOS (1.8 pero alto volumen)
  [
    'name' => 'ROAS 1.8 + Alto Volumen',
    'spend' => 25.00,
    'sales' => 45.00,
    'results' => 80,
    'impressions' => 12000,
    'reach' => 10500,
    'clicks' => 250,
    'ctr' => 2.08,
    'cpc' => 0.10,
    'cpm' => 2.08
  ]
];

// Seleccionar escenario aleatorio
$randomIndex = array_rand($mockScenarios);
$selectedScenario = $mockScenarios[$randomIndex];

// Construir métricas
$mockMetrics = [
  'spend' => $selectedScenario['spend'],
  'results' => $selectedScenario['results'],
  'impressions' => $selectedScenario['impressions'],
  'reach' => $selectedScenario['reach'],
  'clicks' => $selectedScenario['clicks'],
  'ctr' => $selectedScenario['ctr'],
  'cpc' => $selectedScenario['cpc'],
  'cpm' => $selectedScenario['cpm']
];

// Calcular ROAS
$mockSales = $selectedScenario['sales'];
$calculatedROAS = $mockMetrics['spend'] > 0 ? $mockSales / $mockMetrics['spend'] : 0;

$scenarioDesc = "📊 {$selectedScenario['name']} | ROAS: " . round($calculatedROAS, 2) . " | Ventas: $" . $mockSales;

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Test de Reglas Auto-Escalado</title>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f7fa; padding: 2rem; line-height: 1.6; }
    .container { max-width: 1200px; margin: 0 auto; background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
    h1 { color: #2c3e50; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 3px solid #3498db; }
    h2 { color: #2c3e50; margin: 2rem 0 1rem 0; font-size: 1.4rem; }
    .section { margin: 1.5rem 0; padding: 1.5rem; background: #f8f9fa; border-radius: 6px; border-left: 4px solid #3498db; }
    .metric-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin: 1rem 0; }
    .metric-card { background: white; padding: 1rem; border-radius: 4px; border: 1px solid #dee2e6; }
    .metric-label { font-size: 0.85rem; color: #6c757d; text-transform: uppercase; }
    .metric-value { font-size: 1.5rem; font-weight: bold; color: #2c3e50; margin-top: 0.25rem; }
    .success { color: #27ae60; font-weight: bold; }
    .error { color: #e74c3c; font-weight: bold; }
    .warning { color: #f39c12; font-weight: bold; }
    .info { color: #3498db; }
    .code { background: #2c3e50; color: #ecf0f1; padding: 1rem; border-radius: 4px; overflow-x: auto; font-family: 'Courier New', monospace; font-size: 0.9rem; white-space: pre; }
    .badge { display: inline-block; padding: 0.4rem 0.8rem; border-radius: 12px; font-size: 0.85rem; font-weight: 600; margin: 0.25rem; }
    .badge-success { background: #d4edda; color: #155724; }
    .badge-danger { background: #f8d7da; color: #721c24; }
    .summary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 2rem; border-radius: 8px; margin: 2rem 0; }
    .summary h2 { color: white; }
    .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-top: 1rem; }
    .summary-item { background: rgba(255,255,255,0.1); padding: 1rem; border-radius: 6px; }
    .summary-label { font-size: 0.9rem; opacity: 0.9; }
    .summary-value { font-size: 1.3rem; font-weight: bold; margin-top: 0.5rem; }
    ul { margin-left: 1.5rem; margin-top: 0.5rem; }
    li { margin: 0.5rem 0; }
  </style>
</head>
<body>
<div class="container">
  <h1>🧪 Test de Reglas de Auto-Escalado</h1>

  <div class="section">
    <p><strong>📋 Regla ID:</strong> <span class="info"><?= $RULE_ID ?></span></p>
    <p><strong>🎲 Escenario Aleatorio:</strong> <span class="warning"><?= $selectedScenario['name'] ?></span></p>
    <p style="margin-top: 0.5rem; padding: 0.75rem; background: #fff3cd; border-radius: 4px; border-left: 4px solid #ffc107;">
      <strong>💡 Tip:</strong> Recarga la página para probar con otro escenario aleatorio
    </p>
  </div>

  <h2>📊 Métricas Simuladas</h2>
  <div class="section">
    <p><strong><?= $scenarioDesc ?></strong></p>
    <div class="metric-grid">
      <div class="metric-card" style="border-left: 4px solid #3498db;">
        <div class="metric-label">ROAS</div>
        <div class="metric-value" style="color: <?= $calculatedROAS >= 2.5 ? '#27ae60' : ($calculatedROAS >= 1 ? '#f39c12' : '#e74c3c') ?>">
          <?= round($calculatedROAS, 2) ?>
        </div>
      </div>
      <div class="metric-card" style="border-left: 4px solid #27ae60;">
        <div class="metric-label">Ventas</div>
        <div class="metric-value">$<?= $mockSales ?></div>
      </div>
      <div class="metric-card">
        <div class="metric-label">Gasto</div>
        <div class="metric-value">$<?= $mockMetrics['spend'] ?></div>
      </div>
      <div class="metric-card">
        <div class="metric-label">Resultados</div>
        <div class="metric-value"><?= $mockMetrics['results'] ?></div>
      </div>
      <div class="metric-card">
        <div class="metric-label">Impresiones</div>
        <div class="metric-value"><?= number_format($mockMetrics['impressions']) ?></div>
      </div>
      <div class="metric-card">
        <div class="metric-label">Alcance</div>
        <div class="metric-value"><?= number_format($mockMetrics['reach']) ?></div>
      </div>
      <div class="metric-card">
        <div class="metric-label">Clics</div>
        <div class="metric-value"><?= $mockMetrics['clicks'] ?></div>
      </div>
      <div class="metric-card">
        <div class="metric-label">CTR</div>
        <div class="metric-value"><?= $mockMetrics['ctr'] ?>%</div>
      </div>
      <div class="metric-card">
        <div class="metric-label">CPC</div>
        <div class="metric-value">$<?= $mockMetrics['cpc'] ?></div>
      </div>
      <div class="metric-card">
        <div class="metric-label">CPM</div>
        <div class="metric-value">$<?= $mockMetrics['cpm'] ?></div>
      </div>
    </div>
  </div>

  <?php
  // ============================================
  // OBTENER REGLA
  // ============================================
  try {
    $rule = ogDb::t('ad_auto_scale')->find($RULE_ID);
    if (!$rule) throw new Exception("Regla no encontrada");
    
    $asset = ogDb::t('product_ad_assets')->find($rule['ad_assets_id']);
    if (!$asset) throw new Exception("Activo no encontrado");
    
    ?>
    <h2>🔍 Regla desde BD</h2>
    <div class="section">
      <p><span class="success">✅ Regla:</span> <strong><?= $rule['name'] ?></strong></p>
      <p><span class="info">Asset ID:</span> <?= $rule['ad_assets_id'] ?></p>
      <p><span class="info">Status:</span> <?= $rule['status'] == 1 ? 'Activo' : 'Inactivo' ?></p>
      <hr style="margin: 1rem 0; border: none; border-top: 1px solid #dee2e6;">
      <p><span class="success">✅ Activo:</span> <strong><?= $asset['ad_asset_name'] ?></strong></p>
      <p><span class="info">Platform:</span> <?= $asset['ad_platform'] ?></p>
      <p><span class="info">Type:</span> <?= $asset['ad_asset_type'] ?></p>
      <p><span class="info">Product ID:</span> <?= $asset['product_id'] ?></p>
    </div>
    
    <?php
    $config = is_string($rule['config']) ? json_decode($rule['config'], true) : $rule['config'];
    ?>
    
    <h2>📝 Configuración</h2>
    <div class="section">
      <p><strong>Lógica:</strong> <?= $config['conditions_logic'] ?></p>
      <p><strong>Grupos de condiciones:</strong> <?= count($config['condition_groups'] ?? []) ?></p>
      <p><strong>Acciones:</strong> <?= count($config['actions'] ?? []) ?></p>
    </div>
    
    <h2>🎯 Condiciones</h2>
    <div class="section">
      <?php foreach ($config['condition_groups'] ?? [] as $i => $group): ?>
        <p><strong>Grupo <?= $i + 1 ?>:</strong></p>
        <ul>
          <?php foreach ($group['conditions'] ?? [] as $condition): ?>
            <li>
              <strong><?= $condition['metric'] ?></strong> 
              <?= $condition['operator'] ?> 
              <strong><?= $condition['value'] ?></strong>
              <span class="info">(rango: <?= $condition['time_range'] ?>)</span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endforeach; ?>
    </div>
    
    <h2>⚡ Acciones</h2>
    <div class="section">
      <?php foreach ($config['actions'] ?? [] as $i => $action): ?>
        <p><strong>Acción <?= $i + 1 ?>:</strong></p>
        <ul>
          <li><strong>Tipo:</strong> <?= $action['action_type'] ?></li>
          <?php if (isset($action['change_by'])): ?>
            <li><strong>Cambio:</strong> <?= $action['change_by'] ?> <?= $action['change_type'] ?></li>
            <li><strong>Límite:</strong> <?= $action['until_limit'] ?> <?= $action['until_currency'] ?></li>
          <?php endif; ?>
        </ul>
      <?php endforeach; ?>
    </div>
    
    <?php
    // ============================================
    // CALCULAR MÉTRICAS
    // ============================================
    $mockMetrics['roas'] = $calculatedROAS; // Usar ROAS calculado
    $mockMetrics['cost_per_result'] = $mockMetrics['results'] > 0 ? $mockMetrics['spend'] / $mockMetrics['results'] : 0;
    $mockMetrics['frequency'] = $mockMetrics['reach'] > 0 ? $mockMetrics['impressions'] / $mockMetrics['reach'] : 0;
    $mockMetrics['cost_per_result'] = round($mockMetrics['cost_per_result'], 2);
    $mockMetrics['frequency'] = round($mockMetrics['frequency'], 2);
    $mockMetrics['roas'] = round($mockMetrics['roas'], 2);
    ?>
    
    <h2>📊 Métricas Calculadas</h2>
    <div class="section">
      <p>
        <strong>ROAS:</strong> 
        <span style="color: <?= $mockMetrics['roas'] >= 2.5 ? '#27ae60' : ($mockMetrics['roas'] >= 1 ? '#f39c12' : '#e74c3c') ?>; font-size: 1.2rem; font-weight: bold;">
          <?= $mockMetrics['roas'] ?>
        </span>
        <span style="margin-left: 1rem; color: #6c757d;">
          (Ventas: $<?= $mockSales ?> / Gasto: $<?= $mockMetrics['spend'] ?>)
        </span>
      </p>
      <p><strong>Cost per Result:</strong> <span class="warning">$<?= $mockMetrics['cost_per_result'] ?></span></p>
      <p><strong>Frequency:</strong> <?= $mockMetrics['frequency'] ?></p>
    </div>
    
    <?php
    // ============================================
    // VALIDACIONES
    // ============================================
    ?>
    <h2>✔️ Validaciones</h2>
    <div class="section">
      <?php
      $hasData = $mockMetrics['spend'] > 0 || $mockMetrics['results'] > 0 || $mockMetrics['impressions'] > 0;
      $hasActivity = $mockMetrics['results'] >= 2;
      ?>
      <p><?= $hasData ? '<span class="success">✅</span>' : '<span class="error">❌</span>' ?> Hay datos de métricas</p>
      <p><?= $hasActivity ? '<span class="success">✅</span>' : '<span class="error">❌</span>' ?> Actividad suficiente (<?= $mockMetrics['results'] ?> resultados, mínimo 2)</p>
    </div>
    
    <?php
    // ============================================
    // EVALUAR CONDICIONES
    // ============================================
    $conditionsLogic = $config['conditions_logic'] ?? 'and_or_and';
    
    $logic = [];
    if ($conditionsLogic === 'and_or_and') {
      $orGroups = [];
      foreach ($config['condition_groups'] as $group) {
        $andConditions = [];
        foreach ($group['conditions'] ?? [] as $condition) {
          if (isset($condition['metric']) && isset($condition['operator']) && isset($condition['value'])) {
            $andConditions[] = [
              $condition['operator'] => [
                ['var' => $condition['metric']],
                (float)$condition['value']
              ]
            ];
          }
        }
        if (!empty($andConditions)) {
          $orGroups[] = ['and' => $andConditions];
        }
      }
      $logic = ['or' => $orGroups];
    }
    
    $conditionsMet = ogApp()->helper('logic')::apply($logic, $mockMetrics);
    ?>
    
    <h2>🧮 Evaluación ogLogic</h2>
    <div class="section">
      <p><strong>Lógica construida:</strong></p>
      <div class="code"><?= json_encode($logic, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?></div>
      <p style="margin-top: 1rem;">
        <strong>Resultado:</strong> 
        <?php if ($conditionsMet): ?>
          <span class="badge badge-success">✅ CONDICIONES CUMPLIDAS</span>
        <?php else: ?>
          <span class="badge badge-danger">❌ CONDICIONES NO CUMPLIDAS</span>
        <?php endif; ?>
      </p>
    </div>
    
    <?php if ($conditionsMet): ?>
      <h2>⚡ Ejecutando Acciones</h2>
      <div class="section">
        <?php foreach ($config['actions'] ?? [] as $action): ?>
          <?php
          $actionType = $action['action_type'] ?? null;
          if ($actionType === 'increase_budget' || $actionType === 'decrease_budget') {
            $currentBudget = 2.00;
            $changeBy = (float)($action['change_by'] ?? 0);
            $changeType = $action['change_type'] ?? 'percent';
            $untilLimit = (float)($action['until_limit'] ?? 0);
            
            $change = $changeType === 'percent' ? $currentBudget * ($changeBy / 100) : $changeBy;
            $newBudget = $actionType === 'increase_budget' ? $currentBudget + $change : $currentBudget - $change;
            $newBudget = $actionType === 'increase_budget' ? min($newBudget, $untilLimit) : max($newBudget, $untilLimit);
          ?>
            <p><strong>🔧 Acción:</strong> <?= $actionType ?></p>
            <p><strong>💰 Presupuesto actual:</strong> $<?= $currentBudget ?></p>
            <p><strong>📈 Cambio:</strong> <?= $actionType === 'increase_budget' ? '+' : '-' ?>$<?= round($change, 2) ?></p>
            <p><strong>💵 Nuevo presupuesto:</strong> <span class="success">$<?= round($newBudget, 2) ?></span></p>
            <p><strong>🎯 Límite:</strong> $<?= $untilLimit ?></p>
            <p>
              <?php if (abs($newBudget - $currentBudget) < 0.01): ?>
                <span class="warning">ℹ️ Sin cambio (ya en el límite)</span>
              <?php else: ?>
                <span class="success">✅ Presupuesto actualizado</span>
              <?php endif; ?>
            </p>
          <?php } ?>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <h2>⏭️ No se Ejecutan Acciones</h2>
      <div class="section">
        <p class="warning">Las condiciones no se cumplieron, no se ejecuta ninguna acción.</p>
      </div>
    <?php endif; ?>
    
    <div class="summary">
      <h2>📋 RESUMEN</h2>
      <div class="summary-grid">
        <div class="summary-item">
          <div class="summary-label">Regla</div>
          <div class="summary-value"><?= $rule['name'] ?> (ID: <?= $RULE_ID ?>)</div>
        </div>
        <div class="summary-item">
          <div class="summary-label">Escenario Aleatorio</div>
          <div class="summary-value"><?= $selectedScenario['name'] ?></div>
        </div>
        <div class="summary-item">
          <div class="summary-label">ROAS</div>
          <div class="summary-value"><?= $mockMetrics['roas'] ?></div>
        </div>
        <div class="summary-item">
          <div class="summary-label">Condiciones</div>
          <div class="summary-value"><?= $conditionsMet ? '✅ Cumplidas' : '❌ No cumplidas' ?></div>
        </div>
        <div class="summary-item">
          <div class="summary-label">Acción</div>
          <div class="summary-value"><?= $conditionsMet ? '⚡ Ejecutada' : '⏭️ Sin acción' ?></div>
        </div>
      </div>
    </div>
    
    <div class="section">
      <p><strong>💡 Para cambiar el test:</strong></p>
      <ol>
        <li>Edita <code>$RULE_ID</code> para probar otra regla</li>
        <li><strong>Recarga la página</strong> para probar con otro escenario aleatorio (8 escenarios disponibles)</li>
        <li>Modifica tus reglas en la BD y recarga para ver cómo se comportan con diferentes ROAS</li>
      </ol>
      <p style="margin-top: 1rem;"><strong>🎲 Escenarios disponibles:</strong></p>
      <ul>
        <li>ROAS Negativo (sin ventas)</li>
        <li>ROAS 0.5 (pérdida 50%)</li>
        <li>ROAS 1.2 (casi break-even)</li>
        <li>ROAS 2.0 (rentable)</li>
        <li>ROAS 3.5 (muy rentable)</li>
        <li>ROAS 5.0 (excepcional)</li>
        <li>ROAS 8.0 (viral)</li>
        <li>ROAS 1.8 + Alto volumen</li>
      </ul>
    </div>
    
    <?php
  } catch (Exception $e) {
    ?>
    <div class="section">
      <p class="error">❌ ERROR: <?= $e->getMessage() ?></p>
    </div>
    <?php
  }
  ?>
  
</div>
</body>
</html>