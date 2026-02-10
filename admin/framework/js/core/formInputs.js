class ogFormInputs {
  static getTransformClasses(field) {
    const core = ogModule('formCore');
    const transformClasses = [];

    // 1. Transforms explícitos definidos en el campo
    if (field.transform) {
      const transforms = Array.isArray(field.transform) ? field.transform : [field.transform];
      transforms.forEach(t => transformClasses.push(`form-transform-${t}`));
    }

    // 2. Auto-detectar transforms desde validation
    if (field.validation && core?.validationToTransformMap) {
      Object.keys(core.validationToTransformMap).forEach(validationRule => {
        if (field.validation.includes(validationRule)) {
          const transformName = core.validationToTransformMap[validationRule];
          const transformClass = `form-transform-${transformName}`;
          if (!transformClasses.includes(transformClass)) {
            transformClasses.push(transformClass);
          }
        }
      });
    }

    return transformClasses;
  }

  static getValidationAttributes(field) {
    const attrs = [];
    if (!field.validation) return '';

    const rules = field.validation.split('|');
    const isNumberType = field.type === 'number' || field.type === 'range';

    rules.forEach(rule => {
      const [ruleName, ruleValue] = rule.split(':');

      if (ruleName === 'min') {
        const attrName = isNumberType ? 'min' : 'minlength';
        attrs.push(`${attrName}="${ruleValue}"`);
      }
      else if (ruleName === 'max') {
        const attrName = isNumberType ? 'max' : 'maxlength';
        attrs.push(`${attrName}="${ruleValue}"`);
      }
      else if (ruleName === 'minValue') {
        attrs.push(`min="${ruleValue}"`);
      }
      else if (ruleName === 'maxValue') {
        attrs.push(`max="${ruleValue}"`);
      }
    });

    return attrs.join(' ');
  }

  static buildStyleAttr(styleConfig) {
    if (!styleConfig) return '';

    if (typeof styleConfig === 'string') {
      return `style="${styleConfig}"`;
    }

    if (typeof styleConfig === 'object') {
      if (!window.ogStyle) {
        ogLogger?.warn('core:form', 'ogStyle no disponible');
        return '';
      }

      const inlineStyle = ogStyle.resolve(styleConfig);
      return inlineStyle ? `style="${inlineStyle}"` : '';
    }

    return '';
  }

  static buildPropsAttr(props) {
    if (!props || typeof props !== 'object') return '';

    const attrs = [];
    const core = ogModule('formCore');

    for (const [key, value] of Object.entries(props)) {
      const attrName = core?.camelToKebab(key) || key;

      if (value === true) {
        attrs.push(attrName);
      } else if (value === false || value === null || value === undefined) {
        continue;
      } else if (typeof value === 'object') {
        attrs.push(`${attrName}='${JSON.stringify(value)}'`);
      } else {
        attrs.push(`${attrName}="${value}"`);
      }
    }

    return attrs.join(' ');
  }

  static renderField(field, path) {
    const core = ogModule('formCore');
    
    if (!core?.hasRoleAccess(field)) return '';

    if (field.type === 'html') {
      const htmlId = path ? `data-field-name="${path}"` : '';
      return `<div class="og-form-html-wrapper" ${htmlId}>${field.content || ''}</div>`;
    }

    const label = core?.t(field.label) || path;
    const labelI18n = field.label?.startsWith('i18n:') ? `data-i18n="${field.label.replace('i18n:', '')}"` : '';
    const isRequired = field.required || (field.validation && field.validation.includes('required'));
    const requiredAsterisk = isRequired ? '<span class="og-form-required">*</span>' : '';

    const transformClasses = this.getTransformClasses(field);

    const classNames = [
      field.className || '',
      ...transformClasses
    ].filter(c => c).join(' ');

    const validationAttrs = this.getValidationAttributes(field);

    const common = `
      name="${path}"
      placeholder="${core?.t(field.placeholder) || ''}"
      ${field.required ? 'required' : ''}
      ${field.min !== undefined ? `min="${field.min}"` : ''}
      ${field.max !== undefined ? `max="${field.max}"` : ''}
      ${field.step !== undefined ? `step="${field.step}"` : ''}
      ${field.rows !== undefined ? `rows="${field.rows}"` : ''}
      ${field.cols !== undefined ? `cols="${field.cols}"` : ''}
      ${classNames ? `class="${classNames}"` : ''}
      ${validationAttrs}
    `.trim();

    const styleAttr = this.buildStyleAttr(field.style);
    const propsAttr = this.buildPropsAttr(field.props);

    switch(field.type) {
      case 'button':
        return this.renderButton(field, label, propsAttr);

      case 'select':
        return this.renderSelect(field, path, label, labelI18n, requiredAsterisk, common, styleAttr, propsAttr);

      case 'textarea':
        return this.renderTextarea(field, label, labelI18n, requiredAsterisk, common, styleAttr, propsAttr);

      case 'checkbox':
        return this.renderCheckbox(field, path, label, labelI18n, requiredAsterisk, styleAttr, propsAttr);

      case 'button_group':
      case 'button_set':
        return this.renderButtonGroup(field, path, label, labelI18n, requiredAsterisk);

      case 'radio':
        return this.renderRadio(field, path, label, labelI18n, requiredAsterisk);

      default:
        const hint = field.hint ? `<small class="og-form-hint">${core?.t(field.hint)}</small>` : '';
        return `
          <div class="og-form-group">
            <label ${labelI18n}>${label}${requiredAsterisk}</label>
            <input type="${field.type}" ${common} ${styleAttr} ${propsAttr}>
            ${hint}
            <span class="og-form-error"></span>
          </div>`;
    }
  }

  static renderButton(field, label, propsAttr) {
    const buttonI18n = field.label?.startsWith('i18n:') ? `data-i18n="${field.label.replace('i18n:', '')}"` : '';

    let btnPropsAttr = propsAttr;
    let extractedType = null;
    if (field.props?.type) {
      extractedType = field.props.type;
      const propsWithoutType = { ...field.props };
      delete propsWithoutType.type;
      btnPropsAttr = this.buildPropsAttr(propsWithoutType);
    }

    let clickHandler = '';

    if (field.action) {
      const escapedAction = field.action.replace(/"/g, '&quot;');
      clickHandler = `actionProxy.handle('${escapedAction}', {}, {button: this, event: event})`;
    } else if (field.onclick) {
      clickHandler = field.onclick;
    } else if (field.type === 'submit') {
      const formId = field.formId || 'form';
      clickHandler = `form.submit('${formId}')`;
    }

    const btnType = extractedType === 'submit' ? 'submit' : 'button';
    const btnClass = `btn ${field.style === 'secondary' ? 'btn-secondary' : 'btn-primary'}`;
    const onclickAttr = clickHandler ? `onclick="${clickHandler}"` : '';

    return `<button type="${btnType}" class="${btnClass}" ${buttonI18n} ${onclickAttr} ${btnPropsAttr}>${label}</button>`;
  }

  static renderSelect(field, path, label, labelI18n, requiredAsterisk, common, styleAttr, propsAttr) {
    const core = ogModule('formCore');
    const selectId = `select-${path.replace(/\./g, '-')}`;
    const hasSource = field.source ? `data-source="${field.source}"` : '';
    const sourceValue = field.sourceValue || 'value';
    const sourceLabel = field.sourceLabel || 'label';
    const sourceData = hasSource ? `data-source-value="${sourceValue}" data-source-label="${sourceLabel}"` : '';

    const staticOptions = field.options?.map(opt => {
      const optI18n = opt.label?.startsWith('i18n:') ? `data-i18n="${opt.label.replace('i18n:', '')}"` : '';
      return `<option value="${opt.value}" ${optI18n}>${core?.t(opt.label)}</option>`;
    }).join('') || '';

    if (field.source) {
      setTimeout(() => {
        const existingSelect = document.getElementById(selectId);

        if (!existingSelect) {
          this.loadSelectFromAPI(selectId, field.source, sourceValue, sourceLabel);
          return;
        }

        const alreadyLoaded = existingSelect.options.length > 1;
        const hasValue = existingSelect.value && existingSelect.value !== '';

        if (alreadyLoaded || hasValue) {
          return;
        }

        this.loadSelectFromAPI(selectId, field.source, sourceValue, sourceLabel);
      }, 10);
    }

    const selectHint = field.hint ? `<small class="og-form-hint">${core?.t(field.hint)}</small>` : '';
    return `
      <div class="og-form-group">
        <label ${labelI18n}>${label}${requiredAsterisk}</label>
        <select id="${selectId}" ${common} ${styleAttr} ${propsAttr} ${hasSource} ${sourceData}>
          ${staticOptions}
        </select>
        ${selectHint}
        <span class="og-form-error"></span>
      </div>`;
  }

  static renderTextarea(field, label, labelI18n, requiredAsterisk, common, styleAttr, propsAttr) {
    const core = ogModule('formCore');
    const textareaHint = field.hint ? `<small class="og-form-hint">${core?.t(field.hint)}</small>` : '';
    return `
      <div class="og-form-group">
        <label ${labelI18n}>${label}${requiredAsterisk}</label>
        <textarea ${common} ${styleAttr} ${propsAttr}></textarea>
        ${textareaHint}
        <span class="og-form-error"></span>
      </div>`;
  }

  static renderCheckbox(field, path, label, labelI18n, requiredAsterisk, styleAttr, propsAttr) {
    const core = ogModule('formCore');
    const checkboxHint = field.hint ? `<small class="og-form-hint">${core?.t(field.hint)}</small>` : '';
    return `
      <div class="og-form-group og-form-checkbox">
        <label ${labelI18n}>
          <input type="checkbox" name="${path}" ${field.required ? 'required' : ''} ${styleAttr} ${propsAttr}>
          ${label}${requiredAsterisk}
        </label>
        ${checkboxHint}
        <span class="og-form-error"></span>
      </div>`;
  }

  static renderButtonGroup(field, path, label, labelI18n, requiredAsterisk) {
    const core = ogModule('formCore');
    const buttonGroupHint = field.hint ? `<small class="og-form-hint">${core?.t(field.hint)}</small>` : '';
    const buttonGroupSize = field.size === 'sm' ? 'btn-sm' : field.size === 'lg' ? 'btn-lg' : '';
    
    const buttonGroupOptions = (field.options || []).map((opt, index) => {
      const optionId = `${path.replace(/\./g, '-')}-${index}`;
      const isChecked = opt.checked || (field.defaultValue && field.defaultValue === opt.value) || (index === 0 && !field.defaultValue);
      
      return `
        <input type="radio" 
               name="${path}" 
               id="${optionId}" 
               value="${opt.value}" 
               class="btn-group-input"
               ${isChecked ? 'checked' : ''}
               ${field.required ? 'required' : ''}>
        <label class="btn btn-group-item ${buttonGroupSize}" data-input-id="${optionId}">
          ${core?.t(opt.label)}
        </label>
      `;
    }).join('');
    
    return `
      <div class="og-form-group">
        <label ${labelI18n}>${label}${requiredAsterisk}</label>
        <div class="btn-group" role="group">
          ${buttonGroupOptions}
        </div>
        ${buttonGroupHint}
        <span class="og-form-error"></span>
      </div>`;
  }

  static renderRadio(field, path, label, labelI18n, requiredAsterisk) {
    const core = ogModule('formCore');
    const radioHint = field.hint ? `<small class="og-form-hint">${core?.t(field.hint)}</small>` : '';
    
    const radioOptions = (field.options || []).map((opt, index) => {
      const optionId = `${path.replace(/\./g, '-')}-${index}`;
      const isChecked = opt.checked || false;
      
      return `
        <div class="og-form-radio-option">
          <input type="radio" 
                 name="${path}" 
                 id="${optionId}" 
                 value="${opt.value}" 
                 ${isChecked ? 'checked' : ''}
                 ${field.required ? 'required' : ''}>
          <label for="${optionId}">${core?.t(opt.label)}</label>
        </div>
      `;
    }).join('');
    
    return `
      <div class="og-form-group">
        <label ${labelI18n}>${label}${requiredAsterisk}</label>
        <div class="og-form-radio-group">
          ${radioOptions}
        </div>
        ${radioHint}
        <span class="og-form-error"></span>
      </div>`;
  }

  static async loadSelectFromAPI(selectId, source, valueField, labelField) {
    const api = ogModule('api');
    const core = ogModule('formCore');
    const selectEl = document.getElementById(selectId);
    
    if (!selectEl) {
      ogLogger?.error('core:form', `Select no encontrado: ${selectId}`);
      return;
    }

    const firstOption = selectEl.querySelector('option[value=""]');
    const placeholder = firstOption ? firstOption.cloneNode(true) : null;

    const cacheKey = `${source}|${valueField}|${labelField}`;
    if (core?.selectCache?.has(cacheKey)) {
      const cachedData = core.selectCache.get(cacheKey);

      this.populateSelect(selectEl, cachedData, valueField, labelField, placeholder);

      selectEl.dispatchEvent(new CustomEvent('select:afterLoad', {
        bubbles: true,
        detail: { selectId, source, itemCount: cachedData.length, fromCache: true }
      }));
      return;
    }

    try {
      selectEl.disabled = true;

      const data = await api.get(source);
      const items = Array.isArray(data) ? data : (data.data || []);

      core?.selectCache?.set(cacheKey, items);

      this.populateSelect(selectEl, items, valueField, labelField, placeholder);

      selectEl.disabled = false;

      selectEl.dispatchEvent(new CustomEvent('select:afterLoad', {
        bubbles: true,
        detail: { selectId, source, itemCount: items.length, fromCache: false }
      }));

    } catch (error) {
      ogLogger?.error('core:form', `Error cargando select ${selectId} desde ${source}:`, error);
      selectEl.disabled = false;
    }
  }

  static populateSelect(selectEl, items, valueField, labelField, placeholder = null) {
    const currentValue = selectEl.value;

    selectEl.innerHTML = '';

    if (placeholder) {
      selectEl.appendChild(placeholder);
    }

    items.forEach(item => {
      const option = document.createElement('option');
      option.value = item[valueField];
      option.textContent = item[labelField];
      selectEl.appendChild(option);
    });

    if (currentValue) {
      selectEl.value = currentValue;
    }
  }
}

// Global
window.ogFormInputs = ogFormInputs;

// Registrar en ogFramework
if (typeof window.ogFramework !== 'undefined') {
  window.ogFramework.core.formInputs = ogFormInputs;
}