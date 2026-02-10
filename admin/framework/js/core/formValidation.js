class ogFormValidation {
  static validate(formId) {
    const core = ogModule('formCore');
    const formEl = document.querySelector(`[data-real-id="${formId}"]`) || document.getElementById(formId);
    
    if (!formEl) {
      ogLogger?.warn('core:form', `Formulario ${formId} no encontrado`);
      return {
        success: false,
        message: 'Formulario no encontrado',
        data: null
      };
    }

    const schema = core?.schemas?.get(formId);
    if (!schema || !schema.fields) {
      const dataModule = ogModule('formData');
      const formData = dataModule?.getData(formId) || {};
      
      return {
        success: true,
        message: 'Sin campos para validar',
        data: formData
      };
    }

    let isValid = true;
    const errors = [];

    this.clearAllErrors(formId);

    const validateFields = (fields, parentPath = '') => {
      fields.forEach(field => {
        if (field.type === 'group' && field.fields) {
          validateFields(field.fields, parentPath);
        } else if (field.type === 'grouper' && field.groups) {
          field.groups.forEach(group => {
            if (group.fields) validateFields(group.fields, parentPath);
          });
        } else if (field.type === 'repeatable') {
          return;
        } else if (field.name) {
          const fieldPath = parentPath ? `${parentPath}.${field.name}` : field.name;
          const fieldResult = this.validateField(formEl, field, fieldPath);
          
          if (!fieldResult.isValid) {
            isValid = false;
            errors.push({
              field: fieldPath,
              message: fieldResult.message
            });
          }
        }
      });
    };

    validateFields(schema.fields);

    if (!isValid) {
      const firstError = errors[0];
      return {
        success: false,
        message: firstError ? firstError.message : 'Errores de validación',
        errors: errors,
        data: null
      };
    }

    const dataModule = ogModule('formData');
    const formData = dataModule?.getData(formId) || {};

    return {
      success: true,
      message: 'Validación exitosa',
      data: formData,
      errors: []
    };
  }

  static validateField(formEl, field, fieldPath) {
    const input = formEl.querySelector(`[name="${fieldPath}"]`);
    if (!input) return { isValid: true, message: null };

    // Verificar si el campo está oculto por conditions
    const formGroup = input.closest('.og-form-group, .og-form-checkbox, .og-form-repeatable, .og-grouper');
    if (formGroup) {
      // Si el campo tiene la clase wpfw-depend-on o está oculto por display:none, saltarlo
      if (formGroup.classList.contains('wpfw-depend-on') || 
          formGroup.style.display === 'none' ||
          input.disabled) {
        return { isValid: true, message: null };
      }
    }

    const value = this.getFieldValue(input);
    const validation = field.validation;

    if (!validation) return { isValid: true, message: null };

    const rules = validation.split('|');

    for (const rule of rules) {
      const [ruleName, ruleValue] = rule.split(':');

      if (ruleName === 'required') {
        if (!this.validateRequired(value)) {
          this.showFieldError(formEl, fieldPath, 'Este campo es requerido');
          return { isValid: false, message: 'Este campo es requerido' };
        }
      }

      if (ruleName === 'email') {
        if (value && !this.validateEmail(value)) {
          this.showFieldError(formEl, fieldPath, 'Email inválido');
          return { isValid: false, message: 'Email inválido' };
        }
      }

      if (ruleName === 'min') {
        const minLength = parseInt(ruleValue);
        if (value && value.length < minLength) {
          const msg = `Mínimo ${minLength} caracteres`;
          this.showFieldError(formEl, fieldPath, msg);
          return { isValid: false, message: msg };
        }
      }

      if (ruleName === 'max') {
        const maxLength = parseInt(ruleValue);
        if (value && value.length > maxLength) {
          const msg = `Máximo ${maxLength} caracteres`;
          this.showFieldError(formEl, fieldPath, msg);
          return { isValid: false, message: msg };
        }
      }

      if (ruleName === 'minValue') {
        const minValue = parseFloat(ruleValue);
        if (value && parseFloat(value) < minValue) {
          const msg = `Valor mínimo: ${minValue}`;
          this.showFieldError(formEl, fieldPath, msg);
          return { isValid: false, message: msg };
        }
      }

      if (ruleName === 'maxValue') {
        const maxValue = parseFloat(ruleValue);
        if (value && parseFloat(value) > maxValue) {
          const msg = `Valor máximo: ${maxValue}`;
          this.showFieldError(formEl, fieldPath, msg);
          return { isValid: false, message: msg };
        }
      }

      if (ruleName === 'numeric') {
        if (value && !/^\d+$/.test(value)) {
          this.showFieldError(formEl, fieldPath, 'Solo números');
          return { isValid: false, message: 'Solo números' };
        }
      }

      if (ruleName === 'decimal') {
        if (value && !/^\d+(\.\d+)?$/.test(value)) {
          this.showFieldError(formEl, fieldPath, 'Solo números decimales');
          return { isValid: false, message: 'Solo números decimales' };
        }
      }

      if (ruleName === 'alpha_num') {
        if (value && !/^[a-zA-Z0-9]+$/.test(value)) {
          this.showFieldError(formEl, fieldPath, 'Solo letras y números');
          return { isValid: false, message: 'Solo letras y números' };
        }
      }

      if (ruleName === 'pattern') {
        const pattern = new RegExp(ruleValue);
        if (value && !pattern.test(value)) {
          this.showFieldError(formEl, fieldPath, 'Formato inválido');
          return { isValid: false, message: 'Formato inválido' };
        }
      }

      if (ruleName === 'url') {
        if (value && !this.validateUrl(value)) {
          this.showFieldError(formEl, fieldPath, 'URL inválida');
          return { isValid: false, message: 'URL inválida' };
        }
      }
    }

    return { isValid: true, message: null };
  }

  static validateRequired(value) {
    if (typeof value === 'boolean') return value;
    if (Array.isArray(value)) return value.length > 0;
    return value !== null && value !== undefined && String(value).trim() !== '';
  }

  static validateEmail(value) {
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return emailRegex.test(value);
  }

  static validateUrl(value) {
    try {
      const url = new URL(value);
      return url.protocol === 'http:' || url.protocol === 'https:';
    } catch (e) {
      return false;
    }
  }

  static getFieldValue(input) {
    if (input.type === 'checkbox') {
      return input.checked;
    }

    if (input.type === 'radio') {
      const form = input.closest('form');
      const radios = form.querySelectorAll(`input[name="${input.name}"]`);
      const checked = Array.from(radios).find(r => r.checked);
      return checked ? checked.value : '';
    }

    if (input.tagName === 'SELECT' && input.multiple) {
      return Array.from(input.selectedOptions).map(opt => opt.value);
    }

    return input.value;
  }

  static showFieldError(formEl, fieldPath, message) {
    const input = formEl.querySelector(`[name="${fieldPath}"]`);
    if (!input) return;

    const formGroup = input.closest('.og-form-group, .og-form-checkbox');
    if (!formGroup) return;

    formGroup.classList.add('og-has-error');
    const errorEl = formGroup.querySelector('.og-form-error');
    if (errorEl) {
      errorEl.textContent = message;
      errorEl.style.display = 'block';
    }
  }

  static hideFieldError(formEl, fieldPath) {
    const input = formEl.querySelector(`[name="${fieldPath}"]`);
    if (!input) return;

    const formGroup = input.closest('.og-form-group, .og-form-checkbox');
    if (!formGroup) return;

    formGroup.classList.remove('og-has-error');
    const errorEl = formGroup.querySelector('.og-form-error');
    if (errorEl) {
      errorEl.textContent = '';
      errorEl.style.display = 'none';
    }
  }

  static setError(formId, fieldName, errorMessage) {
    const formEl = document.querySelector(`[data-real-id="${formId}"]`) || document.getElementById(formId);
    if (!formEl) return;

    const input = formEl.querySelector(`[name="${fieldName}"]`);
    if (!input) return;

    const formGroup = input.closest('.og-form-group');
    if (!formGroup) return;

    formGroup.classList.add('og-has-error');
    const errorEl = formGroup.querySelector('.og-form-error');
    if (errorEl) {
      errorEl.textContent = errorMessage;
      errorEl.style.display = 'block';
    }
  }

  static clearError(formId, fieldName) {
    const formEl = document.querySelector(`[data-real-id="${formId}"]`) || document.getElementById(formId);
    if (!formEl) return;

    const input = formEl.querySelector(`[name="${fieldName}"]`);
    if (!input) return;

    const formGroup = input.closest('.og-form-group');
    if (!formGroup) return;

    formGroup.classList.remove('og-has-error');
    const errorEl = formGroup.querySelector('.og-form-error');
    if (errorEl) {
      errorEl.textContent = '';
      errorEl.style.display = 'none';
    }
  }

  static clearAllErrors(formId) {
    const formEl = document.querySelector(`[data-real-id="${formId}"]`) || document.getElementById(formId);
    if (!formEl) return;

    formEl.querySelectorAll('.og-form-error').forEach(el => {
      el.textContent = '';
      el.style.display = 'none'
    });
    formEl.querySelectorAll('.og-form-group').forEach(el => el.classList.remove('og-has-error'));
  }
}

window.ogFormValidation = ogFormValidation;

if (typeof window.ogFramework !== 'undefined') {
  window.ogFramework.core.formValidation = ogFormValidation;
}