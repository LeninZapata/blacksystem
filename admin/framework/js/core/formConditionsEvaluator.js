class ogConditionsEvaluator {
  static evaluate(formId) {
    const formEl = document.getElementById(formId);
    if (!formEl) return;

    const core = ogModule('conditionsCore');
    const rulesMap = core?.rules.get(formId);
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
    const core = ogModule('conditionsCore');
    if (core) {
      core.isFillingForm = true;
    }
  }

  static resumeEvaluations(formId) {
    const core = ogModule('conditionsCore');
    if (core) {
      core.isFillingForm = false;
    }

    requestAnimationFrame(() => {
      if (formId) {
        this.evaluate(formId);
      }
    });
  }

  static evaluateRepeatable(formEl, targetFieldPath, rule) {
    const pathParts = targetFieldPath.split('.');
    const fieldName = pathParts[pathParts.length - 1];
    const repeatablePath = pathParts.slice(0, -1).join('.');

    const allContainers = formEl.querySelectorAll('.og-repeatable-items[data-path]');
    const repeatableContainers = Array.from(allContainers).filter(container => {
      const dataPath = container.getAttribute('data-path');
      const normalizedPath = dataPath.replace(/\[\d+\]/g, '');
      return normalizedPath === repeatablePath;
    });

    if (repeatableContainers.length === 0) {
      return;
    }

    repeatableContainers.forEach((repeatableContainer) => {
      const repeatableItems = repeatableContainer.querySelectorAll(':scope > .og-repeatable-item');

      if (repeatableItems.length === 0) {
        return;
      }

      repeatableItems.forEach((item, idx) => {
        const shouldShow = this.checkConditionsWithContext(item, rule);

        let targetField = item.querySelector(`[name*=".${fieldName}"]`);

        if (!targetField) {
          const allFieldPaths = item.querySelectorAll('.og-form-repeatable[data-field-path], .og-grouper[data-field-path], .og-form-group-cols[data-field-path]');
          const candidates = Array.from(allFieldPaths).filter(el => {
            const fieldPath = el.getAttribute('data-field-path');
            const normalizedFieldPath = fieldPath.replace(/\[\d+\]/g, '');
            return normalizedFieldPath.endsWith(`.${fieldName}`) || normalizedFieldPath === fieldName;
          });

          if (candidates.length > 0) {
            targetField = candidates.reduce((shortest, current) => {
              const shortestPath = shortest.getAttribute('data-field-path');
              const currentPath = current.getAttribute('data-field-path');
              return currentPath.length < shortestPath.length ? current : shortest;
            });
          }
        }

        if (targetField) {
          const fieldElement = targetField.closest('.og-form-group, .og-form-checkbox, .og-form-repeatable, .og-grouper, .og-form-group-cols');

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
      });
    });
  }

  static applyVisibilityToAll(formEl, fieldPath, shouldShow) {
    const pathParts = fieldPath.split('.');
    const fieldName = pathParts[pathParts.length - 1];

    const matchingFields = formEl.querySelectorAll(`[name*=".${fieldName}"]`);

    matchingFields.forEach(field => {
      const fieldElement = field.closest('.og-form-group, .og-form-checkbox');

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

      if (fieldElement.classList.contains('og-grouper')) {
        const formGroups = fieldElement.querySelectorAll('.og-form-group, .og-form-checkbox');
        formGroups.forEach(group => {
          group.classList.remove('og-form-hidden');
        });
      }

      const inputs = fieldElement.querySelectorAll('input, select, textarea');
      inputs.forEach(input => {
        input.disabled = false;
      });
    } else {
      fieldElement.style.display = 'none';
      fieldElement.classList.add('wpfw-depend-on');

      if (fieldElement.classList.contains('og-grouper')) {
        const formGroups = fieldElement.querySelectorAll('.og-form-group, .og-form-checkbox');
        formGroups.forEach(group => {
          group.classList.add('og-form-hidden');
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

    const searchContext = this.getContext(formEl, targetFieldPath, context);

    if (logic === 'OR') {
      return conditions.some(cond => this.checkCondition(searchContext, cond));
    } else {
      return conditions.every(cond => this.checkCondition(searchContext, cond));
    }
  }

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
    const operators = ogModule('conditionsOperators');

    let fieldEl = null;

    if (context.classList && context.classList.contains('og-repeatable-item')) {
      let fields = context.querySelectorAll(`[name*=".${field}"]`);
      fieldEl = fields.length > 0 ? fields[0] : null;

      if (!fieldEl) {
        fields = context.querySelectorAll(`[name$=".${field}"]`);
        fieldEl = fields.length > 0 ? fields[0] : null;
      }

      if (!fieldEl) {
        fieldEl = context.querySelector(`[name="${field}"], [name*="${field}"]`);
      }
    } else {
      fieldEl = context.querySelector(`[name="${field}"], [name*="${field}"]`);
    }

    if (!fieldEl) {
      ogLogger?.warn('core:conditions', `[checkCondition] ❌ Campo "${field}" no encontrado en contexto`);
      return false;
    }

    const fieldValue = operators?.getFieldValue(fieldEl);
    return operators?.checkOperator(fieldValue, operator, value);
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
          const repeatable = targetField.closest('.og-repeatable-item');
          return repeatable || formEl;
        }
        return formEl;

      case 'group':
        const targetFieldGroup = this.findFieldElement(formEl, targetFieldPath);
        if (targetFieldGroup) {
          const group = targetFieldGroup.closest('.og-form-group-container, .og-repeatable-item');
          return group || formEl;
        }
        return formEl;

      default:
        return formEl;
    }
  }

  static findFieldElement(formEl, fieldPath) {
    let field = formEl.querySelector(`[name="${fieldPath}"]`);
    if (field) return field.closest('.og-form-group, .og-form-checkbox, .og-form-html-wrapper, .og-form-repeatable');
    
    let group = formEl.querySelector(`.og-form-group-cols[data-field-path="${fieldPath}"]`);
    if (group) return group;
    
    if (fieldPath.includes('.')) {
      const allGroups = formEl.querySelectorAll('.og-form-group-cols[data-field-path]');
      for (const g of allGroups) {
        const gPath = g.getAttribute('data-field-path');
        const normalizedPath = gPath.replace(/\[\d+\]/g, '');
        if (normalizedPath === fieldPath) {
          return g;
        }
      }
    }

    let htmlWrapper = formEl.querySelector(`.og-form-html-wrapper[data-field-name="${fieldPath}"]`);
    if (htmlWrapper) return htmlWrapper;

    let grouper = formEl.querySelector(`.og-grouper[data-field-path="${fieldPath}"]`);
    if (grouper) return grouper;

    const pathParts = fieldPath.split('.');
    if (pathParts.length > 1) {
      const baseField = pathParts[pathParts.length - 1];

      const fields = formEl.querySelectorAll(`[name*=".${baseField}"]`);
      if (fields.length > 0) {
        return fields[0].closest('.og-form-group, .og-form-checkbox, .og-form-html-wrapper, .og-form-repeatable');
      }
    }

    field = formEl.querySelector(`[name*="${fieldPath}"]`);
    if (field) return field.closest('.og-form-group, .og-form-checkbox, .og-form-html-wrapper, .og-form-repeatable');

    ogLogger?.warn('core:conditions', `[findFieldElement] ❌ NO se encontró elemento para: "${fieldPath}"`);
    return null;
  }
}

// Global
window.ogConditionsEvaluator = ogConditionsEvaluator;

// Registrar en ogFramework
if (typeof window.ogFramework !== 'undefined') {
  window.ogFramework.core.conditionsEvaluator = ogConditionsEvaluator;
}