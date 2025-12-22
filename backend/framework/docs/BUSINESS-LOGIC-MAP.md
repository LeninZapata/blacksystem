# BUSINESS-LOGIC-MAP.md - Mapeo de Lógica de Negocio

Template para planificar y mapear la lógica de negocio de tu SaaS **antes de escribir código**.

---

## 🎯 Objetivo

**Evitar fricción mental** al momento de implementar. Tener un mapa claro de:
- Qué clases necesitas
- Qué métodos tiene cada clase
- En qué orden se ejecutan
- Qué valida cada paso

Este documento es **SIN CÓDIGO**, solo estructura y lógica.

---

## 📋 Template de Mapeo

Para cada funcionalidad de tu SaaS, completa:

```
FUNCIONALIDAD: [Nombre descriptivo]
TRIGGER: [Qué lo dispara - endpoint, webhook, cron, etc.]
OBJETIVO: [Qué debe lograr]

FLUJO:
1. [Paso 1]
2. [Paso 2]
3. [Paso 3]

CLASES INVOLUCRADAS:
- ClassName → método1(), método2()
- OtraClase → método()

VALIDACIONES:
- [Qué validar]
- [Qué validar]

DATOS DE SALIDA:
- [Qué retorna]

ERRORES POSIBLES:
- [Error 1] → Acción
- [Error 2] → Acción
```

---

## 📝 Ejemplo Real: Sistema de Ventas con Bot de WhatsApp

### FUNCIONALIDAD 1: Recibir mensaje de cliente

```
FUNCIONALIDAD: Procesar mensaje entrante de WhatsApp
TRIGGER: POST /api/webhook/whatsapp
OBJETIVO: Detectar intención, responder automáticamente o derivar a humano

FLUJO:
1. Webhook llega con mensaje crudo
2. Detectar provider (Evolution API)
3. Normalizar a formato estándar
4. Buscar o crear cliente en BD
5. Detectar intención del mensaje (AI)
6. Ejecutar acción según intención
7. Guardar interacción en historial

CLASES INVOLUCRADAS:
- chatapi → detectAndNormalize($rawData)
- evolutionNormalizer → normalize(), standardize()
- ClientHandler → getOrCreate($number)
- ai → getChatCompletion($prompt, $bot)
- IntentDetector → detect($message, $context)
- ResponseHandler → send($number, $response)
- db → table('interaction')->insert()

VALIDACIONES:
- Webhook tiene estructura válida
- Cliente existe o se puede crear
- Bot configurado correctamente
- Límite de mensajes no excedido

DATOS DE SALIDA:
{
  "success": true,
  "action": "auto_response",
  "message_sent": "Hola, ¿en qué puedo ayudarte?"
}

ERRORES POSIBLES:
- Provider desconocido → Log y retornar 200 (para no reintentar)
- Cliente sin número válido → Ignorar mensaje
- AI no disponible → Fallback a respuesta genérica
- Límite excedido → Notificar admin y pausar bot
```

---

### FUNCIONALIDAD 2: Crear venta desde chat

```
FUNCIONALIDAD: Registrar venta iniciada por WhatsApp
TRIGGER: Cliente envía productos que quiere comprar
OBJETIVO: Crear registro de venta, calcular total, enviar confirmación

FLUJO:
1. Extraer productos del mensaje (AI)
2. Validar que productos existen en BD
3. Calcular precio total
4. Crear registro en tabla 'sale'
5. Actualizar estadísticas del cliente
6. Enviar confirmación por WhatsApp
7. Marcar chat como "venta_pendiente"

CLASES INVOLUCRADAS:
- ai → analyzeMessage($message, "extract_products")
- db → table('product')->whereIn('name', $products)->get()
- SaleCalculator → calculate($products, $client)
- SaleHandler → create($clientId, $products, $total)
- ClientHandler → updateStats($clientId, $total)
- chatapi → send($number, $confirmation)
- chatapi → sendArchive($number, $messageId, false)

VALIDACIONES:
- Al menos 1 producto extraído
- Productos existen en inventario
- Stock disponible suficiente
- Cliente existe en BD
- Total > 0

DATOS DE SALIDA:
{
  "success": true,
  "sale_id": 123,
  "total": 150.00,
  "products": [...],
  "message_sent": true
}

ERRORES POSIBLES:
- Producto no encontrado → Solicitar clarificación
- Sin stock → Notificar cliente y ofrecer alternativa
- Error al crear venta → Rollback y notificar
- Error al enviar mensaje → Guardar para reintento
```

---

### FUNCIONALIDAD 3: Confirmar pago

```
FUNCIONALIDAD: Marcar venta como pagada
TRIGGER: POST /api/sale/{id}/confirm-payment
OBJETIVO: Actualizar estado de venta, generar factura, notificar

FLUJO:
1. Validar que venta existe
2. Validar que está en estado "pending"
3. Actualizar estado a "paid"
4. Generar número de factura
5. Actualizar inventario (restar stock)
6. Actualizar estadísticas del cliente
7. Enviar factura por WhatsApp
8. Archivar chat

CLASES INVOLUCRADAS:
- db → table('sale')->find($id)
- SaleHandler → confirmPayment($saleId, $paymentMethod)
- InvoiceGenerator → generate($sale)
- InventoryHandler → updateStock($products)
- ClientHandler → updateStats($clientId, 'total_purchases')
- chatapi → send($number, $invoice)
- chatapi → sendArchive($number, $messageId, true)

VALIDACIONES:
- Venta existe
- Estado es "pending"
- Usuario tiene permiso
- Stock aún disponible

DATOS DE SALIDA:
{
  "success": true,
  "sale_id": 123,
  "invoice_number": "FAC-2025-001",
  "status": "paid"
}

ERRORES POSIBLES:
- Venta no encontrada → 404
- Ya fue pagada → 400 "already_paid"
- Sin stock → Cancelar venta y notificar
```

---

## 🗺️ Mapa Visual de Clases (Ejemplo)

```
┌─────────────────────────────────────────────────────┐
│                   WEBHOOK RECEIVER                   │
│  POST /api/webhook/whatsapp                         │
└──────────────────┬──────────────────────────────────┘
                   │
                   ▼
┌─────────────────────────────────────────────────────┐
│              chatapi::detectAndNormalize()          │
│  - Detecta provider                                  │
│  - Normaliza formato                                 │
└──────────────────┬──────────────────────────────────┘
                   │
                   ▼
┌─────────────────────────────────────────────────────┐
│            ClientHandler::getOrCreate()             │
│  - Busca cliente por número                         │
│  - Crea si no existe                                 │
└──────────────────┬──────────────────────────────────┘
                   │
                   ▼
┌─────────────────────────────────────────────────────┐
│          IntentDetector::detect() (AI)              │
│  - Analiza mensaje                                   │
│  - Retorna: "compra", "consulta", "reclamo"         │
└──────────────────┬──────────────────────────────────┘
                   │
        ┌──────────┴──────────┬──────────┐
        ▼                     ▼          ▼
   ┌────────┐          ┌─────────┐  ┌────────┐
   │ Compra │          │Consulta │  │Reclamo │
   └───┬────┘          └────┬────┘  └───┬────┘
       │                    │           │
       ▼                    ▼           ▼
 SaleHandler        ResponseHandler  TicketHandler
```

---

## 📊 Tabla de Decisiones (Ejemplo)

**Intención detectada → Acción a ejecutar**

| Intención | Confianza | Acción | Handler | Respuesta Automática |
|-----------|-----------|--------|---------|---------------------|
| compra | > 80% | Crear venta | SaleHandler::create() | Sí - Confirmar productos |
| compra | 50-80% | Pedir confirmación | ResponseHandler::ask() | Sí - "¿Quieres comprar X?" |
| consulta_producto | > 70% | Enviar info | ProductHandler::getInfo() | Sí - Detalles del producto |
| consulta_precio | > 70% | Enviar precio | ProductHandler::getPrice() | Sí - Precio actual |
| reclamo | > 60% | Crear ticket | TicketHandler::create() | Sí - "Un agente te contactará" |
| saludo | > 90% | Responder | ResponseHandler::greet() | Sí - Mensaje de bienvenida |
| despedida | > 90% | Archivar chat | chatapi::sendArchive() | Sí - "Hasta luego" |
| desconocido | < 50% | Derivar humano | HumanHandler::notify() | Sí - "Te comunicamos con agente" |

---

## 🔄 Workflows Complejos

### Workflow: Proceso de Venta Completo

```
1. MENSAJE INICIAL
   ├─ Cliente: "Quiero 2 laptops HP"
   ├─ IntentDetector → "compra" (95%)
   └─ ai::analyzeMessage() → Extrae: ["laptop HP", cantidad: 2]

2. VALIDACIÓN DE PRODUCTOS
   ├─ db::table('product')->where('name', 'LIKE', '%laptop HP%')
   ├─ ✅ Encontrado: Laptop HP ProBook (stock: 5)
   └─ SaleCalculator::calculate() → Total: $1,200

3. CREACIÓN DE VENTA
   ├─ SaleHandler::create()
   │  ├─ Insert en tabla 'sale'
   │  └─ Estado: "pending"
   ├─ ClientHandler::updateStats() → total_purchases++
   └─ chatapi::send() → "Total: $1,200. ¿Confirmas compra?"

4. ESPERAR CONFIRMACIÓN
   ├─ Cliente: "Sí, confirmo"
   ├─ IntentDetector → "confirmacion" (90%)
   └─ SaleHandler::updateStatus($saleId, 'confirmed')

5. ESPERAR PAGO
   ├─ Admin: POST /api/sale/123/confirm-payment
   ├─ SaleHandler::confirmPayment()
   │  ├─ Update status → "paid"
   │  ├─ InventoryHandler::updateStock() → stock - 2
   │  └─ InvoiceGenerator::generate()
   └─ chatapi::send() → "✅ Pago confirmado. Factura: FAC-001"

6. CIERRE
   ├─ chatapi::sendArchive() → Archivar chat
   └─ Log success
```

---

## 🧩 Patrones de Diseño Identificados

### Patrón 1: Factory para Normalizers
```
PROBLEMA: Múltiples providers de WhatsApp (Evolution, Testing, etc.)
SOLUCIÓN: Factory pattern

chatapi::detectAndNormalize($rawData)
  ├─ Detecta provider
  ├─ Carga Normalizer correcto
  │  ├─ evolutionNormalizer
  │  └─ testingNormalizer
  └─ Retorna datos estandarizados
```

### Patrón 2: Strategy para Intenciones
```
PROBLEMA: Diferentes acciones según intención del mensaje
SOLUCIÓN: Strategy pattern

IntentDetector::detect() → "compra"
IntentRouter::route("compra") → SaleStrategy::execute()

IntentDetector::detect() → "reclamo"
IntentRouter::route("reclamo") → TicketStrategy::execute()
```

### Patrón 3: Chain of Responsibility para Validaciones
```
PROBLEMA: Validar múltiples condiciones antes de crear venta
SOLUCIÓN: Chain of Responsibility

SaleHandler::create()
  ├─ ProductExistsValidator::validate() ✅
  ├─ StockAvailableValidator::validate() ✅
  ├─ ClientActiveValidator::validate() ✅
  └─ Todos pasaron → Crear venta
```

---

## 📝 Template Vacío para Tu Proyecto

### FUNCIONALIDAD: _______________________

```
FUNCIONALIDAD: 
TRIGGER: 
OBJETIVO: 

FLUJO:
1. 
2. 
3. 

CLASES INVOLUCRADAS:
- 
- 

VALIDACIONES:
- 
- 

DATOS DE SALIDA:


ERRORES POSIBLES:
- 
- 
```

---

## 🎯 Checklist de Mapeo Completo

Antes de empezar a codear, asegúrate de tener:

- [ ] Todas las funcionalidades principales mapeadas
- [ ] Flujos de decisión claros (if X → do Y)
- [ ] Validaciones identificadas
- [ ] Clases y métodos nombrados
- [ ] Orden de ejecución definido
- [ ] Errores posibles contemplados
- [ ] Datos de entrada/salida documentados
- [ ] Workflows complejos diagramados
- [ ] Patrones de diseño identificados
- [ ] Dependencias entre clases claras

---

## 🚀 Cómo Usar Este Documento

### Paso 1: Planificar (1-2 horas)
- Llenar templates de las funcionalidades principales
- Identificar clases necesarias
- Mapear flujos de datos

### Paso 2: Revisar (30 min)
- ¿Hay lógica duplicada?
- ¿Faltan validaciones?
- ¿Los nombres son claros?

### Paso 3: Implementar (N horas)
- Crear clases en orden de dependencia
- Implementar método por método
- Seguir el mapa al pie de la letra

### Paso 4: Validar
- ✅ Todo lo mapeado está implementado
- ✅ Los flujos funcionan como se planeó
- ✅ Las validaciones cubren todos los casos

---

## 💡 Tips para Mapear Efectivamente

1. **Empieza simple** - Mapea la funcionalidad más básica primero
2. **Piensa en casos borde** - ¿Qué pasa si X falla?
3. **Nombra con claridad** - `ClientHandler::getOrCreate()` es mejor que `ClientHandler::process()`
4. **Divide en pasos pequeños** - Cada paso = 1 método
5. **Identifica reutilizables** - ¿Esto se puede usar en otra funcionalidad?
6. **Documenta el "por qué"** - No solo el "qué", sino "por qué así"

---

## 📚 Recursos Relacionados

- **FRAMEWORK.md** - Qué clases/helpers tienes disponibles
- **BLUEPRINT.md** - Cómo estructurar el proyecto
- `/framework/docs/` - Documentación de componentes

---

## 🎓 Ejemplo de Uso con IA

**Prompt para IA:**

```
Tengo este mapeo de lógica de negocio:

FUNCIONALIDAD: Procesar pago de venta
TRIGGER: POST /api/sale/{id}/confirm-payment
FLUJO:
1. Validar venta existe y está "pending"
2. Actualizar estado a "paid"
3. Generar factura
4. Actualizar inventario
5. Notificar cliente por WhatsApp

CLASES INVOLUCRADAS:
- SaleHandler → confirmPayment($saleId)
- InvoiceGenerator → generate($sale)
- InventoryHandler → updateStock($products)
- chatapi → send($number, $invoice)

Por favor implementa SaleHandler::confirmPayment() siguiendo este flujo.
Usa el framework documentado en FRAMEWORK.md.
```

La IA generará código consistente siguiendo tu arquitectura.

---

## ✅ Conclusión

Este documento es tu **mapa antes de construir**. 

- Sin él: Código desordenado, refactors constantes, tiempo perdido
- Con él: Implementación directa, código limpio, menos fricción mental

**Invierte 2 horas en mapear, ahorra 20 horas en implementar.**