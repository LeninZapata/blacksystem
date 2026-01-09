class ogI18n {
  // NOTA: i18n es compartido entre todos los plugins
  // El último plugin en inicializarse define el idioma activo
  // Esto es intencional: un usuario solo usa un idioma a la vez
  static currentLang = 'es';
  static translations = new Map();
  static exntesionTranslations = new Map();
  static defaultLang = 'es';
  static availableLangs = ['es', 'en'];
  static config = {};

  static getConfig() {
    return window.ogFramework?.activeConfig || window.appConfig || {};
  }



  static async init(config = {}) {
    this.config = {
      refreshOnChange: true,
      ...config
    };

    this.cleanupOldVersionCache();

    const storedLang = this.getLangFromStorage();
    this.currentLang = storedLang || config.defaultLang || 'es';
    this.defaultLang = config.defaultLang || 'es';
    this.availableLangs = config.availableLangs || ['es', 'en'];

    await this.loadCoreLang(this.currentLang);

    const source = storedLang ? 'localStorage' : (config.defaultLang ? 'config' : 'default');
    ogLogger?.info('core:i18n', `Idioma '${this.currentLang}' desde ${source}`);
  }

  static cleanupOldVersionCache() {
    const globalConfig = this.getConfig();
    const currentVersion = globalConfig.version || "1.0.0";
    const slug = globalConfig.slug || 'default';
    let cleaned = 0;

    Object.keys(localStorage).forEach(key => {
      if (key.startsWith(`cache_${slug}_i18n_core_`) || key.startsWith(`cache_${slug}_i18n_extension_`)) {
        const hasCurrentVersion = key.includes(`_v${currentVersion}`);

        if (!hasCurrentVersion) {
          localStorage.removeItem(key);
          cleaned++;
        }
      }
    });

    if (cleaned > 0) {
      ogLogger?.info('core:i18n', `Limpiados ${cleaned} archivos de idioma de versiones antiguas`);
    }
  }

  static async loadCoreLang(lang) {
    const cache = ogModule('cache');
    const globalConfig = this.getConfig();
    const version = globalConfig.version || "1.0.0" || Date.now();

    const cacheKey = `i18n_core_${lang}`;
    let data = cache?.get(cacheKey);

    if (!data) {
      try {
        const frameworkPath = globalConfig.frameworkPath || 'framework';
        const baseUrl = globalConfig.baseUrl || '/';
        const cacheBuster = `?v=${version}`;
        const url = `${baseUrl}${frameworkPath}/js/lang/${lang}.json${cacheBuster}`;

        ogLogger?.info('core:i18n', `📥 Cargando idioma desde: ${url}`);

        const response = await fetch(url);

        if (response.ok) {
          data = await response.json();

          ogLogger?.success('core:i18n', `✅ Idioma ${lang} cargado exitosamente`);
          ogLogger?.info('core:i18n', `📊 Total de keys cargadas: ${Object.keys(data).length}`);

          cache?.set(cacheKey, data, 60 * 60 * 1000);
        } else {
          ogLogger?.warn('core:i18n', `Idioma ${lang} no encontrado`);
          return;
        }
      } catch (error) {
        ogLogger?.error('core:i18n', 'Error cargando idioma core:', error);
        return;
      }
    } else {
      ogLogger?.info('core:i18n', `♻️ Idioma ${lang} cargado desde caché`);
    }

    this.translations.set(lang, data);
    ogLogger?.success('core:i18n', `✓ Idioma ${lang} disponible para uso`);
  }

  static async loadExtensionLang(extensionName, lang) {
    const globalConfig = this.getConfig();
    const version = globalConfig.version || "1.0.0" || Date.now();
    const baseUrl = globalConfig.baseUrl || '/';
    const cache = ogModule('cache');

    const cacheKey = `i18n_extension_${extensionName}_${lang}`;
    let data = cache.get(cacheKey);

    if (!data) {
      try {
        const cacheBuster = `?v=${version}`;
        const response = await fetch(`${baseUrl}extensions/${extensionName}/lang/${lang}.json${cacheBuster}`);

        if (response.ok) {
          data = await response.json();
          cache.set(cacheKey, data, 60 * 60 * 1000);
        } else {
          return;
        }
      } catch (error) {
        return;
      }
    }

    if (!this.exntesionTranslations.has(extensionName)) {
      this.exntesionTranslations.set(extensionName, new Map());
    }

    this.exntesionTranslations.get(extensionName).set(lang, data);
  }

  // Helper para acceder a propiedades anidadas usando dot notation
  static getNestedProperty(obj, path) {
    return path.split('.').reduce((current, key) => current?.[key], obj);
  }


  static t(key, params = {}) {
    const lang = this.currentLang;
    let translation = null;

    // 1. Buscar en traducciones core primero
    const coreData = this.translations.get(lang);
    if (coreData) {
      translation = this.getNestedProperty(coreData, key);
      if (!translation) {
        translation = coreData[key];
      }
    }

    // 2. Si no se encuentra en core, buscar en extensiones
    if (!translation) {
      const [prefix, ...rest] = key.split('.');
      if (this.exntesionTranslations.has(prefix)) {
        const extensionLangs = this.exntesionTranslations.get(prefix);
        const extensionData = extensionLangs.get(lang);

        if (extensionData) {
          translation = this.getNestedProperty(extensionData, key);
          if (!translation) {
            translation = extensionData[key];
          }
        }
      }
    }

    // 3. Fallback al idioma por defecto
    if (!translation && lang !== this.defaultLang) {
      const defaultData = this.translations.get(this.defaultLang);
      if (defaultData) {
        translation = this.getNestedProperty(defaultData, key);
        if (!translation) {
          translation = defaultData[key];
        }
      }
    }

    // 4. Si translation es un objeto, intentar buscar .label automáticamente
    if (translation && typeof translation === 'object') {
      if (translation.label) {
        translation = translation.label;
      } else if (translation.placeholder) {
        translation = translation.placeholder;
      } else {
        ogLogger?.error('core:i18n', `La key "${key}" retornó un objeto sin label/placeholder:`, translation);
        return key;
      }
    }

    // 5. Si aún no hay traducción, retornar la key
    if (!translation) {
      if (!key.startsWith('i18n:')) {
        ogLogger?.warn('core:i18n', `Key no encontrada: ${key}`);
      }
      return key;
    }

    // 6. Validar que translation sea un string
    if (typeof translation !== 'string') {
      ogLogger?.error('core:i18n', `La key "${key}" no es un string válido:`, translation);
      return key;
    }

    // 7. Reemplazar parámetros {param}
    return translation.replace(/\{(\w+)\}/g, (match, param) => {
      return params[param] !== undefined ? params[param] : match;
    });
  }

  static async setLang(lang) {

    if (!this.availableLangs.includes(lang)) {
      ogLogger?.warn('core:i18n', `Idioma ${lang} no disponible`);
      return;
    }

    await this.loadCoreLang(lang);

    for (const extensionName of this.exntesionTranslations.keys()) {
      await this.loadExtensionLang(extensionName, lang);
    }

    this.currentLang = lang;
    this.saveLangToStorage(lang);

    if (this.config.refreshOnChange) {
      ogLogger?.info('core:i18n', '🔄 Recargando página...');
      window.location.reload();
    } else {
      ogLogger?.info('core:i18n', '⚡ Actualizando dinámicamente...');
      this.updateDynamicContent();

      document.dispatchEvent(new CustomEvent('lang-changed', {
        detail: { lang, method: 'dynamic' }
      }));
    }
  }

  static updateDynamicContent() {

    document.querySelectorAll('[data-i18n]').forEach(el => {
      const key = el.getAttribute('data-i18n');
      const params = this.parseDataParams(el);
      el.textContent = this.t(key, params);
    });

    document.querySelectorAll('[data-i18n-placeholder]').forEach(el => {
      const key = el.getAttribute('data-i18n-placeholder');
      el.placeholder = this.t(key);
    });

    document.querySelectorAll('[data-i18n-title]').forEach(el => {
      const key = el.getAttribute('data-i18n-title');
      el.title = this.t(key);
    });

    document.querySelectorAll('label[data-i18n]').forEach(el => {
      const key = el.getAttribute('data-i18n');

      const input = el.querySelector('input[type="checkbox"], input[type="radio"]');
      if (input) {
        const inputHTML = input.outerHTML;
        el.innerHTML = inputHTML + ' ' + this.t(key);
      } else {
        el.textContent = this.t(key);
      }
    });

    const elementsCount = document.querySelectorAll('[data-i18n]').length;
    ogLogger?.success('core:i18n', `${elementsCount} elementos actualizados`);
  }

  static parseDataParams(element) {
    const params = {};
    const paramsAttr = element.getAttribute('data-i18n-params');

    if (paramsAttr) {
      try {
        Object.assign(params, JSON.parse(paramsAttr));
      } catch (e) {
        ogLogger?.warn('core:i18n', 'Error parseando data-i18n-params');
      }
    }

    return params;
  }

  static markElement(key, params = null) {
    const attrs = `data-i18n="${key}"`;
    const paramsAttr = params ? ` data-i18n-params='${JSON.stringify(params)}'` : '';
    return attrs + paramsAttr;
  }

  static getLang() {
    return this.currentLang;
  }

  static getAvailableLangs() {
    return this.availableLangs;
  }

  static getLangFromStorage() {
    const config = this.getConfig();
    const slug = config.slug || 'app';
    return localStorage.getItem(`${slug}_lang`) || null;
  }

  static saveLangToStorage(lang) {
    const config = this.getConfig();
    const slug = config.slug || 'app';
    localStorage.setItem(`${slug}_lang`, lang);
  }


  static clearCache() {
    this.translations.clear();
    this.exntesionTranslations.clear();
    
    // Limpiar también localStorage
    const config = this.getConfig();
    const slug = config.slug || 'app';
    const keysToRemove = [];
    
    for (let i = 0; i < localStorage.length; i++) {
      const key = localStorage.key(i);
      if (key && (key.startsWith(`cache_${slug}_i18n_`) || key.startsWith(`i18n_`))) {
        keysToRemove.push(key);
      }
    }
    
    keysToRemove.forEach(key => localStorage.removeItem(key));
    
    ogLogger?.info('core:i18n', `🗑️ Cache limpiado: ${keysToRemove.length} items eliminados`);
    
    return keysToRemove.length;
  }

  static processString(str) {
    if (!str || typeof str !== 'string') return str;

    if (str.startsWith('i18n:')) {
      const key = str.substring(5);
      return this.t(key);
    }

    return str.replace(/\{i18n:([^}]+)\}/g, (match, content) => {
      const parts = content.split('|');
      const key = parts[0];
      const params = {};

      for (let i = 1; i < parts.length; i++) {
        const [paramKey, paramValue] = parts[i].split(':');
        if (paramKey && paramValue) {
          params[paramKey] = paramValue;
        }
      }

      return this.t(key, params);
    });
  }
}

// global
window.ogI18n = ogI18n;

// Registrar en ogFramework (preferido)
if (typeof window.ogFramework !== 'undefined') {
  window.ogFramework.core.i18n = ogI18n;
}

window.__ = (key, params) => ogI18n.t(key, params);