class i18n {
  static currentLang = 'es';
  static translations = new Map();
  static exntesionTranslations = new Map();
  static defaultLang = 'es';
  static availableLangs = ['es', 'en'];
  static config = {};

  static async init(config = {}) {
    this.config = {
      refreshOnChange: true,
      ...config
    };

    // Limpiar cache de versiones antiguas
    this.cleanupOldVersionCache();

    const storedLang = this.getLangFromStorage();
    this.currentLang = storedLang || config.defaultLang || 'es';
    this.defaultLang = config.defaultLang || 'es';
    this.availableLangs = config.availableLangs || ['es', 'en'];

    await this.loadCoreLang(this.currentLang);

    const source = storedLang ? 'localStorage' : (config.defaultLang ? 'config' : 'default');
    logger.info('core:i18n', `Idioma '${this.currentLang}' desde ${source}`);
  }

  static cleanupOldVersionCache() {
    const currentVersion = window.VERSION;
    let cleaned = 0;

    Object.keys(localStorage).forEach(key => {
      if (key.startsWith('cache_i18n_core_') || key.startsWith('cache_i18n_extension_')) {
        const hasCurrentVersion = key.includes(`_v${currentVersion}`);
        
        if (!hasCurrentVersion) {
          localStorage.removeItem(key);
          cleaned++;
        }
      }
    });

    if (cleaned > 0) {
      logger.info('core:i18n', `Limpiados ${cleaned} archivos de idioma de versiones antiguas`);
    }
  }

  static async loadCoreLang(lang) {
    const cacheKey = `i18n_core_${lang}_v${window.VERSION}`;
    let data = cache.get(cacheKey);

    if (!data) {
      try {
        const frameworkPath = window.appConfig?.frameworkPath || 'framework';
        const cacheBuster = `?v=${window.VERSION}`;
        const url = `${window.BASE_URL}${frameworkPath}/js/lang/${lang}.json${cacheBuster}`;

        logger.info('core:i18n', `📥 Cargando idioma desde: ${url}`);

        const response = await fetch(url);

        if (response.ok) {
          data = await response.json();

          logger.success('core:i18n', `✅ Idioma ${lang} cargado exitosamente`);
          logger.info('core:i18n', `📊 Total de keys cargadas: ${Object.keys(data).length}`);

          cache.set(cacheKey, data, 60 * 60 * 1000);
        } else {
          logger.warn('core:i18n', `Idioma ${lang} no encontrado`);
          return;
        }
      } catch (error) {
        logger.error('core:i18n', 'Error cargando idioma core:', error);
        return;
      }
    } else {
      logger.info('core:i18n', `♻️ Idioma ${lang} cargado desde caché`);
    }

    this.translations.set(lang, data);
    logger.success('core:i18n', `✓ Idioma ${lang} disponible para uso`);
  }

  static async loadExtensionLang(extensionName, lang) {
    const cacheKey = `i18n_extension_${extensionName}_${lang}_v${window.VERSION}`;
    let data = cache.get(cacheKey);

    if (!data) {
      try {
        const cacheBuster = `?v=${window.VERSION}`;
        const response = await fetch(`${window.BASE_URL}extensions/${extensionName}/lang/${lang}.json${cacheBuster}`);

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

    // Buscar en exntesion primero (si key empieza con nombre de exntesion)
    if (this.exntesionTranslations.has(prefix)) {
      const extensionLangs = this.exntesionTranslations.get(prefix);
      const extensionData = extensionLangs.get(lang);

      if (extensionData) {
        translation = extensionData[key];
      }
    }

    // Si no encontró, buscar en core
    if (!translation) {
      const coreData = this.translations.get(lang);
      translation = coreData?.[key];

      // Log de debug cuando se busca una key
      if (!translation && !key.startsWith('i18n:')) {
        logger.warn('core:i18n', `❌ Key no encontrada: "${key}" (idioma: ${lang})`);
      }
    }

    // Fallback a idioma por defecto
    if (!translation && lang !== this.defaultLang) {
      const defaultData = this.translations.get(this.defaultLang);
      translation = defaultData?.[key];
    }

    // Si aún no hay traducción, retornar la key
    if (!translation) {
      logger.warn('core:i18n', `Key no encontrada: ${key}`);
      return key;
    }

    // Reemplazar parámetros {param}
    return translation.replace(/\{(\w+)\}/g, (match, param) => {
      return params[param] !== undefined ? params[param] : match;
    });
  }

  static async setLang(lang) {
    if (!this.availableLangs.includes(lang)) {
      logger.warn('core:i18n', `Idioma ${lang} no disponible`);
      return;
    }

    await this.loadCoreLang(lang);

    // Recargar idiomas de extensions activos
    for (const extensionName of this.exntesionTranslations.keys()) {
      await this.loadExtensionLang(extensionName, lang);
    }

    this.currentLang = lang;
    this.saveLangToStorage(lang);

    if (this.config.refreshOnChange) {
      logger.info('core:i18n', '🔄 Recargando página...');
      window.location.reload();
    } else {
      logger.info('core:i18n', '⚡ Actualizando dinámicamente...');
      this.updateDynamicContent();

      document.dispatchEvent(new CustomEvent('lang-changed', {
        detail: { lang, method: 'dynamic' }
      }));
    }
  }

  static updateDynamicContent() {
    // 1. Actualizar elementos con data-i18n
    document.querySelectorAll('[data-i18n]').forEach(el => {
      const key = el.getAttribute('data-i18n');
      const params = this.parseDataParams(el);
      el.textContent = this.t(key, params);
    });

    // 2. Actualizar placeholders con data-i18n-placeholder
    document.querySelectorAll('[data-i18n-placeholder]').forEach(el => {
      const key = el.getAttribute('data-i18n-placeholder');
      el.placeholder = this.t(key);
    });

    // 3. Actualizar titles con data-i18n-title
    document.querySelectorAll('[data-i18n-title]').forEach(el => {
      const key = el.getAttribute('data-i18n-title');
      el.title = this.t(key);
    });

    // 4. Actualizar labels de formularios
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
    logger.success('core:i18n', `${elementsCount} elementos actualizados`);
  }

  static parseDataParams(element) {
    const params = {};
    const paramsAttr = element.getAttribute('data-i18n-params');

    if (paramsAttr) {
      try {
        Object.assign(params, JSON.parse(paramsAttr));
      } catch (e) {
        logger.warn('core:i18n', 'Error parseando data-i18n-params');
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

window.i18n = i18n;
window.__ = (key, params) => i18n.t(key, params);