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

  static getModules() {
    return {
      cache: window.ogFramework?.core?.cache || window.cache,
    };
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
    const currentVersion = globalConfig.version || window.VERSION;
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
    const { cache } = this.getModules();
    const globalConfig = this.getConfig();
    const version = globalConfig.version || window.VERSION || Date.now();

    const cacheKey = `i18n_core_${lang}_v${version}`;
    let data = cache?.get(cacheKey);

    if (!data) {
      try {
        const frameworkPath = globalConfig.frameworkPath || 'framework';
        const baseUrl = globalConfig.baseUrl || window.BASE_URL || '/';
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
    const version = globalConfig.version || window.VERSION || Date.now();
    const baseUrl = globalConfig.baseUrl || window.BASE_URL || '/';
    const { cache } = this.getModules();

    const cacheKey = `i18n_extension_${extensionName}_${lang}_v${version}`;
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

  static t(key, params = {}) {
    const lang = this.currentLang;
    const [prefix, ...rest] = key.split('.');

    let translation = null;

    if (this.exntesionTranslations.has(prefix)) {
      const extensionLangs = this.exntesionTranslations.get(prefix);
      const extensionData = extensionLangs.get(lang);

      if (extensionData) {
        translation = extensionData[key];
      }
    }

    if (!translation) {
      const coreData = this.translations.get(lang);
      translation = coreData?.[key];

      if (!translation && !key.startsWith('i18n:')) {
        ogLogger?.warn('core:i18n', `❌ Key no encontrada: "${key}" (idioma: ${lang})`);
      }
    }

    if (!translation && lang !== this.defaultLang) {
      const defaultData = this.translations.get(this.defaultLang);
      translation = defaultData?.[key];
    }

    if (!translation) {
      ogLogger?.warn('core:i18n', `Key no encontrada: ${key}`);
      return key;
    }

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
    return localStorage.getItem('app_lang') || null;
  }

  static saveLangToStorage(lang) {
    localStorage.setItem('app_lang', lang);
  }

  static clearCache() {
    this.translations.clear();
    this.exntesionTranslations.clear();
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