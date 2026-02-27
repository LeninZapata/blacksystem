# Guía: Activar número en WhatsApp Business API (Cloud API de Meta)

## IDs importantes que debes guardar

| Nombre | ID | Descripción |
|--------|-----|-------------|
| **WABA ID** | `2452367395218340` | WhatsApp Business Account — la cuenta contenedora de tu negocio en Meta |
| **Phone Number ID** | `1107672142420396` | Identificador del número `+593 98 728 3117` dentro de la API |
| **App ID** | `1378840610272101` | Tu app "Escala Automatica" en Meta for Developers |
| **Webhook URL** | `https://blacksystem.site/fbv.php` | Donde llegan los eventos de WhatsApp |

---

## Paso 1 — Agregar el número al Business Manager

1. Ve a **business.facebook.com → Configuración → Cuentas de WhatsApp**
2. Selecciona tu WABA (ej. "Erika - aquí para ayudarte")
3. Ve a la pestaña **Números de teléfono → Agregar número de teléfono**
4. Ingresa el número y verifica vía **SMS o llamada** con el código que llega
5. El número quedará en estado **"Pendiente"** — esto es normal, falta el siguiente paso

---

## Paso 2 — Registrar el número en la API (Cloud API)

Este paso es el más importante y el que no es obvio. Sin esto el número queda en "Pendiente" para siempre.

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

> **Nota:** Si no tienes PIN configurado (verificación en dos pasos desactivada), usa `000000`. Si tienes PIN propio, úsalo.

**Respuesta esperada:**
```json
{ "success": true }
```

Luego de esto el número cambia de **"Pendiente"** a **"Conectado"** en el Administrador de WhatsApp.

---

## Paso 3 — Suscribir la app a la WABA para recibir webhooks

Sin este paso los webhooks NO llegan aunque todo lo demás esté configurado.

En el **Graph API Explorer** ejecuta:

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

> **Importante:** Este paso se debe repetir para cada WABA nueva que agregues.

---

## Paso 4 — Verificar que el webhook está configurado

Ve a **developers.facebook.com → tu app → WhatsApp → Configuración → Webhooks**

Asegúrate que:
- La **URL de devolución de llamada** apunta a tu endpoint
- El campo **`messages`** está en estado **"Suscritos"** (toggle azul)

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

## Checklist para agregar un número nuevo

- [ ] Agregar número al BM y verificar con SMS/llamada
- [ ] Ejecutar POST `/{phone_number_id}/register` con pin
- [ ] Verificar que el estado cambió a **"Conectado"**
- [ ] Ejecutar POST `/{waba_id}/subscribed_apps`
- [ ] Verificar que el campo `messages` en webhooks está suscrito
- [ ] Hacer prueba: escribir al número y confirmar que llega el webhook
- [ ] Configurar PIN de verificación en dos pasos (recomendado por seguridad)

---

## Token permanente

Para producción **nunca uses el token temporal de 60 minutos**. Usa el token del **Usuario del Sistema** que se crea en:

**Business Manager → Configuración → Usuarios del sistema → selecciona usuario → Generar token**

Permisos necesarios:
- `whatsapp_business_messaging`
- `whatsapp_business_management`