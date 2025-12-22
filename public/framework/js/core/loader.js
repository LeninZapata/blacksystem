class loader {
  static loaded = new Set();

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
        logger.success('core:loader', `✅ Script cargado: ${normalizedUrl}`);
        resolve(true);
      };
      script.onerror = () => {
        if (options.optional) {
          resolve(false);
        } else {
          logger.error('core:loader', `❌ Error cargando script: ${normalizedUrl}`);
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
        logger.success('core:loader', `✅ Style cargado: ${normalizedUrl}`);
        resolve(true);
      };
      link.onerror = () => {
        if (options.optional) {
          resolve(false);
        } else {
          logger.error('core:loader', `❌ Error cargando style: ${normalizedUrl}`);
          reject(new Error(`Failed to load: ${normalizedUrl}`));
        }
      };
      document.head.appendChild(link);
    });
  }

  static async loadResources(scripts = [], styles = []) {
    if (!Array.isArray(scripts)) {
      logger.error('core:loader', '❌ scripts no es un array:', scripts);
      scripts = [];
    }

    if (!Array.isArray(styles)) {
      logger.error('core:loader', '❌ styles no es un array:', styles);
      styles = [];
    }

    const validScripts = scripts.filter(url => {
      if (!url || typeof url !== 'string') {
        logger.warn('core:loader', '⚠️ Script inválido encontrado:', url);
        return false;
      }
      return true;
    });

    const validStyles = styles.filter(url => {
      if (!url || typeof url !== 'string') {
        logger.warn('core:loader', '⚠️ Style inválido encontrado:', url);
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

    if (url.startsWith(window.BASE_URL)) {
      return url;
    }

    if (url.startsWith('/')) {
      const baseWithoutSlash = window.BASE_URL.slice(0, -1);
      if (url.startsWith(baseWithoutSlash)) {
        return url;
      }
      return window.BASE_URL + url.substring(1);
    }

    return window.BASE_URL + url;
  }

  static async loadJson(url, options = {}) {
    try {
      const normalizedUrl = this.normalizeUrl(url);

      const response = await fetch(normalizedUrl);

      // Si es opcional, cualquier error se trata como "no disponible"
      if (!response.ok) {
        if (options.optional) {
          return null;
        }

        // Si no es opcional, es un error real
        const errorMsg = `HTTP ${response.status}: ${response.statusText}`;
        logger.error('core:loader', `❌ Error cargando JSON: ${normalizedUrl} - ${errorMsg}`);
        throw new Error(errorMsg);
      }

      const data = await response.json();

      return data;

    } catch (error) {
      // Si es opcional, capturar CUALQUIER error y retornar null
      if (options.optional) {
        return null;
      }

      // Si no es opcional, propagar el error
      logger.error('core:loader', `❌ Error cargando JSON: ${url}`, error);
      throw error;
    }
  }
}

window.loader = loader;