/**
 * ============================================================================
 * FORMEVENTS.JS - Manejo de Eventos y Callbacks
 * ============================================================================
 * 
 * SEPARACIÓN DEL ARCHIVO PRINCIPAL: form.js
 * Este archivo extiende la clase 'form' agregando métodos de eventos.
 * NO crea una clase separada, mantiene la API pública como 'window.form'.
 * 
 * RESPONSABILIDADES:
 * - Event listeners globales y por campo
 * - Callbacks (onChange, onBlur, onFocus, etc)
 * - Actualización reactiva de UI según condiciones
 * - Manejo de eventos de arrays (add/remove)
 * - Propagación de cambios entre campos dependientes
 * - Integración con transforms
 * - Eventos de selects dinámicos
 * 
 * MÉTODOS QUE AGREGA A 'form':
 * - form.bindEventsOnce()
 * - form.bindTransforms(formId, container)
 * - form.initRepeatables(formId, container)
 * 
 * DEPENDENCIAS:
 * - form.js (debe cargarse ANTES)
 * - formState.js (para leer/escribir valores)
 * - formValidation.js (para validar en tiempo real)
 * - conditions.js (para re-evaluar condiciones)
 * 
 * NOTA PARA IA:
 * Este archivo NO crea window.formEvents. Extiende window.form.
 * Si necesitas analizar la lógica completa del sistema de formularios,
 * solicita también:
 * - form.js (orquestador principal y renderizado)
 * - formState.js (gestión de estado)
 * - formValidation.js (validación)
 * - conditions.js (motor de condiciones)
 * ============================================================================
 */

(function() {
  if (!window.form) {
    console.error('core:formEvents - form.js debe cargarse antes que formEvents.js');
    return;
  }

  // Bindear eventos globales una sola vez
  form.bindEventsOnce = function() {
    if (form.registeredEvents.has('global')) return;

    document.addEventListener('change', (e) => {
      const input = e.target;
      if (!input.form) return;

      const formId = input.form.id;
      const schema = form.schemas.get(formId);
      if (!schema) return;

      const field = form._findFieldByName(schema, input.name);
      if (!field) return;

      form._handleFieldChange(formId, field, input);
      
      if (window.conditions) {
        conditions.evaluate(formId);
      }
    });

    document.addEventListener('blur', (e) => {
      const input = e.target;
      if (!input.form) return;

      const formId = input.form.id;
      const schema = form.schemas.get(formId);
      if (!schema) return;

      const field = form._findFieldByName(schema, input.name);
      if (field?.onBlur) {
        form._executeCallback(field.onBlur, formId, field.name, input.value);
      }
    }, true);

    document.addEventListener('focus', (e) => {
      const input = e.target;
      if (!input.form) return;

      const formId = input.form.id;
      const schema = form.schemas.get(formId);
      if (!schema) return;

      const field = form._findFieldByName(schema, input.name);
      if (field?.onFocus) {
        form._executeCallback(field.onFocus, formId, field.name, input.value);
      }
    }, true);

    document.addEventListener('click', (e) => {
      if (e.target.matches('.repeatable-remove')) {
        e.preventDefault();
        const item = e.target.closest('.repeatable-item');
        if (item) {
          item.remove();
          const formId = e.target.closest('form')?.id;
          if (formId && window.conditions) {
            conditions.evaluate(formId);
          }
        }
      }

      if (e.target.matches('.repeatable-add')) {
        e.preventDefault();
        const path = e.target.dataset.path;
        form.addRepeatableItem(path, e.target);
      }
    });

    form.registeredEvents.add('global');
  };

  // Manejar cambio de campo (privado)
  form._handleFieldChange = function(formId, field, input) {
    form.clearError(formId, field.name);

    if (field.onChange) {
      form._executeCallback(field.onChange, formId, field.name, input.value);
    }

    if (field.source && input.tagName === 'SELECT') {
      form._handleSelectChange(formId, field, input);
    }

    form._propagateChanges(formId, field.name);
  };

  // Manejar cambio en select (privado)
  form._handleSelectChange = function(formId, field, selectEl) {
    if (!field.source) return;

    const selectedValue = selectEl.value;
    
    selectEl.dispatchEvent(new CustomEvent('select:change', {
      bubbles: true,
      detail: { 
        formId, 
        fieldName: field.name, 
        value: selectedValue,
        source: field.source 
      }
    }));
  };

  // Ejecutar callback (privado)
  form._executeCallback = function(callback, formId, fieldName, value) {
    if (typeof callback === 'function') {
      try {
        callback(formId, fieldName, value);
      } catch (error) {
        logger.error('core:formEvents', `Error en callback de ${fieldName}:`, error);
      }
    } else if (typeof callback === 'string') {
      try {
        const fn = new Function('formId', 'fieldName', 'value', callback);
        fn(formId, fieldName, value);
      } catch (error) {
        logger.error('core:formEvents', `Error ejecutando callback string de ${fieldName}:`, error);
      }
    }
  };

  // Propagar cambios a campos dependientes (privado)
  form._propagateChanges = function(formId, changedFieldName) {
    const schema = form.schemas.get(formId);
    if (!schema) return;

    const checkDependencies = (fields) => {
      fields.forEach(field => {
        if (field.type === 'group' && field.fields) {
          checkDependencies(field.fields);
          return;
        }

        if (form._fieldDependsOn(field, changedFieldName)) {
          form._updateFieldState(formId, field);
        }
      });
    };

    if (schema.fields) checkDependencies(schema.fields);
  };

  // Verificar si campo depende de otro (privado)
  form._fieldDependsOn = function(field, targetFieldName) {
    // Verificar si el campo tiene condiciones que dependen del targetFieldName
    if (!field.condition || !Array.isArray(field.condition)) return false;
    
    return field.condition.some(cond => cond.field === targetFieldName);
  };

  // Actualizar estado visual de campo (privado)
  form._updateFieldState = function(formId, field) {
    const formEl = document.querySelector(`[data-real-id="${formId}"]`) || document.getElementById(formId);
    if (!formEl) return;

    const input = formEl.querySelector(`[name="${field.name}"]`);
    if (!input) return;

    const formGroup = input.closest('.form-group');
    if (!formGroup) return;

    const isVisible = form.isFieldVisible(formId, field);
    const isReadonly = form.isFieldReadonly(formId, field);
    const isRequired = form.isFieldRequired(formId, field);

    formGroup.style.display = isVisible ? '' : 'none';
    input.readOnly = isReadonly;
    input.required = isRequired;

    const label = formGroup.querySelector('label');
    if (label) {
      if (isRequired && !label.textContent.includes('*')) {
        label.innerHTML += ' <span class="required">*</span>';
      } else if (!isRequired) {
        const asterisk = label.querySelector('.required');
        if (asterisk) asterisk.remove();
      }
    }
  };

  // Bindear transforms a campos
  form.bindTransforms = function(formId, container = null) {
    const formEl = container
      ? container.querySelector(`#${formId}`)
      : document.getElementById(formId);

    if (!formEl) return;

    const transforms = form.transforms;

    formEl.querySelectorAll('[class*="form-transform-"]').forEach(input => {
      const classes = input.className.split(' ');
      const transformClasses = classes.filter(c => c.startsWith('form-transform-'));

      if (transformClasses.length === 0) return;

      input.addEventListener('input', function(e) {
        let value = e.target.value;
        const originalValue = value;
        const cursorPos = e.target.selectionStart;

        transformClasses.forEach(transformClass => {
          const transformName = transformClass.replace('form-transform-', '');
          if (transforms[transformName]) {
            value = transforms[transformName](value);
          }
        });

        if (originalValue !== value) {
          e.target.value = value;
          
          // Ajustar cursor para mantener posición relativa
          const lengthDiff = value.length - originalValue.length;
          let newCursorPos = cursorPos + lengthDiff;
          
          newCursorPos = Math.max(0, Math.min(newCursorPos, value.length));
          
          e.target.setSelectionRange(newCursorPos, newCursorPos);
        }
      });
    });
  };

  // Inicializar repeatables
  form.initRepeatables = function(formId, container = null) {
    const schema = form.schemas.get(formId);
    if (!schema) return;

    const target = container || document.getElementById('content');
    const formEl = target.querySelector(`#${formId}`) || document.getElementById(formId);
    if (!formEl) return;

    const initField = (fields) => {
      fields.forEach(field => {
        if (field.type === 'group' && field.fields) {
          initField(field.fields);
          return;
        }

        // Soporte para grouper
        if (field.type === 'grouper' && field.groups) {
          field.groups.forEach(group => {
            if (group.fields) {
              initField(group.fields);
            }
          });
          return;
        }

        if (field.type === 'repeatable' || field.type === 'array') {
          const repeatableContainer = formEl.querySelector(`.repeatable-items[data-path="${field.name}"]`);
          if (repeatableContainer) {
            // Inicializar el contenedor con el schema
            form.initRepeatableContainer(repeatableContainer, field, field.name);
            
            // Agregar items mínimos si es necesario
            if (field.minItems) {
              const items = repeatableContainer.querySelectorAll('.repeatable-item');
              if (items.length < field.minItems) {
                const addButton = repeatableContainer.closest('.form-repeatable').querySelector('.repeatable-add');
                if (addButton) {
                  for (let i = items.length; i < field.minItems; i++) {
                    addButton.click();
                  }
                }
              }
            }
          }
        }
      });
    };

    if (schema.fields) initField(schema.fields);
  };

  // Helper para buscar campo por nombre (privado)
  form._findFieldByName = function(schema, fieldName, fields = null) {
    const fieldsToSearch = fields || schema.fields || [];

    for (const field of fieldsToSearch) {
      if (field.name === fieldName) return field;
      if (field.fields) {
        const found = form._findFieldByName(schema, fieldName, field.fields);
        if (found) return found;
      }
    }

    return null;
  };

})();