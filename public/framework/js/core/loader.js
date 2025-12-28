class ogLoader {
  static loaded = new Set();

  static getConfig() {
    return window.ogFramework?.activeConfig || window.appConfig || {};
  }

  static async loadScript(url, options = {}) {
    const normalizedUrl = this.normalizeUrl(url);

    if (this.loaded.has(normalizedUrl)) {
      return true;
    }

    return new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = normalizedUrl;
      script.onload = () => {
        this.loaded.add(normalizedUrl);
        ogLogger?.success('core:loader', `✅ Script cargado: ${normalizedUrl}`);
        resolve(true);
      };
      script.onerror = () => {
        if (options.optional) {
          resolve(false);
        } else {
          ogLogger?.error('core:loader', `❌ Error cargando script: ${normalizedUrl}`);
          reject(new Error(`Failed to load: ${normalizedUrl}`));
        }
      };
      document.head.appendChild(script);
    });
  }

  static async loadStyle(url, options = {}) {
    const normalizedUrl = this.normalizeUrl(url);

    if (this.loaded.has(normalizedUrl)) {
      return true;
    }

    return new Promise((resolve, reject) => {
      const link = document.createElement('link');
      link.rel = 'stylesheet';
      link.href = normalizedUrl;
      link.onload = () => {
        this.loaded.add(normalizedUrl);
        ogLogger?.success('core:loader', `✅ Style cargado: ${normalizedUrl}`);
        resolve(true);
      };
      link.onerror = () => {
        if (options.optional) {
          resolve(false);
        } else {
          ogLogger?.error('core:loader', `❌ Error cargando style: ${normalizedUrl}`);
          reject(new Error(`Failed to load: ${normalizedUrl}`));
        }
      };
      document.head.appendChild(link);
    });
  }

  static async loadResources(scripts = [], styles = []) {
    if (!Array.isArray(scripts)) {
      ogLogger?.error('core:loader', '❌ scripts no es un array:', scripts);
      scripts = [];
    }

    if (!Array.isArray(styles)) {
      ogLogger?.error('core:loader', '❌ styles no es un array:', styles);
      styles = [];
    }

    const validScripts = scripts.filter(url => {
      if (!url || typeof url !== 'string') {
        ogLogger?.warn('core:loader', '⚠️ Script inválido encontrado:', url);
        return false;
      }
      return true;
    });

    const validStyles = styles.filter(url => {
      if (!url || typeof url !== 'string') {
        ogLogger?.warn('core:loader', '⚠️ Style inválido encontrado:', url);
        return false;
      }
      return true;
    });

    const normalizedScripts = validScripts.map(url => this.normalizeUrl(url));
    const normalizedStyles = validStyles.map(url => this.normalizeUrl(url));

    const promises = [
      ...normalizedScripts.map(url => this.loadScript(url)),
      ...normalizedStyles.map(url => this.loadStyle(url))
    ];

    return Promise.all(promises);
  }

  static normalizeUrl(url) {
    if (url.startsWith('http://') || url.startsWith('https://')) {
      return url;
    }

    const config = this.getConfig();
    const BASE_URL = config.baseUrl || window.BASE_URL || '/';

    if (url.startsWith(BASE_URL)) {
      return url;
    }

    if (url.startsWith('/')) {
      const baseWithoutSlash = BASE_URL.slice(0, -1);
      if (url.startsWith(baseWithoutSlash)) {
        return url;
      }
      return BASE_URL + url.substring(1);
    }

    return BASE_URL + url;
  }

  static async loadJson(url, options = {}) {
    try {
      const normalizedUrl = this.normalizeUrl(url);

      const response = await fetch(normalizedUrl);

      if (!response.ok) {
        if (options.optional) {
          return null;
        }

        const errorMsg = `HTTP ${response.status}: ${response.statusText}`;
        ogLogger?.error('core:loader', `❌ Error cargando JSON: ${normalizedUrl} - ${errorMsg}`);
        throw new Error(errorMsg);
      }

      const data = await response.json();

      return data;

    } catch (error) {
      if (options.optional) {
        return null;
      }

      ogLogger?.error('core:loader', `❌ Error cargando JSON: ${url}`, error);
      throw error;
    }
  }
}

// Global
window.loader = ogLoader;

// Registrar en ogFramework (preferido)
if (typeof window.ogFramework !== 'undefined') {
  window.ogFramework.core.loader = ogLoader;
}