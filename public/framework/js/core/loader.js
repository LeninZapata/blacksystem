class loader {
  static loaded = new Set();

  static async loadScript(url, options = {}) {
    const normalizedUrl = this.normalizeUrl(url);

    if (this.loaded.has(normalizedUrl)) {
      logger.debug('core:loader', `✓ Script en caché: ${normalizedUrl}`);
      return true;
    }

    logger.debug('core:loader', `📥 Cargando script: ${normalizedUrl}`);

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
          logger.debug('core:loader', `⚠️ Script opcional no encontrado: ${normalizedUrl}`);
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
      logger.debug('core:loader', `✓ Style en caché: ${normalizedUrl}`);
      return true;
    }

    logger.debug('core:loader', `🎨 Cargando style: ${normalizedUrl}`);

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
          logger.debug('core:loader', `⚠️ Style opcional no encontrado: ${normalizedUrl}`);
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
    logger.debug('core:loader', `📦 loadResources llamado con:`, {
      scripts: scripts.length,
      styles: styles.length
    });

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

    logger.debug('core:loader', `🔧 URLs normalizadas:`, {
      scripts: normalizedScripts,
      styles: normalizedStyles
    });

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

      // Solo log de "Cargando" si no es silent
      if (options.silent !== true) {
        logger.debug('core:loader', `📄 Cargando JSON: ${normalizedUrl}`);
      }

      const response = await fetch(normalizedUrl);

      // Si es opcional, cualquier error se trata como "no disponible"
      if (!response.ok) {
        if (options.optional) {
          // Log solo si no es silent
          if (options.silent !== true) {
            if (response.status === 404) {
              logger.debug('core:loader', `⚠️ JSON opcional no encontrado: ${normalizedUrl}`);
            } else {
              logger.debug('core:loader', `⚠️ JSON opcional no disponible (HTTP ${response.status}): ${normalizedUrl}`);
            }
          }
          // Si es silent, simplemente retornar null sin logs
          return null;
        }

        // Si no es opcional, es un error real
        const errorMsg = `HTTP ${response.status}: ${response.statusText}`;
        logger.error('core:loader', `❌ Error cargando JSON: ${normalizedUrl} - ${errorMsg}`);
        throw new Error(errorMsg);
      }

      const data = await response.json();

      // Solo log de success si no es silent
      if (options.silent !== true) {
        logger.success('core:loader', `✅ JSON cargado: ${normalizedUrl}`);
      }

      return data;

    } catch (error) {
      // Si es opcional, capturar CUALQUIER error y retornar null
      if (options.optional) {
        if (options.silent !== true) {
          logger.debug('core:loader', `⚠️ Error cargando JSON opcional: ${url} - ${error.message}`);
        }
        return null;
      }

      // Si no es opcional, propagar el error
      logger.error('core:loader', `❌ Error cargando JSON: ${url}`, error);
      throw error;
    }
  }
}

window.loader = loader;