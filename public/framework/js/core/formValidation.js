/**
 * ============================================================================
 * FORMVALIDATION.JS - Validación y Condiciones Dinámicas
 * ============================================================================
 * 
 * SEPARACIÓN DEL ARCHIVO PRINCIPAL: form.js
 * Este archivo extiende la clase 'form' agregando métodos de validación.
 * NO crea una clase separada, mantiene la API pública como 'window.form'.
 * 
 * RESPONSABILIDADES:
 * - Validación de campos individuales
 * - Validación completa de formularios
 * - Verificación de propiedades (visible, readonly, required)
 * - Mensajes de error personalizados
 * - Reglas de validación (required, email, numeric, etc)
 * 
 * MÉTODOS QUE AGREGA A 'form':
 * - form.validate(formId)
 * - form.validateRule(value, rule, field)
 * - form.isFieldVisible(formId, field)
 * - form.isFieldReadonly(formId, field)
 * - form.isFieldRequired(formId, field)
 * - form.setError(formId, fieldName, errorMessage)
 * - form.clearError(formId, fieldName)
 * - form.clearAllErrors(formId)
 * 
 * DEPENDENCIAS:
 * - form.js (debe cargarse ANTES)
 * - formState.js (para obtener valores)
 * - validator.js (reglas de validación core)
 * 
 * NOTA PARA IA:
 * Este archivo NO crea window.formValidation. Extiende window.form.
 * Si necesitas analizar la lógica completa del sistema de formularios,
 * solicita también:
 * - form.js (orquestador principal y renderizado)
 * - formState.js (gestión de estado)
 * - formEvents.js (eventos y callbacks)
 * - conditions.js (motor de condiciones)
 * - validator.js (validaciones core del framework)
 * ============================================================================
 */

(function() {
  if (!window.form) {
    console.error('core:formValidation - form.js debe cargarse antes que formValidation.js');
    return;
  }

  // Validar formulario completo
  form.validate = function(formId) {
    const schema = form.schemas.get(formId);
    if (!schema) {
      logger.error('core:formValidation', `Schema no encontrado: ${formId}`);
      return { success: false, errors: [], message: 'Schema no encontrado' };
    }

    const formEl = document.querySelector(`[data-real-id="${formId}"]`) || document.getElementById(formId);
    if (!formEl) {
      logger.error('core:formValidation', `Formulario no encontrado: ${formId}`);
      return { success: false, errors: [], message: 'Formulario no encontrado' };
    }

    const errors = [];
    const formData = form.getData(formId);
    const __ = (key, params) => window.i18n?.t(key, params) || key;

    const processFields = (fields, parentPath = '') => {
      fields.forEach(field => {
        if (!field.name && field.type !== 'group' && field.type !== 'grouper') return;

        const fieldPath = parentPath ? `${parentPath}.${field.name}` : field.name;

        if (field.type === 'group' && field.fields) {
          processFields(field.fields, parentPath);
          return;
        }

        // Validar campos dentro de groupers
        if (field.type === 'grouper' && field.groups) {
          // Chequear si el grouper está visible antes de validar sus campos
          const isGrouperVisible = form._isGrouperVisible(formId, field);
          
          if (isGrouperVisible) {
            field.groups.forEach(group => {
              if (group.fields) processFields(group.fields, parentPath);
            });
          }
          return;
        }

        // Validar campos dentro de repeatables
        if (field.type === 'repeatable' && field.fields) {
          const repeatableData = formData[field.name];
          
          if (Array.isArray(repeatableData) && repeatableData.length > 0) {
            repeatableData.forEach((itemData, index) => {
              const itemPath = `${fieldPath}[${index}]`;
              
              field.fields.forEach(subField => {
                if (subField.type === 'repeatable' && subField.fields) {
                  // Recursión para repeatables anidados
                  processFields([subField], itemPath);
                } else if (subField.name) {
                  const subFieldPath = `${itemPath}.${subField.name}`;
                  validateField(subField, subFieldPath);
                }
              });
            });
          }
          return;
        }

        const validateField = (fld, path) => {
          const isVisible = form.isFieldVisible(formId, fld);
          const isReadonly = form.isFieldReadonly(formId, fld);

          if (!isVisible || isReadonly) return;

          const isRequired = form.isFieldRequired(formId, fld);
          const value = form.getFieldValue(formId, fld.name);

          if (isRequired && (value === null || value === '' || value === undefined)) {
            const label = form.t(fld.label) || fld.name;
            errors.push({
              field: fld.name,
              path: path,
              message: __('core.form.validation.required', { field: label })
            });
            form.setError(formId, fld.name, __('core.form.validation.required', { field: label }));
            return;
          }

          if (value && fld.validation) {
            const validationRules = Array.isArray(fld.validation) ? fld.validation : [fld.validation];

            for (const rule of validationRules) {
              const result = form.validateRule(value, rule, fld);
              if (!result.valid) {
                const label = form.t(fld.label) || fld.name;
                const errorMsg = result.message || __('core.form.validation.invalid', { field: label });
                errors.push({
                  field: fld.name,
                  path: path,
                  rule: rule,
                  message: errorMsg
                });
                form.setError(formId, fld.name, errorMsg);
                break;
              }
            }
          }
        };

        validateField(field, fieldPath);
      });
    };

    processFields(schema.fields);

    const success = errors.length === 0;
    const message = success 
      ? __('core.form.validation.success') 
      : __('core.form.validation.errors', { count: errors.length });

    return {
      success,
      errors,
      message,
      data: success ? formData : null
    };
  };

  // Validar regla específica
  form.validateRule = function(value, rule, field) {
    if (typeof rule === 'string') {
      if (window.validator && validator[rule]) {
        return validator[rule](value) 
          ? { valid: true } 
          : { valid: false, message: `Validación ${rule} falló` };
      }

      const [ruleName, ...params] = rule.split(':');
      if (window.validator && validator[ruleName]) {
        return validator[ruleName](value, ...params) 
          ? { valid: true } 
          : { valid: false, message: `Validación ${ruleName} falló` };
      }
    }

    if (typeof rule === 'function') {
      const result = rule(value, field);
      if (typeof result === 'boolean') {
        return { valid: result };
      }
      return result;
    }

    return { valid: true };
  };

  // Verificar si campo es visible (simplificado - solo verifica propiedad directa)
  form.isFieldVisible = function(formId, field) {
    // Si el campo no tiene name, asumir visible (grouper, group, etc)
    if (!field.name) return true;
    
    const formEl = document.querySelector(`[data-real-id="${formId}"]`) || document.getElementById(formId);
    if (!formEl) return true;

    // Buscar el input en el DOM
    const input = formEl.querySelector(`[name="${field.name}"]`);
    if (!input) return false; // Si no existe en DOM, no está visible

    // Buscar el contenedor del campo
    const fieldContainer = input.closest('.form-group, .form-checkbox, .form-html-wrapper, .grouper-tab-panel, .grouper');
    if (!fieldContainer) return true;

    // Chequear si está oculto por CSS
    if (fieldContainer.style.display === 'none') return false;
    
    const computedStyle = window.getComputedStyle(fieldContainer);
    if (computedStyle.display === 'none') return false;
    
    // Chequear si tiene clase wpfw-depend-on (usada por conditions.js)
    if (fieldContainer.classList.contains('wpfw-depend-on')) return false;

    return true;
  };

  // Verificar si grouper está visible (privado)
  form._isGrouperVisible = function(formId, field) {
    const formEl = document.querySelector(`[data-real-id="${formId}"]`) || document.getElementById(formId);
    if (!formEl) return true;

    // Si el grouper tiene name, buscar el wrapper por data-field-name
    if (field.name) {
      const wrapper = formEl.querySelector(`.form-html-wrapper[data-field-name="${field.name}"]`);
      if (!wrapper) return false; // No existe en DOM
      
      // Verificar si el wrapper está oculto
      if (wrapper.style.display === 'none') return false;
      
      const computedStyle = window.getComputedStyle(wrapper);
      if (computedStyle.display === 'none') return false;
      
      if (wrapper.classList.contains('wpfw-depend-on')) return false;
      
      return true;
    }

    // Si no tiene name, buscar por los campos internos (fallback)
    if (!field.groups || field.groups.length === 0) return true;
    
    const firstGroup = field.groups[0];
    if (!firstGroup.fields || firstGroup.fields.length === 0) return true;
    
    const firstField = firstGroup.fields.find(f => f.name);
    if (!firstField) return true;
    
    const input = formEl.querySelector(`[name="${firstField.name}"]`);
    if (!input) return false;
    
    const grouperEl = input.closest('.grouper');
    if (!grouperEl) return true;
    
    // Buscar el wrapper padre
    const wrapper = grouperEl.closest('.form-html-wrapper');
    if (!wrapper) return true;
    
    if (wrapper.style.display === 'none') return false;
    
    const computedStyle = window.getComputedStyle(wrapper);
    if (computedStyle.display === 'none') return false;
    
    if (wrapper.classList.contains('wpfw-depend-on')) return false;
    
    return true;
  };

  // Verificar si campo es readonly (simplificado - solo verifica propiedad directa)
  form.isFieldReadonly = function(formId, field) {
    return field.readonly === true;
  };

  // Verificar si campo es requerido (simplificado - solo verifica propiedad directa)
  form.isFieldRequired = function(formId, field) {
    // Chequear ambas formas: atributo directo o regla en validation
    if (field.required === true) return true;
    
    if (field.validation) {
      const validationStr = Array.isArray(field.validation) 
        ? field.validation.join('|') 
        : field.validation;
      return validationStr.includes('required');
    }
    
    return false;
  };

  // Establecer error manual
  form.setError = function(formId, fieldName, errorMessage) {
    const formEl = document.querySelector(`[data-real-id="${formId}"]`) || document.getElementById(formId);
    if (!formEl) return;

    const input = formEl.querySelector(`[name="${fieldName}"]`);
    if (!input) return;

    const formGroup = input.closest('.form-group');
    if (!formGroup) return;

    formGroup.classList.add('has-error');
    const errorEl = formGroup.querySelector('.form-error');
    if (errorEl) {
      errorEl.textContent = errorMessage;
      errorEl.style.display = 'block';
    }
  };

  // Limpiar error de campo
  form.clearError = function(formId, fieldName) {
    const formEl = document.querySelector(`[data-real-id="${formId}"]`) || document.getElementById(formId);
    if (!formEl) return;

    const input = formEl.querySelector(`[name="${fieldName}"]`);
    if (!input) return;

    const formGroup = input.closest('.form-group');
    if (!formGroup) return;

    formGroup.classList.remove('has-error');
    const errorEl = formGroup.querySelector('.form-error');
    if (errorEl) {
      errorEl.textContent = '';
      errorEl.style.display = 'none';
    }
  };

  // Limpiar todos los errores
  form.clearAllErrors = function(formId) {
    const formEl = document.querySelector(`[data-real-id="${formId}"]`) || document.getElementById(formId);
    if (!formEl) return;

    formEl.querySelectorAll('.form-error').forEach(el => {
      el.textContent = '';
      el.style.display = 'none';
    });
    formEl.querySelectorAll('.form-group').forEach(el => el.classList.remove('has-error'));
  };

})();