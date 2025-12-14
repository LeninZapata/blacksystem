class form {
  static schemas = new Map();
  static registeredEvents = new Set();

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
    slug: (value) => value.toLowerCase().replace(/\s+/g, '-').replace(/[^a-z0-9-]/g, '')
  };

  // ✅ Mapeo de reglas de validación a transforms automáticos
  static validationToTransformMap = {
    'numeric': 'numeric',           // Solo números
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

      if (isCore === true) {
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
    return fields.map((field) => {
      // ✅ Normalizar tipo ANTES de procesar
      const normalizedField = this.normalizeFieldType(field);

      // ✅ Validar role antes de renderizar
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
    const addText = this.t(field.addText) || __('core.form.repeatable.add');
    const buttonPosition = field.buttonPosition || 'top';

    const addButton = `
      <button type="button" class="btn btn-primary btn-sm repeatable-add" data-path="${path}">
        ${addText}
      </button>
    `;

    if (buttonPosition === 'middle') {
      return `
        <div class="form-repeatable" data-field-path="${path}">
          <div class="repeatable-header">
            <h4>${this.t(field.label)}</h4>
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
            <h4>${this.t(field.label)}</h4>
          </div>
          <div class="repeatable-items" data-path="${path}"></div>
          <div class="repeatable-add-container" style="margin: 0.5rem 0; text-align: center;">
            ${addButton}
          </div>
        </div>
      `;
    } else {
      return `
        <div class="form-repeatable" data-field-path="${path}">
          <div class="repeatable-header">
            <h4>${this.t(field.label)}</h4>
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

  static renderGrouper(field, parentPath) {
    const mode = field.mode || 'linear';
    const grouperId = `grouper-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;

    let html = '';

    if (mode === 'linear') {
      html += this.renderGrouperLinear(field, grouperId, parentPath);
    } else if (mode === 'tabs') {
      html += this.renderGrouperTabs(field, grouperId, parentPath);
    }

    setTimeout(() => {
      this.bindGrouperEvents(grouperId, mode);
    }, 10);

    return html;
  }

  static renderGrouperLinear(field, grouperId, parentPath) {
    const collapsible = field.collapsible !== false;
    const openFirst = field.openFirst !== false;

    let html = `<div class="grouper grouper-linear" id="${grouperId}">`;

    field.groups.forEach((group, index) => {
      const isOpen = openFirst && index === 0;
      const contentId = `${grouperId}-content-${index}`;

      html += `
        <div class="grouper-section ${isOpen ? 'open' : ''} ${!collapsible ? 'non-collapsible' : ''}" data-group-index="${index}">
          <div class="grouper-header ${collapsible ? 'collapsible' : 'non-collapsible'}"
               ${collapsible ? `data-toggle="${contentId}"` : ''}>
            <h3 class="grouper-title">${group.title || `Grupo ${index + 1}`}</h3>
            ${collapsible ? '<span class="grouper-toggle">▼</span>' : ''}
          </div>
          <div class="grouper-content" id="${contentId}" ${!isOpen && collapsible ? 'style="display:none"' : ''}>
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

  static renderGrouperTabs(field, grouperId, parentPath) {
    const activeIndex = field.activeIndex || 0;

    let html = `<div class="grouper grouper-tabs" id="${grouperId}">`;

    html += `<div class="grouper-tabs-header">`;
    field.groups.forEach((group, index) => {
      const isActive = index === activeIndex;
      html += `
        <button type="button" class="grouper-tab-btn ${isActive ? 'active' : ''}"
                data-tab-index="${index}">
          ${group.title || `Tab ${index + 1}`}
        </button>
      `;
    });
    html += `</div>`;

    html += `<div class="grouper-tabs-content">`;
    field.groups.forEach((group, index) => {
      const isActive = index === activeIndex;
      html += `
        <div class="grouper-tab-panel ${isActive ? 'active' : ''}"
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
    if (!container) return;

    if (mode === 'linear') {
      // Para linear: seleccionar solo headers directos del grouper
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
          }
        });
      });
    } else if (mode === 'tabs') {
      // ✅ FIX: Seleccionar solo botones y panels DIRECTOS de este grouper
      // No los de groupers anidados
      const tabButtons = container.querySelectorAll(':scope > .grouper-tabs-header > .grouper-tab-btn');
      const tabPanels = container.querySelectorAll(':scope > .grouper-tabs-content > .grouper-tab-panel');

      tabButtons.forEach(button => {
        button.addEventListener('click', () => {
          const index = parseInt(button.dataset.tabIndex);

          // Remover active de todos los botones de ESTE grouper
          tabButtons.forEach(btn => btn.classList.remove('active'));
          tabPanels.forEach(panel => panel.classList.remove('active'));

          button.classList.add('active');

          // Activar el panel correspondiente de ESTE grouper
          if (tabPanels[index]) {
            tabPanels[index].classList.add('active');
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
          setTimeout(() => this.loadSelectFromAPI(selectId, field.source, sourceValue, sourceLabel), 10);
        }

        return `
          <div class="form-group">
            <label ${labelI18n}>${label}${requiredAsterisk}</label>
            <select id="${selectId}" ${common} ${styleAttr} ${propsAttr} ${hasSource} ${sourceData}>
              ${staticOptions}
            </select>
            <span class="form-error"></span>
          </div>`;

      case 'textarea':
        return `
          <div class="form-group">
            <label ${labelI18n}>${label}${requiredAsterisk}</label>
            <textarea ${common} ${styleAttr} ${propsAttr}></textarea>
            <span class="form-error"></span>
          </div>`;

      case 'checkbox':
        return `
          <div class="form-group form-checkbox">
            <label ${labelI18n}>
              <input type="checkbox" name="${path}" ${field.required ? 'required' : ''} ${styleAttr} ${propsAttr}>
              ${label}${requiredAsterisk}
            </label>
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
        const item = e.target.closest('.repeatable-item');
        if (item && confirm('¿Eliminar este elemento?')) {
          item.remove();
        }
      }
    });

    this.registeredEvents.add('form-events');
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
      logger.error('core:form', `Formulario no encontrado: ${formId}`);
      return;
    }

    const schema = this.schemas.get(formId);
    if (!schema) {
      logger.error('core:form', `Schema no encontrado para: ${formId}`);
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
        logger.error('core:form', `Container no encontrado para repeatable: "${path}"`);
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

    // Crear items iniciales si initialItems > 0
    const initialItems = parseInt(field.initialItems) || 0;
    if (initialItems > 0) {
      for (let i = 0; i < initialItems; i++) {
        this.addRepeatableItem(path);
      }
    }
  }

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
      logger.error('core:form', `Container no encontrado para: "${path}"`);
      return;
    }

    // 2. Obtener el schema y configuración
    const fieldSchema = JSON.parse(container.dataset.fieldSchema || '[]');
    const itemCount = parseInt(container.dataset.itemCount || '0');
    const newIndex = itemCount;

    // Obtener columns y gap si existen
    const columns = container.dataset.columns ? parseInt(container.dataset.columns) : null;
    const gap = container.dataset.gap || 'normal';

    // 3. Construir el path del item
    const itemPath = `${path}[${newIndex}]`;

    // 4. Renderizar cada field del schema (incluyendo repetibles anidados)
    const itemFields = fieldSchema.map(field => {
      const fieldPath = `${itemPath}.${field.name}`;

      if (field.type === 'repeatable') {
        return this.renderRepeatable(field, fieldPath);
      }

      if (field.type === 'group') {
        return this.renderGroup(field, itemPath);
      }

      if (field.type === 'grouper') {
        return this.renderGrouper(field, itemPath);
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

    // 6. Crear el HTML del item
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

    // 7. Insertar en el DOM
    container.insertAdjacentHTML('beforeend', itemHtml);
    container.dataset.itemCount = (newIndex + 1).toString();

    // 8. INICIALIZAR REPETIBLES ANIDADOS dentro del item recién agregado
    const formId = container.closest('form')?.id;
    if (formId) {
      setTimeout(() => {
        const addedItem = container.lastElementChild;

        if (addedItem && addedItem.classList.contains('repeatable-item')) {
          const nestedRepeatables = this.findNestedRepeatables(fieldSchema, itemPath);

          if (nestedRepeatables.length > 0) {
            nestedRepeatables.forEach(({ field, path: nestedPath }) => {
              const nestedContainer = addedItem.querySelector(`.repeatable-items[data-path="${nestedPath}"]`);

              if (nestedContainer) {
                this.initRepeatableContainer(nestedContainer, field, nestedPath);
              }
              // No reportar error si no se encuentra - es normal para repetibles de niveles más profundos
            });
          }
        } else {
          logger.error('core:form', `No se pudo obtener el item recién agregado en: "${path}"`);
        }

        // Re-inicializar transforms y conditions
        this.bindTransforms(formId);

        if (window.conditions) {
          conditions.init(formId);
        }
      }, 20);
    }
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

        transformClasses.forEach(transformClass => {
          const transformName = transformClass.replace('form-transform-', '');
          if (transforms[transformName]) {
            value = transforms[transformName](value);
          }
        });

        if (e.target.value !== value) {
          const cursorPos = e.target.selectionStart;
          e.target.value = value;
          e.target.setSelectionRange(cursorPos, cursorPos);
        }
      });
    });
  }

  static fill(formId, data, container = null) {
    // Buscar el formulario dentro del contexto (modal) o globalmente
    const formEl = container
      ? container.querySelector(`#${formId}`)
      : document.getElementById(formId);

    if (!formEl) {
      logger.warn('core:form', `Formulario ${formId} no encontrado`);
      return;
    }

    const schema = this.schemas.get(formId);
    if (!schema) {
      logger.warn('core:form', `Schema para ${formId} no encontrado`);
      return;
    }

    logger.debug('core:form', `Llenando formulario ${formId} con datos:`, data);

    // Procesar campos recursivamente
    const processFields = (fields) => {
      if (!fields) return;

      fields.forEach(field => {
        if (field.type === 'repeatable') {
          this.fillRepeatable(formEl, field, data, '');
        } else if (field.type === 'group' && field.fields) {
          processFields(field.fields);
        } else if (field.type === 'grouper' && field.groups) {
          field.groups.forEach(group => {
            if (group.fields) processFields(group.fields);
          });
        } else if (field.name) {
          const value = data[field.name];
          if (value !== undefined && value !== null) {
            const input = formEl.querySelector(`[name="${field.name}"]`);
            if (input) {
              this.setInputValue(input, value);
            }
          }
        }
      });
    };

    processFields(schema.fields);
  }

  // Llenar campos repetibles (RECURSIVO - funciona en cualquier nivel)
  static fillRepeatable(container, field, data, parentPath) {
    const fieldName = field.name;
    const items = data[fieldName];

    if (!Array.isArray(items) || items.length === 0) {
      logger.debug('core:form', `No hay datos para: ${fieldName}`);
      return;
    }

    const fullPath = parentPath ? `${parentPath}.${fieldName}` : fieldName;
    logger.info('core:form', `📋 Llenando ${fullPath}: ${items.length} items`);

    // Encontrar botón "Agregar" (puede tener path completo o simple)
    let addButton = container.querySelector(`.repeatable-add[data-path="${fullPath}"]`);
    if (!addButton) {
      addButton = container.querySelector(`.repeatable-add[data-path="${fieldName}"]`);
    }

    if (!addButton) {
      logger.error('core:form', `Botón "Agregar" no encontrado para: ${fullPath}`);
      return;
    }

    // Encontrar contenedor de items
    let itemsContainer = container.querySelector(`.repeatable-items[data-path="${fullPath}"]`);
    if (!itemsContainer) {
      itemsContainer = container.querySelector(`.repeatable-items[data-path="${fieldName}"]`);
    }

    if (!itemsContainer) {
      logger.error('core:form', `Contenedor no encontrado para: ${fullPath}`);
      return;
    }

    // Limpiar items existentes
    itemsContainer.innerHTML = '';

    // Agregar items uno por uno
    items.forEach((itemData, index) => {
      setTimeout(() => {
        logger.debug('core:form', `Agregando item ${index + 1}/${items.length} de ${fullPath}`);

        // Click en "Agregar"
        addButton.click();

        // Esperar y llenar
        setTimeout(() => {
          this.fillRepeatableItem(itemsContainer, fieldName, index, itemData, field.fields, fullPath);
        }, 100);

      }, index * 300); // 300ms entre cada item
    });
  }

  // Llenar un item específico del repeatable (RECURSIVO)
  static fillRepeatableItem(container, fieldName, index, itemData, fieldSchema, parentPath) {
    logger.debug('core:form', `Llenando item [${index}] de ${parentPath || fieldName}:`, itemData);

    // Obtener el item recién agregado
    const items = container.querySelectorAll('.repeatable-item');
    const currentItem = items[items.length - 1];

    if (!currentItem) {
      logger.error('core:form', `Item [${index}] no encontrado en el DOM`);
      return;
    }

    // Calcular path del item: proyectos[0] o proyectos[0].tareas[1]
    const itemPath = parentPath ? `${parentPath}[${index}]` : `${fieldName}[${index}]`;

    // Iterar sobre cada campo del schema
    fieldSchema.forEach(subField => {
      if (subField.type === 'repeatable') {
        // ✅ RECURSIÓN: Llenar repeatable anidado
        logger.debug('core:form', `🔄 Procesando repeatable anidado: ${subField.name}`);

        // Llamar recursivamente pasando el item actual como contenedor
        this.fillRepeatable(currentItem, subField, itemData, itemPath);

      } else {
        // Campo normal
        const value = itemData[subField.name];

        if (value === undefined || value === null) {
          return;
        }

        // Selector con path completo: proyectos[0].nombre_proyecto
        const inputName = `${itemPath}.${subField.name}`;
        const input = currentItem.querySelector(`[name="${inputName}"]`);

        if (input) {
          this.setInputValue(input, value);
          logger.debug('core:form', `✓ ${inputName} = ${value}`);
        } else {
          logger.warn('core:form', `Campo no encontrado: ${inputName}`);
        }
      }
    });
  }

  // Asignar valor a un input
  static setInputValue(input, value) {
    if (input.type === 'checkbox') {
      input.checked = !!value;
    } else if (input.type === 'radio') {
      if (input.value === value) {
        input.checked = true;
      }
    } else {
      input.value = value;
    }

    // Disparar evento change
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
          fieldContainer.style.display === 'none' ||
          window.getComputedStyle(fieldContainer).display === 'none'
        )) {
          return; // Saltar validación
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
    // Si el campo no tiene restricción de role, permitir acceso
    if (!field.role) return true;

    // Obtener role del usuario actual
    const userRole = window.auth?.user?.role;

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

    // Si es objeto, usar styleHandler
    if (typeof styleConfig === 'object') {
      if (!window.styleHandler) {
        logger.warn('cor:form', 'styleHandler no disponible');
        return '';
      }

      const inlineStyle = styleHandler.resolve(styleConfig);
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
      el.style.display = 'none';
    });
    formEl.querySelectorAll('.form-group').forEach(el => el.classList.remove('has-error'));
  }

  static async loadSelectFromAPI(selectId, source, valueField, labelField) {
    const selectEl = document.getElementById(selectId);
    if (!selectEl) {
      logger.error('core:form', `Select no encontrado: ${selectId}`);
      return;
    }

    const firstOption = selectEl.querySelector('option');
    const placeholder = firstOption?.value === '' ? firstOption : null;

    try {
      selectEl.disabled = true;
      
      const data = await api.get(source);
      const items = Array.isArray(data) ? data : (data.data || []);

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

      selectEl.disabled = false;
      logger.debug('core:form', `Select ${selectId} cargado con ${items.length} items desde ${source}`);
    } catch (error) {
      logger.error('core:form', `Error cargando select ${selectId} desde ${source}:`, error);
      selectEl.disabled = false;
    }
  }
}

window.form = form;