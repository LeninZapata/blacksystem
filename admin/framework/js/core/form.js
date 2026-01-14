class ogForm {
  static schemas = new Map();
  static registeredEvents = new Set();
  static selectCache = new Map(); // ✅ Cache para selects con source



  static getConfig() {
    return window.ogFramework?.activeConfig || window.appConfig || {};
  }

  // Mapeo de tipos genéricos (Web, Genérico, React Native)
  static typeAliases = {
    // Nivel 2: Genérico → Web
    'input': 'text',
    'textarea': 'textarea',
    'checkbox': 'checkbox',
    'switch': 'checkbox',
    'select': 'select',
    'picker': 'select',

    // Nivel 3: React Native → Web
    'textinput': 'text',
    'TextInput': 'text',
    'Switch': 'checkbox',
    'Picker': 'select',
    'FlatList': 'repeatable',
    'flatlist': 'repeatable'
  };

  // ✅ Transforms disponibles (única fuente de verdad)
  static transforms = {
    lowercase: (value) => value.toLowerCase(),
    uppercase: (value) => value.toUpperCase(),
    trim: (value) => value.replace(/\s+/g, ''),
    alphanumeric: (value) => value.replace(/[^a-zA-Z0-9]/g, ''),
    numeric: (value) => value.replace(/[^0-9]/g, ''),
    decimal: (value) => value.replace(/,/g, '.').replace(/[^0-9.]/g, '').replace(/(\..*)\./g, '$1'),
    slug: (value) => value.toLowerCase().replace(/\s+/g, '-').replace(/[^a-z0-9-]/g, '')
  };

  // ✅ Mapeo de reglas de validación a transforms automáticos
  static validationToTransformMap = {
    'numeric': 'numeric',           // Solo números
    'decimal': 'decimal',           // Números con decimales
    'alpha_num': 'alphanumeric',    // Letras y números
    // Agregar más según necesidad:
    // 'url': 'lowercase',
    // 'slug': 'slug',
  };

  // Normalizar tipo de campo (soporta 3 niveles)
  static normalizeFieldType(field) {
    if (!field || !field.type) return field;

    const type = field.type.toLowerCase();

    // Si el tipo tiene un alias, usarlo
    if (this.typeAliases[type]) {
      const normalizedType = this.typeAliases[type];

      const normalized = { ...field, type: normalizedType };

      // Casos especiales basados en propiedades adicionales
      // TextInput con multiline → textarea
      if ((type === 'textinput') && field.multiline === true) {
        normalized.type = 'textarea';
        normalized.rows = field.numberOfLines || field.rows || 4;
      }

      // Input con inputType → usar inputType específico
      if (type === 'input' && field.inputType) {
        normalized.type = field.inputType;
      }

      return normalized;
    }

    // Si no tiene alias, devolver sin cambios
    return field;
  }

  static t(text) {
    const i18n = ogModule('i18n');
    
    if (!text || typeof text !== 'string') return text || '';
    if (!text.startsWith('i18n:')) return text;
    const key = text.replace('i18n:', '');
    return i18n?.t(key) || key;
  }

  static async load(formName, container = null, data = null, isCore = null, afterRender = null) {
    const cache = ogModule('cache');
    const hook = ogModule('hook');
    const conditions = ogModule('conditions');
    const config = this.getConfig();
    const cacheKey = `form_${formName.replace(/\//g, '_')}`;
    let schema = cache?.get(cacheKey);

    if (!schema) {
      let url;

      // Manejar notación extension|path (ej: ejemplos|forms/formularios/form-inputs-normales)
      if (formName.includes('|')) {
        const [extensionName, restPath] = formName.split('|');
        const extensionsBase = config.extensionsPath || `${config.baseUrl}extensions/`;
        url = `${extensionsBase}${extensionName}/views/${restPath}.json`;  // ← Agregado /views/
      }
      else if (isCore === true) {
        const basePath = config.routes?.coreViews || 'js/views';
        url = `${config.baseUrl || "/"}${basePath}/${formName}.json`;
      }
      else if (isCore === false) {
        const parts = formName.split('/');
        const extensionName = parts[0];
        const restPath = parts.slice(1).join('/');
        const extensionsBase = config.extensionsPath || `${config.baseUrl}extensions/`;
        url = `${extensionsBase}${extensionName}/views/forms/${restPath}.json`;  // ← Agregado /views/
      }
      else if (formName.startsWith('core:')) {
        formName = formName.replace('core:', '');
        const basePath = config.routes?.coreViews || 'js/views';
        url = `${config.baseUrl || "/"}${basePath}/${formName}.json`;
      }
      else if (formName.startsWith('middle:')) {
        formName = formName.replace('middle:', '');
        url = `${config.baseUrl || "/"}middle/views/${formName}.json`;
      }
      else if (formName.includes('/')) {
        const parts = formName.split('/');
        const firstPart = parts[0];

        const isExtension = window.view?.loadedExtensions?.[firstPart] ||
                        hook?.isExtensionEnabled?.(firstPart);

        if (isExtension) {
          const extensionName = parts[0];
          const restPath = parts.slice(1).join('/');
          const extensionsBase = config.extensionsPath || `${config.baseUrl}extensions/`;
          url = `${extensionsBase}${extensionName}/views/forms/${restPath}.json`;  // ← Agregado /views/
        } else {
          const basePath = config.routes?.coreViews || 'js/views';
          url = `${config.baseUrl || "/"}${basePath}/${formName}.json`;
        }
      } else {
        const basePath = config.routes?.coreViews || 'js/views';
        url = `${config.baseUrl || "/"}${basePath}/${formName}.json`;
      }

      const cacheBuster = `?t=${config.version || "1.0.0"}`;
      const response = await fetch(url + cacheBuster);

      if (!response.ok) {
        throw new Error(`Form not found: ${formName} (${url})`);
      }

      schema = await response.json();

      if (config.cache?.forms) {
        cache?.set(cacheKey, schema);
      }
    }

    const instanceId = `${schema.id}-${(config.version || "1.0.0").replace(/\./g, '-')}`;
    const instanceSchema = JSON.parse(JSON.stringify(schema));
    instanceSchema.id = instanceId;

    // Asignar order automático a fields originales (múltiplos de 5)
    if (instanceSchema.fields) {
      instanceSchema.fields = instanceSchema.fields.map((field, index) => {
        if (!field.order) {
          field.order = (index + 1) * 5;
        }
        return field;
      });
    }

    // Procesar hooks con contexto 'form'
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

    if (data) this.fill(instanceId, data, target);

    this.bindEventsOnce();

    setTimeout(() => {
      // Buscar el formulario dentro del contexto (container) en lugar de globalmente
      const formEl = target.querySelector(`#${instanceId}`) || document.getElementById(instanceId);
      if (formEl) {
        this.initRepeatables(instanceId, target);
        this.bindTransforms(instanceId, target);

        // Aplicar valores por defecto ANTES de conditions
        this.applyDefaultValues(instanceId, target);

        ogModule('conditions')?.init(instanceId);

        // Enviar focus al campo que lo requiera
        const focusField = instanceSchema.fields?.find(f => f.focus === true);
        if (focusField) {
          const input = formEl.querySelector(`[name="${focusField.name}"]`);
          if (input) input.focus();
        }

        if (typeof afterRender === 'function') {
          try {
            afterRender(instanceId, formEl);
          } catch (error) {
            ogLogger?.error('core:form', 'Error en afterRender:', error);
          }
        }
      }
    }, 10);

    return instanceId;
  }

  static render(schema) {
    const realId = schema.id.split('-')[0]; // Extraer el id original sin timestamp
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
    return fields.map((field, index) => {
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
        return this.renderGrouper(normalizedField, path, index);
      }

      return this.renderField(normalizedField, fieldPath);
    }).join('');
  }

  static renderRepeatable(field, path) {
    const addText = this.t(field.addText) || __('core.form.repeatable.add');
    const buttonPosition = field.buttonPosition || 'top';

    // ✅ Agregar description si existe
    const description = field.description
      ? `<p class="repeatable-description">${this.t(field.description)}</p>`
      : '';

    const addButton = `
      <button type="button" class="btn btn-primary btn-sm repeatable-add" data-path="${path}">
        ${addText}
      </button>
    `;

    // ✅ Envolver h4 y description en un div para que queden verticalmente
    const headerContent = `
      <div class="repeatable-header-content">
        <h4>${this.t(field.label)}</h4>
        ${description}
      </div>
    `;

    if (buttonPosition === 'middle') {
      return `
        <div class="form-repeatable" data-field-path="${path}">
          <div class="repeatable-header">
            ${headerContent}
          </div>
          <div class="repeatable-add-container" style="margin: 0.5rem 0;">
            ${addButton}
          </div>
          <div class="repeatable-items" data-path="${path}"></div>
        </div>
      `;
    } else if (buttonPosition === 'bottom') {
      return `
        <div class="form-repeatable" data-field-path="${path}">
          <div class="repeatable-header">
            ${headerContent}
          </div>
          <div class="repeatable-items" data-path="${path}"></div>
          <div class="repeatable-add-container" style="margin: 0.5rem 0; text-align: center;">
            ${addButton}
          </div>
        </div>
      `;
    } else {
      // top (default)
      return `
        <div class="form-repeatable" data-field-path="${path}">
          <div class="repeatable-header">
            ${headerContent}
            ${addButton}
          </div>
          <div class="repeatable-items" data-path="${path}"></div>
        </div>
      `;
    }
  }

  static renderGroup(field, basePath) {
    const columns = field.columns || 2;
    const gap = field.gap || 'normal';

    const groupClass = `form-group-cols form-group-cols-${columns} form-group-gap-${gap}`;

    return `
      <div class="${groupClass}">
        ${field.fields ? field.fields.map(subField => {
          // ✅ Normalizar tipo
          const normalizedSubField = this.normalizeFieldType(subField);

          // ✅ Validar role antes de renderizar subfields
          if (!this.hasRoleAccess(normalizedSubField)) return '';

          const fieldPath = basePath ? `${basePath}.${normalizedSubField.name}` : normalizedSubField.name;
          return this.renderField(normalizedSubField, fieldPath);
        }).join('') : ''}
      </div>
    `;
  }

  static renderGrouper(field, parentPath, index = 0) {
    const mode = field.mode || 'linear';
    const grouperId = `og-grouper-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;

    // Generar fieldPath: usar name si existe, sino usar índice
    let fieldPath;
    if (field.name) {
      fieldPath = parentPath ? `${parentPath}.${field.name}` : field.name;
    } else {
      fieldPath = parentPath ? `${parentPath}.__grouper_${index}` : `__grouper_${index}`;
    }

    let html = '';

    if (mode === 'linear') {
      html += this.renderGrouperLinear(field, grouperId, parentPath, fieldPath);
    } else if (mode === 'tabs') {
      html += this.renderGrouperTabs(field, grouperId, parentPath, fieldPath);
    }

    setTimeout(() => {
      this.bindGrouperEvents(grouperId, mode);
    }, 10);

    return html;
  }


  static renderGrouperLinear(field, grouperId, parentPath, fieldPath = "") {
    const collapsible = field.collapsible !== false;
    const openFirst = field.openFirst !== false;

    // CAMBIAR: .grouper → .og-grouper
    let html = `<div class="og-grouper og-grouper-linear" id="${grouperId}" data-field-path="${fieldPath}">`;

    field.groups.forEach((group, index) => {
      const isOpen = openFirst && index === 0;
      const contentId = `${grouperId}-content-${index}`;
      const processedTitle = this.processI18nTitle(group.title) || `Grupo ${index + 1}`;

      // CAMBIAR: .grouper-section → .og-grouper-section
      // CAMBIAR: .grouper-header → .og-grouper-header
      // CAMBIAR: .grouper-title → .og-grouper-title
      // CAMBIAR: .grouper-toggle → .og-grouper-toggle
      // CAMBIAR: .grouper-content → .og-grouper-content
      
      html += `
        <div class="og-grouper-section ${isOpen ? 'open' : ''} ${!collapsible ? 'non-collapsible' : ''}" data-group-index="${index}">
          <div class="og-grouper-header ${collapsible ? 'collapsible' : 'non-collapsible'}"
              ${collapsible ? `data-toggle="${contentId}"` : ''}>
            <h3 class="og-grouper-title">${processedTitle}</h3>
            ${collapsible ? '<span class="og-grouper-toggle">▼</span>' : ''}
          </div>
          <div class="og-grouper-content" id="${contentId}" ${!isOpen && collapsible ? 'style="display:none"' : ''}>
      `;

      if (group.fields && Array.isArray(group.fields)) {
        html += this.renderFields(group.fields, parentPath);
      }

      html += `
          </div>
        </div>
      `;
    });

    html += `</div>`;
    return html;
  }

  static renderGrouperTabs(field, grouperId, parentPath, fieldPath = "") {
    const activeIndex = field.activeIndex || 0;

    // CAMBIAR: .grouper → .og-grouper
    // CAMBIAR: .grouper-tabs → .og-grouper-tabs
    let html = `<div class="og-grouper og-grouper-tabs" id="${grouperId}" data-field-path="${fieldPath}">`;

    // CAMBIAR: .grouper-tabs-header → .og-grouper-tabs-header
    html += `<div class="og-grouper-tabs-header">`;
    field.groups.forEach((group, index) => {
      const isActive = index === activeIndex;
      const processedTitle = this.processI18nTitle(group.title) || `Tab ${index + 1}`;
      
      // CAMBIAR: .grouper-tab-btn → .og-grouper-tab-btn
      html += `
        <button type="button" class="og-grouper-tab-btn ${isActive ? 'active' : ''}"
                data-tab-index="${index}">
          ${processedTitle}
        </button>
      `;
    });
    html += `</div>`;

    // CAMBIAR: .grouper-tabs-content → .og-grouper-tabs-content
    html += `<div class="og-grouper-tabs-content">`;
    field.groups.forEach((group, index) => {
      const isActive = index === activeIndex;
      
      // CAMBIAR: .grouper-tab-panel → .og-grouper-tab-panel
      html += `
        <div class="og-grouper-tab-panel ${isActive ? 'active' : ''}"
            data-panel-index="${index}">
      `;

      if (group.fields && Array.isArray(group.fields)) {
        html += this.renderFields(group.fields, parentPath);
      }

      html += `</div>`;
    });
    html += `</div>`;

    html += `</div>`;
    return html;
  }

  static bindGrouperEvents(grouperId, mode) {
    const container = document.getElementById(grouperId);
    const conditions = ogModule('conditions');
    if (!container) return;

    if (mode === 'linear') {
      // CAMBIAR: .grouper-section → .og-grouper-section
      // CAMBIAR: .grouper-header → .og-grouper-header
      container.querySelectorAll(':scope > .og-grouper-section > .og-grouper-header.collapsible').forEach(header => {
        header.addEventListener('click', (e) => {
          const targetId = header.dataset.toggle;
          const content = document.getElementById(targetId);
          // CAMBIAR: .grouper-section → .og-grouper-section
          const section = header.closest('.og-grouper-section');

          if (!content) return;

          const isOpen = section.classList.contains('open');

          if (isOpen) {
            section.classList.remove('open');
            content.style.display = 'none';
          } else {
            section.classList.add('open');
            content.style.display = 'block';

            if (conditions) {
              const formId = container.closest('form')?.id;
              if (formId) {
                setTimeout(() => {
                  ogModule('conditions')?.evaluate(formId);
                }, 50);
              }
            }
          }
        });
      });
    } else if (mode === 'tabs') {
      // CAMBIAR: .grouper-tabs-header → .og-grouper-tabs-header
      // CAMBIAR: .grouper-tab-btn → .og-grouper-tab-btn
      // CAMBIAR: .grouper-tabs-content → .og-grouper-tabs-content
      // CAMBIAR: .grouper-tab-panel → .og-grouper-tab-panel
      const tabButtons = container.querySelectorAll(':scope > .og-grouper-tabs-header > .og-grouper-tab-btn');
      const tabPanels = container.querySelectorAll(':scope > .og-grouper-tabs-content > .og-grouper-tab-panel');

      tabButtons.forEach(button => {
        button.addEventListener('click', () => {
          const index = parseInt(button.dataset.tabIndex);

          tabButtons.forEach(btn => btn.classList.remove('active'));
          tabPanels.forEach(panel => panel.classList.remove('active'));

          button.classList.add('active');

          if (tabPanels[index]) {
            tabPanels[index].classList.add('active');

            if (conditions) {
              const formId = container.closest('form')?.id;
              if (formId) {
                setTimeout(() => {
                  ogModule('conditions')?.evaluate(formId);
                }, 50);
              }
            }
          }
        });
      });
    }
  }

  /**
   * Obtener clases de transform basadas en field.transform y field.validation
   * Mapea reglas de validación a sus transforms equivalentes automáticamente
   */
  static getTransformClasses(field) {
    const transformClasses = [];

    // 1. Agregar transforms explícitos (field.transform)
    if (field.transform) {
      const transforms = Array.isArray(field.transform) ? field.transform : [field.transform];
      transforms.forEach(t => transformClasses.push(`form-transform-${t}`));
    }

    // 2. Auto-detectar transforms desde validation usando el mapa compartido
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

  /**
   * Extraer atributos HTML desde field.validation
   * Convierte min:8|max:20 → minlength="8" maxlength="20" (para text)
   * o min="8" max="20" (para number)
   */
  static getValidationAttributes(field) {
    const attrs = [];

    if (!field.validation) return '';

    const rules = field.validation.split('|');
    const isNumberType = field.type === 'number' || field.type === 'range';

    rules.forEach(rule => {
      const [ruleName, ruleValue] = rule.split(':');

      if (ruleName === 'min') {
        // Para number usa 'min', para text usa 'minlength'
        const attrName = isNumberType ? 'min' : 'minlength';
        attrs.push(`${attrName}="${ruleValue}"`);
      }
      else if (ruleName === 'max') {
        // Para number usa 'max', para text usa 'maxlength'
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
    // ✅ Validar role al inicio
    if (!this.hasRoleAccess(field)) return '';

    if (field.type === 'html') {
      // ✅ Envolver HTML en contenedor para soportar condiciones
      const htmlId = path ? `data-field-name="${path}"` : '';
      return `<div class="form-html-wrapper" ${htmlId}>${field.content || ''}</div>`;
    }

    const label = this.t(field.label) || path;
    const labelI18n = field.label?.startsWith('i18n:') ? `data-i18n="${field.label.replace('i18n:', '')}"` : '';
    // Verificar si es requerido por campo required o por validation
    const isRequired = field.required || (field.validation && field.validation.includes('required'));
    const requiredAsterisk = isRequired ? '<span class="form-required">*</span>' : '';

    // ✅ Obtener clases de transform (explícitas + auto-detectadas)
    const transformClasses = this.getTransformClasses(field);

    const classNames = [
      field.className || '',
      ...transformClasses
    ].filter(c => c).join(' ');

    // ✅ Extraer atributos desde validation (min, max, etc.)
    const validationAttrs = this.getValidationAttributes(field);

    const common = `
      name="${path}"
      placeholder="${this.t(field.placeholder) || ''}"
      ${field.required ? 'required' : ''}
      ${field.min !== undefined ? `min="${field.min}"` : ''}
      ${field.max !== undefined ? `max="${field.max}"` : ''}
      ${field.step !== undefined ? `step="${field.step}"` : ''}
      ${field.rows !== undefined ? `rows="${field.rows}"` : ''}
      ${field.cols !== undefined ? `cols="${field.cols}"` : ''}
      ${classNames ? `class="${classNames}"` : ''}
      ${validationAttrs}
    `.trim();

    // Procesar estilos
    const styleAttr = this.buildStyleAttr(field.style);

    // Procesar props custom
    const propsAttr = this.buildPropsAttr(field.props);

    switch(field.type) {
      case 'button':
        const buttonI18n = field.label?.startsWith('i18n:') ? `data-i18n="${field.label.replace('i18n:', '')}"` : '';

        // Extraer y remover type de props si existe (solo para buttons)
        let btnPropsAttr = propsAttr;
        let extractedType = null;
        if (field.props?.type) {
          extractedType = field.props.type;
          const propsWithoutType = { ...field.props };
          delete propsWithoutType.type;
          btnPropsAttr = this.buildPropsAttr(propsWithoutType);
        }

        // Determinar el click handler
        let clickHandler = '';

        if (field.action) {
          // Prioridad 1: Usar action para acciones abstractas
          // Escapar comillas para uso en HTML
          const escapedAction = field.action.replace(/"/g, '&quot;');
          clickHandler = `actionProxy.handle('${escapedAction}', {}, {button: this, event: event})`;

        } else if (field.onclick) {
          // Prioridad 2: Usar onclick tradicional (backward compatibility)
          clickHandler = field.onclick;

        } else if (field.type === 'submit') {
          // Prioridad 3: Submit form si es tipo submit
          const formId = field.formId || 'form';
          clickHandler = `form.submit('${formId}')`;
        }

        // Construir atributos del botón
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
          setTimeout(() => {

            // ✅ Verificar si el select existe
            const existingSelect = document.getElementById(selectId);

            if (!existingSelect) {
              this.loadSelectFromAPI(selectId, field.source, sourceValue, sourceLabel);
              return;
            }

            // Verificar si ya fue cargado
            const alreadyLoaded = existingSelect.options.length > 1;
            const hasValue = existingSelect.value && existingSelect.value !== '';

            if (alreadyLoaded || hasValue) {
              return;
            }

            this.loadSelectFromAPI(selectId, field.source, sourceValue, sourceLabel);
          }, 10);
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

  static bindEventsOnce() {
    if (this.registeredEvents.has('form-events')) {
      return;
    }

    document.addEventListener('click', (e) => {
      if (e.target.classList.contains('repeatable-add')) {
        const path = e.target.dataset.path;
        // ✅ Pasar el botón clickeado como contexto
        this.addRepeatableItem(path, e.target);
      }

      if (e.target.classList.contains('repeatable-remove')) {
        e.stopPropagation(); // Evitar que dispare el toggle del acordeón
        const item = e.target.closest('.repeatable-item');
        if (item && confirm('¿Eliminar este elemento?')) {
          item.remove();
        }
      }

      // Toggle de acordeón en repeatable items
      const header = e.target.closest('.repeatable-item-header');
      if (header && header.dataset.toggle === 'accordion') {
        // No hacer nada si se clickeó el botón eliminar o el drag handle
        if (e.target.classList.contains('repeatable-remove') || 
            e.target.closest('.repeatable-remove') ||
            e.target.classList.contains('repeatable-drag-handle') ||
            e.target.closest('.repeatable-drag-handle')) {
          return;
        }
        
        const item = header.closest('.repeatable-item');
        const body = item.querySelector('.repeatable-item-body');
        const toggle = header.querySelector('.repeatable-toggle');
        
        if (body) {
          const isOpen = body.style.display !== 'none';
          
          // Si accordionSingle está activo, cerrar todos los otros del mismo nivel
          const container = item.closest('.repeatable-items');
          if (container && container.dataset.accordionSingle === 'true' && !isOpen) {
            const allItems = container.querySelectorAll(':scope > .repeatable-item');
            
            allItems.forEach(otherItem => {
              if (otherItem !== item) {
                const otherBody = otherItem.querySelector('.repeatable-item-body');
                const otherToggle = otherItem.querySelector('.repeatable-toggle');
                
                if (otherBody && otherBody.style.display !== 'none') {
                  otherBody.style.display = 'none';
                  if (otherToggle) {
                    otherToggle.textContent = '▶';
                  }
                  otherItem.classList.add('collapsed');
                }
              }
            });
          }
          
          // Toggle del item actual
          body.style.display = isOpen ? 'none' : 'block';
          if (toggle) {
            toggle.textContent = isOpen ? '▶' : '▼';
          }
          item.classList.toggle('collapsed', isOpen);
        }
      }
    });

    // Drag and Drop para sortable repeatables
    let draggedItem = null;
    let draggedContainer = null;

    document.addEventListener('dragstart', (e) => {
      const dragHandle = e.target.closest('.repeatable-drag-handle');
      if (!dragHandle) return;

      const item = dragHandle.closest('.repeatable-item');
      const container = item?.closest('.repeatable-items');
      
      if (item && container && container.dataset.sortable === 'true') {
        draggedItem = item;
        draggedContainer = container;
        item.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/html', item.innerHTML);
      }
    });

    document.addEventListener('dragover', (e) => {
      if (!draggedItem) return;
      e.preventDefault();
      
      const afterElement = this.getDragAfterElement(draggedContainer, e.clientY);
      
      if (afterElement == null) {
        draggedContainer.appendChild(draggedItem);
      } else {
        draggedContainer.insertBefore(draggedItem, afterElement);
      }
    });

    document.addEventListener('dragend', (e) => {
      if (!draggedItem) return;
      
      draggedItem.classList.remove('dragging');
      
      // Recalcular índices y títulos
      if (draggedContainer) {
        this.reindexRepeatableItems(draggedContainer);
      }
      
      draggedItem = null;
      draggedContainer = null;
    });

    // 🔥 NUEVO: Marcar campos cuando el usuario los modifica
    document.addEventListener('input', (e) => {
      const input = e.target;
      if (input.closest('form') && (input.tagName === 'INPUT' || input.tagName === 'TEXTAREA' || input.tagName === 'SELECT')) {
        // Marcar que el usuario modificó este campo
        input.dataset.userModified = 'true';
      }
    });

    document.addEventListener('change', (e) => {
      const input = e.target;
      if (input.closest('form') && input.tagName === 'SELECT') {
        // Marcar que el usuario modificó este select
        input.dataset.userModified = 'true';
      }
    });

    this.registeredEvents.add('form-events');
  }

  // Helper para calcular dónde insertar el item arrastrado
  static getDragAfterElement(container, y) {
    const draggableElements = [...container.querySelectorAll(':scope > .repeatable-item:not(.dragging)')];

    return draggableElements.reduce((closest, child) => {
      const box = child.getBoundingClientRect();
      const offset = y - box.top - box.height / 2;

      if (offset < 0 && offset > closest.offset) {
        return { offset: offset, element: child };
      } else {
        return closest;
      }
    }, { offset: Number.NEGATIVE_INFINITY }).element;
  }

  // ============================================================================
  // FASE 2: REPETIBLES CON ANIDACIÓN
  // ============================================================================

  static initRepeatables(formId, container = null) {
    // Buscar el formulario dentro del contexto o globalmente
    const formEl = container
      ? container.querySelector(`#${formId}`)
      : document.getElementById(formId);

    if (!formEl) {
      ogLogger?.error('core:form', `Formulario no encontrado: ${formId}`);
      return;
    }

    const schema = this.schemas.get(formId);
    if (!schema) {
      ogLogger?.error('core:form', `Schema no encontrado para: ${formId}`);
      return;
    }

    // Buscar repetibles de forma recursiva
    const findRepeatables = (fields, basePath = '', level = 0) => {
      const repeatables = [];

      fields?.forEach(field => {
        // CASO 1: Campo repeatable
        if (field.type === 'repeatable') {
          const fieldPath = basePath ? `${basePath}.${field.name}` : field.name;
          repeatables.push({ field, path: fieldPath, level });

          // RECURSIÓN: Buscar repetibles dentro de este repeatable
          if (field.fields && field.fields.length > 0) {
            const nested = findRepeatables(field.fields, fieldPath, level + 1);
            repeatables.push(...nested);
          }
        }

        // CASO 2: Group con fields
        else if (field.type === 'group' && field.fields) {
          repeatables.push(...findRepeatables(field.fields, basePath, level));
        }

        // CASO 3: Grouper con groups
        else if (field.type === 'grouper' && field.groups) {
          field.groups.forEach(group => {
            if (group.fields) {
              repeatables.push(...findRepeatables(group.fields, basePath, level));
            }
          });
        }
      });

      return repeatables;
    };

    const repeatables = findRepeatables(schema.fields);

    // Inicializar solo los repetibles de nivel 0 (los demás se inicializan dinámicamente)
    const topLevelRepeatables = repeatables.filter(r => r.level === 0);

    topLevelRepeatables.forEach(({ field, path }) => {
      const container = formEl.querySelector(`.repeatable-items[data-path="${path}"]`);

      if (container) {
        this.initRepeatableContainer(container, field, path);
      } else {
        ogLogger?.error('core:form', `Container no encontrado para repeatable: "${path}"`);
      }
    });
  }

  // ============================================================================
  // INICIALIZAR UN CONTAINER DE REPEATABLE (Reutilizable)
  // ============================================================================

  static initRepeatableContainer(container, field, path) {
    // Guardar schema completo
    container.dataset.fieldSchema = JSON.stringify(field.fields);
    container.dataset.itemCount = '0';

    // Guardar columns y gap si existen
    if (field.columns) {
      container.dataset.columns = field.columns;
    }
    if (field.gap) {
      container.dataset.gap = field.gap;
    }

    // Guardar opciones de acordeón
    if (field.accordion !== undefined) {
      container.dataset.accordion = field.accordion;
    }
    if (field.hasHeader !== undefined) {
      container.dataset.hasHeader = field.hasHeader;
    }
    if (field.headerTitle) {
      container.dataset.headerTitle = field.headerTitle;
    }
    if (field.removeText) {
      container.dataset.removeText = this.t(field.removeText);
    }
    if (field.accordionSingle !== undefined) {
      container.dataset.accordionSingle = field.accordionSingle;
    }
    if (field.sortable !== undefined) {
      container.dataset.sortable = field.sortable;
    }

    // Crear items iniciales si initialItems > 0
    const initialItems = parseInt(field.initialItems) || 0;
    if (initialItems > 0) {
      for (let i = 0; i < initialItems; i++) {
        this.addRepeatableItem(path);
      }
    }
  }

  // ============================================================================
  // PROCESAR VALORES POR DEFECTO ESPECIALES
  // ============================================================================

  // ============================================================================
  // APLICAR VALORES POR DEFECTO
  // ============================================================================

  static applyDefaultValues(formId, container = null) {
    const schema = this.schemas.get(formId);
    if (!schema || !schema.fields) return;

    const formEl = container
      ? container.querySelector(`#${formId}`)
      : document.getElementById(formId);

    if (!formEl) return;

    // Recorrer todos los campos y aplicar defaults
    this.applyDefaultsToFields(schema.fields, '', formEl);
  }

  static applyDefaultsToFields(fields, parentPath = '', formEl) {
    fields.forEach(field => {
      const fieldPath = parentPath ? `${parentPath}.${field.name}` : field.name;

      // Procesar según tipo de campo
      if (field.type === 'group' && field.fields) {
        // Recursivo para grupos
        this.applyDefaultsToFields(field.fields, parentPath, formEl);
      } else if (field.type === 'grouper' && field.groups) {
        // Recursivo para grouper
        field.groups.forEach(group => {
          if (group.fields) {
            this.applyDefaultsToFields(group.fields, parentPath, formEl);
          }
        });
      } else if (field.type === 'repeatable') {
        // Los repeatables aplican defaults al agregar items, skip
        return;
      } else if (field.defaultValue !== undefined && field.defaultValue !== null && field.name) {
        // Aplicar default al campo
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

  // ============================================================================
  // PROCESAMIENTO DE TOKENS ESPECIALES
  // ============================================================================

  static processDefaultValue(value) {
    if (typeof value !== 'string') return value;

    // Tokens especiales disponibles
    const tokens = {
      // {hash:n} - Genera un hash aleatorio de n caracteres
      hash: (length = 8) => {
        const chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
        let result = '';
        for (let i = 0; i < length; i++) {
          result += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        return result;
      },
      // {uuid} - Genera un UUID simple
      uuid: () => {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
          const r = Math.random() * 16 | 0;
          const v = c === 'x' ? r : (r & 0x3 | 0x8);
          return v.toString(16);
        });
      },
      // {timestamp} - Timestamp actual
      timestamp: () => {
        return Date.now().toString();
      },
      // {date} - Fecha actual (YYYY-MM-DD)
      date: () => {
        return new Date().toISOString().split('T')[0];
      },
      // {time} - Hora actual (HH:mm)
      time: () => {
        const now = new Date();
        return `${now.getHours().toString().padStart(2, '0')}:${now.getMinutes().toString().padStart(2, '0')}`;
      },
      // {random:min:max} - Número aleatorio entre min y max
      random: (min = 0, max = 100) => {
        return Math.floor(Math.random() * (max - min + 1)) + min;
      }
    };

    // Procesar tokens en el formato {token:param1:param2}
    return value.replace(/\{([^}]+)\}/g, (match, content) => {
      const parts = content.split(':');
      const tokenName = parts[0];
      const params = parts.slice(1).map(p => {
        // Convertir a número si es numérico
        const num = parseFloat(p);
        return isNaN(num) ? p : num;
      });

      if (tokens[tokenName]) {
        return tokens[tokenName](...params);
      }

      // Si no se reconoce el token, devolver el match original
      return match;
    });
  }

  // ============================================================================
  // AGREGAR ITEM A REPEATABLE
  // ============================================================================

  static addRepeatableItem(path, buttonElement = null) {
    // 1. Buscar el container DENTRO del formulario correcto
    let container;

    if (buttonElement) {
      // Buscar dentro del formulario que contiene el botón clickeado
      const form = buttonElement.closest('form');
      if (form) {
        container = form.querySelector(`.repeatable-items[data-path="${path}"]`);
      } else {
        // Si no hay form, buscar en el contenedor padre más cercano
        const parentContainer = buttonElement.closest('.repeatable-items');
        if (parentContainer) {
          container = parentContainer.querySelector(`.repeatable-items[data-path="${path}"]`);
        }
      }
    }

    // Fallback: búsqueda global (para compatibilidad con código legacy)
    if (!container) {
      container = document.querySelector(`.repeatable-items[data-path="${path}"]`);
    }

    if (!container) {
      ogLogger?.error('core:form', `Container no encontrado para: "${path}"`);
      return;
    }

    // 2. Obtener el schema y configuración
    const fieldSchema = JSON.parse(container.dataset.fieldSchema || '[]');
    const itemCount = parseInt(container.dataset.itemCount || '0');
    const newIndex = itemCount;

    // Obtener columns y gap si existen
    const columns = container.dataset.columns ? parseInt(container.dataset.columns) : null;
    const gap = container.dataset.gap || 'normal';

    // Obtener opciones de acordeón
    const accordion = container.dataset.accordion === 'true';
    const hasHeader = container.dataset.hasHeader === 'true' || accordion;
    const headerTitle = container.dataset.headerTitle || 'Item #{index}';
    const removeText = container.dataset.removeText || __('core.form.repeatable.remove') || 'Eliminar';
    const accordionSingle = container.dataset.accordionSingle === 'true';
    const sortable = container.dataset.sortable === 'true';

    // 3. Construir el path del item
    const itemPath = `${path}[${newIndex}]`;

    // 4. Renderizar cada field del schema (incluyendo repetibles anidados)
    const itemFields = fieldSchema.map((field, fieldIndex) => {
      const fieldPath = `${itemPath}.${field.name}`;

      if (field.type === 'repeatable') {
        return this.renderRepeatable(field, fieldPath);
      }

      if (field.type === 'group') {
        return this.renderGroup(field, itemPath);
      }

      if (field.type === 'grouper') {
        return this.renderGrouper(field, itemPath, fieldIndex);
      }

      return this.renderField(field, fieldPath);
    }).join('');

    // 5. Si el repeatable tiene columns, envolver los fields en un div con clases de grupo
    let contentHtml;
    if (columns) {
      const groupClass = `form-group-cols form-group-cols-${columns} form-group-gap-${gap}`;
      contentHtml = `<div class="${groupClass}">${itemFields}</div>`;
    } else {
      contentHtml = itemFields;
    }

    // Opciones de acordeón (ya obtenidas del dataset arriba)
    const processedTitle = headerTitle.replace('{index}', (newIndex + 1).toString());

    // 6. Crear el HTML del item
    let itemHtml = '';
    
    if (hasHeader) {
      // Con header (puede ser acordeón o no)
      const headerClass = accordion ? 'repeatable-item-accordion' : 'repeatable-item-with-header';
      const contentClass = accordion ? 'repeatable-item-body' : 'repeatable-content';
      
      // Icono drag si sortable está activo (3 columnas de puntos para que sea más fácil clickear)
      const dragIcon = sortable ? '<span class="repeatable-drag-handle" draggable="true">⋮⋮⋮</span>' : '';
      
      // Botón eliminar siempre en el header cuando hay header
      const removeButton = `<button type="button" class="btn btn-sm btn-danger repeatable-remove">${removeText}</button>`;
      const toggleIcon = accordion ? '<span class="repeatable-toggle">▼</span>' : '';
      
      // Orden: drag, eliminar, toggle
      const headerActions = `
        <div class="repeatable-item-header-actions">
          ${dragIcon}
          ${removeButton}
          ${toggleIcon}
        </div>
      `;
      
      itemHtml = `
        <div class="repeatable-item ${headerClass}" data-index="${newIndex}">
          <div class="repeatable-item-header ${accordion ? 'clickable' : ''}" ${accordion ? 'data-toggle="accordion"' : ''}>
            <span class="repeatable-item-title">${processedTitle}</span>
            ${headerActions}
          </div>
          <div class="${contentClass}" ${accordion ? 'style="display:block"' : ''}>
            ${contentHtml}
          </div>
        </div>
      `;
    } else {
      // Sin header (comportamiento original)
      itemHtml = `
        <div class="repeatable-item" data-index="${newIndex}">
          <div class="repeatable-content">
            ${contentHtml}
          </div>
          <div class="repeatable-remove">
            <button type="button" class="btn btn-sm btn-danger repeatable-remove">${removeText}</button>
          </div>
        </div>
      `;
    }

    // 7. Insertar en el DOM
    container.insertAdjacentHTML('beforeend', itemHtml);
    container.dataset.itemCount = (newIndex + 1).toString();

    // Si accordionSingle está activo, cerrar todos los otros items del mismo nivel
    if (accordionSingle && accordion) {
      const addedItem = container.lastElementChild;
      const allItems = container.querySelectorAll(':scope > .repeatable-item');
      
      allItems.forEach(item => {
        if (item !== addedItem) {
          const body = item.querySelector('.repeatable-item-body');
          const toggle = item.querySelector('.repeatable-toggle');
          
          if (body) {
            body.style.display = 'none';
            if (toggle) {
              toggle.textContent = '▶';
            }
            item.classList.add('collapsed');
          }
        }
      });
    }

    // 🚨 VERIFICAR IDS DUPLICADOS
    const selectIds = new Set();
    const allSelectsAfter = document.querySelectorAll(`select[id^="select-${path.replace(/\./g, '-')}"]`);
    allSelectsAfter.forEach(sel => {
      if (selectIds.has(sel.id)) {
        ogLogger?.error('core:form', `   ❌ ID DUPLICADO DETECTADO: ${sel.id}`);
      }
      selectIds.add(sel.id);
    });

    // 8. Aplicar valores por defecto a los campos
    const addedItem = container.lastElementChild;
    if (addedItem) {
      fieldSchema.forEach(field => {
        if (field.defaultValue !== undefined && field.defaultValue !== null) {
          const fieldPath = `${itemPath}.${field.name}`;
          const fieldEl = addedItem.querySelector(`[name="${fieldPath}"]`);

          if (fieldEl) {
            // Procesar valor por defecto especial (tokens como {hash:10})
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

    // 9. INICIALIZAR REPETIBLES ANIDADOS dentro del item recién agregado
    const formId = container.closest('form')?.id;
    if (formId) {
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
        } else {
          ogLogger?.error('core:form', `No se pudo obtener el item recién agregado en: "${path}"`);
        }

        // Re-inicializar transforms y conditions
        this.bindTransforms(formId);

        const conditions = ogModule('conditions');
        conditions?.init(formId);
      }, 20);
    }
  }

  // ============================================================================
  // REINDEXAR ITEMS DE REPEATABLE DESPUÉS DE DRAG AND DROP
  // ============================================================================

  static reindexRepeatableItems(container) {
    const items = container.querySelectorAll(':scope > .repeatable-item');
    const headerTitle = container.dataset.headerTitle || 'Item #{index}';
    const path = container.dataset.path;

    items.forEach((item, index) => {
      // Actualizar data-index
      item.dataset.index = index;

      // Actualizar título si tiene {index}
      if (headerTitle.includes('{index}')) {
        const titleEl = item.querySelector('.repeatable-item-title');
        if (titleEl) {
          titleEl.textContent = headerTitle.replace('{index}', (index + 1).toString());
        }
      }

      // Actualizar atributos name de todos los inputs dentro del item
      const inputs = item.querySelectorAll('input, select, textarea');
      inputs.forEach(input => {
        const currentName = input.getAttribute('name');
        if (currentName) {
          // Reemplazar el índice en el path: path[oldIndex].field -> path[newIndex].field
          const newName = currentName.replace(/\[(\d+)\]/, `[${index}]`);
          input.setAttribute('name', newName);
        }
      });
    });

    // Actualizar itemCount
    container.dataset.itemCount = items.length.toString();
  }

  // ============================================================================
  // BUSCAR REPETIBLES ANIDADOS EN UN SCHEMA (Solo Nivel Directo)
  // ============================================================================

  static findNestedRepeatables(fields, basePath = '') {
    const repeatables = [];

    fields?.forEach(field => {
      if (field.type === 'repeatable') {
        const fieldPath = `${basePath}.${field.name}`;
        repeatables.push({ field, path: fieldPath });

        // NO buscar más profundo - los niveles más profundos se inicializan cuando se crean
        // Esto evita errores de "container no encontrado" para repetibles que aún no existen
      }
      else if (field.type === 'group' && field.fields) {
        repeatables.push(...this.findNestedRepeatables(field.fields, basePath));
      }
    });

    return repeatables;
  }

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

  static bindTransforms(formId, container = null) {
    const formEl = container
      ? container.querySelector(`#${formId}`)
      : document.getElementById(formId);

    if (!formEl) return;

    // ✅ Usar transforms desde la variable de clase
    const transforms = this.transforms;

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

          // Ajustar cursor: si el valor cambió de longitud o caracteres, mantener posición relativa
          const lengthDiff = value.length - originalValue.length;
          let newCursorPos = cursorPos + lengthDiff;

          // Asegurar que el cursor no se salga del rango
          newCursorPos = Math.max(0, Math.min(newCursorPos, value.length));

          e.target.setSelectionRange(newCursorPos, newCursorPos);
        }
      });
    });
  }

  static fill(formId, data, container = null, skipRepeatables = false) {
    // Buscar el formulario dentro del contexto (modal) o globalmente
    const formEl = container
      ? container.querySelector(`#${formId}`)
      : document.getElementById(formId);

    if (!formEl) {
      ogLogger?.warn('core:form', `Formulario ${formId} no encontrado`);
      return;
    }

    const schema = this.schemas.get(formId);
    if (!schema) {
      ogLogger?.warn('core:form', `Schema para ${formId} no encontrado`);
      return;
    }

    // ✅ Guardar data en el formulario para reutilizar después
    if (!formEl.dataset.formData) {
      formEl.dataset.formData = JSON.stringify(data);
    }

    // Procesar campos recursivamente (solo selects, no repeatables)
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
          // ✅ Procesar selects dentro de repeatables EXISTENTES sin recrearlos
          const repeatableData = data[field.name];
          if (Array.isArray(repeatableData) && repeatableData.length > 0) {
            const itemsContainer = formEl.querySelector(`.repeatable-items[data-path="${field.name}"]`);
            if (itemsContainer) {
              const items = itemsContainer.querySelectorAll('.repeatable-item');
              items.forEach((item, index) => {
                const itemData = repeatableData[index];
                if (itemData && field.fields) {
                  this.fillRepeatableItemSelects(item, field.name, index, itemData, field.fields);
                }
              });
            }
          }
        } else if (field.name) {
          const value = data[field.name];
          if (value !== undefined && value !== null) {
            const input = formEl.querySelector(`[name="${field.name}"]`);
            if (input) {
              this.setInputValue(input, value, true);
            }
          }
        }
      });
    };

    // Procesar todos los campos
    const processAllFields = (fields) => {
      if (!fields) return;

      // Primero llenar campos simples (rápido)
      fields.forEach(field => {
        if (field.type === 'repeatable') {
          // Postponer repetables
          return;
        } else if (field.type === 'group' && field.fields) {
          processAllFields(field.fields);
        } else if (field.type === 'grouper' && field.groups) {
          field.groups.forEach(group => {
            if (group.fields) processAllFields(group.fields);
          });
        } else if (field.name) {
          // Buscar valor por name directo o con dot notation
          let value = data[field.name];
          
          // Si no se encuentra, intentar con dot notation
          if (value === undefined) {
            const dotNotationKey = Object.keys(data).find(key => 
              key.endsWith('.' + field.name) || key === field.name
            );
            if (dotNotationKey) {
              value = data[dotNotationKey];
            }
          }

          if (value !== undefined && value !== null) {
            const input = formEl.querySelector(`[name="${field.name}"]`);
            if (input) {
              this.setInputValue(input, value, true);
            }
          }
        }
      });

      // Luego llenar repetables (asíncrono)
      if (!skipRepeatables) {
        fields.forEach(field => {
          if (field.type === 'repeatable') {
            // Buscar datos del repeatable con dot notation
            let repeatableData = data[field.name];
            
            if (!repeatableData) {
              const dotNotationKey = Object.keys(data).find(key => 
                key.endsWith('.' + field.name) || key === field.name
              );
              if (dotNotationKey) {
                repeatableData = data[dotNotationKey];
              }
            }

            if (repeatableData) {
              // Crear un objeto temporal con el nombre correcto para fillRepeatable
              const tempData = { [field.name]: repeatableData };
              this.fillRepeatable(formEl, field, tempData, '');
            }
          }
        });
      }
    };

    // Primera pasada: llenar todo (o solo selects si skipRepeatables=true)
    if (skipRepeatables) {
      processFieldsForSelects(schema.fields);
    } else {
      processAllFields(schema.fields);
    }

    // ✅ Registrar listener SOLO UNA VEZ para reintentar seleccionar valores cuando selects carguen
    if (!formEl.dataset.fillListenerRegistered) {
      let selectLoadCount = 0;

      formEl.addEventListener('select:afterLoad', (e) => {
        selectLoadCount++;

        // Solo reintentar seleccionar valores en selects, NO recrear repeatables
        const savedData = JSON.parse(formEl.dataset.formData || '{}');
        processFieldsForSelects(schema.fields);
      });

      formEl.dataset.fillListenerRegistered = 'true';
    }
  }

  // Llenar campos repetibles (RECURSIVO - funciona en cualquier nivel)
  static fillRepeatable(container, field, data, parentPath) {
    const fieldName = field.name;
    const items = data[fieldName];
    const conditions = ogModule('conditions');

    if (!Array.isArray(items) || items.length === 0) {
      return;
    }

    const fullPath = parentPath ? `${parentPath}.${fieldName}` : fieldName;
    ogLogger?.info('core:form', `📋 Llenando ${fullPath}: ${items.length} items`);

    // Pausar evaluaciones de condiciones durante el llenado masivo
    if (conditions) {
      conditions.pauseEvaluations();
    }

    // Encontrar botón "Agregar"
    let addButton = container.querySelector(`.repeatable-add[data-path="${fullPath}"]`);
    if (!addButton) {
      addButton = container.querySelector(`.repeatable-add[data-path="${fieldName}"]`);
    }

    if (!addButton) {
      ogLogger?.error('core:form', `Botón "Agregar" no encontrado para: ${fullPath}`);
      if (conditions) conditions.resumeEvaluations();
      return;
    }

    // Encontrar contenedor de items
    let itemsContainer = container.querySelector(`.repeatable-items[data-path="${fullPath}"]`);
    if (!itemsContainer) {
      itemsContainer = container.querySelector(`.repeatable-items[data-path="${fieldName}"]`);
    }

    if (!itemsContainer) {
      ogLogger?.error('core:form', `Contenedor no encontrado para: ${fullPath}`);
      if (conditions) conditions.resumeEvaluations();
      return;
    }

    // Limpiar items existentes
    itemsContainer.innerHTML = '';

    const formEl = container.closest('form');
    let completedItems = 0;
    const totalItems = items.length;

    // Función para procesar cada item con un pequeño delay para selects
    const processItem = (index) => {
      if (index >= totalItems) {
        // Todos los items procesados
        if (conditions) {
          requestAnimationFrame(() => {
            conditions.resumeEvaluations(formEl?.id);
          });
        }
        return;
      }

      const itemData = items[index];
      addButton.click();

      // Usar setTimeout mínimo para dar tiempo a que el select se inicialice
      setTimeout(() => {
        this.fillRepeatableItem(itemsContainer, fieldName, index, itemData, field.fields, fullPath);
        
        // Procesar siguiente item
        processItem(index + 1);
      }, 50); // 50ms es suficiente para que el select se inicialice
    };

    // Iniciar procesamiento
    processItem(0);
  }

  // Llenar un item específico del repeatable (RECURSIVO)
  // Nota: isLastItem se mantiene por compatibilidad pero ya no se usa
  static fillRepeatableItem(container, fieldName, index, itemData, fieldSchema, parentPath, isLastItem = false) {
    // Obtener el item recién agregado
    const items = container.querySelectorAll('.repeatable-item');
    const currentItem = items[items.length - 1];

    if (!currentItem) {
      ogLogger?.error('core:form', `Item [${index}] no encontrado en el DOM`);
      return;
    }

    // Obtener el índice REAL del DOM
    const domIndex = parseInt(currentItem.dataset.index || index);

    // Calcular path del item
    const itemPath = parentPath ? `${parentPath}[${domIndex}]` : `${fieldName}[${domIndex}]`;

    // Iterar sobre cada campo del schema
    fieldSchema.forEach(subField => {
      if (subField.type === 'repeatable') {
        // RECURSIÓN: Llenar repeatable anidado
        this.fillRepeatable(currentItem, subField, itemData, itemPath);

      } else {
        // Campo normal
        const value = itemData[subField.name];

        if (value === undefined || value === null) {
          return;
        }

        // Selector con path completo
        const inputName = `${itemPath}.${subField.name}`;
        const input = currentItem.querySelector(`[name="${inputName}"]`);

        if (input) {
          // Si es un select con source, esperar a que cargue
          if (input.tagName === 'SELECT' && input.dataset.source) {
            // Marcar que necesita ser llenado después de cargar
            input.dataset.pendingValue = JSON.stringify(value);
            
            // Si el select ya está cargado (cache), llenar inmediatamente
            if (input.options.length > 1) {
              this.setInputValue(input, value, true);
            } else {
              // Esperar a que el select se cargue
              const waitForLoad = (e) => {
                if (e.target === input || e.detail?.selectId === input.id) {
                  const pendingValue = input.dataset.pendingValue;
                  if (pendingValue) {
                    try {
                      const val = JSON.parse(pendingValue);
                      this.setInputValue(input, val, true);
                      delete input.dataset.pendingValue;
                    } catch (err) {
                      this.setInputValue(input, pendingValue, true);
                    }
                  }
                  input.removeEventListener('select:afterLoad', waitForLoad);
                }
              };
              input.addEventListener('select:afterLoad', waitForLoad);
            }
          } else {
            // Campo normal (no select o select sin source)
            this.setInputValue(input, value, true);
          }
        }
      }
    });
  }

  // Llenar solo los selects de un item repeatable (sin recrear)
  static fillRepeatableItemSelects(item, fieldName, index, itemData, fieldSchema, parentPath = '') {
    // ✅ Obtener el índice REAL del DOM
    const domIndex = parseInt(item.dataset.index || index);
    const itemPath = parentPath ? `${parentPath}[${domIndex}]` : `${fieldName}[${domIndex}]`;

    fieldSchema.forEach(subField => {
      if (subField.type === 'repeatable') {
        // Recursivo: procesar repeatables anidados
        const nestedData = itemData[subField.name];
        if (Array.isArray(nestedData) && nestedData.length > 0) {
          const nestedContainer = item.querySelector(`.repeatable-items[data-path="${itemPath}.${subField.name}"]`);
          if (nestedContainer) {
            const nestedItems = nestedContainer.querySelectorAll('.repeatable-item');
            nestedItems.forEach((nestedItem, nestedIndex) => {
              const nestedItemData = nestedData[nestedIndex];
              if (nestedItemData && subField.fields) {
                this.fillRepeatableItemSelects(nestedItem, subField.name, nestedIndex, nestedItemData, subField.fields, itemPath);
              }
            });
          }
        }
      } else if (subField.type === 'group' && subField.fields) {
        // Procesar campos dentro de groups
        this.fillRepeatableItemSelects(item, fieldName, index, itemData, subField.fields, parentPath);
      } else if (subField.name) {
        const value = itemData[subField.name];
        if (value !== undefined && value !== null) {
          const inputName = `${itemPath}.${subField.name}`;
          const input = item.querySelector(`[name="${inputName}"]`);
          if (input) {
            this.setInputValue(input, value, true);
          } else {
            ogLogger?.warn('core:form', `Campo no encontrado: ${inputName}`);
          }
        }
      }
    });
  }

  // Asignar valor a un input
  static setInputValue(input, value, isFromFill = false) {
    if (!input) return;

    // 🔥 SOLUCIÓN: Si el valor viene de un fill() y el usuario ya modificó el campo, NO sobrescribir
    if (isFromFill && input.dataset.userModified === 'true') {
      return;
    }

    const inputType = input.type?.toLowerCase();

    if (inputType === 'checkbox' || inputType === 'radio') {
      input.checked = !!value;
    } else if (input.tagName === 'SELECT') {
      // Para selects, esperar a que estén cargados
      if (input.options.length <= 1 && value) {
        // Select aún no cargado, esperar al evento afterLoad
        input.dataset.pendingValue = value;

        const waitForLoad = (e) => {
          if (e.target === input || e.detail?.selectId === input.id) {
            input.value = value;
            delete input.dataset.pendingValue;
            input.removeEventListener('select:afterLoad', waitForLoad);
          }
        };

        input.addEventListener('select:afterLoad', waitForLoad);
      } else {
        input.value = value;
      }
    } else {
      input.value = value;
    }

    // ✅ TRIGGER: Disparar evento change para que conditions se actualicen
    input.dispatchEvent(new Event('change', { bubbles: true }));
  }


  static validate(formId) {
    const formEl = document.getElementById(formId);
    if (!formEl) {
      return { success: false, errors: [__('core.form.validation.form_not_found')], message: __('core.form.validation.form_not_found') };
    }

    const schema = this.schemas.get(formId);
    if (!schema) {
      return { success: true, errors: [], message: 'OK', data: this.getData(formId) };
    }

    const errors = [];
    const formData = this.getData(formId);

    formEl.querySelectorAll('.form-error').forEach(el => {
      el.textContent = '';
      el.style.display = 'none';
    });
    formEl.querySelectorAll('.form-group').forEach(el => el.classList.remove('has-error'));

    const getValueByPath = (obj, path) => {
      const keys = path.replace(/\[/g, '.').replace(/\]/g, '').split('.');
      let current = obj;
      for (let key of keys) {
        if (current === null || current === undefined) return null;
        current = current[key];
      }
      return current;
    };

    const validateField = (field, fieldPath) => {
      if (field.type === 'button' || field.type === 'html') return;

      // ✅ VALIDACIÓN: Saltar campos ocultos por condiciones
      const input = formEl.querySelector(`[name="${fieldPath}"]`);
      if (input) {
        const fieldContainer = input.closest('.form-group, .form-checkbox, .form-html-wrapper');

        // Si el campo está oculto (por condiciones o cualquier otro motivo), no validar
        if (fieldContainer && (
          fieldContainer.classList.contains('wpfw-depend-on') ||
          fieldContainer.classList.contains('og-hidden') ||
          fieldContainer.classList.contains('form-hidden') ||
          fieldContainer.style.display === 'none' ||
          window.getComputedStyle(fieldContainer).display === 'none'
        )) {
          return; // Saltar validación
        }

        // También verificar si el grouper padre está oculto
        const parentGrouper = input.closest('.og-grouper');
        if (parentGrouper && (
          parentGrouper.classList.contains('og-hidden') ||
          parentGrouper.style.display === 'none' ||
          window.getComputedStyle(parentGrouper).display === 'none'
        )) {
          return; // Saltar validación si el grouper está oculto
        }
      }

      const value = getValueByPath(formData, fieldPath);
      const label = this.t(field.label) || field.name;
      const fieldErrors = [];

      // Validar campo requerido (propiedad booleana)
      if (field.required) {
        const isEmpty = value === null || value === undefined || value.toString().trim() === '';
        if (isEmpty) {
          fieldErrors.push(__('core.form.validation.required', { field: label }));
        }
      }

      // Validar regla 'required' dentro del string de validation
      if (field.validation && field.validation.includes('required')) {
        const isEmpty = value === null || value === undefined || value.toString().trim() === '';
        if (isEmpty && !fieldErrors.some(err => err.includes(__('core.form.validation.required_text')))) {
          fieldErrors.push(__('core.form.validation.required', { field: label }));
        }
      }

      // Validar otras reglas solo si hay valor
      if (field.validation && value && value.toString().trim() !== '') {
        const rules = field.validation.split('|');

        for (const rule of rules) {
          const [ruleName, ruleParam] = rule.split(':');

          // Saltar 'required' porque ya se validó arriba
          if (ruleName === 'required') continue;

          if (ruleName === 'email') {
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
              fieldErrors.push(__('core.form.validation.email', { field: label }));
            }
          }
          else if (ruleName === 'min') {
            if (value.toString().length < parseInt(ruleParam)) {
              fieldErrors.push(__('core.form.validation.min', { field: label, min: ruleParam }));
            }
          }
          else if (ruleName === 'max') {
            if (value.toString().length > parseInt(ruleParam)) {
              fieldErrors.push(__('core.form.validation.max', { field: label, max: ruleParam }));
            }
          }
          else if (ruleName === 'minValue') {
            if (parseFloat(value) < parseFloat(ruleParam)) {
              fieldErrors.push(__('core.form.validation.min_value', { field: label, min: ruleParam }));
            }
          }
          else if (ruleName === 'maxValue') {
            if (parseFloat(value) > parseFloat(ruleParam)) {
              fieldErrors.push(__('core.form.validation.max_value', { field: label, max: ruleParam }));
            }
          }
          else if (ruleName === 'number') {
            if (isNaN(value) || !isFinite(value)) {
              fieldErrors.push(__('core.form.validation.number', { field: label }));
            }
          }
          else if (ruleName === 'url') {
            if (!/^https?:\/\/.+/.test(value)) {
              fieldErrors.push(__('core.form.validation.url', { field: label }));
            }
          }
          else if (ruleName === 'alpha_num') {
            if (!/^[a-zA-Z0-9]+$/.test(value)) {
              fieldErrors.push(__('core.form.validation.alpha_num', { field: label }));
            }
          }
        }
      }

      if (fieldErrors.length > 0) {
        errors.push(...fieldErrors);

        const input = formEl.querySelector(`[name="${fieldPath}"]`);
        if (input) {
          const formGroup = input.closest('.form-group');
          if (formGroup) {
            formGroup.classList.add('has-error');
            const errorEl = formGroup.querySelector('.form-error');
            if (errorEl) {
              errorEl.textContent = fieldErrors[0];
              errorEl.style.display = 'block';
            }
          }
        }
      }
    };

    const processFields = (fields, basePath = '') => {
      if (!fields) return;

      fields.forEach(field => {
        if (field.type === 'group' && field.fields) {
          processFields(field.fields, basePath);
        }
        else if (field.type === 'grouper' && field.groups) {
          field.groups.forEach(group => {
            if (group.fields) processFields(group.fields, basePath);
          });
        }
        // ✅ NUEVO: Validar campos dentro de repetables
        else if (field.type === 'repeatable' && field.fields) {
          const repeatableFieldPath = basePath ? `${basePath}.${field.name}` : field.name;
          const repeatableData = getValueByPath(formData, repeatableFieldPath);

          // Si hay datos en el repeatable (array de items)
          if (Array.isArray(repeatableData) && repeatableData.length > 0) {
            repeatableData.forEach((itemData, index) => {
              const itemPath = `${repeatableFieldPath}[${index}]`;

              // Validar cada campo dentro del item
              field.fields.forEach(subField => {
                if (subField.type === 'repeatable' && subField.fields) {
                  // Recursión para repetables anidados
                  processFields([subField], itemPath);
                } else if (subField.name) {
                  const subFieldPath = `${itemPath}.${subField.name}`;
                  validateField(subField, subFieldPath);
                }
              });
            });
          }
        }
        else if (field.name) {
          const fieldPath = basePath ? `${basePath}.${field.name}` : field.name;
          validateField(field, fieldPath);
        }
      });
    };

    processFields(schema.fields);

    const success = errors.length === 0;
    const message = success ? __('core.form.validation.success') : __('core.form.validation.errors', { count: errors.length });

    return {
      success,
      errors,
      message,
      data: success ? formData : null
    };
  }

  static reset(formId) {
    const formEl = document.getElementById(formId);
    if (formEl) {
      formEl.reset();
      formEl.querySelectorAll('.form-error').forEach(el => {
        el.textContent = '';
        el.style.display = 'none';
      });
      formEl.querySelectorAll('.form-group').forEach(el => el.classList.remove('has-error'));
    }
  }

  // Agregar este método al inicio de la clase form (después de la línea 10)
  static hasRoleAccess(field) {
    const auth = ogModule('auth');
    // Si el campo no tiene restricción de role, permitir acceso
    if (!field.role) return true;
    // Obtener role del usuario actual
    const userRole = auth?.user?.role;
    // Si no hay usuario autenticado, denegar acceso
    if (!userRole) return false;
    // Validar si el role coincide
    return userRole === field.role;
  }

  static buildStyleAttr(styleConfig) {
    if (!styleConfig) return '';

    // Si es string, usar tal cual (backward compatibility)
    if (typeof styleConfig === 'string') {
      return `style="${styleConfig}"`;
    }

    // Si es objeto, usar ogStyle
    if (typeof styleConfig === 'object') {
      if (!window.ogStyle) {
        ogLogger?.warn('cor:form', 'ogStyle no disponible');
        return '';
      }

      const inlineStyle = ogStyle.resolve(styleConfig);
      return inlineStyle ? `style="${inlineStyle}"` : '';
    }

    return '';
  }

  /**
   * Construir atributos custom desde props (estilo React Native)
   * Convierte: {autoCapitalize: "none"} → autocapitalize="none"
   */
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

  // Agregar error manualmente a un campo
  static setError(formId, fieldName, errorMessage) {
    // Buscar formulario por data-real-id
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
  }

  // Quitar error manualmente de un campo
  static clearError(formId, fieldName) {
    // Buscar formulario por data-real-id
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
  }

  // Limpiar todos los errores del formulario
  static clearAllErrors(formId) {
    // Buscar formulario por data-real-id
    const formEl = document.querySelector(`[data-real-id="${formId}"]`) || document.getElementById(formId);
    if (!formEl) return;

    formEl.querySelectorAll('.form-error').forEach(el => {
      el.textContent = '';
      el.style.display = 'none'
    });
    formEl.querySelectorAll('.form-group').forEach(el => el.classList.remove('has-error'));
  }

  static async loadSelectFromAPI(selectId, source, valueField, labelField) {
    const api = ogModule('api');
    const selectEl = document.getElementById(selectId);
    if (!selectEl) {
      ogLogger?.error('core:form', `Select no encontrado: ${selectId}`);
      return;
    }

    // Guardar placeholder ANTES de hacer cualquier cosa
    const firstOption = selectEl.querySelector('option[value=""]');
    const placeholder = firstOption ? firstOption.cloneNode(true) : null;

    // Verificar cache primero
    const cacheKey = `${source}|${valueField}|${labelField}`;
    if (this.selectCache.has(cacheKey)) {
      const cachedData = this.selectCache.get(cacheKey);

      this.populateSelect(selectEl, cachedData, valueField, labelField, placeholder);

      // Disparar evento
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

      // Guardar en cache
      this.selectCache.set(cacheKey, items);

      this.populateSelect(selectEl, items, valueField, labelField, placeholder);

      selectEl.disabled = false;

      // Disparar evento afterLoad para que se intente seleccionar el valor
      selectEl.dispatchEvent(new CustomEvent('select:afterLoad', {
        bubbles: true,
        detail: { selectId, source, itemCount: items.length, fromCache: false }
      }));

    } catch (error) {
      ogLogger?.error('core:form', `Error cargando select ${selectId} desde ${source}:`, error);
      selectEl.disabled = false;
    }
  }

  // Versión mejorada de populateSelect con logs detallados
  static populateSelect(selectEl, items, valueField, labelField, placeholder = null) {
    // Guardar información del estado actual
    const selectId = selectEl.id;
    const currentValue = selectEl.value;

    selectEl.innerHTML = '';

    // Agregar placeholder primero si existe
    if (placeholder) {
      selectEl.appendChild(placeholder);
    }

    items.forEach(item => {
      const option = document.createElement('option');
      option.value = item[valueField];
      option.textContent = item[labelField];
      selectEl.appendChild(option);
    });

    // Restaurar valor si existía
    if (currentValue) {
      selectEl.value = currentValue;
    }
  }

  /**
   * Procesar títulos con claves i18n para grouper
   * Usa i18n.processString() para soportar ambos formatos
   */
  static processI18nTitle(title) {
    const i18n = ogModule('i18n');
    return i18n ? i18n.processString(title) : title;
  }

  // Limpiar cache de selects por source
  static clearSelectCache(source) {
    if (!this.selectCache) return 0;

    const keysToDelete = [];
    this.selectCache.forEach((value, key) => {
      if (key.includes(source)) {
        keysToDelete.push(key);
      }
    });

    keysToDelete.forEach(key => {
      this.selectCache.delete(key);
      ogLogger?.debug('core:form', `Cache eliminado: ${key}`);
    });

    return keysToDelete.length;
  }
}

// Exponer GLOBALMENTE como ogModal
window.ogForm = ogForm;

// Registrar en ogFramework (preferido)
if (typeof window.ogFramework !== 'undefined') {
  window.ogFramework.core.form = ogForm;
}