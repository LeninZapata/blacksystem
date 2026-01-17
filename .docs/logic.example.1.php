<?php

require_once 'OgLogic.php';

echo "=== EJEMPLOS DE AGRUPACIONES LÓGICAS COMPLEJAS ===\n\n";

// --- EJEMPLO 1: IF (x AND y AND z) OR (a AND b AND x) ---
echo "1. IF (x AND y AND z) OR (a AND b AND x)\n\n";

$regla1 = [
  'or' => [
    [
      'and' => [
        ['var' => 'x'],
        ['var' => 'y'],
        ['var' => 'z']
      ]
    ],
    [
      'and' => [
        ['var' => 'a'],
        ['var' => 'b'],
        ['var' => 'x']
      ]
    ]
  ]
];

// Caso 1: Cumple el primer grupo (x AND y AND z)
$datos1a = ['x' => true, 'y' => true, 'z' => true, 'a' => false, 'b' => false];
$resultado1a = OgLogic::apply($regla1, $datos1a);
echo "   Caso 1 - Cumple primer grupo:\n";
echo "   x=true, y=true, z=true, a=false, b=false\n";
echo "   Resultado: " . ($resultado1a ? 'TRUE' : 'FALSE') . "\n\n";

// Caso 2: Cumple el segundo grupo (a AND b AND x)
$datos1b = ['x' => true, 'y' => false, 'z' => false, 'a' => true, 'b' => true];
$resultado1b = OgLogic::apply($regla1, $datos1b);
echo "   Caso 2 - Cumple segundo grupo:\n";
echo "   x=true, y=false, z=false, a=true, b=true\n";
echo "   Resultado: " . ($resultado1b ? 'TRUE' : 'FALSE') . "\n\n";

// Caso 3: No cumple ningún grupo
$datos1c = ['x' => false, 'y' => true, 'z' => true, 'a' => true, 'b' => false];
$resultado1c = OgLogic::apply($regla1, $datos1c);
echo "   Caso 3 - No cumple ningún grupo:\n";
echo "   x=false, y=true, z=true, a=true, b=false\n";
echo "   Resultado: " . ($resultado1c ? 'TRUE' : 'FALSE') . "\n\n";

// Caso 4: Cumple AMBOS grupos
$datos1d = ['x' => true, 'y' => true, 'z' => true, 'a' => true, 'b' => true];
$resultado1d = OgLogic::apply($regla1, $datos1d);
echo "   Caso 4 - Cumple ambos grupos:\n";
echo "   x=true, y=true, z=true, a=true, b=true\n";
echo "   Resultado: " . ($resultado1d ? 'TRUE' : 'FALSE') . "\n\n";

echo str_repeat('-', 60) . "\n\n";

// --- EJEMPLO 2: IF (x OR y OR z) AND (a OR b OR x) ---
echo "2. IF (x OR y OR z) AND (a OR b OR x)\n\n";

$regla2 = [
  'and' => [
    [
      'or' => [
        ['var' => 'x'],
        ['var' => 'y'],
        ['var' => 'z']
      ]
    ],
    [
      'or' => [
        ['var' => 'a'],
        ['var' => 'b'],
        ['var' => 'x']
      ]
    ]
  ]
];

// Caso 1: Cumple ambos grupos
$datos2a = ['x' => true, 'y' => false, 'z' => false, 'a' => true, 'b' => false];
$resultado2a = OgLogic::apply($regla2, $datos2a);
echo "   Caso 1 - Cumple ambos OR:\n";
echo "   x=true, y=false, z=false, a=true, b=false\n";
echo "   Resultado: " . ($resultado2a ? 'TRUE' : 'FALSE') . "\n\n";

// Caso 2: Solo cumple primer grupo
$datos2b = ['x' => false, 'y' => true, 'z' => false, 'a' => false, 'b' => false];
$resultado2b = OgLogic::apply($regla2, $datos2b);
echo "   Caso 2 - Solo cumple primer OR:\n";
echo "   x=false, y=true, z=false, a=false, b=false\n";
echo "   Resultado: " . ($resultado2b ? 'TRUE' : 'FALSE') . "\n\n";

// Caso 3: Solo cumple segundo grupo
$datos2c = ['x' => false, 'y' => false, 'z' => false, 'a' => false, 'b' => true];
$resultado2c = OgLogic::apply($regla2, $datos2c);
echo "   Caso 3 - Solo cumple segundo OR:\n";
echo "   x=false, y=false, z=false, a=false, b=true\n";
echo "   Resultado: " . ($resultado2c ? 'TRUE' : 'FALSE') . "\n\n";

// Caso 4: No cumple ninguno
$datos2d = ['x' => false, 'y' => false, 'z' => false, 'a' => false, 'b' => false];
$resultado2d = OgLogic::apply($regla2, $datos2d);
echo "   Caso 4 - No cumple ninguno:\n";
echo "   x=false, y=false, z=false, a=false, b=false\n";
echo "   Resultado: " . ($resultado2d ? 'TRUE' : 'FALSE') . "\n\n";

echo str_repeat('-', 60) . "\n\n";

// --- EJEMPLO 3: CASO PUBLICITARIO REAL ---
echo "3. EJEMPLO PUBLICITARIO REAL\n\n";
echo "   Regla: (ROAS > 2 AND CTR > 1.5 AND CPC < 0.50)\n";
echo "          OR\n";
echo "          (Conversiones > 10 AND CPA < 5)\n\n";

$reglaPublicitaria = [
  'or' => [
    [
      'and' => [
        ['>', ['var' => 'roas'], 2],
        ['>', ['var' => 'ctr'], 1.5],
        ['<', ['var' => 'cpc'], 0.50]
      ]
    ],
    [
      'and' => [
        ['>', ['var' => 'conversiones'], 10],
        ['<', ['var' => 'cpa'], 5]
      ]
    ]
  ]
];

// Campaña A: Buen ROAS pero bajas conversiones
$campanaA = [
  'roas' => 2.5,
  'ctr' => 2.0,
  'cpc' => 0.40,
  'conversiones' => 5,
  'cpa' => 8
];
$resultadoA = OgLogic::apply($reglaPublicitaria, $campanaA);
echo "   Campaña A (Buen ROAS):\n";
echo "   ROAS: 2.5, CTR: 2.0%, CPC: \$0.40\n";
echo "   Conversiones: 5, CPA: \$8\n";
echo "   → CUMPLE: " . ($resultadoA ? 'SÍ (Subir presupuesto)' : 'NO') . "\n\n";

// Campaña B: Bajo ROAS pero muchas conversiones baratas
$campanaB = [
  'roas' => 1.5,
  'ctr' => 1.0,
  'cpc' => 0.60,
  'conversiones' => 15,
  'cpa' => 4.50
];
$resultadoB = OgLogic::apply($reglaPublicitaria, $campanaB);
echo "   Campaña B (Muchas conversiones):\n";
echo "   ROAS: 1.5, CTR: 1.0%, CPC: \$0.60\n";
echo "   Conversiones: 15, CPA: \$4.50\n";
echo "   → CUMPLE: " . ($resultadoB ? 'SÍ (Subir presupuesto)' : 'NO') . "\n\n";

// Campaña C: No cumple ningún criterio
$campanaC = [
  'roas' => 1.2,
  'ctr' => 0.8,
  'cpc' => 0.70,
  'conversiones' => 3,
  'cpa' => 12
];
$resultadoC = OgLogic::apply($reglaPublicitaria, $campanaC);
echo "   Campaña C (Bajo rendimiento):\n";
echo "   ROAS: 1.2, CTR: 0.8%, CPC: \$0.70\n";
echo "   Conversiones: 3, CPA: \$12\n";
echo "   → CUMPLE: " . ($resultadoC ? 'SÍ (Subir presupuesto)' : 'NO (Pausar o reducir)') . "\n\n";

echo str_repeat('-', 60) . "\n\n";

// --- EJEMPLO 4: TRIPLE ANIDACIÓN ---
echo "4. ANIDACIÓN COMPLEJA (3 niveles)\n\n";
echo "   ((A AND B) OR (C AND D)) AND ((E OR F) OR (G AND H))\n\n";

$reglaCompleja = [
  'and' => [
    [
      'or' => [
        ['and' => [['var' => 'A'], ['var' => 'B']]],
        ['and' => [['var' => 'C'], ['var' => 'D']]]
      ]
    ],
    [
      'or' => [
        ['or' => [['var' => 'E'], ['var' => 'F']]],
        ['and' => [['var' => 'G'], ['var' => 'H']]]
      ]
    ]
  ]
];

$datosComplejo = [
  'A' => false,
  'B' => true,
  'C' => true,
  'D' => true,
  'E' => false,
  'F' => false,
  'G' => true,
  'H' => true
];

$resultadoComplejo = OgLogic::apply($reglaCompleja, $datosComplejo);
echo "   A=false, B=true, C=true, D=true\n";
echo "   E=false, F=false, G=true, H=true\n";
echo "   Resultado: " . ($resultadoComplejo ? 'TRUE' : 'FALSE') . "\n";
echo "   (Cumple porque C AND D es true, y G AND H es true)\n\n";

echo str_repeat('-', 60) . "\n\n";

// --- EJEMPLO 5: CON IF para acciones específicas ---
echo "5. USANDO IF con grupos lógicos para acciones\n\n";

$reglaConAcciones = [
  'if' => [
    // Si cumple (ROAS > 3 AND CTR > 2) OR (Conversiones > 20 AND CPA < 3)
    [
      'or' => [
        ['and' => [['>', ['var' => 'roas'], 3], ['>', ['var' => 'ctr'], 2]]],
        ['and' => [['>', ['var' => 'conversiones'], 20], ['<', ['var' => 'cpa'], 3]]]
      ]
    ],
    'AUMENTAR_50_PORCIENTO',
    
    // Else if cumple (ROAS > 2 OR CTR > 1.5) AND Conversiones > 5
    [
      'and' => [
        ['or' => [['>', ['var' => 'roas'], 2], ['>', ['var' => 'ctr'], 1.5]]],
        ['>', ['var' => 'conversiones'], 5]
      ]
    ],
    'AUMENTAR_20_PORCIENTO',
    
    // Else
    'MANTENER'
  ]
];

$campanas = [
  ['nombre' => 'Premium', 'roas' => 3.5, 'ctr' => 2.5, 'conversiones' => 8, 'cpa' => 4],
  ['nombre' => 'Standard', 'roas' => 2.2, 'ctr' => 1.2, 'conversiones' => 12, 'cpa' => 5],
  ['nombre' => 'Low', 'roas' => 1.5, 'ctr' => 0.8, 'conversiones' => 2, 'cpa' => 8]
];

foreach ($campanas as $campana) {
  $accion = OgLogic::apply($reglaConAcciones, $campana);
  echo "   Campaña {$campana['nombre']}: → $accion\n";
}

echo "\n=== FIN DE EJEMPLOS ===\n";