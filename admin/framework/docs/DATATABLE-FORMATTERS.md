# Sistema de Formatters para DataTables

## 📋 Tabla de Contenidos
1. [Formatos Predefinidos](#formatos-predefinidos)
2. [Formatters Personalizados](#formatters-personalizados)
3. [Arquitecturas Disponibles](#arquitecturas-disponibles)
4. [Ejemplos de Uso](#ejemplos-de-uso)
5. [Mejores Prácticas](#mejores-prácticas)

---

## Formatos Predefinidos

Estos formatos están disponibles de forma nativa en `dataTable.js`:

### 1. `date` - Formatear fechas
```json
{
  "dc": {
    "name": "Fecha de Creación",
    "format": "date",
    "width": "120px"
  }
}
```
**Output:** `15/01/2025`

### 2. `datetime` - Formatear fecha y hora
```json
{
  "dc": {
    "name": "Creado",
    "format": "datetime",
    "width": "180px"
  }
}
```
**Output:** `15/01/2025 14:30:45`

### 3. `money` - Formatear moneda
```json
{
  "price": {
    "name": "Precio",
    "format": "money",
    "align": "right"
  }
}
```
**Output:** `$49.99`

### 4. `status` - Estado con badge de color
```json
{
  "status": {
    "name": "Estado",
    "format": "status",
    "width": "120px",
    "align": "center"
  }
}
```
**Output:** `🟢 Activo` o `🔴 Inactivo`

### 5. `boolean` - Sí/No
```json
{
  "active": {
    "name": "Activo",
    "format": "boolean"
  }
}
```
**Output:** `Sí` o `No`

### 6. `uppercase` - Mayúsculas
```json
{
  "code": {
    "name": "Código",
    "format": "uppercase"
  }
}
```
**Output:** `ABC123`

### 7. `lowercase` - Minúsculas
```json
{
  "email": {
    "name": "Email",
    "format": "lowercase"
  }
}
```
**Output:** `user@example.com`

### 8. `capitalize` - Primera letra mayúscula
```json
{
  "name": {
    "name": "Nombre",
    "format": "capitalize"
  }
}
```
**Output:** `Juan pérez` → `Juan pérez`

---

## Formatters Personalizados

### ¿Cuándo crear un formatter personalizado?

✅ **Usar formatter personalizado cuando:**
- Necesitas lógica compleja de negocio
- Requieres HTML personalizado con estilos específicos
- Debes acceder a múltiples campos del row
- Quieres agregar interactividad (botones, tooltips)
- Necesitas validaciones o cálculos complejos

❌ **NO crear formatter personalizado si:**
- Puedes usar un formato predefinido
- Solo necesitas cambiar el texto (usa traducciones)
- Es un cambio puntual (mejor inline en JS)

---

## Arquitecturas Disponibles

### 1️⃣ **Registry Pattern** (Recomendado) ⭐

**Ubicación:** Archivo JS de la extensión  
**Cuándo usar:** Formatters reutilizables que se usan en múltiples tablas

**Ventajas:**
- ✅ Reutilizable en múltiples vistas
- ✅ Fácil de mantener y testear
- ✅ Se define en JSON con string simple
- ✅ Puede usarse en diferentes extensiones

**Ejemplo:**

```javascript
// En: /extensions/infoproduct/assets/js/infoproductProduct.js

static initFormatters() {
  ogDatatable.registerFormatter('product-price', (value, row) => {
    if (!value || value === 0) {
      return '<span class="badge badge-gray">Gratis</span>';
    }
    
    const formatted = new Intl.NumberFormat('es-EC', {
      style: 'currency',
      currency: 'USD'
    }).format(value);
    
    return `<span class="badge badge-success">${formatted}</span>`;
  });
}

// Llamar al inicializar
infoproductProduct.initFormatters();
```

**Uso en JSON:**
```json
{
  "price": {
    "name": "i18n:infoproduct.products.column.price",
    "format": "product-price",
    "width": "120px"
  }
}
```

---

### 2️⃣ **Inline Function** (Para casos únicos)

**Ubicación:** Configuración dinámica en JS  
**Cuándo usar:** Lógica única que solo se usa una vez

**Ventajas:**
- ✅ No requiere registro previo
- ✅ Útil para prototipos rápidos
- ✅ Acceso directo al contexto

**Desventajas:**
- ❌ No se puede usar desde JSON
- ❌ No es reutilizable
- ❌ Más difícil de mantener

**Ejemplo:**

```javascript
// Configurar datatable programáticamente
const datatableConfig = {
  source: 'api/products',
  columns: [
    {
      price: {
        name: 'Precio',
        format: function(value, row) {
          return value > 100 
            ? `<strong class="text-red">${value}</strong>`
            : `<span>${value}</span>`;
        }
      }
    }
  ]
};
```

---

### 3️⃣ **Template String** (Futuro - No implementado aún)

**Concepto:** Usar placeholders en strings para generar HTML

**Propuesta:**
```json
{
  "name": {
    "name": "Producto",
    "format": "template",
    "template": "<div><strong>{name}</strong><br><small>Bot: {bot_id}</small></div>"
  }
}
```

**Estado:** 🚧 Pendiente de implementar

---

### 4️⃣ **Formatter con Opciones** (Futuro - Avanzado)

**Concepto:** Formatter parametrizable desde JSON

**Propuesta:**
```json
{
  "status": {
    "name": "Estado",
    "format": "badge",
    "formatOptions": {
      "field": "status",
      "mapping": {
        "1": { "text": "Activo", "color": "green" },
        "0": { "text": "Inactivo", "color": "red" }
      }
    }
  }
}
```

**Estado:** 🚧 Pendiente de implementar

---

## Ejemplos de Uso

### Ejemplo 1: Precio con Badge de Color

```javascript
// Registrar formatter
ogDatatable.registerFormatter('product-price-badge', (value, row) => {
  if (!value || value === 0) {
    return '<span class="og-bg-gray-200 og-p-1 og-rounded">Gratis</span>';
  }
  
  const formatted = new Intl.NumberFormat('es-EC', {
    style: 'currency',
    currency: 'USD'
  }).format(value);
  
  const color = value > 100 ? 'blue' : 'green';
  return `<span class="og-bg-${color}-100 og-text-${color}-700 og-p-1 og-rounded">${formatted}</span>`;
});
```

**Usar en JSON:**
```json
{
  "price": {
    "name": "Precio",
    "format": "product-price-badge",
    "width": "150px",
    "align": "center"
  }
}
```

---

### Ejemplo 2: Nombre con Info Adicional

```javascript
ogDatatable.registerFormatter('product-name-detailed', (value, row) => {
  const context = row.context === 'infoproductws' ? '📚' : '🛒';
  const botInfo = row.bot_id ? `<small style="color: #6b7280;">Bot #${row.bot_id}</small>` : '';
  
  return `
    <div style="display: flex; flex-direction: column; gap: 0.25rem;">
      <div><span style="margin-right: 0.5rem;">${context}</span><strong>${value}</strong></div>
      ${botInfo}
    </div>
  `;
});
```

---

### Ejemplo 3: Estado con Lógica de Negocio

```javascript
ogDatatable.registerFormatter('product-health', (value, row) => {
  // Calcular "salud" del producto
  const hasPrice = row.price > 0;
  const isActive = row.status == 1;
  const hasDescription = row.description && row.description.length > 10;
  
  const health = [hasPrice, isActive, hasDescription].filter(Boolean).length;
  const total = 3;
  const percentage = Math.round((health / total) * 100);
  
  const colors = {
    100: { bg: 'green', text: '✅ Completo' },
    66: { bg: 'yellow', text: '⚠️ Revisar' },
    33: { bg: 'red', text: '❌ Incompleto' }
  };
  
  const status = colors[Math.floor(percentage / 34) * 34] || colors[33];
  
  return `<span class="og-bg-${status.bg}-100 og-text-${status.bg}-700 og-p-1 og-rounded">${status.text}</span>`;
});
```

---

### Ejemplo 4: Acciones Inline

```javascript
ogDatatable.registerFormatter('product-quick-actions', (value, row) => {
  return `
    <div class="og-flex og-gap-xs" style="justify-content: center;">
      <button 
        class="btn btn-sm btn-primary" 
        onclick="infoproductProduct.quickToggle(${row.id})"
        title="${row.status == 1 ? 'Desactivar' : 'Activar'}">
        ${row.status == 1 ? '⏸️' : '▶️'}
      </button>
      <button 
        class="btn btn-sm btn-secondary" 
        onclick="infoproductProduct.duplicate(${row.id})"
        title="Duplicar">
        📋
      </button>
    </div>
  `;
});
```

---

## Mejores Prácticas

### ✅ DO - Buenas Prácticas

1. **Nomenclatura descriptiva con prefijo**
   ```javascript
   ogDatatable.registerFormatter('product-price-badge', ...); // ✅
   ogDatatable.registerFormatter('price', ...); // ❌ Muy genérico
   ```

2. **Validar datos antes de procesarlos**
   ```javascript
   if (!value && value !== 0) return '';
   if (!row.id) return '<span>N/A</span>';
   ```

3. **Usar clases del sistema (grid.css, colors.css)**
   ```javascript
   return `<span class="og-bg-blue-100 og-text-blue-700 og-rounded og-p-1">${value}</span>`;
   ```

4. **Escapar HTML de valores de usuario**
   ```javascript
   const safe = String(value)
     .replace(/</g, '&lt;')
     .replace(/>/g, '&gt;')
     .replace(/"/g, '&quot;');
   ```

5. **Usar traducciones en lugar de texto hardcoded**
   ```javascript
   const text = __('infoproduct.products.status.active');
   ```

6. **Documentar formatters complejos**
   ```javascript
   // Formatter que calcula el score de completitud del producto
   // basado en campos requeridos: name, price, description, bot_id
   ogDatatable.registerFormatter('product-completion-score', ...);
   ```

---

### ❌ DON'T - Malas Prácticas

1. **NO hacer llamadas a APIs dentro de formatters**
   ```javascript
   // ❌ MAL - Esto hará la tabla muy lenta
   ogDatatable.registerFormatter('user-name', async (value, row) => {
     const user = await ogApi.get(`/api/user/${row.user_id}`);
     return user.name;
   });
   ```

2. **NO usar estilos inline excesivos**
   ```javascript
   // ❌ MAL
   return `<div style="background: blue; color: white; padding: 10px; border-radius: 5px; font-weight: bold;">${value}</div>`;
   
   // ✅ BIEN
   return `<div class="og-bg-blue-600 og-text-white og-p-2 og-rounded og-font-bold">${value}</div>`;
   ```

3. **NO retornar HTML no seguro**
   ```javascript
   // ❌ MAL - Vulnerable a XSS
   return `<div>${row.userInput}</div>`;
   
   // ✅ BIEN - Escapar contenido
   const safe = String(row.userInput).replace(/</g, '&lt;').replace(/>/g, '&gt;');
   return `<div>${safe}</div>`;
   ```

4. **NO modificar el row dentro del formatter**
   ```javascript
   // ❌ MAL
   ogDatatable.registerFormatter('test', (value, row) => {
     row.modified = true; // NO HACER ESTO
     return value;
   });
   ```

5. **NO crear formatters demasiado específicos**
   ```javascript
   // ❌ MAL - Solo sirve para una tabla
   ogDatatable.registerFormatter('infoproduct-table-row-3-price', ...);
   
   // ✅ BIEN - Genérico y reutilizable
   ogDatatable.registerFormatter('price-badge', ...);
   ```

---

## Debugging y Testing

### Ver formatters registrados
```javascript
console.log(ogDatatable.customFormatters);
```

### Verificar si existe un formatter
```javascript
if (ogDatatable.customFormatters.has('product-price')) {
  console.log('✅ Formatter registrado');
}
```

### Remover formatter
```javascript
ogDatatable.unregisterFormatter('product-price');
```

### Test manual en consola
```javascript
const testRow = { id: 1, name: 'Test', price: 99.99, status: 1 };
const formatter = ogDatatable.customFormatters.get('product-price');
console.log(formatter(testRow.price, testRow));
```

---

## Roadmap Futuro

### 🚧 En desarrollo
- [ ] Template strings con placeholders
- [ ] Formatter con opciones parametrizables desde JSON
- [ ] Sistema de validación de formatters

### 💡 Ideas
- [ ] Formatters asíncronos con caché
- [ ] Formatter builder visual
- [ ] Biblioteca de formatters comunes compartida

---

## Resumen

| Método | Ubicación | Reutilizable | Desde JSON | Complejidad |
|--------|-----------|--------------|------------|-------------|
| **Registry** | JS Extension | ✅ Sí | ✅ Sí | Baja |
| **Inline Function** | JS Config | ❌ No | ❌ No | Baja |
| **Template String** | JSON | ✅ Sí | ✅ Sí | Muy Baja (futuro) |
| **Con Opciones** | JSON | ✅ Sí | ✅ Sí | Media (futuro) |

**Recomendación:** Usa **Registry Pattern** para la mayoría de casos.
