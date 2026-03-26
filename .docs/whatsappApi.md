# Guía: Activar número en WhatsApp Business API (Cloud API de Meta)

## IDs importantes que debes guardar

| Nombre | ID | Descripción |
|--------|-----|-------------|
| **WABA ID** | `2452367395218340` | WhatsApp Business Account — la cuenta contenedora de tu negocio en Meta |
| **Phone Number ID** | `1107672142420396` | Identificador del número `+593 98 728 3117` dentro de la API |
| **App ID** | `1378840610272101` | Tu app "Escala Automatica" en Meta for Developers |
| **Webhook URL** | `https://blacksystem.site/fbv.php` | Donde llegan los eventos de WhatsApp |

---

## Arquitectura para múltiples BMs

### ⚠️ Limitación crítica: Webhooks entre BMs diferentes

**No es posible recibir webhooks de un número de BM B usando la App de BM A**, aunque tengas:
- ✅ "Control total" asignado en el System User
- ✅ El número visible en BM A
- ✅ Token con todos los permisos
- ✅ Puedas enviar mensajes desde BM A con ese número

Nada de esto te da acceso a los webhooks. Meta enruta los webhooks **siempre al BM dueño del número**, independientemente de los permisos cruzados que tengas.

Los permisos cruzados entre BMs solo sirven para:
- Enviar mensajes
- Ver/crear plantillas
- Administrar el perfil del número

**Nunca sirven para recibir webhooks.**

Esto fue confirmado también por la documentación oficial: los números no pueden migrar entre BMs distintos, y los webhooks siguen al BM dueño del número.

---

### El modelo correcto para escalar

Cada BM necesita su propia App. Todas apuntan al mismo webhook URL de tu servidor:

```
BM A  →  App propia  →  misma Webhook URL tuya
BM B  →  App propia  →  misma Webhook URL tuya
BM C  →  App propia  →  misma Webhook URL tuya
```

Tu servidor recibe todo en un solo lugar y distingue los números por el `phone_number_id` del payload.

### Token por número

Cada número debe tener su propio token en tu base de datos:

```sql
tabla: whatsapp_numbers
├── phone_number_id   -- ej: 961973587007174
├── waba_id           -- ej: 4322425164664526
├── display_number    -- ej: +54 9 2262 50-4753
├── nombre            -- ej: Erika
├── access_token      -- token permanente de SU App/BM
├── app_id            -- App a la que pertenece
└── activo
```

---

## Paso 1 — Agregar el número al Business Manager

1. Ve a **business.facebook.com → Configuración → Cuentas de WhatsApp**
2. Selecciona tu WABA
3. Ve a la pestaña **Números de teléfono → Agregar número de teléfono**
4. Ingresa el número y verifica vía **SMS o llamada** con el código que llega
5. El número quedará en estado **"Pendiente"** — esto es normal, falta el siguiente paso

---

## Paso 2 — Registrar el número en la API (Cloud API) ⚠️ OBLIGATORIO

Este paso es **el más importante** y el que no es obvio. Sin esto el número queda en "Pendiente" y **no existe en la red de WhatsApp** (los mensajes llegan con 1 solo visto).

> **⚠️ Importante:** Este paso es obligatorio para **TODOS los números nuevos**, sin excepción, incluso si la WABA ya existe y ya tiene otros números configurados.

Ve al **Graph API Explorer**: `developers.facebook.com/tools/explorer`

Ejecuta este POST:

```
POST /{phone_number_id}/register
```

Body JSON:
```json
{
  "messaging_product": "whatsapp",
  "pin": "000000"
}
```

> **Nota sobre el PIN:** Si el número nunca tuvo verificación en dos pasos activada, usa `000000`. Si tenía PIN propio configurado antes de eliminar WhatsApp, úsalo. Si no recuerdas el PIN, reinstala WhatsApp, activa verificación en dos pasos con un PIN nuevo, anótalo, elimina la cuenta y luego usa ese PIN aquí.

**Respuesta esperada:**
```json
{ "success": true }
```

Luego de esto el número cambia de **"Pendiente"** a **"Conectado"** en el Administrador de WhatsApp y aparece activo en la red de WhatsApp (los mensajes llegan con 2 vistos).

> **¿Por qué tarda unos segundos?** El endpoint `/register` propaga el número a la red de WhatsApp, por eso puede demorar 3-10 segundos en responder. Es normal.

---

## Paso 3 — Suscribir la App a la WABA para recibir webhooks ⚠️ OBLIGATORIO

Sin este paso los webhooks **NO llegan** aunque todo lo demás esté configurado. Son 2 cosas completamente distintas: el `register` activa el número en WhatsApp, el `subscribed_apps` conecta los eventos a tu App.

> **⚠️ Importante:** El endpoint correcto usa el **WABA ID**, NO el Phone Number ID.

```
POST /{waba_id}/subscribed_apps
```

Sin body, solo con tu token de acceso.

**Ejemplo:**
```
POST /2452367395218340/subscribed_apps
```

**Respuesta esperada:**
```json
{ "success": true }
```

Para verificar que quedó correctamente suscrito:
```
GET /{waba_id}/subscribed_apps?access_token={TOKEN}
```

Debe devolver tu App en la respuesta.

> **Importante:** Este paso se debe repetir para cada WABA nueva que agregues. Una App puede estar suscrita a múltiples WABAs.

> **Clave:** Debes usar el **token permanente del Usuario del Sistema** (no el token personal del Graph API Explorer). Con el token personal da error 100 porque no tiene permisos sobre la WABA.

---

## Paso 4 — Verificar que el webhook está configurado

Ve a **developers.facebook.com → tu app → WhatsApp → Configuración → Webhooks**

Asegúrate que:
- La **URL de devolución de llamada** apunta a tu endpoint
- El campo **`messages`** está en estado **"Suscritos"** (toggle azul)

---

## Paso 5 — Números de BM diferente (clientes o WABAs externas)

Cuando el número pertenece a otro BM (cliente), el proceso es el mismo pero usando el token del System User de **ese BM**:

1. El cliente crea un System User en su BM con permisos `whatsapp_business_messaging` y `whatsapp_business_management`
2. Te pasa el token permanente y el WABA ID
3. Ejecutas el `register` con el token del cliente
4. Ejecutas el `subscribed_apps` con el token del cliente apuntando a **su App**
5. Guardas en tu sistema: `phone_number_id` + token del cliente

> **Nota:** No es posible recibir webhooks de un número de otro BM usando tu propia App. Cada BM necesita su propia App suscrita.

---

## Resumen de llamadas API para uso diario

### Enviar mensaje de texto libre (dentro de ventana de 24h)

```
POST https://graph.facebook.com/v25.0/{phone_number_id}/messages
Authorization: Bearer {TOKEN}
Content-Type: application/json
```

```json
{
  "messaging_product": "whatsapp",
  "to": "593978745575",
  "type": "text",
  "text": {
    "body": "Tu mensaje aquí"
  }
}
```

---

### Enviar mensaje con botones (dentro de ventana de 24h)

```json
{
  "messaging_product": "whatsapp",
  "to": "593978745575",
  "type": "interactive",
  "interactive": {
    "type": "button",
    "body": {
      "text": "¿En qué te puedo ayudar?"
    },
    "footer": {
      "text": "Erika - aquí para ayudarte"
    },
    "action": {
      "buttons": [
        { "type": "reply", "reply": { "id": "btn_1", "title": "Ver productos" }},
        { "type": "reply", "reply": { "id": "btn_2", "title": "Hablar con agente" }},
        { "type": "reply", "reply": { "id": "btn_3", "title": "Seguimiento" }}
      ]
    }
  }
}
```

---

### Enviar mensaje con lista de opciones (dentro de ventana de 24h)

```json
{
  "messaging_product": "whatsapp",
  "to": "593978745575",
  "type": "interactive",
  "interactive": {
    "type": "list",
    "body": { "text": "Selecciona una opción" },
    "footer": { "text": "Erika - aquí para ayudarte" },
    "action": {
      "button": "Ver opciones",
      "sections": [
        {
          "title": "Servicios",
          "rows": [
            { "id": "op_1", "title": "Opción 1", "description": "Descripción opcional" },
            { "id": "op_2", "title": "Opción 2" }
          ]
        }
      ]
    }
  }
}
```

---

### Enviar template (para iniciar conversación tú primero)

```json
{
  "messaging_product": "whatsapp",
  "to": "593978745575",
  "type": "template",
  "template": {
    "name": "nombre_del_template",
    "language": { "code": "es" },
    "components": [
      {
        "type": "body",
        "parameters": [
          { "type": "text", "text": "valor del {{1}}" }
        ]
      }
    ]
  }
}
```

> **Nota sobre números nuevos sin WhatsApp previo:** Si el número nunca tuvo WhatsApp instalado, no aparecerá en búsquedas de WhatsApp Web y los mensajes llegarán con 1 solo visto hasta completar el `register`. Una vez registrado vía API queda activo en la red. Para poder escribirle primero necesitarás una plantilla aprobada por Meta.

---

## Estructura del webhook entrante (mensaje de cliente)

```json
{
  "object": "whatsapp_business_account",
  "entry": [{
    "id": "2452367395218340",
    "changes": [{
      "value": {
        "messaging_product": "whatsapp",
        "metadata": {
          "display_phone_number": "593987283117",
          "phone_number_id": "1107672142420396"
        },
        "contacts": [{
          "profile": { "name": "Nombre del cliente" },
          "wa_id": "593978745575"
        }],
        "messages": [{
          "from": "593978745575",
          "id": "wamid.xxx",
          "timestamp": "1772130098",
          "text": { "body": "Mensaje del cliente" },
          "type": "text"
        }]
      },
      "field": "messages"
    }]
  }]
}
```

---

## Reglas de ventanas de tiempo (costos)

| Situación | Ventana | Costo |
|-----------|---------|-------|
| Cliente te escribe primero | 24 horas gratis | $0 |
| Cliente viene de un ad de Facebook/Instagram | 72 horas gratis | $0 |
| Tú inicias la conversación con template de utilidad | — | ~$0.004 |
| Tú inicias con template de marketing | — | ~$0.02–$0.06 según país |

---

## Diferencia entre register y subscribed_apps

| Endpoint | Para qué sirve | Sin esto... |
|---|---|---|
| `POST /{phone_number_id}/register` | Activa el número en la red de WhatsApp | El número no existe en WA, 1 solo visto |
| `POST /{waba_id}/subscribed_apps` | Conecta los webhooks a tu App | El número existe pero no llegan webhooks |

**Ambos son obligatorios. No son opcionales ni se reemplazan entre sí.**

---

## Checklist para agregar un número nuevo

- [ ] Agregar número al BM y verificar con SMS/llamada
- [ ] Ejecutar `POST /{phone_number_id}/register` con pin (usar `000000` si no hay PIN propio)
- [ ] Verificar que el estado cambió a **"Conectado"**
- [ ] Verificar que los mensajes llegan con **2 vistos** (no 1 solo)
- [ ] Si es WABA nueva: ejecutar `POST /{waba_id}/subscribed_apps`
- [ ] Verificar suscripción con `GET /{waba_id}/subscribed_apps`
- [ ] Asignar la nueva Cuenta de WhatsApp al usuario del sistema con Control total
- [ ] Regenerar el token del usuario del sistema
- [ ] Obtener el **Phone Number ID** real desde el WhatsApp Manager
- [ ] Verificar que el campo `messages` en webhooks está suscrito
- [ ] Hacer prueba: escribir al número y confirmar que llega el webhook
- [ ] Guardar en tu sistema: `phone_number_id`, `waba_id`, `access_token`, `app_id`
- [ ] Configurar PIN de verificación en dos pasos (recomendado por seguridad)

---

## Token permanente

Para producción **nunca uses el token temporal de 60 minutos**. Usa el token del **Usuario del Sistema** que se crea en:

**Business Manager → Configuración → Usuarios del sistema → selecciona usuario → Generar token**

Permisos necesarios:
- `whatsapp_business_messaging`
- `whatsapp_business_management`

> **Recuerda:** Cada vez que asignes un activo nuevo al usuario del sistema debes regenerar el token para que los nuevos permisos queden incluidos.

---

## Usar número de BM B en campañas del BM A (Click-to-WhatsApp)

Cuando tienes números en distintos BMs pero quieres correr todas las campañas desde un BM principal (BM A), necesitas hacer una vinculación especial. Los webhooks y el bot siguen funcionando desde BM B, pero el número aparece disponible para anuncios en BM A.

### El flujo completo paso a paso (confirmado y probado)

**Paso 1 — En BM B (dueño del número):**
1. Ve a **Configuración → Cuentas de WhatsApp → selecciona la cuenta del número**
2. Haz clic en **"Asignar socio"**
3. Ingresa el ID del BM A y asígnalo con **Control total**
4. Haz clic en **"Asignar"**

**Paso 2 — Verificar en BM A:**
1. Ve a **BM A → Configuración → Cuentas de WhatsApp**
2. El número de BM B aparece automáticamente con etiqueta **"Pertenece a BM no agrega persona"**
3. Selecciónalo y haz clic en **"Asignar acceso"**
4. Agrega las personas del BM A que necesiten usarlo con **Control total**

✅ Con solo estos 2 pasos el número ya aparece disponible en el selector de anuncios de BM A **sin estado "Pendiente"** y listo para usar en campañas Click-to-WhatsApp.

---

### Caso especial: número con historia previa en el Fanpage

Si el número ya estuvo vinculado anteriormente al Fanpage desde WhatsApp Business App, Meta puede requerir una nueva aprobación. En ese caso:

1. Entra al Fanpage y ve a:
   `https://www.facebook.com/settings/?tab=linked_whatsapp`
2. Aparecerá una **"Solicitud de conexión pendiente"** con el número
3. Confirma la conexión desde ahí
4. Ve a **BM B → Configuración → Solicitudes → Requieren revisión**
5. Aprueba la solicitud: *"BM A solicitó conectar la página [Fanpage] con la cuenta de WhatsApp [número]"*

> **Nota:** Este paso adicional solo ocurre con números que ya tuvieron historia previa con ese Fanpage. Números nuevos o sin historia previa aparecen directo en el selector sin necesitar este proceso.

---

### Lo que puedes hacer desde BM A con el número de BM B

| Acción | ¿Funciona? |
|---|---|
| Crear campañas Click-to-WhatsApp | ✅ Sí |
| Usar el número como destino de anuncios | ✅ Sí |
| Enviar mensajes via API con token de BM A | ✅ Sí |
| Ver estadísticas | ✅ Sí |
| Recibir webhooks en App de BM A | ❌ No — siempre van al BM dueño |

### Checklist para número de otro BM en campañas de BM A

- [ ] En BM B: asignar BM A como socio con Control total
- [ ] En BM A: verificar que el número aparece en Cuentas de WhatsApp
- [ ] En BM A: asignar acceso a personas del BM A con Control total
- [ ] Verificar que el número aparece en el selector de anuncios sin "Pendiente"
- [ ] Solo si tiene historia previa: aprobar solicitud en Fanpage y en BM B → Solicitudes

---

## Errores comunes y soluciones

| Error | Causa | Solución |
|---|---|---|
| `Tried accessing nonexisting field (subscribed_apps)` | Token sin permisos o usando Phone Number ID en vez de WABA ID | Usar WABA ID y token del System User correcto |
| `Object does not exist or missing permissions` | Token de BM A intentando operar sobre número de BM B | Usar token del BM dueño del número |
| Mensaje con 1 solo visto | Número no registrado en la red de WhatsApp | Ejecutar `POST /{phone_number_id}/register` |
| Webhooks no llegan aunque número funciona | WABA no suscrita a la App | Ejecutar `POST /{waba_id}/subscribed_apps` |
| `Template name does not exist` | Plantilla en PENDING o no existe en ese idioma | Esperar aprobación o verificar nombre/idioma |