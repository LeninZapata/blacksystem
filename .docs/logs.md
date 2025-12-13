# 📋 Sistema de Logs - Documentación

## 🎯 Características

✅ **Formato TAB-separated** - Fácil de parsear
✅ **Auto-detección** de archivo y línea
✅ **Tags personalizados** - Agrupar logs por categorías
✅ **Filtros avanzados** - Por módulo, tags, custom vars, user_id
✅ **Configuración flexible** - Presets + templates
✅ **Rotación por tamaño** - Archivos de máximo 1MB
✅ **API REST** - Endpoints para consultar logs
✅ **User ID separado** - Campo dedicado para auditoría

---

## 📋 Formato de Log

### Estructura (TAB-separated)
```
[timestamp]	LEVEL	module	message	context_json	file:line	user_id	tags
```

**Orden de prioridad:** mensaje → datos → ubicación → quién → categoría
**Nota:** Campos vacíos usan `-` para mantener formato consistente (8 columnas TSV)

### Ejemplo Real
```
[2025-12-10 15:30:00]	INFO	auth	Login exitoso	{"user":"admin44"}	userHandlers.php:142	3	auth,login
[2025-12-10 15:35:42]	ERROR	integrations/whatsapp	Error al enviar	{"number":"593987654321","bot_id":10}	whatsapp.php:87	5	whatsapp,error
[2025-12-10 15:40:00]	INFO	user	Usuario actualizado	{"user_id":5}	userController.php:160	3	-
[2025-12-10 15:45:00]	INFO	worker	Proceso completado	-	worker.php:50	-	-
```

---

## 🔧 Uso Básico

### 1. Log Simple
```php
log::info('Usuario creado exitosamente');
```

### 2. Log con Contexto
```php
log::info('Login exitoso', $varBool|$varInt|$varString );
log::info('Login exitoso', ['user' => 'admin44', 'id' => 3]);
```

### 3. Log con Módulo y Tags
```php
log::info('Mensaje enviado', ['text' => 'Hola'], [
  'module' => 'integrations/whatsapp',
  'tags' => ['whatsapp', 'outbound']
]);
```

### 4. Log con Custom Vars
```php
log::info('Mensaje recibido', ['text' => 'Ayuda'], [
  'module' => 'integrations/whatsapp',
  'number' => '593987654321',
  'bot_id' => 10
]);
```

### 5. User ID Automático
```php
// Si hay usuario autenticado ($GLOBALS['auth_user_id']), se incluye automáticamente
log::info('Acción realizada', ['data' => 'value']);
// Resultado: ... {"data":"value"} ... 5 ... (user_id)
```

---

## ⚙️ Configuración

```php
log::setConfig([
  'format' => 'daily',        // single, monthly, daily, custom
  'level' => 'info',          // debug, info, warning, error
  'max_size' => 1048576,      // 1MB
  'enabled' => true
]);
```

---

## 🔍 Consultar Logs (API)

### Endpoints
```bash
GET /api/logs/today?limit=100
GET /api/logs/latest?limit=50
GET /api/logs/2025/12/10
GET /api/logs/search?from=2025-12-01&to=2025-12-10
```

### Filtros Disponibles
- `level` - Por nivel: `?level=ERROR`
- `module` - Por módulo: `?module=integrations/whatsapp`
- `tags` - Por tags: `?tags=whatsapp,error`
- `user_id` - Por usuario: `?user_id=5`
- `search` - Buscar texto: `?search=login`
- `number`, `bot_id`, etc - Custom vars: `?bot_id=10`

### Respuesta
```json
{
  "success": true,
  "data": {
    "logs": [{
      "timestamp": "2025-12-10 15:35:42",
      "level": "INFO",
      "module": "integrations/whatsapp",
      "message": "Mensaje enviado",
      "context": {"number": "593987654321", "bot_id": 10},
      "location": "whatsapp.php:142",
      "user_id": "5",
      "tags": ["whatsapp", "outbound"]
    }],
    "count": 1
  }
}
```

---

## 💡 Casos de Uso

### Auditoría por Usuario
```bash
# Ver acciones de un usuario
GET /api/logs/today?user_id=5

# Acciones en rango de fechas
GET /api/logs/search?user_id=5&from=2025-12-01&to=2025-12-10
```

### WhatsApp Bot
```bash
# Logs de un número
GET /api/logs/today?number=593987654321

# Errores de un bot
GET /api/logs/today?bot_id=10&level=ERROR
```

---

## 🎓 Mejores Prácticas

✅ Módulos jerárquicos: `integrations/whatsapp`
✅ Tags consistentes: `['whatsapp', 'outbound']`
✅ User ID automático desde `$GLOBALS['auth_user_id']`
✅ Contexto flexible: arrays, strings, números, booleans
❌ No loguear passwords ni datos sensibles

**Campos vacíos:** context, user_id y tags usan `-` (formato TSV consistente con 8 columnas)

---

## 📊 Ventajas del Formato

✅ **Mensaje primero** - Lo importante es lo primero
✅ **Datos junto al mensaje** - Contexto inmediato
✅ **User ID separado** - Auditoría rápida
✅ **Ubicación al final** - Debug disponible pero no estorba
✅ **Orden lógico** - Prioridad descendente