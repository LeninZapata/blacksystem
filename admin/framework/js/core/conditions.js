class ogConditions {
  static rules = new Map();
  static watchers = new Map();
  static initialized = false;
  static evaluateTimeout = null;
  static isFillingForm = false;

  static init(formId) {
    if (!formId) return;

    const form = ogModule('form');
    const schema = form?.schemas?.get(formId);
    if (!schema || !schema.fields) return;

    // Limpiar reglas anteriores de este formulario
    this.rules.delete(formId);
    this.watchers.delete(formId);

    // Extraer todas las condiciones del schema
    const rulesMap = new Map();
    this.extractConditions(schema.fields, rulesMap, '');

    if (rulesMap.size === 0) return;

    this.rules.set(formId, rulesMap);
    ogLogger?.info('core:conditions', `[init] 📋 Total de reglas extraídas: ${rulesMap.size} para formulario "${formId}"`);

    // Configurar watchers
    this.setupWatchers(formId);

    // Configurar observer para detectar nuevos items de repeatable
    this.setupRepeatableObserver(formId);

    // Evaluación inicial
    setTimeout(() => this.evaluate(formId), 50);
  }

  static setupRepeatableObserver(formId) {
    const formEl = document.getElementById(formId);
    if (!formEl) return;

    // Función recursiva para observar todos los contenedores de repetibles
    const observeRepeatableContainers = (rootElement) => {
      const repeatableContainers = rootElement.querySelectorAll('.repeatable-items');

      repeatableContainers.forEach(container => {
        // Verificar si ya está siendo observado para evitar duplicados
        if (container.dataset.conditionsObserved === 'true') {
          return;
        }

        // Marcar como observado
        container.dataset.conditionsObserved = 'true';

        // Crear un MutationObserver para detectar cuando se agregan nuevos items
        const observer = new MutationObserver((mutations) => {
          mutations.forEach((mutation) => {
            if (mutation.type === 'childList' && mutation.addedNodes.length > 0) {
              mutation.addedNodes.forEach(node => {
                // Solo procesar elementos (no nodos de texto)
                if (node.nodeType === 1 && node.classList.contains('repeatable-item')) {
                  // Solo observar repetibles anidados sin evaluar condiciones
                  observeRepeatableContainers(node);
                }
              });
            }
          });
        });

        // Observar cambios en los hijos del contenedor
        observer.observe(container, {
          childList: true,
          subtree: false
        });

        // Guardar referencia al observer para limpiarlo después
        if (!this.watchers.has(formId)) {
          this.watchers.set(formId, []);
        }
        const watchers = this.watchers.get(formId);
        watchers.push({ type: 'observer', observer, container });
      });
    };

    // Iniciar observación desde el formulario raíz
    observeRepeatableContainers(formEl);
  }

  static extractConditions(fields, rulesMap, parentPath = '') {
    fields.forEach((field, index) => {
      let fieldPath;

      // Construir fieldPath según el tipo de campo
      if (field.name) {
        fieldPath = parentPath ? `${parentPath}.${field.name}` : field.name;
      } else if (field.type === 'grouper') {
        // Grouper sin name: usar índice como fallback
        fieldPath = parentPath ? `${parentPath}.__grouper_${index}` : `__grouper_${index}`;
      } else {
        // Otro tipo sin name: usar índice
        fieldPath = parentPath ? `${parentPath}.__field_${index}` : `__field_${index}`;
      }

      // Si el campo tiene condiciones
      if (field.condition && Array.isArray(field.condition) && field.condition.length > 0) {
        rulesMap.set(fieldPath, {
          conditions: field.condition,
          context: field.conditionContext || 'form',
          logic: field.conditionLogic || 'AND',
          isGrouper: field.type === 'grouper'
        });
      }

      // Recursivo para repetibles
      if (field.type === 'repeatable' && field.fields) {
        this.extractConditions(field.fields, rulesMap, fieldPath);
      }

      // Recursivo para grouper
      if (field.type === 'grouper') {
        if (field.groups && Array.isArray(field.groups)) {
          field.groups.forEach((group, groupIndex) => {
            if (group.fields && Array.isArray(group.fields)) {
              this.extractConditions(group.fields, rulesMap, parentPath);
            }
          });
        } else if (field.fields) {
          this.extractConditions(field.fields, rulesMap, parentPath);
        }
      }

      // Recursivo para groups
      if (field.type === 'group') {
        // Si el group tiene condiciones, extraerlas primero
        if (field.condition && Array.isArray(field.condition) && field.condition.length > 0) {
          rulesMap.set(fieldPath, {
            conditions: field.condition,
            context: field.conditionContext || 'form',
            logic: field.conditionLogic || 'AND',
            isGroup: true
          });
        }
        
        // Luego procesar campos internos
        if (field.fields) {
          this.extractConditions(field.fields, rulesMap, parentPath);
        }
      }
    });
  }

  static setupWatchers(formId) {
    const events = ogModule('events');
    const formEl = document.getElementById(formId);
    if (!formEl) return;

    const rulesMap = this.rules.get(formId);
    const watchedFields = new Set();

    // Identificar qué campos necesitan ser observados
    rulesMap.forEach((rule, targetField) => {
      rule.conditions.forEach(cond => {
        watchedFields.add(cond.field);
      });
    });

    // Registrar evento de cambio delegado
    const watcherId = events.on(
      `#${formId} input, #${formId} select, #${formId} textarea`,
      'change',
      (e) => {
        // No evaluar si se está llenando el formulario
        if (this.isFillingForm) {
          return;
        }

        const fieldName = this.getFieldName(e.target);
        if (watchedFields.has(fieldName)) {
          this.evaluate(formId);
        }
      },
      document
    );

    // También escuchar input para fields de texto (cambios en tiempo real)
    const inputWatcherId = events.on(
      `#${formId} input[type="text"], #${formId} input[type="email"], #${formId} input[type="number"], #${formId} textarea`,
      'input',
      (e) => {
        // No evaluar si se está llenando el formulario
        if (this.isFillingForm) {
          return;
        }

        const fieldName = this.getFieldName(e.target);
        if (watchedFields.has(fieldName)) {
          this.evaluate(formId);
        }
      },
      document
    );

    // Inicializar array de watchers si no existe
    if (!this.watchers.has(formId)) {
      this.watchers.set(formId, []);
    }

    // Agregar los event listener IDs al array existente
    const watchers = this.watchers.get(formId);
    watchers.push(watcherId, inputWatcherId);
  }

  static evaluate(formId) {
    const formEl = document.getElementById(formId);
    if (!formEl) return;

    const rulesMap = this.rules.get(formId);
    if (!rulesMap) return;

    rulesMap.forEach((rule, targetFieldPath) => {
      const { context } = rule;

      if (context === 'repeatable') {
        // Para contexto repeatable, evaluar cada item individualmente
        this.evaluateRepeatable(formEl, targetFieldPath, rule);
      } else {
        // Para otros contextos, evaluación normal
        const shouldShow = this.checkConditions(formEl, rule, targetFieldPath);
        this.applyVisibilitySimple(formEl, targetFieldPath, shouldShow);
      }
    });
  }

  // Pausar evaluaciones durante llenado masivo
  static pauseEvaluations() {
    this.isFillingForm = true;
  }

  // Reanudar y ejecutar evaluación final
  static resumeEvaluations(formId) {
    this.isFillingForm = false;

    // Usar requestAnimationFrame para asegurar que el DOM está listo
    requestAnimationFrame(() => {
      if (formId) {
        this.evaluate(formId);
      }
    });
  }

  static evaluateRepeatable(formEl, targetFieldPath, rule) {
    // Extraer el path del repeatable desde el targetFieldPath
    // Ej: "proyectos.fases.nombre_fase" -> repeatablePath="proyectos.fases", fieldName="nombre_fase"
    const pathParts = targetFieldPath.split('.');
    const fieldName = pathParts[pathParts.length - 1];
    const repeatablePath = pathParts.slice(0, -1).join('.');

    // Buscar TODOS los contenedores que coincidan con este path
    const allContainers = formEl.querySelectorAll('.repeatable-items[data-path]');
    const repeatableContainers = Array.from(allContainers).filter(container => {
      const dataPath = container.getAttribute('data-path');
      // Eliminar índices para comparar: proyectos[0].fases[1] -> proyectos.fases
      const normalizedPath = dataPath.replace(/\[\d+\]/g, '');
      return normalizedPath === repeatablePath;
    });

    if (repeatableContainers.length === 0) {
      return;
    }

    // Iterar sobre cada contenedor (uno por cada item del repeatable padre)
    repeatableContainers.forEach((repeatableContainer) => {
      // Buscar items solo dentro de este repeatable específico
      const repeatableItems = repeatableContainer.querySelectorAll(':scope > .repeatable-item');

      if (repeatableItems.length === 0) {
        return;
      }

      // Evaluar cada item individualmente
      repeatableItems.forEach((item, idx) => {
        // Para contexto repeatable, el item YA ES el contexto correcto
        const shouldShow = this.checkConditionsWithContext(item, rule);

        // Buscar el campo target dentro de este item específico
        let targetField = item.querySelector(`[name*=".${fieldName}"]`);

        // Si no se encuentra por name, buscar por data-field-path (repeatables anidados o groupers)
        if (!targetField) {
          const allFieldPaths = item.querySelectorAll('.form-repeatable[data-field-path], .og-grouper[data-field-path], .form-group-cols[data-field-path]');
          const candidates = Array.from(allFieldPaths).filter(el => {
            const fieldPath = el.getAttribute('data-field-path');
            // Eliminar índices para comparar: productos[0].grouper_envio_fisico -> productos.grouper_envio_fisico
            const normalizedFieldPath = fieldPath.replace(/\[\d+\]/g, '');
            // Comparar si termina con el fieldName
            return normalizedFieldPath.endsWith(`.${fieldName}`) || normalizedFieldPath === fieldName;
          });

          // Si hay múltiples candidatos, tomar el de path más corto (el más cercano al nivel actual)
          if (candidates.length > 0) {
            targetField = candidates.reduce((shortest, current) => {
              const shortestPath = shortest.getAttribute('data-field-path');
              const currentPath = current.getAttribute('data-field-path');
              return currentPath.length < shortestPath.length ? current : shortest;
            });
          }
        }

        if (targetField) {
          const fieldElement = targetField.closest('.form-group, .form-checkbox, .form-repeatable, .og-grouper, .form-group-cols');

          if (fieldElement) {
            if (shouldShow) {
              fieldElement.style.display = '';
              fieldElement.classList.remove('wpfw-depend-on');
              // Solo deshabilitar inputs reales, no contenedores
              if (targetField.tagName === 'INPUT' || targetField.tagName === 'SELECT' || targetField.tagName === 'TEXTAREA') {
                targetField.disabled = false;
              }
            } else {
              fieldElement.style.display = 'none';
              fieldElement.classList.add('wpfw-depend-on');
              // Solo deshabilitar inputs reales, no contenedores
              if (targetField.tagName === 'INPUT' || targetField.tagName === 'SELECT' || targetField.tagName === 'TEXTAREA') {
                targetField.disabled = true;
              }
            }
          }
        }
      });
    });
  }

  static applyVisibilityToAll(formEl, fieldPath, shouldShow) {
    const pathParts = fieldPath.split('.');
    const fieldName = pathParts[pathParts.length - 1];

    const matchingFields = formEl.querySelectorAll(`[name*=".${fieldName}"]`);

    matchingFields.forEach(field => {
      const fieldElement = field.closest('.form-group, .form-checkbox');

      if (fieldElement) {
        if (shouldShow) {
          fieldElement.style.display = '';
          fieldElement.classList.remove('wpfw-depend-on');
          field.disabled = false;
        } else {
          fieldElement.style.display = 'none';
          fieldElement.classList.add('wpfw-depend-on');
          field.disabled = true;
        }
      }
    });
  }

  static applyVisibilitySimple(formEl, fieldPath, shouldShow) {
    const fieldElement = this.findFieldElement(formEl, fieldPath);

    if (!fieldElement) {
      ogLogger?.warn('core:conditions', `No se encontró el elemento para "${fieldPath}"`);
      return;
    }

    if (shouldShow) {
      fieldElement.style.display = '';
      fieldElement.classList.remove('wpfw-depend-on');

      // Si es un grouper, remover form-hidden de los form-groups internos
      if (fieldElement.classList.contains('grouper')) {
        const formGroups = fieldElement.querySelectorAll('.form-group, .form-checkbox');
        formGroups.forEach(group => {
          group.classList.remove('form-hidden');
        });
      }

      const inputs = fieldElement.querySelectorAll('input, select, textarea');
      inputs.forEach(input => {
        input.disabled = false;
      });
    } else {
      fieldElement.style.display = 'none';
      fieldElement.classList.add('wpfw-depend-on');

      // Si es un grouper, agregar form-hidden a los form-groups internos
      if (fieldElement.classList.contains('grouper')) {
        const formGroups = fieldElement.querySelectorAll('.form-group, .form-checkbox');
        formGroups.forEach(group => {
          group.classList.add('form-hidden');
        });
      }

      const inputs = fieldElement.querySelectorAll('input, select, textarea');
      inputs.forEach(input => {
        input.disabled = true;
      });
    }
  }

  static checkConditions(formEl, rule, targetFieldPath) {
    const { conditions, logic, context } = rule;

    // Determinar el contexto de búsqueda
    const searchContext = this.getContext(formEl, targetFieldPath, context);

    if (logic === 'OR') {
      // Al menos una condición debe cumplirse
      return conditions.some(cond => this.checkCondition(searchContext, cond));
    } else {
      // Todas las condiciones deben cumplirse (AND por defecto)
      return conditions.every(cond => this.checkCondition(searchContext, cond));
    }
  }

  // Versión alternativa que recibe directamente el contexto (para evaluateRepeatable)
  static checkConditionsWithContext(context, rule) {
    const { conditions, logic } = rule;

    if (logic === 'OR') {
      return conditions.some(cond => {
        return this.checkCondition(context, cond);
      });
    } else {
      return conditions.every(cond => {
        return this.checkCondition(context, cond);
      });
    }
  }

  static checkCondition(context, condition) {
    const { field, operator, value } = condition;

    // Buscar el campo en el contexto
    let fieldEl = null;

    // Si el contexto es un repeatable-item, buscar solo dentro de él
    if (context.classList && context.classList.contains('repeatable-item')) {
      // Buscar por el nombre del campo (puede o no tener punto antes)
      let fields = context.querySelectorAll(`[name*=".${field}"]`);
      fieldEl = fields.length > 0 ? fields[0] : null;

      // Buscar que termine con el nombre (ej: "[0].action_type")
      if (!fieldEl) {
        fields = context.querySelectorAll(`[name$=".${field}"]`);
        fieldEl = fields.length > 0 ? fields[0] : null;
      }

      // Buscar que contenga el nombre exacto (fallback)
      if (!fieldEl) {
        fieldEl = context.querySelector(`[name="${field}"], [name*="${field}"]`);
      }
    } else {
      // Búsqueda normal en form/view
      fieldEl = context.querySelector(`[name="${field}"], [name*="${field}"]`);
    }

    if (!fieldEl) {
      ogLogger?.warn('core:conditions', `[checkCondition] ❌ Campo "${field}" no encontrado en contexto`);
      return false;
    }

    const fieldValue = this.getFieldValue(fieldEl);
    return this.evalOperator(operator, fieldValue, value);
  }

  static evalOperator(operator, fieldValue, targetValue) {
    switch (operator) {
      case '==':
        return this.normalize(fieldValue) == this.normalize(targetValue);

      case '!=':
        return this.normalize(fieldValue) != this.normalize(targetValue);

      case '>':
        return parseFloat(fieldValue) > parseFloat(targetValue);

      case '<':
        return parseFloat(fieldValue) < parseFloat(targetValue);

      case '>=':
        return parseFloat(fieldValue) >= parseFloat(targetValue);

      case '<=':
        return parseFloat(fieldValue) <= parseFloat(targetValue);

      case 'any':
        // Si el valor del campo está en la lista separada por comas
        const anyList = String(targetValue).split(',').map(v => v.trim());
        if (Array.isArray(fieldValue)) {
          return fieldValue.some(v => anyList.includes(String(v).trim()));
        }
        return anyList.includes(String(fieldValue).trim());

      case 'not-any':
        // Si el valor del campo NO está en la lista
        const notAnyList = String(targetValue).split(',').map(v => v.trim());
        if (Array.isArray(fieldValue)) {
          return !fieldValue.some(v => notAnyList.includes(String(v).trim()));
        }
        return !notAnyList.includes(String(fieldValue).trim());

      case 'empty':
        // Campo vacío (null, undefined, '', [])
        if (Array.isArray(fieldValue)) return fieldValue.length === 0;
        return !fieldValue || String(fieldValue).trim() === '';

      case 'not-empty':
        // Campo NO vacío
        if (Array.isArray(fieldValue)) return fieldValue.length > 0;
        return fieldValue && String(fieldValue).trim() !== '';

      case 'contains':
        // El campo contiene el texto
        return String(fieldValue).toLowerCase().includes(String(targetValue).toLowerCase());

      case 'not-contains':
        // El campo NO contiene el texto
        return !String(fieldValue).toLowerCase().includes(String(targetValue).toLowerCase());

      default:
        ogLogger?.warn('core:conditions', `Operador desconocido "${operator}"`);
        return false;
    }
  }

  static normalize(value) {
    // Normalizar booleanos y strings
    if (value === true || value === 'true' || value === '1' || value === 1) return true;
    if (value === false || value === 'false' || value === '0' || value === 0) return false;
    return value;
  }

  static getFieldValue(fieldEl) {
    const type = fieldEl.type;
    const name = fieldEl.name;

    // Checkbox
    if (type === 'checkbox') {
      return fieldEl.checked;
    }

    // Radio buttons (buscar todos con el mismo name)
    if (type === 'radio') {
      const form = fieldEl.closest('form');
      const radios = form.querySelectorAll(`input[name="${name}"]`);
      const checked = Array.from(radios).find(r => r.checked);
      return checked ? checked.value : '';
    }

    // Select multiple
    if (fieldEl.tagName === 'SELECT' && fieldEl.multiple) {
      return Array.from(fieldEl.selectedOptions).map(opt => opt.value);
    }

    // Cualquier otro input
    return fieldEl.value;
  }

  static getFieldName(element) {
    const name = element.name || '';
    // Remover índices de repetibles: "proyectos[0].nombre" -> "nombre"
    // Extraer solo el último segmento después del punto
    const withoutIndexes = name.replace(/\[\d+\]/g, '');
    const parts = withoutIndexes.split('.');
    return parts[parts.length - 1]; // Retornar solo la última parte
  }

  static getContext(formEl, targetFieldPath, contextType) {
    switch (contextType) {
      case 'view':
        // Todo el documento
        return document;

      case 'form':
        // Solo dentro del formulario
        return formEl;

      case 'repeatable':
        // Dentro del repeatable más cercano
        const targetField = this.findFieldElement(formEl, targetFieldPath);
        if (targetField) {
          const repeatable = targetField.closest('.repeatable-item');
          return repeatable || formEl;
        }
        return formEl;

      case 'group':
        // Dentro del grupo más cercano (si existe)
        const targetFieldGroup = this.findFieldElement(formEl, targetFieldPath);
        if (targetFieldGroup) {
          const group = targetFieldGroup.closest('.form-group-container, .repeatable-item');
          return group || formEl;
        }
        return formEl;

      default:
        return formEl;
    }
  }

  static findFieldElement(formEl, fieldPath) {
    // Intentar encontrar por name exacto
    let field = formEl.querySelector(`[name="${fieldPath}"]`);
    if (field) return field.closest('.form-group, .form-checkbox, .form-html-wrapper, .form-repeatable');
    
    // Buscar group por data-field-path exacto
    let group = formEl.querySelector(`.form-group-cols[data-field-path="${fieldPath}"]`);
    if (group) return group;
    
    // Buscar group considerando índices de repeatables
    if (fieldPath.includes('.')) {
      const allGroups = formEl.querySelectorAll('.form-group-cols[data-field-path]');
      for (const g of allGroups) {
        const gPath = g.getAttribute('data-field-path');
        // Comparar removiendo índices: "actions[0].budget_fields_group" → "actions.budget_fields_group"
        const normalizedPath = gPath.replace(/\[\d+\]/g, '');
        if (normalizedPath === fieldPath) {
          return g;
        }
      }
    }

    // Buscar wrapper HTML por data-field-name
    let htmlWrapper = formEl.querySelector(`.form-html-wrapper[data-field-name="${fieldPath}"]`);
    if (htmlWrapper) return htmlWrapper;

    // Buscar grouper por data-field-path (clase correcta: .og-grouper)
    let grouper = formEl.querySelector(`.og-grouper[data-field-path="${fieldPath}"]`);
    if (grouper) return grouper;

    // Para repetibles: buscar considerando índices [0], [1], etc
    const pathParts = fieldPath.split('.');
    if (pathParts.length > 1) {
      const baseField = pathParts[pathParts.length - 1]; // última parte

      // Buscar cualquier campo que termine con ese nombre
      const fields = formEl.querySelectorAll(`[name*=".${baseField}"]`);
      if (fields.length > 0) {
        // Si hay múltiples, devolver el contenedor del primero
        return fields[0].closest('.form-group, .form-checkbox, .form-html-wrapper, .form-repeatable');
      }
    }

    // Buscar por name parcial (fallback)
    field = formEl.querySelector(`[name*="${fieldPath}"]`);
    if (field) return field.closest('.form-group, .form-checkbox, .form-html-wrapper, .form-repeatable');

    ogLogger?.warn('core:conditions', `[findFieldElement] ❌ NO se encontró elemento para: "${fieldPath}"`);
    return null;
  }

  static destroy(formId) {
    // Limpiar watchers y observers
    const watchers = this.watchers.get(formId);
    if (watchers) {
      watchers.forEach(watcher => {
        if (typeof watcher === 'number') {
          // Es un event listener ID
          window.events?.off?.(watcher);
        } else if (watcher.type === 'observer') {
          // Es un MutationObserver
          watcher.observer.disconnect();

          // Limpiar marca de observado
          if (watcher.container) {
            delete watcher.container.dataset.conditionsObserved;
          }
        }
      });
      this.watchers.delete(formId);
    }

    // Limpiar reglas
    this.rules.delete(formId);
  }

  static debug(formId) {
    ogLogger?.info('core:conditions', `Debug: ${formId}`);

    const rules = this.rules.get(formId);
    if (!rules) {
      ogLogger?.info('core:conditions', 'No hay reglas registradas para este formulario');
      return;
    }

    ogLogger?.info('core:conditions', `Reglas activas: ${rules.size}`);
  }
}

// Global
window.ogConditions = ogConditions;

// Registrar en ogFramework (preferido)
if (typeof window.ogFramework !== 'undefined') {
  window.ogFramework.core.conditions = ogConditions;
}