# service - Orquestador de servicios de integración

Sistema para cargar y usar servicios de integración (AI, ChatAPI, Email, Storage).

## Uso

```php
// Acceder a servicio
$ai = service::integration('ai');
$response = $ai->getChatCompletion($prompt, $bot);

$chatapi = service::integration('chatapi');
chatapi::send($number, $message, $media);

// Detectar provider automáticamente
$provider = service::detect('chatapi', $webhookData);
// Returns: 'evolution', 'testing', etc.
```

**Servicios disponibles:** `ai`, `chatapi`, `email`, `storage`

Ver: `/framework/core/service.php`, `/framework/services/`
