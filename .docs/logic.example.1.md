# OgLogic: Ejemplos y Estructura de Evaluación

Este documento detalla cómo estructurar reglas lógicas complejas usando `OgLogic` y cómo interpretar la respuesta detallada de `evaluate()`.

## 1. Estructura de Salida (`evaluate`)

A diferencia de `apply()` que solo devuelve el valor final, `evaluate()` devuelve el **contexto** de la decisión. Esto es crítico para saber *por qué* una regla pasó.

**Formato de Respuesta:**
```json
{
  "result": true,          // El resultado final (bool)
  "details": [...],        // Traza completa de la evaluación
  "matched_rules": [       // ¿Qué condiciones específicas activaron el TRUE?
    {
      "operator": "or",    // El operador contenedor
      "index": 1,          // El índice del array que se cumplió (0, 1, 2...)
      "condition": {...},  // La lógica interna que validó
      "details": [...]     // Valores exactos evaluados
    }
  ]
}
```

---

## 2. Escenarios de Ejemplo

### Escenario A: Estrategia de Marketing Híbrida
**Lógica:** Aprobar campaña si es **muy rentable** (ROAS alto) **O** si tiene **mucho volumen** (Conversiones altas).

#### Definición de la Regla (PHP Array)
```php
$reglaMarketing = [
  'or' => [
    // Grupo 0: Alta Rentabilidad (Calidad)
    [
      'and' => [
        ['>=', ['var' => 'roas'], 3.0],
        ['>=', ['var' => 'ctr'], 1.5]
      ]
    ],
    // Grupo 1: Alto Volumen (Cantidad)
    [
      'and' => [
        ['>=', ['var' => 'conversiones'], 10],
        ['<=', ['var' => 'cpa'], 5.0]
      ]
    ]
  ]
];
```

#### Caso de Prueba: Campaña de Volumen
**Datos:**
```json
{
  "roas": 1.2,
  "ctr": 0.8,
  "conversiones": 50,
  "cpa": 3.5
}
```

**Interpretación del Resultado:**
La IA o el sistema puede ver que `matched_rules` apunta al **índice 1**, lo que indica que pasó por volumen, no por rentabilidad.

```json
{
  "result": true,
  "matched_rules": [
    {
      "index": 1,
      "operator": "or",
      "condition": {
        "and": [
          [">=", {"var": "conversiones"}, 10],
          ["<=", {"var": "cpa"}, 5.0]
        ]
      },
      "details": [
        {"result": true, "details": {"left": 50, "right": 10, "operator": ">="}},
        {"result": true, "details": {"left": 3.5, "right": 5.0, "operator": "<="}}
      ]
    }
  ]
}
```

---

### Escenario B: Permisos de Usuario (Anidación)
**Lógica:** Permitir acceso si el usuario es **Admin** **O** si es **Editor Y es dueño del post**.

#### Definición de la Regla
```php
$reglaAcceso = [
  'or' => [
    ['==', ['var' => 'rol'], 'admin'], // Opción A (Índice 0)
    [
      'and' => [ // Opción B (Índice 1)
        ['==', ['var' => 'rol'], 'editor'],
        ['==', ['var' => 'user_id'], ['var' => 'post_author_id']]
      ]
    ]
  ]
];
```

#### Caso de Prueba: Editor intentando editar su propio post
**Datos:**
```json
{
  "rol": "editor",
  "user_id": 99,
  "post_author_id": 99
}
```

**Interpretación del Resultado:**
El sistema detecta que no es admin (falló índice 0), pero cumplió las condiciones complejas del índice 1.

```json
{
  "result": true,
  "matched_rules": [
    {
      "index": 1,
      "operator": "or",
      "details": [
        {"condition": ["==", {"var": "rol"}, "editor"], "result": true},
        {"condition": ["==", {"var": "user_id"}, {"var": "post_author_id"}], "result": true}
      ]
    }
  ]
}
```

---

### Escenario C: Validación de Stock y Exclusividad
**Lógica:** `(Stock > 0) AND ( (No es exclusivo) OR (Es exclusivo AND Tiene Membresía) )`

#### Definición de la Regla
```php
$reglaProducto = [
  'and' => [
    ['>', ['var' => 'stock'], 0], // Condición base obligatoria
    [
      'or' => [
        ['==', ['var' => 'es_exclusivo'], false],
        ['and' => [
            ['==', ['var' => 'es_exclusivo'], true],
            ['==', ['var' => 'tiene_membresia'], true]
        ]]
      ]
    ]
  ]
];
```

#### Caso de Prueba: VIP comprando producto exclusivo
**Datos:** `{'stock': 5, 'es_exclusivo': true, 'tiene_membresia': true}`

**Interpretación del Resultado:**
1. El primer `AND` (Stock) se cumple.
2. El segundo `AND` evalúa un grupo `OR`.
3. Dentro del `OR`, la primera opción falla (es exclusivo), pero la segunda opción (Membresía) pasa.
4. `evaluate()` devolverá `result: true` y en `matched_rules` verás la ruta lógica exacta.