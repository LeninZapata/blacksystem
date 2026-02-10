// ==============================================================================
// EJEMPLOS DE FORMATTERS PERSONALIZADOS PARA DATATABLES
// ==============================================================================

// Este archivo muestra diferentes estrategias para crear formatters personalizados
// Puedes copiar estos ejemplos a infoproductProduct.js u otro archivo JS de tu extensión

// ==============================================================================
// ESTRATEGIA 1: Formatter Simple (Badge de precio)
// ==============================================================================

// Registrar formatter al cargar el módulo
ogDatatable.registerFormatter('product-price-badge', (value, row) => {
  if (!value || value === 0) {
    return '<span class="og-bg-gray-200 og-p-1 og-rounded og-text-gray-700" style="font-size: 0.875rem;">Gratis</span>';
  }
  
  const priceFormatted = new Intl.NumberFormat('es-EC', {
    style: 'currency',
    currency: 'USD'
  }).format(value);
  
  const color = value > 100 ? 'blue' : value > 50 ? 'green' : 'yellow';
  return `<span class="og-bg-${color}-100 og-p-1 og-rounded og-text-${color}-700" style="font-size: 0.875rem; font-weight: 600;">${priceFormatted}</span>`;
});

// Uso en JSON:
// {
//   "price": {
//     "name": "i18n:infoproduct.products.column.price",
//     "format": "product-price-badge",
//     "width": "120px",
//     "align": "center"
//   }
// }

// ==============================================================================
// ESTRATEGIA 2: Formatter con Iconos (Estado del bot)
// ==============================================================================

ogDatatable.registerFormatter('bot-status-icon', (value, row) => {
  const statusMap = {
    'active': { icon: '🟢', text: 'Activo', color: 'green' },
    'inactive': { icon: '🔴', text: 'Inactivo', color: 'red' },
    'pending': { icon: '🟡', text: 'Pendiente', color: 'yellow' },
    'error': { icon: '⚠️', text: 'Error', color: 'red' }
  };
  
  const status = statusMap[value] || statusMap['inactive'];
  return `<span style="display: flex; align-items: center; gap: 0.5rem;">
    <span style="font-size: 1.2rem;">${status.icon}</span>
    <span class="og-text-${status.color}-600">${status.text}</span>
  </span>`;
});

// ==============================================================================
// ESTRATEGIA 3: Formatter con Datos de Otra Columna
// ==============================================================================

ogDatatable.registerFormatter('product-name-with-context', (value, row) => {
  const contextBadges = {
    'infoproductws': { label: 'Infoproducto', color: 'blue' },
    'ecommercews': { label: 'E-commerce', color: 'purple' },
    'servicews': { label: 'Servicio', color: 'cyan' }
  };
  
  const context = contextBadges[row.context] || { label: row.context, color: 'gray' };
  
  return `
    <div style="display: flex; flex-direction: column; gap: 0.25rem;">
      <strong>${value}</strong>
      <span class="og-bg-${context.color}-100 og-text-${context.color}-700" 
            style="display: inline-block; padding: 0.125rem 0.5rem; border-radius: 0.25rem; font-size: 0.75rem; width: fit-content;">
        ${context.label}
      </span>
    </div>
  `;
});

// ==============================================================================
// ESTRATEGIA 4: Formatter con HTML Complejo (Progreso)
// ==============================================================================

ogDatatable.registerFormatter('product-completion', (value, row) => {
  // Calcular % de completitud basado en campos llenos
  const requiredFields = ['name', 'price', 'description', 'bot_id'];
  const filledFields = requiredFields.filter(field => row[field] && row[field] !== '').length;
  const percentage = Math.round((filledFields / requiredFields.length) * 100);
  
  const color = percentage === 100 ? 'green' : percentage >= 50 ? 'yellow' : 'red';
  
  return `
    <div style="display: flex; align-items: center; gap: 0.5rem; min-width: 150px;">
      <div style="flex: 1; background-color: #e5e7eb; border-radius: 9999px; height: 8px; overflow: hidden;">
        <div style="background-color: var(--og-${color}-500); height: 100%; width: ${percentage}%; transition: width 0.3s;"></div>
      </div>
      <span style="font-size: 0.75rem; color: #6b7280; font-weight: 500; min-width: 40px;">${percentage}%</span>
    </div>
  `;
});

// ==============================================================================
// ESTRATEGIA 5: Formatter con Lógica de Negocio
// ==============================================================================

ogDatatable.registerFormatter('product-recommendation', (value, row) => {
  // Lógica: recomendar acción basada en datos
  const hasPrice = row.price && row.price > 0;
  const isActive = row.status == 1;
  const hasDescription = row.description && row.description.length > 10;
  
  let recommendation = '';
  let color = 'green';
  let icon = '✅';
  
  if (!hasPrice) {
    recommendation = 'Agregar precio';
    color = 'red';
    icon = '⚠️';
  } else if (!isActive) {
    recommendation = 'Activar producto';
    color = 'yellow';
    icon = '⏸️';
  } else if (!hasDescription) {
    recommendation = 'Mejorar descripción';
    color = 'blue';
    icon = 'ℹ️';
  } else {
    recommendation = 'Todo listo';
  }
  
  return `<span style="color: var(--og-${color}-600); font-size: 0.875rem;">${icon} ${recommendation}</span>`;
});

// ==============================================================================
// ESTRATEGIA 6: Formatter con Tooltip
// ==============================================================================

ogDatatable.registerFormatter('product-created-tooltip', (value, row) => {
  const date = new Date(value);
  const formatted = date.toLocaleDateString('es-EC');
  const fullDateTime = date.toLocaleString('es-EC');
  const daysAgo = Math.floor((Date.now() - date.getTime()) / (1000 * 60 * 60 * 24));
  
  return `
    <span title="${fullDateTime}" style="cursor: help; border-bottom: 1px dotted #9ca3af;">
      ${formatted} 
      <small style="color: #6b7280;">(hace ${daysAgo} días)</small>
    </span>
  `;
});

// ==============================================================================
// ESTRATEGIA 7: Formatter con Acciones Inline
// ==============================================================================

ogDatatable.registerFormatter('product-quick-actions', (value, row) => {
  return `
    <div class="og-flex og-gap-xs" style="justify-content: center;">
      <button 
        class="btn btn-sm btn-primary" 
        style="padding: 0.25rem 0.5rem; font-size: 0.75rem;"
        onclick="infoproductProduct.quickEdit(${row.id})"
        title="Edición rápida">
        ✏️
      </button>
      <button 
        class="btn btn-sm btn-secondary" 
        style="padding: 0.25rem 0.5rem; font-size: 0.75rem;"
        onclick="infoproductProduct.duplicate(${row.id})"
        title="Duplicar">
        📋
      </button>
      <button 
        class="btn btn-sm ${row.status == 1 ? 'btn-warning' : 'btn-success'}" 
        style="padding: 0.25rem 0.5rem; font-size: 0.75rem;"
        onclick="infoproductProduct.toggleStatus(${row.id})"
        title="${row.status == 1 ? 'Desactivar' : 'Activar'}">
        ${row.status == 1 ? '⏸️' : '▶️'}
      </button>
    </div>
  `;
});

// ==============================================================================
// CÓMO USAR EN TUS ARCHIVOS JSON
// ==============================================================================

/*
EJEMPLO 1: Columna simple con formatter registrado
{
  "price": {
    "name": "i18n:infoproduct.products.column.price",
    "format": "product-price-badge",
    "width": "120px",
    "align": "center"
  }
}

EJEMPLO 2: Múltiples columnas con diferentes formatos
{
  "columns": [
    {
      "name": {
        "name": "i18n:infoproduct.products.column.name",
        "format": "product-name-with-context",
        "width": "200px"
      }
    },
    {
      "price": {
        "name": "i18n:infoproduct.products.column.price",
        "format": "product-price-badge",
        "width": "120px",
        "align": "center"
      }
    },
    {
      "status": {
        "name": "i18n:infoproduct.products.column.status",
        "format": "status",
        "width": "120px",
        "align": "center"
      }
    },
    {
      "id": {
        "name": "i18n:infoproduct.products.column.actions",
        "format": "product-quick-actions",
        "width": "150px",
        "align": "center",
        "sortable": false
      }
    }
  ]
}

EJEMPLO 3: Columna virtual (no existe en BD, solo para UI)
{
  "completion": {
    "name": "Completitud",
    "format": "product-completion",
    "width": "180px",
    "align": "center"
  }
}

*/

// ==============================================================================
// REGISTRO EN INICIALIZACIÓN DEL MÓDULO
// ==============================================================================

// Opción A: Registrar al cargar el archivo JS
// (El código de arriba ya lo hace automáticamente)

// Opción B: Registrar bajo demanda cuando se necesite
function initializeProductFormatters() {
  // Aquí puedes hacer lógica condicional
  if (!ogDatatable.customFormatters.has('product-price-badge')) {
    ogDatatable.registerFormatter('product-price-badge', (value, row) => {
      // ... formatter code
    });
  }
}

// Opción C: Limpiar formatters al cambiar de módulo
function cleanupProductFormatters() {
  ogDatatable.unregisterFormatter('product-price-badge');
  ogDatatable.unregisterFormatter('bot-status-icon');
  // ... otros formatters
}

// ==============================================================================
// TIPS Y MEJORES PRÁCTICAS
// ==============================================================================

/*
1. NOMENCLATURA: Usa prefijos para evitar conflictos
   ✅ 'product-price-badge' 
   ❌ 'price' (muy genérico)

2. PERFORMANCE: No hagas llamadas a APIs dentro de formatters
   ✅ Usa datos que ya estén en row
   ❌ await ogApi.get() dentro del formatter

3. VALIDACIÓN: Siempre valida que los datos existan
   ✅ if (!value && value !== 0) return '';
   ❌ return value.toString() (puede fallar si value es null)

4. HTML SEGURO: Escapa valores de usuario para evitar XSS
   ✅ const safe = String(value).replace(/</g, '&lt;').replace(/>/g, '&gt;');
   ❌ return `<div>${userInput}</div>` (vulnerabilidad XSS)

5. ESTILOS: Usa clases de grid.css o colors.css cuando sea posible
   ✅ class="og-bg-blue-100 og-text-blue-700 og-rounded og-p-1"
   ❌ style="background: blue; color: white" (inconsistente)

6. i18n: Usa traducciones para textos
   ✅ __('infoproduct.products.status.active')
   ❌ 'Activo' (hardcoded)

7. REUTILIZACIÓN: Crea formatters genéricos que puedan usarse en múltiples tablas
   ✅ 'badge-with-color' que recibe el valor y row.colorField
   ❌ Formatter muy específico que solo funciona en una tabla
*/

console.log('📦 Formatters de ejemplo cargados - Ver formatters.example.js para uso');
