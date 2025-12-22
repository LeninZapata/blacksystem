/**
 * ============================================================================
 * FORMSTATE.JS - Gestión de Estado y Datos
 * ============================================================================
 * 
 * SEPARACIÓN DEL ARCHIVO PRINCIPAL: form.js
 * Este archivo extiende la clase 'form' agregando métodos de gestión de estado.
 * NO crea una clase separada, mantiene la API pública como 'window.form'.
 * 
 * RESPONSABILIDADES:
 * - Gestión de valores de campos (get/set)
 * - Llenado de formularios con data externa
 * - Manejo de valores por defecto
 * - Estado de formularios anidados
 * - Tracking de cambios en arrays
 * - Conversión y normalización de datos
 * 
 * MÉTODOS QUE AGREGA A 'form':
 * - form.getData(formId)
 * - form.getFieldValue(formId, fieldName)
 * - form.setFieldValue(formId, fieldName, value)
 * - form.fill(formId, data, container)
 * - form.applyDefaultValues(formId, container)
 * - form.getArrayFieldValue(formId, fieldName)
 * - form.fillArrayField(formId, fieldName, items, container)
 * 
 * DEPENDENCIAS:
 * - form.js (debe cargarse ANTES de este archivo)
 * - conditions.js (para evaluar condiciones dinámicas)
 * 
 * NOTA PARA IA:
 * Este archivo NO crea window.formState. Extiende window.form.
 * Si necesitas analizar la lógica completa del sistema de formularios,
 * solicita también:
 * - form.js (orquestador principal y renderizado)
 * - formValidation.js (validación y reglas)
 * - formEvents.js (eventos y callbacks)
 * - conditions.js (evaluación de condiciones)
 * ============================================================================
 */

(function() {
  if (!window.form) {
    console.error('core:formState - form.js debe cargarse antes que formState.js');
    return;
  }

  // Obtener todos los datos del formulario
  form.getData = function(formId) {
    const formEl = document.querySelector(`[data-real-id="${formId}"]`) || document.getElementById(formId);
    if (!formEl) {
      logger.error('core:formState', `Formulario no encontrado: ${formId}`);
      return {};
    }

    const schema = form.schemas.get(formId);
    if (!schema) {
      logger.error('core:formState', `Schema no encontrado para: ${formId}`);
      return {};
    }

    const formData = {};

    const processFields = (fields, parentPath = '') => {
      fields.forEach(field => {
        const fieldPath = parentPath ? `${parentPath}.${field.name}` : field.name;

        if (field.type === 'group') {
          if (field.fields) processFields(field.fields, fieldPath);
          return;
        }

        if (field.type === 'grouper') {
          if (field.groups) {
            field.groups.forEach(group => {
              if (group.fields) processFields(group.fields, parentPath);
            });
          }
          return;
        }

        if (field.type === 'repeatable' || field.type === 'array') {
          const arrayValue = form.getArrayFieldValue(formId, field.name);
          if (arrayValue && arrayValue.length > 0) {
            form._setNestedValue(formData, field.name, arrayValue);
          }
          return;
        }

        const value = form.getFieldValue(formId, field.name);
        if (value !== null && value !== undefined) {
          form._setNestedValue(formData, fieldPath, value);
        }
      });
    };

    if (schema.fields) processFields(schema.fields);
    return formData;
  };

  // Obtener valor de un campo específico
  form.getFieldValue = function(formId, fieldName) {
    const formEl = document.querySelector(`[data-real-id="${formId}"]`) || document.getElementById(formId);
    if (!formEl) return null;

    const input = formEl.querySelector(`[name="${fieldName}"]`);
    if (!input) return null;

    if (input.type === 'checkbox') return input.checked;
    if (input.type === 'radio') {
      const checked = formEl.querySelector(`[name="${fieldName}"]:checked`);
      return checked ? checked.value : null;
    }
    if (input.type === 'number') {
      return input.value !== '' ? Number(input.value) : null;
    }

    return input.value || null;
  };

  // Establecer valor de un campo específico
  form.setFieldValue = function(formId, fieldName, value) {
    const formEl = document.querySelector(`[data-real-id="${formId}"]`) || document.getElementById(formId);
    if (!formEl) return;

    const input = formEl.querySelector(`[name="${fieldName}"]`);
    if (!input) return;

    if (input.type === 'checkbox') {
      input.checked = !!value;
    } else if (input.type === 'radio') {
      const radio = formEl.querySelector(`[name="${fieldName}"][value="${value}"]`);
      if (radio) radio.checked = true;
    } else {
      input.value = value ?? '';
    }

    input.dispatchEvent(new Event('change', { bubbles: true }));
  };

  // Llenar formulario con datos externos
  form.fill = function(formId, data, container = null, skipRepeatables = false) {
    const formEl = container
      ? container.querySelector(`#${formId}`)
      : document.getElementById(formId);

    if (!formEl) {
      logger.warn('core:formState', `Formulario ${formId} no encontrado`);
      return;
    }

    const schema = form.schemas.get(formId);
    if (!schema) {
      logger.warn('core:formState', `Schema para ${formId} no encontrado`);
      return;
    }

    logger.debug('core:formState', `Llenando formulario ${formId}${skipRepeatables ? ' (solo selects)' : ''}`);
    logger.debug('core:formState', `Estructura de datos recibidos:`, JSON.stringify(Object.keys(data)));
    logger.debug('core:formState', `¿Tiene config?:`, !!data.config);
    if (data.config) {
      logger.debug('core:formState', `Keys en config:`, Object.keys(data.config));
    }
    // Guardar data en el formulario
    if (!formEl.dataset.formData) {
      formEl.dataset.formData = JSON.stringify(data);
    }

    // Procesar campos para selects
    const processFieldsForSelects = (fields) => {
      if (!fields) return;

      fields.forEach(field => {
        if (field.type === 'group' && field.fields) {
          processFieldsForSelects(field.fields);
        } else if (field.type === 'grouper' && field.groups) {
          field.groups.forEach(group => {
            if (group.fields) processFieldsForSelects(group.fields);
          });
        } else if (field.type === 'repeatable') {
          let repeatableData;
          if (field.name.includes('.')) {
            repeatableData = form._getNestedValue(data, field.name);
          } else {
            repeatableData = data[field.name];
          }
          
          if (Array.isArray(repeatableData) && repeatableData.length > 0) {
            const itemsContainer = formEl.querySelector(`.repeatable-items[data-path="${field.name}"]`);
            if (itemsContainer) {
              const items = itemsContainer.querySelectorAll('.repeatable-item');
              items.forEach((item, index) => {
                const itemData = repeatableData[index];
                if (itemData && field.fields) {
                  form._fillRepeatableItemSelects(item, field.name, index, itemData, field.fields);
                }
              });
            }
          }
        } else if (field.name) {
          const value = field.name.includes(".") ? form._getNestedValue(data, field.name) : data[field.name];
          if (value !== undefined && value !== null) {
            const input = formEl.querySelector(`[name="${field.name}"]`);
            if (input) {
              form._setInputValue(input, value);
            }
          }
        }
      });
    };

    // Procesar todos los campos incluyendo repeatables
    const processAllFields = (fields) => {
      if (!fields) return;

      fields.forEach(field => {
        if (field.type === 'repeatable') {
          if (!skipRepeatables) {
            form._fillRepeatable(formEl, field, data, '');
          }
        } else if (field.type === 'group' && field.fields) {
          processAllFields(field.fields);
        } else if (field.type === 'grouper' && field.groups) {
          field.groups.forEach(group => {
            if (group.fields) processAllFields(group.fields);
          });
        } else if (field.name) {
          const value = field.name.includes(".") ? form._getNestedValue(data, field.name) : data[field.name];
          if (value !== undefined && value !== null) {
            const input = formEl.querySelector(`[name="${field.name}"]`);
            if (input) {
              form._setInputValue(input, value);
            }
          }
        }
      });
    };

    // Primera pasada
    if (skipRepeatables) {
      processFieldsForSelects(schema.fields);
    } else {
      processAllFields(schema.fields);
    }

    // Registrar listener para selects
    if (!formEl.dataset.fillListenerRegistered) {
      formEl.addEventListener('select:afterLoad', (e) => {
        const savedData = JSON.parse(formEl.dataset.formData || '{}');
        processFieldsForSelects(schema.fields);
      });
      formEl.dataset.fillListenerRegistered = 'true';
    }
  };

  // Llenar repeatable (privado)
  form._fillRepeatable = function(container, field, data, parentPath) {
    const fieldName = field.name;
    
    // Navegar por el path con puntos (config.welcome_messages -> data.config.welcome_messages)
    let items;
    if (fieldName.includes('.')) {
      items = form._getNestedValue(data, fieldName);
    } else {
      items = data[fieldName];
    }

    logger.info('core:formState', `Intentando llenar repeatable: ${fieldName}`);
    logger.info('core:formState', `Items encontrados:`, items);

    if (!Array.isArray(items) || items.length === 0) {
      logger.warn('core:formState', `No hay datos array para: ${fieldName}`);
      return;
    }

    const fullPath = parentPath ? `${parentPath}.${fieldName}` : fieldName;
    logger.info('core:formState', `Llenando ${fullPath}: ${items.length} items`);

    // Pausar evaluaciones
    if (window.conditions && items.length >= 1) {
      conditions.pauseEvaluations();
    }

    // Encontrar botón agregar
    let addButton = container.querySelector(`.repeatable-add[data-path="${fullPath}"]`);
    if (!addButton) {
      addButton = container.querySelector(`.repeatable-add[data-path="${fieldName}"]`);
    }

    if (!addButton) {
      logger.error('core:formState', `Botón agregar no encontrado para: ${fullPath}`);
      return;
    }

    // Encontrar contenedor
    let itemsContainer = container.querySelector(`.repeatable-items[data-path="${fullPath}"]`);
    if (!itemsContainer) {
      itemsContainer = container.querySelector(`.repeatable-items[data-path="${fieldName}"]`);
    }

    if (!itemsContainer) {
      logger.error('core:formState', `Contenedor no encontrado para: ${fullPath}`);
      return;
    }

    // Limpiar items existentes
    itemsContainer.innerHTML = '';

    // Agregar items uno por uno
    items.forEach((itemData, index) => {
      setTimeout(() => {
        addButton.click();

        setTimeout(() => {
          const isLastItem = (index === items.length - 1);
          form._fillRepeatableItem(itemsContainer, fieldName, index, itemData, field.fields, fullPath, isLastItem);
        }, 100);

      }, index * 300);
    });
  };

  // Llenar item de repeatable (privado)
  form._fillRepeatableItem = function(container, fieldName, index, itemData, fieldSchema, parentPath, isLastItem = false) {
    // Reanudar evaluaciones si es el último
    if (isLastItem && window.conditions) {
      const formEl = container.closest('form');
      setTimeout(() => {
        conditions.resumeEvaluations(formEl?.id);
      }, 200);
    }

    const items = container.querySelectorAll('.repeatable-item');
    const currentItem = items[items.length - 1];

    if (!currentItem) {
      logger.error('core:formState', `Item no encontrado en index ${index}`);
      return;
    }

    const itemPath = `${parentPath}[${index}]`;

    fieldSchema.forEach(field => {
      if (field.type === 'repeatable') {
        // RECURSIÓN: Llenar repeatable anidado
        logger.info('core:formState', `Procesando repeatable anidado: ${field.name}`);
        
        // Llamar recursivamente pasando el item actual como contenedor
        form._fillRepeatable(currentItem, field, itemData, itemPath);
        
      } else {
        // Campo normal
        const value = itemData[field.name];

        if (value !== undefined && value !== null) {
          const fieldPath = `${itemPath}.${field.name}`;
          const input = currentItem.querySelector(`[name="${fieldPath}"]`);
          if (input) {
            form._setInputValue(input, value);
          }
        }
      }
    });
  };


  // Llenar selects de item repeatable (privado)
  form._fillRepeatableItemSelects = function(item, fieldName, index, itemData, fieldSchema) {
    fieldSchema.forEach(field => {
      if (field.type === 'select' && field.source) {
        const value = itemData[field.name];
        if (value !== undefined && value !== null) {
          const input = item.querySelector(`[name*="${field.name}"]`);
          if (input) {
            form._setInputValue(input, value);
          }
        }
      }
    });
  };

  // Set input value helper (privado)
  form._setInputValue = function(input, value) {
    if (input.type === 'checkbox') {
      input.checked = !!value;
    } else if (input.type === 'radio') {
      const formEl = input.closest('form');
      const radio = formEl.querySelector(`[name="${input.name}"][value="${value}"]`);
      if (radio) radio.checked = true;
    } else {
      input.value = value;
    }
    input.dispatchEvent(new Event('change', { bubbles: true }));
  };

  // Aplicar valores por defecto
  // Aplicar valores por defecto a campos del formulario
  form.applyDefaultValues = function(formId, container = null) {
    const schema = form.schemas.get(formId);
    if (!schema || !schema.fields) return;

    const formEl = container 
      ? container.querySelector(`#${formId}`)
      : document.getElementById(formId);
    
    if (!formEl) return;

    form._applyDefaultsToFields(schema.fields, '', formEl);
  };

  // Aplicar defaults recursivamente (privado)
  form._applyDefaultsToFields = function(fields, parentPath = '', formEl) {
    fields.forEach(field => {
      const fieldPath = parentPath ? `${parentPath}.${field.name}` : field.name;

      if (field.type === 'group' && field.fields) {
        form._applyDefaultsToFields(field.fields, parentPath, formEl);
      } else if (field.type === 'grouper' && field.groups) {
        field.groups.forEach(group => {
          if (group.fields) {
            form._applyDefaultsToFields(group.fields, parentPath, formEl);
          }
        });
      } else if (field.type === 'repeatable') {
        // Los repeatables aplican defaults al agregar items
        return;
      } else if (field.defaultValue !== undefined && field.defaultValue !== null && field.name) {
        const fieldEl = formEl.querySelector(`[name="${fieldPath}"]`);
        
        if (fieldEl) {
          const processedValue = form.processDefaultValue(field.defaultValue);
          
          if (fieldEl.type === 'checkbox' || fieldEl.type === 'radio') {
            fieldEl.checked = !!processedValue;
          } else {
            fieldEl.value = processedValue;
          }
        }
      }
    });
  };

  // Obtener valor de campo array/repeatable
  form.getArrayFieldValue = function(formId, fieldName) {
    const formEl = document.querySelector(`[data-real-id="${formId}"]`) || document.getElementById(formId);
    if (!formEl) return [];

    // Buscar contenedor por data-path (nuevo sistema) o data-repeatable (legacy)
    const container = formEl.querySelector(`[data-path="${fieldName}"]`) || 
                      formEl.querySelector(`[data-repeatable="${fieldName}"]`);
    if (!container) {
      logger.warn('core:formState', `Contenedor repeatable no encontrado: ${fieldName}`);
      return [];
    }

    const items = container.querySelectorAll('.repeatable-item');
    const values = [];

    items.forEach((item, index) => {
      const itemData = {};
      const inputs = item.querySelectorAll('input, select, textarea');
      
      inputs.forEach(input => {
        if (!input.name) return;
        
        // Extraer el nombre del campo del path completo
        // De "config.welcome_messages[0].message" extraer "message"
        const match = input.name.match(/\[(\d+)\]\.(.+)$/);
        if (match) {
          const fieldName = match[2];
          if (input.type === 'checkbox') {
            itemData[fieldName] = input.checked;
          } else if (input.type === 'radio') {
            if (input.checked) {
              itemData[fieldName] = input.value;
            }
          } else {
            itemData[fieldName] = input.value;
          }
        }
      });

      if (Object.keys(itemData).length > 0) {
        values.push(itemData);
      }
    });

    return values;
  };

  // Llenar campo array/repeatable
  form.fillArrayField = function(formId, fieldName, items, container) {
    if (!Array.isArray(items) || items.length === 0) return;

    const schema = form.schemas.get(formId);
    if (!schema) return;

    const field = form._findFieldInSchema(schema, fieldName);
    if (!field) return;

    const formEl = container.querySelector(`#${formId}`) || document.getElementById(formId);
    const arrayContainer = formEl.querySelector(`[data-repeatable="${fieldName}"]`);
    if (!arrayContainer) return;

    arrayContainer.innerHTML = '';

    items.forEach((itemData, index) => {
      const itemHtml = form.buildArrayField(field, `${fieldName}[${index}]`, itemData);
      const wrapper = document.createElement('div');
      wrapper.innerHTML = itemHtml;
      arrayContainer.appendChild(wrapper.firstElementChild);
    });

    form.bindEventsOnce();
  };

  // Helpers internos para navegación anidada
  form._getNestedValue = function(obj, path) {
    return path.split('.').reduce((current, key) => current?.[key], obj);
  };

  form._setNestedValue = function(obj, path, value) {
    const keys = path.split('.');
    const lastKey = keys.pop();
    const target = keys.reduce((current, key) => {
      if (!current[key]) current[key] = {};
      return current[key];
    }, obj);
    target[lastKey] = value;
  };

  form._findFieldInSchema = function(schema, fieldName, fields = null) {
    const fieldsToSearch = fields || schema.fields || [];

    for (const field of fieldsToSearch) {
      if (field.name === fieldName) return field;
      if (field.fields) {
        const found = form._findFieldInSchema(schema, fieldName, field.fields);
        if (found) return found;
      }
    }

    return null;
  };

})();