class ogFormCore {
  static schemas = new Map();
  static registeredEvents = new Set();
  static selectCache = new Map();

  // Mapeo de tipos genéricos (Web, Genérico, React Native)
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

  // Mapeo de reglas de validación a transforms automáticos
  static validationToTransformMap = {
    'numeric': 'numeric',
    'decimal': 'decimal',
    'alpha_num': 'alphanumeric',
  };

  static getConfig() {
    return window.ogFramework?.activeConfig || window.appConfig || {};
  }

  static normalizeFieldType(field) {
    if (!field || !field.type) return field;

    const type = field.type.toLowerCase();

    if (this.typeAliases[type]) {
      const normalizedType = this.typeAliases[type];
      const normalized = { ...field, type: normalizedType };

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
    const cacheKey = `form_${formName.replace(/\//g, '_')}_v${config.version || '1.0.0'}`;
    let schema = cache?.get(cacheKey);

    if (!schema) {
      let url;

      // Manejar notación extension|path
      if (formName.includes('|')) {
        const [extensionName, restPath] = formName.split('|');
        const extensionsBase = config.extensionsPath || `${config.baseUrl}extensions/`;
        url = `${extensionsBase}${extensionName}/views/${restPath}.json`;
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
        url = `${extensionsBase}${extensionName}/views/forms/${restPath}.json`;
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
          url = `${extensionsBase}${extensionName}/views/forms/${restPath}.json`;
        } else {
          const basePath = config.routes?.coreViews || 'js/views';
          url = `${config.baseUrl || "/"}${basePath}/${formName}.json`;
        }
      } else {
        const basePath = config.routes?.coreViews || 'js/views';
        url = `${config.baseUrl || "/"}${basePath}/${formName}.json`;
      }

      const cacheBuster = `?t=${config.version || "1.0.0"}`;
      url += cacheBuster;

      const response = await fetch(url);
      if (!response.ok) {
        throw new Error(`Failed to load form: ${formName} from ${url}`);
      }

      schema = await response.json();
      cache?.set(cacheKey, schema);
    }

    // Generar ID único con timestamp
    const timestamp = Date.now();
    const instanceId = `${schema.id}-${timestamp}`;
    const instanceSchema = { ...schema, id: instanceId };

    this.schemas.set(instanceId, instanceSchema);

    const render = ogModule('formRender');
    const html = render?.render(instanceSchema);

    const target = container || document.querySelector(config.container || '#app');
    if (container) {
      container.innerHTML = html;
    } else {
      target.innerHTML = html;
    }

    this.bindEventsOnce();

    setTimeout(() => {
      const formEl = target.querySelector(`#${instanceId}`) || document.getElementById(instanceId);
      if (formEl) {
        const repeatables = ogModule('formRepeatables');
        const dataModule = ogModule('formData');
        
        repeatables?.initRepeatables(instanceId, target);
        dataModule?.bindTransforms(instanceId, target);
        dataModule?.applyDefaultValues(instanceId, target);

        conditions?.init(instanceId);

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

  static bindEventsOnce() {
    if (this.registeredEvents.has('global')) return;

    const events = ogModule('events');
    if (!events) return;

    events.on('form', 'submit', (e) => {
      e.preventDefault();
      const validation = ogModule('formValidation');
      const isValid = validation?.validate(e.target.id);
      
      if (!isValid) {
        ogLogger?.warn('core:form', 'Validación fallida');
      }
    });

    // Inicializar eventos de repetibles
    const repeatables = ogModule('formRepeatables');
    repeatables?.bindRepeatableEvents();
    repeatables?.setupDragAndDrop();

    // Inicializar eventos de button_group
    this.bindButtonGroupEvents();

    // Marcar eventos de input modificados
    document.addEventListener('input', (e) => {
      const input = e.target;
      if (input.closest('form') && (input.tagName === 'INPUT' || input.tagName === 'TEXTAREA' || input.tagName === 'SELECT')) {
        input.dataset.userModified = 'true';
      }
    });

    document.addEventListener('change', (e) => {
      const input = e.target;
      if (input.closest('form') && input.tagName === 'SELECT') {
        input.dataset.userModified = 'true';
      }
    });

    this.registeredEvents.add('global');
  }

  static bindButtonGroupEvents() {
    // Manejar button groups
    document.addEventListener('click', (e) => {
      const btnGroupItem = e.target.closest('.btn-group-item');
      if (!btnGroupItem) return;

      e.preventDefault();
      e.stopPropagation();
      
      const inputId = btnGroupItem.dataset.inputId;
      if (inputId) {
        const input = document.getElementById(inputId);
        if (input && !input.checked) {
          input.checked = true;
          input.dispatchEvent(new Event('change', { bubbles: true }));
        }
      }
    });
  }

  static hasRoleAccess(field) {
    if (!field.role) return true;

    const auth = ogModule('auth');
    if (!auth || !auth.user) return true;

    const userRole = auth.user.role;
    const allowedRoles = Array.isArray(field.role) ? field.role : [field.role];

    return allowedRoles.includes(userRole);
  }

  static processI18nTitle(title) {
    if (!title || typeof title !== 'string') return title;

    const i18n = ogModule('i18n');
    if (!i18n) return title;

    if (title.startsWith('i18n:')) {
      const match = title.match(/^i18n:([a-zA-Z0-9._-]+)(.*)$/);
      if (match) {
        const key = match[1];
        const rest = match[2];
        const translated = i18n.t(key);
        return translated + rest;
      }
    }

    const i18nMatch = title.match(/i18n:([a-zA-Z0-9._-]+)/);
    if (i18nMatch) {
      const key = i18nMatch[1];
      const translated = i18n.t(key);
      return title.replace(/i18n:[a-zA-Z0-9._-]+/, translated);
    }

    const bracketMatch = title.match(/\{i18n:([a-zA-Z0-9._-]+)\}/);
    if (bracketMatch) {
      const key = bracketMatch[1];
      const translated = i18n.t(key);
      return title.replace(/\{i18n:[a-zA-Z0-9._-]+\}/, translated);
    }

    return title;
  }

  static camelToKebab(str) {
    return str.replace(/([a-z0-9])([A-Z])/g, '$1-$2').toLowerCase();
  }

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

// Global
window.ogFormCore = ogFormCore;

// Registrar en ogFramework
if (typeof window.ogFramework !== 'undefined') {
  window.ogFramework.core.formCore = ogFormCore;
}