class ogFormData {
  static getData(formId) {
    const formEl = document.getElementById(formId);
    if (!formEl) return {};

    const formData = new FormData(formEl);
    const data = {};

    for (const [key, value] of formData.entries()) {
      this.setNestedValue(data, key, value);
    }

    return data;
  }

  static setNestedValue(obj, path, value) {
    const keys = path.replace(/\[/g, '.').replace(/\]/g, '').split('.');

    let current = obj;
    for (let i = 0; i < keys.length - 1; i++) {
      const key = keys[i];
      const nextKey = keys[i + 1];

      if (!isNaN(nextKey)) {
        if (!Array.isArray(current[key])) {
          current[key] = [];
        }
        if (!current[key][nextKey]) {
          current[key][nextKey] = {};
        }
        current = current[key][nextKey];
        i++;
      } else {
        if (!current[key]) {
          current[key] = {};
        }
        current = current[key];
      }
    }

    current[keys[keys.length - 1]] = value;
  }

  static fill(formId, data, container = null, skipRepeatables = false) {
    const core = ogModule('formCore');
    const formEl = container ? container.querySelector(`#${formId}`) : document.getElementById(formId);

    if (!formEl) {
      ogLogger?.warn('core:form', `Formulario ${formId} no encontrado`);
      return;
    }

    const schema = core?.schemas?.get(formId);
    if (!schema) {
      ogLogger?.warn('core:form', `Schema para ${formId} no encontrado`);
      return;
    }

    if (!formEl.dataset.formData) {
      formEl.dataset.formData = JSON.stringify(data);
    }
    
    formEl.dataset.hasFillData = 'true';

    // Contar repetibles a llenar
    const countRepeatables = (fields) => {
      let count = 0;
      fields?.forEach(field => {
        if (field.type === 'repeatable' && data[field.name]) {
          count++;
        } else if (field.type === 'group' && field.fields) {
          count += countRepeatables(field.fields);
        } else if (field.type === 'grouper' && field.groups) {
          field.groups.forEach(group => {
            if (group.fields) count += countRepeatables(group.fields);
          });
        }
      });
      return count;
    };

    const repeatablesToFill = countRepeatables(schema.fields);
    if (repeatablesToFill > 0) {
      formEl.dataset.repeatablesToFill = repeatablesToFill;
      formEl.dataset.repeatablesFilled = 0;
    }

    // Llenar campos simples primero
    const fillSimpleFields = (fields, targetContainer) => {
      fields?.forEach(field => {
        if (field.type === 'repeatable') {
          return; // Los repetibles se llenan después
        } else if (field.type === 'group' && field.fields) {
          fillSimpleFields(field.fields, targetContainer);
        } else if (field.type === 'grouper' && field.groups) {
          field.groups.forEach(group => {
            if (group.fields) fillSimpleFields(group.fields, targetContainer);
          });
        } else if (field.name && data[field.name] !== undefined) {
          const input = targetContainer.querySelector(`[name="${field.name}"]`);
          if (input) {
            this.setInputValue(input, data[field.name], true);
          }
        }
      });
    };

    fillSimpleFields(schema.fields, formEl);

    // Llenar campos del statusbar
    if (schema.statusbar && Array.isArray(schema.statusbar)) {
      schema.statusbar.forEach(field => {
        if (field.name && data[field.name] !== undefined) {
          const input = formEl.querySelector(`[name="${field.name}"]`);
          if (input) {
            this.setInputValue(input, data[field.name], true);
          }
        }
      });
    }

    // Llenar repetibles
    if (!skipRepeatables) {
      const fillRepeatableFields = (fields, targetContainer) => {
        fields?.forEach(field => {
          if (field.type === 'repeatable' && data[field.name]) {
            setTimeout(() => {
              this.fillRepeatable(targetContainer, field, data, '');
            }, 100);
          } else if (field.type === 'group' && field.fields) {
            fillRepeatableFields(field.fields, targetContainer);
          } else if (field.type === 'grouper' && field.groups) {
            field.groups.forEach(group => {
              if (group.fields) fillRepeatableFields(group.fields, targetContainer);
            });
          }
        });
      };

      fillRepeatableFields(schema.fields, formEl);
    }
  }

  static fillRepeatable(container, field, data, parentPath) {
    const fieldName = field.name;
    const items = data[fieldName];
    const conditions = ogModule('conditions');

    if (!Array.isArray(items) || items.length === 0) {
      return;
    }

    const fullPath = parentPath ? `${parentPath}.${fieldName}` : fieldName;
    ogLogger?.info('core:form', `📋 Llenando ${fullPath}: ${items.length} items`);

    // Buscar botón "Agregar" dentro del container específico
    let addButton = container.querySelector(`.og-repeatable-add[data-path="${fullPath}"]`);
    if (!addButton) {
      addButton = container.querySelector(`.og-repeatable-add[data-path="${fieldName}"]`);
    }

    if (!addButton) {
      ogLogger?.error('core:form', `Botón "Agregar" no encontrado para: ${fullPath}`);
      return;
    }

    // Buscar contenedor de items dentro del container específico
    let itemsContainer = container.querySelector(`.og-repeatable-items[data-path="${fullPath}"]`);
    if (!itemsContainer) {
      itemsContainer = container.querySelector(`.og-repeatable-items[data-path="${fieldName}"]`);
    }

    if (!itemsContainer) {
      ogLogger?.error('core:form', `Contenedor no encontrado para: ${fullPath}`);
      return;
    }

    // Limpiar items existentes
    itemsContainer.innerHTML = '';
    itemsContainer.dataset.itemCount = '0';

    const formEl = container.closest('form');

    // Procesar cada item secuencialmente
    const processItem = (index) => {
      if (index >= items.length) {
        // Todos completados
        if (formEl && formEl.dataset.repeatablesToFill) {
          const filled = parseInt(formEl.dataset.repeatablesFilled || 0) + 1;
          formEl.dataset.repeatablesFilled = filled;

          const total = parseInt(formEl.dataset.repeatablesToFill);
          if (filled >= total) {
            if (conditions) {
              setTimeout(() => conditions.resumeEvaluations(formEl.id), 100);
            }
          }
        }
        return;
      }

      const itemData = items[index];
      addButton.click();

      setTimeout(() => {
        this.fillRepeatableItem(itemsContainer, fieldName, index, itemData, field.fields, fullPath);
        processItem(index + 1);
      }, 50);
    };

    processItem(0);
  }

  static fillRepeatableItem(container, fieldName, index, itemData, fieldSchema, parentPath) {
    const items = container.querySelectorAll('.og-repeatable-item');
    const currentItem = items[items.length - 1];

    if (!currentItem) {
      ogLogger?.error('core:form', `Item [${index}] no encontrado`);
      return;
    }

    const domIndex = parseInt(currentItem.dataset.index || index);
    const itemPath = parentPath ? `${parentPath}[${domIndex}]` : `${fieldName}[${domIndex}]`;

    fieldSchema?.forEach(subField => {
      if (subField.type === 'repeatable') {
        // RECURSIÓN: Llenar repeatable anidado
        this.fillRepeatable(currentItem, subField, itemData, itemPath);
      } else if (subField.type === 'group' && subField.fields) {
        // Procesar campos dentro del group
        subField.fields.forEach(groupField => {
          const value = itemData[groupField.name];
          if (value === undefined || value === null) return;

          const inputName = `${itemPath}.${groupField.name}`;
          const input = currentItem.querySelector(`[name="${inputName}"]`);

          if (input) {
            this.setInputValue(input, value, true);
          }
        });
      } else {
        // Campo normal
        const value = itemData[subField.name];
        if (value === undefined || value === null) return;

        const inputName = `${itemPath}.${subField.name}`;
        const input = currentItem.querySelector(`[name="${inputName}"]`);

        if (input) {
          this.setInputValue(input, value, true);
        }
      }
    });
  }

  static setInputValue(input, value, isFilling = false) {
    if (!input) return;

    if (input.type === 'checkbox') {
      input.checked = !!value;
    } else if (input.type === 'radio') {
      const radios = document.querySelectorAll(`[name="${input.name}"]`);
      radios.forEach(radio => {
        if (radio.value === String(value)) {
          radio.checked = true;
        }
      });
    } else if (input.tagName === 'SELECT') {
      if (input.dataset.source && !isFilling) {
        input.addEventListener('select:afterLoad', function handler() {
          input.value = value;
          input.removeEventListener('select:afterLoad', handler);
        }, { once: true });
      } else {
        input.value = value;
      }
    } else {
      input.value = value;
    }

    if (!isFilling) {
      input.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }

  static bindTransforms(formId, container = null) {
    const core = ogModule('formCore');
    const formEl = container ? container.querySelector(`#${formId}`) : document.getElementById(formId);

    if (!formEl) return;

    const transforms = core?.transforms || {};

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

          const lengthDiff = value.length - originalValue.length;
          let newCursorPos = cursorPos + lengthDiff;

          newCursorPos = Math.max(0, Math.min(newCursorPos, value.length));

          e.target.setSelectionRange(newCursorPos, newCursorPos);
        }
      });
    });
  }

  static applyDefaultValues(formId, container = null) {
    const core = ogModule('formCore');
    const schema = core?.schemas?.get(formId);
    if (!schema || !schema.fields) return;

    const formEl = container ? container.querySelector(`#${formId}`) : document.getElementById(formId);

    if (!formEl) return;

    this.applyDefaultsToFields(schema.fields, '', formEl);
  }

  static applyDefaultsToFields(fields, parentPath = '', formEl) {
    fields.forEach(field => {
      const fieldPath = parentPath ? `${parentPath}.${field.name}` : field.name;

      if (field.type === 'group' && field.fields) {
        this.applyDefaultsToFields(field.fields, parentPath, formEl);
      } else if (field.type === 'grouper' && field.groups) {
        field.groups.forEach(group => {
          if (group.fields) {
            this.applyDefaultsToFields(group.fields, parentPath, formEl);
          }
        });
      } else if (field.type === 'repeatable') {
        return;
      } else if (field.defaultValue !== undefined && field.defaultValue !== null && field.name) {
        const fieldEl = formEl.querySelector(`[name="${fieldPath}"]`);

        if (fieldEl) {
          const processedValue = this.processDefaultValue(field.defaultValue);

          if (fieldEl.type === 'checkbox' || fieldEl.type === 'radio') {
            fieldEl.checked = !!processedValue;
          } else {
            fieldEl.value = processedValue;
          }
        }
      }
    });
  }

  static processDefaultValue(value) {
    if (typeof value !== 'string') return value;

    const tokens = {
      hash: (length = 8) => {
        const chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
        let result = '';
        for (let i = 0; i < length; i++) {
          result += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        return result;
      },
      uuid: () => {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
          const r = Math.random() * 16 | 0;
          const v = c === 'x' ? r : (r & 0x3 | 0x8);
          return v.toString(16);
        });
      },
      timestamp: () => {
        return Date.now().toString();
      },
      date: () => {
        return new Date().toISOString().split('T')[0];
      },
      time: () => {
        const now = new Date();
        return `${now.getHours().toString().padStart(2, '0')}:${now.getMinutes().toString().padStart(2, '0')}`;
      },
      random: (min = 0, max = 100) => {
        return Math.floor(Math.random() * (max - min + 1)) + min;
      }
    };

    return value.replace(/\{([^}]+)\}/g, (match, content) => {
      const parts = content.split(':');
      const tokenName = parts[0];
      const params = parts.slice(1).map(p => {
        const num = parseFloat(p);
        return isNaN(num) ? p : num;
      });

      if (tokens[tokenName]) {
        return tokens[tokenName](...params);
      }

      return match;
    });
  }
}

window.ogFormData = ogFormData;

if (typeof window.ogFramework !== 'undefined') {
  window.ogFramework.core.formData = ogFormData;
}