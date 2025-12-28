class ogCache {
  static memoryCache = new Map();
  static defaultTTL = 60 * 60 * 1000;
  static prefix = null;

  static getPrefix() {
    if (!this.prefix) {
      // Priorizar ogFramework.activeConfig, luego window.appConfig
      const config = window.ogFramework?.activeConfig || window.appConfig;
      const slug = config?.slug || config?.proyect_slug || 'default';
      this.prefix = `cache_${slug}_`;
    }
    return this.prefix;
  }

  static getPrefixedKey(key) {
    return `${this.getPrefix()}${key}`;
  }

  static resetPrefix() {
    this.prefix = null;
  }

  static setMemory(key, data, ttl = this.defaultTTL) {
    const prefixedKey = this.getPrefixedKey(key);
    const item = {
      data,
      expiry: Date.now() + ttl,
      ttl
    };
    this.memoryCache.set(prefixedKey, item);
    return true;
  }

  static getMemory(key) {
    const prefixedKey = this.getPrefixedKey(key);
    const item = this.memoryCache.get(prefixedKey);
    if (!item) return null;

    if (Date.now() > item.expiry) {
      this.memoryCache.delete(prefixedKey);
      return null;
    }

    return item.data;
  }

  static setLocal(key, data, ttl = this.defaultTTL) {
    try {
      const prefixedKey = this.getPrefixedKey(key);
      const item = {
        data,
        expiry: Date.now() + ttl,
        ttl
      };
      localStorage.setItem(prefixedKey, JSON.stringify(item));
      return true;
    } catch (error) {
      return false;
    }
  }

  static getLocal(key) {
    try {
      const prefixedKey = this.getPrefixedKey(key);
      const stored = localStorage.getItem(prefixedKey);
      if (!stored) return null;

      const item = JSON.parse(stored);
      if (Date.now() > item.expiry) {
        localStorage.removeItem(prefixedKey);
        return null;
      }

      return item.data;
    } catch (error) {
      return null;
    }
  }

  static get(key) {
    const memoryData = this.getMemory(key);
    if (memoryData) return memoryData;

    const localData = this.getLocal(key);
    if (localData) {
      this.setMemory(key, localData);
      return localData;
    }

    return null;
  }

  static set(key, data, ttl = this.defaultTTL) {
    this.setMemory(key, data, ttl);
    this.setLocal(key, data, ttl);
    return true;
  }

  static delete(key) {
    const prefixedKey = this.getPrefixedKey(key);
    this.memoryCache.delete(prefixedKey);
    localStorage.removeItem(prefixedKey);
  }

  static clear() {
    const prefix = this.getPrefix();

    // Limpiar memoryCache solo del proyecto actual
    for (const key of this.memoryCache.keys()) {
      if (key.startsWith(prefix)) {
        this.memoryCache.delete(key);
      }
    }

    // Limpiar localStorage solo del proyecto actual
    Object.keys(localStorage)
      .filter(key => key.startsWith(prefix))
      .forEach(key => localStorage.removeItem(key));
  }

  static isExpired(key) {
    const prefixedKey = this.getPrefixedKey(key);
    const stored = localStorage.getItem(prefixedKey);
    if (!stored) return true;

    try {
      const item = JSON.parse(stored);
      return Date.now() > item.expiry;
    } catch (error) {
      return true;
    }
  }

  static getTimeToExpire(key) {
    const prefixedKey = this.getPrefixedKey(key);
    const stored = localStorage.getItem(prefixedKey);
    if (!stored) return 0;

    try {
      const item = JSON.parse(stored);
      const remaining = item.expiry - Date.now();
      return remaining > 0 ? remaining : 0;
    } catch (error) {
      return 0;
    }
  }

  static getStats() {
    const prefix = this.getPrefix();
    const memoryKeys = Array.from(this.memoryCache.keys())
      .filter(key => key.startsWith(prefix))
      .map(key => key.replace(prefix, ''));

    const localKeys = Object.keys(localStorage)
      .filter(key => key.startsWith(prefix))
      .map(key => key.replace(prefix, ''));

    return {
      memory: {
        count: memoryKeys.length,
        keys: memoryKeys
      },
      localStorage: {
        count: localKeys.length,
        keys: localKeys
      }
    };
  }

  static cleanup() {
    let cleaned = 0;
    const prefix = this.getPrefix();

    // Limpiar memoryCache
    for (const [key, item] of this.memoryCache.entries()) {
      if (key.startsWith(prefix) && Date.now() > item.expiry) {
        this.memoryCache.delete(key);
        cleaned++;
      }
    }

    // Limpiar localStorage
    Object.keys(localStorage)
      .filter(key => key.startsWith(prefix))
      .forEach(key => {
        try {
          const item = JSON.parse(localStorage.getItem(key));
          if (Date.now() > item.expiry) {
            localStorage.removeItem(key);
            cleaned++;
          }
        } catch (error) {
          localStorage.removeItem(key);
          cleaned++;
        }
      });

    return cleaned;
  }

  static enableDebug(slug = null) {
    // Si no se pasa slug, usar el del activeConfig
    if (!slug) {
      const config = window.ogFramework?.activeConfig || window.appConfig;
      slug = config?.slug || 'default';
    }

    const debugKey = `debugCache_${slug}`;

    window[debugKey] = {
      stats: () => this.getStats(),
      clear: () => this.clear(),
      list: (type = 'all') => {
        const stats = this.getStats();
        if (type === 'memory') return stats.memory;
        if (type === 'local') return stats.localStorage;
        return stats;
      },
      get: (key) => this.get(key),
      delete: (key) => this.delete(key),
      isExpired: (key) => this.isExpired(key),
      timeToExpire: (key) => this.getTimeToExpire(key),
      prefix: () => this.getPrefix(),
      slug: () => slug
    };

    // También mantener window.debugCache genérico (apunta al último)
    window.debugCache = window[debugKey];

    ogLogger.debug('core:cache', `Cache debug enabled for: ${slug} (access via window.${debugKey})`);
  }
}

setInterval(() => cache.cleanup(), 5 * 60 * 1000);

window.addEventListener('load', () => cache.cleanup());

// Global
window.ogCache = ogCache;

// Registrar en ogFramework (preferido)
if (typeof window.ogFramework !== 'undefined') {
  window.ogFramework.core.cache = ogCache;
}