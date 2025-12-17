class conditions {
  static rules = new Map();
  static watchers = new Map();
  static initialized = false;
  static evaluateTimeout = null;
  static isFillingForm = false;

  static init(formId) {
    if (!formId) return;

    const schema = window.form?.schemas?.get(formId);
    if (!schema || !schema.fields) return;

    this.rules.delete(formId);
    this.watchers.delete(formId);

    const rulesMap = new Map();
    this.extractConditions(schema.fields, rulesMap, '');

    if (rulesMap.size === 0) return;

    this.rules.set(formId, rulesMap);
    this.setupWatchers(formId);
    this.setupRepeatableObserver(formId);

    setTimeout(() => this.evaluate(formId), 50);
  }

  static setupRepeatableObserver(formId) {
    const formEl = document.getElementById(formId);
    if (!formEl) return;

    const observeRepeatableContainers = (rootElement) => {
      const repeatableContainers = rootElement.querySelectorAll('.repeatable-items');

      repeatableContainers.forEach(container => {
        if (container.dataset.conditionsObserved === 'true') {
          return;
        }

        container.dataset.conditionsObserved = 'true';

        const observer = new MutationObserver((mutations) => {
          mutations.forEach((mutation) => {
            if (mutation.type === 'childList' && mutation.addedNodes.length > 0) {
              mutation.addedNodes.forEach(node => {
                if (node.nodeType === 1 && node.classList.contains('repeatable-item')) {
                  observeRepeatableContainers(node);
                }
              });
            }
          });
        });

        observer.observe(container, {
          childList: true,
          subtree: false
        });

        if (!this.watchers.has(formId)) {
          this.watchers.set(formId, []);
        }
        const watchers = this.watchers.get(formId);
        watchers.push({ type: 'observer', observer, container });
      });
    };

    observeRepeatableContainers(formEl);
  }

  static extractConditions(fields, rulesMap, parentPath = '') {
    fields.forEach((field, index) => {
      if (!field.name) {
        logger.warn('core:conditions', `⚠️ Campo sin 'name' encontrado en index ${index}, tipo: ${field.type}. Se omitirá.`);
        return;
      }

      const fieldPath = parentPath ? `${parentPath}.${field.name}` : field.name;

      if (field.condition && Array.isArray(field.condition) && field.condition.length > 0) {
        rulesMap.set(fieldPath, {
          conditions: field.condition,
          context: field.conditionContext || 'form',
          logic: field.conditionLogic || 'AND'
        });
      }

      if (field.type === 'repeatable' && field.fields) {
        this.extractConditions(field.fields, rulesMap, fieldPath);
      }

      if (field.type === 'grouper') {
        if (field.condition && Array.isArray(field.condition) && field.condition.length > 0) {
          const grouperName = field.name || `grouper_${index}`;
          const grouperPath = parentPath ? `${parentPath}.${grouperName}` : grouperName;
          rulesMap.set(grouperPath, {
            conditions: field.condition,
            logic: field.conditionLogic || 'AND',
            context: field.conditionContext || 'form'
          });
        }

        if (field.groups && Array.isArray(field.groups)) {
          field.groups.forEach((group, index) => {
            if (group.fields && Array.isArray(group.fields)) {
              this.extractConditions(group.fields, rulesMap, parentPath);
            }
          });
        } else if (field.fields) {
          this.extractConditions(field.fields, rulesMap, parentPath);
        }
      }
    });
  }

  static setupWatchers(formId) {
    const formEl = document.getElementById(formId);
    if (!formEl) return;

    const rulesMap = this.rules.get(formId);
    const watchedFields = new Set();

    rulesMap.forEach((rule, targetField) => {
      rule.conditions.forEach(cond => {
        watchedFields.add(cond.field);
      });
    });

    const watcherId = window.events.on(
      `#${formId} input, #${formId} select, #${formId} textarea`,
      'change',
      (e) => {
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

    const inputWatcherId = window.events.on(
      `#${formId} input[type="text"], #${formId} input[type="email"], #${formId} input[type="number"], #${formId} textarea`,
      'input',
      (e) => {
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

    if (!this.watchers.has(formId)) {
      this.watchers.set(formId, []);
    }

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
        this.evaluateRepeatable(formEl, targetFieldPath, rule);
      } else {
        const shouldShow = this.checkConditions(formEl, rule, targetFieldPath);
        this.applyVisibilitySimple(formEl, targetFieldPath, shouldShow);
      }
    });
  }

  static pauseEvaluations() {
    this.isFillingForm = true;
  }

  static resumeEvaluations(formId) {
    this.isFillingForm = false;

    setTimeout(() => {
      if (formId) {
        this.evaluate(formId);
      }
    }, 200);
  }

  static evaluateRepeatable(formEl, targetFieldPath, rule) {
    const pathParts = targetFieldPath.split('.');
    const fieldName = pathParts[pathParts.length - 1];
    const repeatablePath = pathParts.slice(0, -1).join('.');

    const allContainers = formEl.querySelectorAll('.repeatable-items[data-path]');
    const repeatableContainers = Array.from(allContainers).filter(container => {
      const dataPath = container.getAttribute('data-path');
      const normalizedPath = dataPath.replace(/\[\d+\]/g, '');
      return normalizedPath === repeatablePath;
    });

    if (repeatableContainers.length === 0) {
      return;
    }

    repeatableContainers.forEach(container => {
      const items = container.querySelectorAll('.repeatable-item');

      items.forEach(item => {
        const shouldShow = this.checkConditions(item, rule, targetFieldPath);

        const targetFields = item.querySelectorAll(`[name*=".${fieldName}"]`);

        targetFields.forEach(targetField => {
          const fieldElement = targetField.closest('.form-group, .form-checkbox, .form-html-wrapper, .form-grouper-wrapper');

          if (fieldElement) {
            if (shouldShow) {
              fieldElement.style.display = '';
              fieldElement.classList.remove('wpfw-depend-on');
              if (targetField.tagName === 'INPUT' || targetField.tagName === 'SELECT' || targetField.tagName === 'TEXTAREA') {
                targetField.disabled = false;
              }
            } else {
              fieldElement.style.display = 'none';
              fieldElement.classList.add('wpfw-depend-on');
              if (targetField.tagName === 'INPUT' || targetField.tagName === 'SELECT' || targetField.tagName === 'TEXTAREA') {
                targetField.disabled = true;
              }
            }
          }
        }
      );
      });
    });
  }

  static applyVisibilityToAll(formEl, fieldPath, shouldShow) {
    const pathParts = fieldPath.split('.');
    const fieldName = pathParts[pathParts.length - 1];

    const matchingFields = formEl.querySelectorAll(`[name*=".${fieldName}"]`);

    matchingFields.forEach(field => {
      const fieldElement = field.closest('.form-group, .form-checkbox, .form-grouper-wrapper');

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
      logger.warn('core:conditions', `No se encontró el elemento para "${fieldPath}"`);
      return;
    }

    if (shouldShow) {
      fieldElement.style.display = '';
      fieldElement.classList.remove('wpfw-depend-on');

      const inputs = fieldElement.querySelectorAll('input, select, textarea');
      inputs.forEach(input => {
        input.disabled = false;
      });
    } else {
      fieldElement.style.display = 'none';
      fieldElement.classList.add('wpfw-depend-on');

      const inputs = fieldElement.querySelectorAll('input, select, textarea');
      inputs.forEach(input => {
        input.disabled = true;
      });
    }
  }

  static checkConditions(formEl, rule, targetFieldPath) {
    const { conditions, logic, context } = rule;

    const searchContext = this.getContext(formEl, targetFieldPath, context);

    if (logic === 'OR') {
      return conditions.some(cond => this.checkCondition(searchContext, cond));
    } else {
      return conditions.every(cond => this.checkCondition(searchContext, cond));
    }
  }

  static checkCondition(context, condition) {
    const { field, operator, value } = condition;

    let fieldEl = null;

    if (context.classList && context.classList.contains('repeatable-item')) {
      const fields = context.querySelectorAll(`[name*=".${field}"]`);
      fieldEl = fields.length > 0 ? fields[0] : null;

      if (!fieldEl) {
        fieldEl = context.querySelector(`[name="${field}"], [name*="${field}"]`);
      }
    } else {
      fieldEl = context.querySelector(`[name="${field}"]`);

      if (!fieldEl) {
        fieldEl = context.querySelector(`[name*="${field}"]`);
      }
    }

    if (!fieldEl) {
      logger.warn('core:conditions', `[checkCondition] ❌ Campo "${field}" no encontrado en contexto`);
      return false;
    }

    const fieldValue = this.getFieldValue(fieldEl);
    const result = this.evalOperator(operator, fieldValue, value);

    return result;
  }

  static evalOperator(operator, fieldValue, targetValue) {
    // Validar que targetValue no sea undefined o null en operadores que lo requieran
    if (targetValue === undefined || targetValue === null) {
      targetValue = '';
    }

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
        const anyList = String(targetValue).split(',').map(v => v.trim());
        if (Array.isArray(fieldValue)) {
          return fieldValue.some(v => anyList.includes(String(v).trim()));
        }
        return anyList.includes(String(fieldValue).trim());

      case 'not-any':
        const notAnyList = String(targetValue).split(',').map(v => v.trim());
        if (Array.isArray(fieldValue)) {
          return !fieldValue.some(v => notAnyList.includes(String(v).trim()));
        }
        return !notAnyList.includes(String(fieldValue).trim());

      case 'empty':
        if (Array.isArray(fieldValue)) return fieldValue.length === 0;
        return !fieldValue || String(fieldValue).trim() === '';

      case 'not-empty':
        if (Array.isArray(fieldValue)) return fieldValue.length > 0;
        return fieldValue && String(fieldValue).trim() !== '';

      case 'contains':
        return String(fieldValue).toLowerCase().includes(String(targetValue).toLowerCase());

      case 'not-contains':
        return !String(fieldValue).toLowerCase().includes(String(targetValue).toLowerCase());

      default:
        logger.warn('core:conditions', `Operador desconocido "${operator}"`);
        return false;
    }
  }

  static normalize(value) {
    if (value === true || value === 'true' || value === '1' || value === 1) return true;
    if (value === false || value === 'false' || value === '0' || value === 0) return false;
    return value;
  }

  static getFieldValue(fieldEl) {
    const type = fieldEl.type;
    const name = fieldEl.name;

    if (type === 'checkbox') {
      return fieldEl.checked;
    }

    if (type === 'radio') {
      const form = fieldEl.closest('form');
      const radios = form.querySelectorAll(`input[name="${name}"]`);
      const checked = Array.from(radios).find(r => r.checked);
      return checked ? checked.value : '';
    }

    if (fieldEl.tagName === 'SELECT' && fieldEl.multiple) {
      return Array.from(fieldEl.selectedOptions).map(opt => opt.value);
    }

    return fieldEl.value;
  }

  static getFieldName(element) {
    const name = element.name || '';
    const withoutIndexes = name.replace(/\[\d+\]/g, '');
    const parts = withoutIndexes.split('.');
    return parts[parts.length - 1];
  }

  static getContext(formEl, targetFieldPath, contextType) {
    switch (contextType) {
      case 'view':
        return document;

      case 'form':
        return formEl;

      case 'repeatable':
        const targetField = this.findFieldElement(formEl, targetFieldPath);
        if (targetField) {
          const repeatable = targetField.closest('.repeatable-item');
          return repeatable || formEl;
        }
        return formEl;

      case 'group':
        const targetFieldGroup = this.findFieldElement(formEl, targetFieldPath);
        if (targetFieldGroup) {
          const group = targetFieldGroup.closest('.form-group-container, .repeatable-item, .grouper-content');
          return group || formEl;
        }
        return formEl;

      default:
        return formEl;
    }
  }

  static findFieldElement(formEl, fieldPath) {
    let field = formEl.querySelector(`[name="${fieldPath}"]`);
    if (field) return field.closest('.form-group, .form-checkbox, .form-html-wrapper, .form-grouper-wrapper');

    let htmlWrapper = formEl.querySelector(`.form-html-wrapper[data-field-name="${fieldPath}"]`);
    if (htmlWrapper) return htmlWrapper;

    let grouperWrapper = formEl.querySelector(`.form-grouper-wrapper[data-field-name="${fieldPath}"]`);
    if (grouperWrapper) return grouperWrapper;

    const pathParts = fieldPath.split('.');
    if (pathParts.length > 1) {
      const baseField = pathParts[pathParts.length - 1];

      const fields = formEl.querySelectorAll(`[name*=".${baseField}"]`);
      if (fields.length > 0) {
        return fields[0].closest('.form-group, .form-checkbox, .form-html-wrapper, .form-grouper-wrapper');
      }
    }

    field = formEl.querySelector(`[name*="${fieldPath}"]`);
    if (field) return field.closest('.form-group, .form-checkbox, .form-html-wrapper, .form-grouper-wrapper');

    return null;
  }

  static destroy(formId) {
    const watchers = this.watchers.get(formId);
    if (watchers) {
      watchers.forEach(watcher => {
        if (typeof watcher === 'number') {
          window.events?.off?.(watcher);
        } else if (watcher.type === 'observer') {
          watcher.observer.disconnect();

          if (watcher.container) {
            delete watcher.container.dataset.conditionsObserved;
          }
        }
      });
      this.watchers.delete(formId);
    }

    this.rules.delete(formId);
  }

  static debug(formId) {
    logger.debug('core:conditions', `Debug: ${formId}`);

    const rules = this.rules.get(formId);
    if (!rules) {
      logger.debug('core:conditions', 'No hay reglas registradas para este formulario');
      return;
    }

    logger.debug('core:conditions', `Reglas activas: ${rules.size}`);
  }
}

window.conditions = conditions;