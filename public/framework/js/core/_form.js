/**
 * ============================================================================
 * FORM.JS - Orquestador Principal del Sistema de Formularios
 * ============================================================================
 * 
 * ARCHIVO PRINCIPAL - SEPARADO EN 4 MÓDULOS
 * Este archivo define la clase base 'form' que luego es extendida por otros
 * módulos que le agregan métodos adicionales. Todos trabajan sobre window.form.
 * 
 * ARQUITECTURA MODULAR:
 * 1. form.js (ESTE ARCHIVO) - Clase base y renderizado (~650 líneas)
 *    - Definición de la clase form
 *    - Carga y registro de schemas
 *    - Renderizado de campos (renderField, renderFields)
 *    - Manejo de repeatables y grupos
 *    - Configuración de selects dinámicos
 *    - Normalización de tipos de campo
 * 
 * 2. formState.js - Extiende 'form' con gestión de estado (~300 líneas)
 *    Agrega métodos:
 *    - form.getData(formId)
 *    - form.getFieldValue(formId, fieldName)
 *    - form.setFieldValue(formId, fieldName, value)
 *    - form.fill(formId, data, container)
 *    - form.applyDefaultValues(formId, container)
 *    - form.getArrayFieldValue(formId, fieldName)
 *    - form.fillArrayField(formId, fieldName, items, container)
 * 
 * 3. formValidation.js - Extiende 'form' con validación (~250 líneas)
 *    Agrega métodos:
 *    - form.validate(formId)
 *    - form.validateRule(value, rule, field)
 *    - form.isFieldVisible(formId, field)
 *    - form.isFieldReadonly(formId, field)
 *    - form.isFieldRequired(formId, field)
 *    - form.setError(formId, fieldName, errorMessage)
 *    - form.clearError(formId, fieldName)
 *    - form.clearAllErrors(formId)
 * 
 * 4. formEvents.js - Extiende 'form' con eventos (~400 líneas)
 *    Agrega métodos:
 *    - form.bindEventsOnce()
 *    - form.bindTransforms(formId, container)
 *    - form.initRepeatables(formId, container)
 *    - form._handleFieldChange() (privado)
 *    - form._executeCallback() (privado)
 *    - form._propagateChanges() (privado)
 * 
 * ORDEN DE CARGA EN main.js:
 * 1. 'js/core/form.js'          (ESTE - define la clase base)
 * 2. 'js/core/formState.js'      (extiende con métodos de estado)
 * 3. 'js/core/formValidation.js' (extiende con métodos de validación)
 * 4. 'js/core/formEvents.js'     (extiende con métodos de eventos)
 * 
 * API PÚBLICA:
 * Todos los métodos están disponibles en window.form
 * Ejemplo: form.load(), form.getData(), form.validate(), form.fill()
 * 
 * DEPENDENCIAS EXTERNAS:
 * - conditions.js (condiciones dinámicas)
 * - validator.js (reglas de validación core)
 * - i18n.js (traducciones)
 * - cache.js (caché de schemas)
 * - api.js (carga de datos de API)
 * 
 * NOTA PARA IA:
 * Este archivo fue separado del form.js original (1920 líneas) en 4 módulos.
 * Todos extienden la misma clase 'form', NO crean clases separadas.
 * Si necesitas analizar funcionalidad específica, solicita el módulo correspondiente:
 * - Para datos/estado → formState.js
 * - Para validación → formValidation.js
 * - Para eventos → formEvents.js
 * - Para renderizado → form.js (este archivo)
 * ============================================================================
 */

class form {
  static schemas = new Map();
  static registeredEvents = new Set();
  static selectCache = new Map();

  // Mapeo de tipos genéricos
  static typeAliases = {
    'input': 'text',
    'textarea': 'textarea',
    'checkbox': 'checkbox',
    'switch': 'checkbox',
    'select': 'select',
    'picker': 'select',
    'textinput': 'text',
    'TextInput': 'text',
    'Switch': 'checkbox',
    'Picker': 'select',
    'FlatList': 'repeatable',
    'flatlist': 'repeatable'
  };

  // Transforms disponibles
  static transforms = {
    lowercase: (value) => value.toLowerCase(),
    uppercase: (value) => value.toUpperCase(),
    trim: (value) => value.replace(/\s+/g, ''),
    alphanumeric: (value) => value.replace(/[^a-zA-Z0-9]/g, ''),
    numeric: (value) => value.replace(/[^0-9]/g, ''),
    decimal: (value) => value.replace(/,/g, '.').replace(/[^0-9.]/g, '').replace(/(\..*)\./g, '$1'),
    slug: (value) => value.toLowerCase().replace(/\s+/g, '-').replace(/[^a-z0-9-]/g, '')
  };

  // Mapeo de validación a transforms
  static validationToTransformMap = {
    'numeric': 'numeric',
    'decimal': 'decimal',
    'alpha_num': 'alphanumeric'
  };

  // Normalizar tipo de campo
  static normalizeFieldType(field) {
    if (!field || !field.type) return field;

    const type = field.type.toLowerCase();

    if (this.typeAliases[type]) {
      const normalizedType = this.typeAliases[type];
      const normalized = { ...field, type: normalizedType };

      if ((type === 'textinput') && field.multiline === true) {
        normalized.type = 'textarea';
        normalized.rows = field.numberOfLines || field.rows || 4;
      }

      if (type === 'input' && field.inputType) {
        normalized.type = field.inputType;
      }

      return normalized;
    }

    return field;
  }

  static t(text) {
    if (!text || typeof text !== 'string') return text || '';
    if (!text.startsWith('i18n:')) return text;
    const key = text.replace('i18n:', '');
    return window.i18n?.t(key) || key;
  }

  static async load(formName, container = null, data = null, isCore = null, afterRender = null) {
    const cacheKey = `form_${formName.replace(/\//g, '_')}`;
    let schema = cache.get(cacheKey);

    if (!schema) {
      let url;

      if (formName.includes('|')) {
        const [extensionName, restPath] = formName.split('|');
        const basePath = window.appConfig?.routes?.extensionViews?.replace('{extensionName}', extensionName) || `extensions/${extensionName}/views`;
        url = `${window.BASE_URL}${basePath}/${restPath}.json`;
      }
      else if (isCore === true) {
        const basePath = window.appConfig?.routes?.coreViews || 'js/views';
        url = `${window.BASE_URL}${basePath}/${formName}.json`;
      }
      else if (isCore === false) {
        const parts = formName.split('/');
        const extensionName = parts[0];
        const restPath = parts.slice(1).join('/');
        const basePath = window.appConfig?.routes?.extensionViews?.replace('{extensionName}', extensionName) || `extensions/${extensionName}/views`;
        url = `${window.BASE_URL}${basePath}/forms/${restPath}.json`;
      }
      else if (formName.startsWith('core:')) {
        formName = formName.replace('core:', '');
        const basePath = window.appConfig?.routes?.coreViews || 'js/views';
        url = `${window.BASE_URL}${basePath}/${formName}.json`;
      }
      else if (formName.includes('/')) {
        const parts = formName.split('/');
        const firstPart = parts[0];

        const isExtension = window.view?.loadedExtensions?.[firstPart] ||
                        window.hook?.isExtensionEnabled?.(firstPart);

        if (isExtension) {
          const extensionName = parts[0];
          const restPath = parts.slice(1).join('/');
          const basePath = window.appConfig?.routes?.extensionViews?.replace('{extensionName}', extensionName) || `extensions/${extensionName}/views`;
          url = `${window.BASE_URL}${basePath}/forms/${restPath}.json`;
        } else {
          const basePath = window.appConfig?.routes?.coreViews || 'js/views';
          url = `${window.BASE_URL}${basePath}/${formName}.json`;
        }
      } else {
        const basePath = window.appConfig?.routes?.coreViews || 'js/views';
        url = `${window.BASE_URL}${basePath}/${formName}.json`;
      }

      const cacheBuster = `?t=${window.VERSION}`;
      const response = await fetch(url + cacheBuster);

      if (!response.ok) {
        throw new Error(`Form not found: ${formName} (${url})`);
      }

      schema = await response.json();

      if (window.appConfig?.cache?.forms) {
        cache.set(cacheKey, schema);
      }
    }

    const instanceId = `${schema.id}-${window.VERSION.replace(/\./g, '-')}`;
    const instanceSchema = JSON.parse(JSON.stringify(schema));
    instanceSchema.id = instanceId;

    // Asignar order automático
    if (instanceSchema.fields) {
      instanceSchema.fields = instanceSchema.fields.map((field, index) => {
        if (!field.order) field.order = (index + 1) * 5;
        return field;
      });
    }

    // Procesar hooks
    if (schema.id && window.hook) {
      const hookName = `hook_${schema.id.replace(/-/g, '_')}`;
      const allHooks = hook.execute(hookName, []);
      const formHooks = allHooks.filter(h => h.context === 'form');

      if (formHooks.length > 0) {
        if (!instanceSchema.fields) instanceSchema.fields = [];
        instanceSchema.fields.push(...formHooks);
        instanceSchema.fields.sort((a, b) => (a.order || 999) - (b.order || 999));
      }
    }

    this.schemas.set(instanceId, instanceSchema);

    const html = this.render(instanceSchema);
    const target = container || document.getElementById('content');
    target.innerHTML = html;

    if (data) form.fill(instanceId, data, target);

    form.bindEventsOnce();

    setTimeout(() => {
      const formEl = target.querySelector(`#${instanceId}`) || document.getElementById(instanceId);
      if (formEl) {
        form.initRepeatables(instanceId, target);
        form.bindTransforms(instanceId, target);
        form.applyDefaultValues(instanceId, target);
        
        if (window.conditions) {
          conditions.init(instanceId);
        }

        if (typeof afterRender === 'function') {
          try {
            afterRender(instanceId, formEl);
          } catch (error) {
            logger.error('core:form', 'Error en afterRender:', error);
          }
        }
      }
    }, 10);

    return instanceId;
  }

  static render(schema) {
    const realId = schema.id.split('-')[0];
    return `
      <div class="form-container">
        ${schema.title ? `<h2>${this.t(schema.title)}</h2>` : ''}
        ${schema.description ? `<p class="form-desc">${this.t(schema.description)}</p>` : ''}

        <form id="${schema.id}" data-form-id="${schema.id}" data-real-id="${realId}" method="post">
          ${schema.toolbar ? `<div class="form-toolbar">${this.renderFields(schema.toolbar)}</div>` : ''}
          ${schema.fields ? this.renderFields(schema.fields) : ''}
          ${schema.statusbar ? `<div class="form-statusbar">${this.renderFields(schema.statusbar)}</div>` : ''}
        </form>
      </div>
    `;
  }

  static renderFields(fields, path = '') {
    return fields.map((field) => {
      const normalizedField = this.normalizeFieldType(field);

      if (!this.hasRoleAccess(normalizedField)) return '';

      const fieldPath = path ? `${path}.${normalizedField.name}` : normalizedField.name;

      if (normalizedField.type === 'repeatable') {
        return this.renderRepeatable(normalizedField, fieldPath);
      }

      if (normalizedField.type === 'group') {
        return this.renderGroup(normalizedField, path);
      }

      if (normalizedField.type === 'grouper') {
        return this.renderGrouper(normalizedField, path);
      }

      return this.renderField(normalizedField, fieldPath);
    }).join('');
  }

  static renderRepeatable(field, path) {
    const addText = this.t(field.addText) || window.i18n?.t('core.form.repeatable.add') || 'Agregar';
    const buttonPosition = field.buttonPosition || 'top';

    // Si tiene condiciones, iniciar oculto
    const hasConditions = field.condition && Array.isArray(field.condition) && field.condition.length > 0;
    const initialStyle = hasConditions ? ' style="display: none;"' : '';

    const addButton = `
      <button type="button" class="btn btn-primary btn-sm repeatable-add" data-path="${path}">
        ${addText}
      </button>
    `;

    const templates = {
      middle: `
        <div class="form-repeatable" data-field-path="${path}">
          <div class="repeatable-header"><h4>${this.t(field.label)}</h4></div>
          <div class="repeatable-add-container" style="margin: 0.5rem 0;">${addButton}</div>
          <div class="repeatable-items" data-path="${path}"></div>
        </div>
      `,
      bottom: `
        <div class="form-repeatable" data-field-path="${path}">
          <div class="repeatable-header"><h4>${this.t(field.label)}</h4></div>
          <div class="repeatable-items" data-path="${path}"></div>
          <div class="repeatable-add-container" style="margin: 0.5rem 0; text-align: center;">${addButton}</div>
        </div>
      `,
      top: `
        <div class="form-repeatable" data-field-path="${path}">
          <div class="repeatable-header">
            <h4>${this.t(field.label)}</h4>
            ${addButton}
          </div>
          <div class="repeatable-items" data-path="${path}"></div>
        </div>
      `
    };

    let repeatableHtml = templates[buttonPosition] || templates.top;

    // Si el repeatable tiene condiciones, envolverlo para que conditions.js lo encuentre
    // E INICIAR OCULTO
    if (hasConditions) {
      const schemaPath = path.replace(/\[\d+\]/g, '');
      repeatableHtml = `<div class="form-html-wrapper" data-field-name="${schemaPath}"${initialStyle}>${repeatableHtml}</div>`;
    }

    return repeatableHtml;
  }

  static renderGroup(field, basePath) {
    const columns = field.columns || 2;
    const gap = field.gap || 'normal';
    const groupClass = `form-group-cols form-group-cols-${columns} form-group-gap-${gap}`;

    return `
      <div class="${groupClass}">
        ${field.fields ? field.fields.map(subField => {
          const normalizedSubField = this.normalizeFieldType(subField);
          if (!this.hasRoleAccess(normalizedSubField)) return '';
          const fieldPath = basePath ? `${basePath}.${normalizedSubField.name}` : normalizedSubField.name;
          return this.renderField(normalizedSubField, fieldPath);
        }).join('') : ''}
      </div>
    `;
  }

  static renderGrouper(field, parentPath) {
    const mode = field.mode || 'linear';
    const grouperId = `grouper-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;

    let grouperHtml = mode === 'linear' 
      ? this.renderGrouperLinear(field, grouperId, parentPath)
      : this.renderGrouperTabs(field, grouperId, parentPath);

    // Si el grouper tiene name y condiciones, envolverlo para que conditions.js lo encuentre
    // Usar form-html-wrapper porque conditions.js busca específicamente esa clase
    if (field.name) {
      grouperHtml = `<div class="form-html-wrapper" data-field-name="${field.name}">${grouperHtml}</div>`;
    }

    setTimeout(() => this.bindGrouperEvents(grouperId, mode), 10);

    return grouperHtml;
  }

  static renderGrouperLinear(field, grouperId, parentPath) {
    const collapsible = field.collapsible !== false;
    const openFirst = field.openFirst !== false;

    let html = `<div class="grouper grouper-linear" id="${grouperId}">`;

    field.groups.forEach((group, index) => {
      const isOpen = openFirst && index === 0;
      const contentId = `${grouperId}-content-${index}`;
      const processedTitle = this.processI18nTitle(group.title) || `Grupo ${index + 1}`;

      html += `
        <div class="grouper-section ${isOpen ? 'open' : ''} ${!collapsible ? 'non-collapsible' : ''}" data-group-index="${index}">
          <div class="grouper-header ${collapsible ? 'collapsible' : 'non-collapsible'}"
               ${collapsible ? `data-toggle="${contentId}"` : ''}>
            <h3 class="grouper-title">${processedTitle}</h3>
            ${collapsible ? '<span class="grouper-toggle">▼</span>' : ''}
          </div>
          <div class="grouper-content" id="${contentId}" ${!isOpen && collapsible ? 'style="display:none"' : ''}>
      `;

      if (group.fields && Array.isArray(group.fields)) {
        html += this.renderFields(group.fields, parentPath);
      }

      html += `</div></div>`;
    });

    html += `</div>`;
    return html;
  }

  static renderGrouperTabs(field, grouperId, parentPath) {
    const activeIndex = field.activeIndex || 0;

    let html = `<div class="grouper grouper-tabs" id="${grouperId}">`;

    html += `<div class="grouper-tabs-header">`;
    field.groups.forEach((group, index) => {
      const isActive = index === activeIndex;
      const processedTitle = this.processI18nTitle(group.title) || `Tab ${index + 1}`;
      html += `
        <button type="button" class="grouper-tab-btn ${isActive ? 'active' : ''}" data-tab-index="${index}">
          ${processedTitle}
        </button>
      `;
    });
    html += `</div>`;

    html += `<div class="grouper-tabs-content">`;
    field.groups.forEach((group, index) => {
      const isActive = index === activeIndex;
      html += `<div class="grouper-tab-panel ${isActive ? 'active' : ''}" data-panel-index="${index}">`;
      if (group.fields && Array.isArray(group.fields)) {
        html += this.renderFields(group.fields, parentPath);
      }
      html += `</div>`;
    });
    html += `</div></div>`;

    return html;
  }

  static bindGrouperEvents(grouperId, mode) {
    const container = document.getElementById(grouperId);
    if (!container) return;

    if (mode === 'linear') {
      container.querySelectorAll(':scope > .grouper-section > .grouper-header.collapsible').forEach(header => {
        header.addEventListener('click', (e) => {
          const targetId = header.dataset.toggle;
          const content = document.getElementById(targetId);
          const section = header.closest('.grouper-section');

          if (!content) return;

          const isOpen = section.classList.contains('open');

          if (isOpen) {
            section.classList.remove('open');
            content.style.display = 'none';
          } else {
            section.classList.add('open');
            content.style.display = 'block';
            
            if (window.conditions) {
              const formId = container.closest('form')?.id;
              if (formId) {
                setTimeout(() => conditions.evaluate(formId), 50);
              }
            }
          }
        });
      });
    } else if (mode === 'tabs') {
      container.querySelectorAll('.grouper-tab-btn').forEach(btn => {
        btn.addEventListener('click', () => {
          const index = parseInt(btn.dataset.tabIndex);

          container.querySelectorAll('.grouper-tab-btn').forEach(b => b.classList.remove('active'));
          container.querySelectorAll('.grouper-tab-panel').forEach(p => p.classList.remove('active'));

          btn.classList.add('active');
          const panel = container.querySelector(`.grouper-tab-panel[data-panel-index="${index}"]`);
          if (panel) {
            panel.classList.add('active');
            
            if (window.conditions) {
              const formId = container.closest('form')?.id;
              if (formId) {
                setTimeout(() => conditions.evaluate(formId), 50);
              }
            }
          }
        });
      });
    }
  }

  static getTransformClasses(field) {
    const transformClasses = [];

    if (field.transform) {
      const transforms = Array.isArray(field.transform) ? field.transform : [field.transform];
      transforms.forEach(t => transformClasses.push(`form-transform-${t}`));
    }

    if (field.validation) {
      Object.keys(this.validationToTransformMap).forEach(validationRule => {
        if (field.validation.includes(validationRule)) {
          const transformName = this.validationToTransformMap[validationRule];
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

  static renderField(field, path) {
    if (!this.hasRoleAccess(field)) return '';

    if (field.type === 'html') {
      const htmlId = path ? `data-field-name="${path}"` : '';
      return `<div class="form-html-wrapper" ${htmlId}>${field.content || ''}</div>`;
    }

    const label = this.t(field.label) || path;
    const labelI18n = field.label?.startsWith('i18n:') ? `data-i18n="${field.label.replace('i18n:', '')}"` : '';
    const isRequired = field.required || (field.validation && field.validation.includes('required'));
    const requiredAsterisk = isRequired ? '<span class="form-required">*</span>' : '';

    const transformClasses = this.getTransformClasses(field);

    const classNames = [
      field.className || '',
      ...transformClasses
    ].filter(c => c).join(' ');

    const validationAttrs = this.getValidationAttributes(field);

    const common = `
      name="${path}"
      placeholder="${this.t(field.placeholder) || ''}"
      ${field.required ? 'required' : ''}
      ${field.min !== undefined ? `min="${field.min}"` : ''}
      ${field.max !== undefined ? `max="${field.max}"` : ''}
      ${field.step !== undefined ? `step="${field.step}"` : ''}
      ${classNames ? `class="${classNames}"` : ''}
      ${validationAttrs}
    `.trim();

    const styleAttr = this.buildStyleAttr(field.style);
    const propsAttr = this.buildPropsAttr(field.props);

    switch(field.type) {
      case 'button':
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

      case 'select':
        const selectId = `select-${path.replace(/\./g, '-')}`;
        const hasSource = field.source ? `data-source="${field.source}"` : '';
        const sourceValue = field.sourceValue || 'value';
        const sourceLabel = field.sourceLabel || 'label';
        const sourceData = hasSource ? `data-source-value="${sourceValue}" data-source-label="${sourceLabel}"` : '';
        
        const staticOptions = field.options?.map(opt => {
          const optI18n = opt.label?.startsWith('i18n:') ? `data-i18n="${opt.label.replace('i18n:', '')}"` : '';
          return `<option value="${opt.value}" ${optI18n}>${this.t(opt.label)}</option>`;
        }).join('') || '';

        if (field.source) {
          setTimeout(() => this.loadSelectFromAPI(selectId, field.source, sourceValue, sourceLabel), 10);
        }

        const selectHint = field.hint ? `<small class="form-hint">${this.t(field.hint)}</small>` : '';
        return `
          <div class="form-group">
            <label ${labelI18n}>${label}${requiredAsterisk}</label>
            <select id="${selectId}" ${common} ${styleAttr} ${propsAttr} ${hasSource} ${sourceData}>
              ${staticOptions}
            </select>
            ${selectHint}
            <span class="form-error"></span>
          </div>`;

      case 'textarea':
        const textareaHint = field.hint ? `<small class="form-hint">${this.t(field.hint)}</small>` : '';
        return `
          <div class="form-group">
            <label ${labelI18n}>${label}${requiredAsterisk}</label>
            <textarea ${common} ${styleAttr} ${propsAttr}></textarea>
            ${textareaHint}
            <span class="form-error"></span>
          </div>`;

      case 'checkbox':
        const checkboxHint = field.hint ? `<small class="form-hint">${this.t(field.hint)}</small>` : '';
        return `
          <div class="form-group form-checkbox">
            <label ${labelI18n}>
              <input type="checkbox" name="${path}" ${field.required ? 'required' : ''} ${styleAttr} ${propsAttr}>
              ${label}${requiredAsterisk}
            </label>
            ${checkboxHint}
            <span class="form-error"></span>
          </div>`;

      default:
        const hint = field.hint ? `<small class="form-hint">${this.t(field.hint)}</small>` : '';
        return `
          <div class="form-group">
            <label ${labelI18n}>${label}${requiredAsterisk}</label>
            <input type="${field.type}" ${common} ${styleAttr} ${propsAttr}>
            ${hint}
            <span class="form-error"></span>
          </div>`;
    }
  }

  static reset(formId) {
    const formEl = document.getElementById(formId);
    if (formEl) {
      formEl.reset();
      form.clearAllErrors(formId);
    }
  }

  static hasRoleAccess(field) {
    if (!field.role) return true;
    const userRole = window.auth?.user?.role;
    if (!userRole) return false;
    return userRole === field.role;
  }

  static buildStyleAttr(styleConfig) {
    if (!styleConfig) return '';

    if (typeof styleConfig === 'string') {
      return `style="${styleConfig}"`;
    }

    if (typeof styleConfig === 'object') {
      if (!window.styleHandler) {
        logger.warn('core:form', 'styleHandler no disponible');
        return '';
      }

      const inlineStyle = styleHandler.resolve(styleConfig);
      return inlineStyle ? `style="${inlineStyle}"` : '';
    }

    return '';
  }

  static buildPropsAttr(props) {
    if (!props || typeof props !== 'object') return '';

    const attrs = [];

    for (const [key, value] of Object.entries(props)) {
      const attrName = this.camelToKebab(key);

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

  static camelToKebab(str) {
    return str.replace(/([a-z0-9])([A-Z])/g, '$1-$2').toLowerCase();
  }

  static async loadSelectFromAPI(selectId, source, valueField, labelField) {
    const selectEl = document.getElementById(selectId);
    if (!selectEl) {
      logger.error('core:form', `Select no encontrado: ${selectId}`);
      return;
    }

    const firstOption = selectEl.querySelector('option[value=""]');
    const placeholder = firstOption ? firstOption.cloneNode(true) : null;

    const cacheKey = `${source}|${valueField}|${labelField}`;
    if (this.selectCache.has(cacheKey)) {
      const cachedData = this.selectCache.get(cacheKey);
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

      this.selectCache.set(cacheKey, items);

      this.populateSelect(selectEl, items, valueField, labelField, placeholder);

      selectEl.disabled = false;
      
      selectEl.dispatchEvent(new CustomEvent('select:afterLoad', {
        bubbles: true,
        detail: { selectId, source, itemCount: items.length, fromCache: false }
      }));
      
    } catch (error) {
      logger.error('core:form', `Error cargando select ${selectId} desde ${source}:`, error);
      selectEl.disabled = false;
    }
  }

  static populateSelect(selectEl, items, valueField, labelField, placeholder = null) {
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
  }

  static processI18nTitle(title) {
    return window.i18n ? i18n.processString(title) : title;
  }

  // Métodos de repeatables
  static addRepeatableItem(path, buttonElement = null) {
    let container;

    if (buttonElement) {
      const formEl = buttonElement.closest('form');
      if (formEl) {
        container = formEl.querySelector(`.repeatable-items[data-path="${path}"]`);
      } else {
        const parentContainer = buttonElement.closest('.repeatable-items');
        if (parentContainer) {
          container = parentContainer.querySelector(`.repeatable-items[data-path="${path}"]`);
        }
      }
    }

    if (!container) {
      container = document.querySelector(`.repeatable-items[data-path="${path}"]`);
    }

    if (!container) {
      logger.error('core:form', `Container no encontrado para: "${path}"`);
      return;
    }

    logger.info('core:form', `Container encontrado. dataset.fieldSchema length: ${container.dataset.fieldSchema?.length || 0}`);

    const fieldSchema = JSON.parse(container.dataset.fieldSchema || '[]');
    
    logger.info('core:form', `fieldSchema parseado. Tiene ${fieldSchema.length} campos`);
    
    const itemCount = parseInt(container.dataset.itemCount || '0');
    const newIndex = itemCount;

    const columns = container.dataset.columns ? parseInt(container.dataset.columns) : null;
    const gap = container.dataset.gap || 'normal';

    const itemPath = `${path}[${newIndex}]`;

    const itemFields = fieldSchema.map(field => {
      const fieldPath = `${itemPath}.${field.name}`;

      logger.info('core:form', `Renderizando campo en repeatable: ${field.name}, type: ${field.type}, path: ${fieldPath}`);

      if (field.type === 'repeatable') {
        return this.renderRepeatable(field, fieldPath);
      }

      if (field.type === 'group') {
        return this.renderGroup(field, itemPath);
      }

      if (field.type === 'grouper') {
        return this.renderGrouper(field, itemPath);
      }

      const rendered = this.renderField(field, fieldPath);
      logger.info('core:form', `Campo renderizado, HTML length: ${rendered.length}`);
      return rendered;
    }).join('');

    let contentHtml;
    if (columns) {
      const groupClass = `form-group-cols form-group-cols-${columns} form-group-gap-${gap}`;
      contentHtml = `<div class="${groupClass}">${itemFields}</div>`;
    } else {
      contentHtml = itemFields;
    }

    const itemHtml = `
      <div class="repeatable-item" data-index="${newIndex}">
        <div class="repeatable-content">
          ${contentHtml}
        </div>
        <div class="repeatable-remove">
          <button type="button" class="btn btn-sm btn-danger repeatable-remove">Eliminar</button>
        </div>
      </div>
    `;

    container.insertAdjacentHTML('beforeend', itemHtml);
    container.dataset.itemCount = (newIndex + 1).toString();

    logger.info('core:form', `Item agregado. HTML insertado length: ${itemHtml.length}, itemFields length: ${itemFields.length}`);

    const addedItem = container.lastElementChild;
    if (addedItem) {
      fieldSchema.forEach(field => {
        if (field.defaultValue !== undefined && field.defaultValue !== null) {
          const fieldPath = `${itemPath}.${field.name}`;
          const fieldEl = addedItem.querySelector(`[name="${fieldPath}"]`);
          
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

    const formId = container.closest('form')?.id;
    if (formId) {
      // Pausar evaluaciones de condiciones mientras agregamos el item
      if (window.conditions) {
        conditions.pauseEvaluations();
      }

      setTimeout(() => {
        if (addedItem && addedItem.classList.contains('repeatable-item')) {
          const nestedRepeatables = this.findNestedRepeatables(fieldSchema, itemPath);

          if (nestedRepeatables.length > 0) {
            nestedRepeatables.forEach(({ field, path: nestedPath }) => {
              const nestedContainer = addedItem.querySelector(`.repeatable-items[data-path="${nestedPath}"]`);

              if (nestedContainer) {
                this.initRepeatableContainer(nestedContainer, field, nestedPath);
              }
            });
          }
        }

        form.bindTransforms(formId);

        if (window.conditions) {
          // Reanudar y re-evaluar condiciones
          conditions.resumeEvaluations(formId);
        }
      }, 20);
    }
  }

  static initRepeatableContainer(container, field, path) {
    logger.info('core:form', `Inicializando repeatable container: ${path}`);
    logger.info('core:form', `Field.fields:`, field.fields);
    
    container.dataset.fieldSchema = JSON.stringify(field.fields);
    container.dataset.itemCount = '0';

    if (field.columns) {
      container.dataset.columns = field.columns;
    }
    if (field.gap) {
      container.dataset.gap = field.gap;
    }

    const initialItems = parseInt(field.initialItems) || 0;
    if (initialItems > 0) {
      for (let i = 0; i < initialItems; i++) {
        this.addRepeatableItem(path);
      }
    }
  }

  static findNestedRepeatables(fields, basePath = '') {
    const repeatables = [];

    fields?.forEach(field => {
      if (field.type === 'repeatable') {
        const fieldPath = `${basePath}.${field.name}`;
        repeatables.push({ field, path: fieldPath });
      }
      else if (field.type === 'group' && field.fields) {
        repeatables.push(...this.findNestedRepeatables(field.fields, basePath));
      }
    });

    return repeatables;
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

  static buildArrayField(field, path, data = {}) {
    // Este método se usa en fillArrayField de formState
    // Simplemente renderiza un item de repeatable con datos
    const itemFields = field.fields.map(f => {
      const fieldPath = `${path}.${f.name}`;
      return this.renderField(f, fieldPath);
    }).join('');

    return `
      <div class="repeatable-item">
        <div class="repeatable-content">
          ${itemFields}
        </div>
        <div class="repeatable-remove">
          <button type="button" class="btn btn-sm btn-danger repeatable-remove">Eliminar</button>
        </div>
      </div>
    `;
  }

  static reset(formId) {
    const formEl = document.getElementById(formId);
    if (formEl) {
      formEl.reset();
      form.clearAllErrors(formId);
    }
  }
}

window.form = form;