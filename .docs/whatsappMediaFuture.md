# Facebook Media Manager — Plan e Investigación

## Comportamiento confirmado de Facebook Graph API

### Envío con `link` (URL externa)
- Facebook **acepta el envío** con `200 OK` y retorna un `message_id`
- **No valida la URL** en el momento del envío
- Si la URL falla al momento de entrega, Facebook intenta descargarla y falla silenciosamente
- Llega un webhook de status `failed` al endpoint (código `131053` media download error)
- **Riesgo**: si el hosting tiene latencia o caída, el media nunca se entrega

### Envío con `media_id` inválido o expirado
- Facebook **rechaza inmediatamente** con error `400`
- Respuesta exacta:
```json
{
  "error": {
    "message": "(#100) Param image['id'] is not a valid whatsapp business account media attachment ID",
    "type": "OAuthException",
    "code": 100,
    "fbtrace_id": "A6HYbfzS587c3RgwCr8RwQE"
  }
}
```
- Esto permite capturar el error en `postToGraph()` y hacer fallback automático

### Envío con `media_id` válido
- El más estable — Facebook ya tiene el archivo en su CDN
- No depende del hosting en el momento del envío
- El `media_id` dura **30 días** desde que se sube
- Solo válido para el mismo `phone_number_id` que lo subió

### Upload de media
```
POST https://graph.facebook.com/v21.0/{PHONE_NUMBER_ID}/media
Authorization: Bearer {ACCESS_TOKEN}
Content-Type: multipart/form-data

messaging_product: whatsapp
type: image/jpeg
file: [archivo binario]
```
Respuesta: `{ "id": "123456789012345" }`

---

## Plan: Media Manager (implementación futura)

### Problema a resolver
Cada producto maneja ~8 archivos de media (audios, imágenes). Con ~10 productos por número son ~80 medias. Subir + olvidar el ID en cada envío es ineficiente y costoso en requests.

### Arquitectura propuesta

```
[Admin sube archivo en el sistema]
        ↓
[Se guarda localmente en storage/media/]
        ↓
[Proceso semanal (cron)] 
  → sube cada archivo a Facebook Graph API
  → guarda media_id + fecha_expira en DB
        ↓
[facebookProvider::sendMessage()]
  → busca media_id vigente para ese archivo
  → si existe y no expiró → envía con id
  → si error code 100 (expirado/inválido) → fallback a link del hosting
  → si link también falla → log + notificación
```

### Tabla sugerida: `product_media`
| campo | tipo | descripción |
|---|---|---|
| `id` | int | PK |
| `product_id` | int | FK productos |
| `bot_number` | varchar | número del bot que subió |
| `file_path` | varchar | ruta local en storage/media/ |
| `file_type` | varchar | image/jpeg, audio/ogg, etc |
| `fb_media_id` | varchar | ID retornado por Facebook |
| `fb_uploaded_at` | datetime | fecha de subida |
| `fb_expires_at` | datetime | uploaded_at + 30 días |
| `dc` | datetime | fecha creación |

### Proceso semanal (cron)
- Ejecuta cada 7 días (antes de los 30 días de expiración)
- Busca registros donde `fb_expires_at < NOW() + 7 days`
- Re-sube el archivo y actualiza `fb_media_id` y `fb_expires_at`

### Fallback en `facebookProvider::postToGraph()`
```php
// Si Facebook retorna error code 100 → media_id inválido
if (isset($data['error']['code']) && $data['error']['code'] === 100) {
  // Reintentar con link externo como fallback
  return $this->retryWithLink($payload, $originalUrl);
}
```

### Prioridad
- **Baja** — el hosting es robusto, este sistema es para mayor estabilidad
- Implementar cuando se tenga el panel de administración de media
- La tabla `product_media` se puede agregar a `tables.php` cuando sea el momento