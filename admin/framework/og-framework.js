;(function(window, document, undefined) {
  'use strict';

  // ==========================================
  // ONLY GROW FRAMEWORK - MULTI-INSTANCE
  // ==========================================

  if (window.ogFramework && window.ogFramework._initialized) {
    console.log('ℹ️ ogFramework already initialized');
    return;
  }

  var ogFramework = window.ogFramework || {
    version: '1.0.0',
    _initialized: false,
    _scriptsLoaded: false,
    _pendingInits: [],
    instances: {},
    configs: {},
    activeConfig: null,
    core: {},
    components: {},
    utils: {}
  };

  // Scripts a cargar (una sola vez globalmente)
  const FRAMEWORK_SCRIPTS = [
    'js/core/logger.js',
    'js/core/trigger.js',
    'js/core/cache.js',
    'js/core/action.js',
    'js/core/i18n.js',
    'js/core/event.js',
    'js/core/api.js',
    'js/core/style.js',
    'js/core/loader.js',
    'js/core/hook.js',
    'js/core/validator.js',
    'js/core/conditions.js',
    'js/core/dataLoader.js',
    'js/core/form.js',
    'js/core/view.js',
    'js/core/navigation.js',
    'js/core/sidebar.js',
    'js/core/layout.js',
    'js/components/langSelector.js',
    'js/components/toast.js',
    'js/components/modal.js',
    'js/components/tabs.js',
    'js/components/widget.js',
    'js/components/grouper.js',
    'js/components/dataTable.js'
  ];

  // ==========================================
  // REGISTRO DE CONFIGURACIONES
  // ==========================================

  ogFramework.register = async function(slug, config) {
    if (!slug || !config) {
      console.error('❌ ogFramework.register requiere slug y config');
      return;
    }

    console.log(`✅ Registering config for: ${slug}`);

    // Normalizar config
    const normalizedConfig = this.normalizeConfig(config);

    // Guardar en AMBOS lugares para compatibilidad
    this.instances[slug] = normalizedConfig;
    this.configs[slug] = normalizedConfig;

    // Si es la primera configuración, iniciar carga de scripts
    if (!this._scriptsLoading) {
      this._scriptsLoading = true;
      await this.loadFrameworkScripts();
    }

    // Activar esta instancia
    this.setActiveContext(slug);

    // Si los scripts ya están cargados, inicializar inmediatamente
    if (this._scriptsLoaded) {
      await this.initInstance(slug, normalizedConfig);
    } else {
      // Si no, agregar a pendientes
      this._pendingInits.push({ slug, config: normalizedConfig });
    }

    console.log(`✅ Config registered and activated for: ${slug}`);
  };

  // ==========================================
  // CARGA DE SCRIPTS DEL FRAMEWORK
  // ==========================================

  ogFramework.loadFrameworkScripts = async function() {
    if (this._scriptsLoaded) {
      console.log('ℹ️ Framework scripts already loaded');
      return;
    }

    console.log('🔄 Loading framework scripts...');

    try {
      // Usar la primera config para obtener frameworkUrl
      const firstConfig = Object.values(this.instances)[0];
      if (!firstConfig) {
        throw new Error('No config found for loading scripts');
      }

      const frameworkUrl = firstConfig.frameworkUrl || 'framework/';
      const cacheBuster = '?v=' + (firstConfig.version || Date.now());

      // Cargar todos los scripts del framework en paralelo
      const scriptPromises = FRAMEWORK_SCRIPTS.map(url =>
        fetch(frameworkUrl + url + cacheBuster)
          .then(r => {
            if (!r.ok) throw new Error('Failed to load ' + url);
            return r.text();
          })
      );

      const scripts = await Promise.all(scriptPromises);

      // Ejecutar scripts del framework
      scripts.forEach(scriptContent => {
        new Function(scriptContent)();
      });

      // Asegurar ogLogger global
      if (window.ogLogger) {
        window.ogFramework.logger = window.ogLogger;
      }

      ogLogger.success('framework', '✅ Framework scripts loaded');

      // Cargar scripts de MIDDLE condicionalmente (auth.js)
      if (firstConfig.auth?.enabled === true) {
        ogLogger.info('framework', '🔐 Auth habilitado - Cargando middle/auth.js');
        try {
          const authUrl = firstConfig.baseUrl + 'middle/js/auth.js' + cacheBuster;
          const authResponse = await fetch(authUrl);

          if (!authResponse.ok) {
            throw new Error('Failed to load auth.js');
          }

          const authScript = await authResponse.text();
          new Function(authScript)();

          ogLogger.success('framework', '✅ Auth.js cargado correctamente');
        } catch (error) {
          ogLogger.error('framework', '❌ Error cargando auth.js:', error);
        }
      } else {
        ogLogger.info('framework', '⚠️ Auth deshabilitado - Saltando middle/auth.js');
      }

      this._scriptsLoaded = true;
      ogLogger.success('framework', '✅ Todos los scripts core cargados');

      // Inicializar instancias pendientes
      this.initPendingInstances();

    } catch (error) {
      console.error('❌ Error loading framework scripts:', error);
      throw error;
    }
  };

  // ==========================================
  // INICIALIZACIÓN DE INSTANCIAS PENDIENTES
  // ==========================================

  ogFramework.initPendingInstances = function() {
    ogLogger.info('framework', `🚀 Initializing ${this._pendingInits.length} pending instances`);

    this._pendingInits.forEach(({ slug, config }) => {
      this.initInstance(slug, config);
    });

    this._pendingInits = [];
  };

  // ==========================================
  // INICIALIZACIÓN DE INSTANCIA (CORREGIDO)
  // ==========================================

  ogFramework.initInstance = async function(slug, config) {
    try {
      ogLogger.info('framework', `🎯 Initializing instance: ${slug}`);

      // Buscar contenedor
      const container = document.querySelector(config.container);

      if (!container) {
        console.warn(`⚠️ Container "${config.container}" not found for ${slug}`);
        this.waitForContainer(slug, config);
        return;
      }

      // Crear instancia
      const instance = {
        slug: slug,
        config: config,
        container: container,
        context: {}
      };

      // Setear como contexto activo
      this.setActiveContext(slug);

      // i18n
      if (config.i18n?.enabled && this.core.i18n) {
        await this.core.i18n.init(config.i18n);
        ogLogger?.info('framework', `✅ i18n initialized for: ${slug}`);
      }

      // Auth
      if (config.auth?.enabled && this.core.auth) {
        ogLogger?.info('framework', `🔐 Auth enabled, initializing...`);
        await this.core.auth.init(config.auth);

        if (!this.core.auth.isAuthenticated()) {
          ogLogger?.info('framework', `⚠️ User not authenticated for: ${slug}`);
          this.instances[slug] = instance;
          return;
        }

        ogLogger?.info('framework', `✅ User authenticated for ${slug}`);
        
        // PASO 1: Inicializar layout con el container
        if (this.core.layout) {
          this.core.layout.init('app', container);
        }

        // PASO 2: Cargar hooks de extensiones ANTES del sidebar
        if (this.core.hook) {
          await this.core.hook.loadPluginHooks();
          
          // Filtrar extensiones por permisos del usuario
          if (this.core.auth && this.core.auth.filterExtensionsByPermissions) {
            this.core.auth.filterExtensionsByPermissions();
          }
        }

        // PASO 3: Inicializar sidebar (ya tiene los menús de extensiones)
        if (this.core.sidebar) {
          await this.core.sidebar.init();
        }

        // PASO 4: Cargar vista por defecto
        if (this.core.view) {
          this.core.view.loadView(config.defaultView || 'middle:dashboard/dashboard');
        }

      } else {
        // Sin auth - mostrar app directamente
        if (this.core.layout) {
          this.core.layout.init('app', container);
        }

        // Cargar hooks de extensiones ANTES del sidebar
        if (this.core.hook) {
          await this.core.hook.loadPluginHooks();
        }

        if (this.core.sidebar) {
          await this.core.sidebar.init();
        }

        if (this.core.view) {
          this.core.view.loadView(config.defaultView || 'middle:dashboard/dashboard');
        }
      }

      // Cache cleanup
      if (config.isDevelopment && this.core.cache?.enableDebug) {
        this.core.cache.enableDebug(slug);
      }

      this.instances[slug] = instance;
      ogLogger?.success('framework', `✅ Instance initialized: ${slug}`);

    } catch (error) {
      console.error(`❌ Error initializing ${slug}:`, error);
      this.showError(error, config);
    }
  };

  // ==========================================
  // ESPERAR A QUE APAREZCA EL CONTENEDOR
  // ==========================================

  ogFramework.waitForContainer = function(slug, config) {
    const observer = new MutationObserver((mutations, obs) => {
      const container = document.querySelector(config.container);
      if (container) {
        obs.disconnect();
        console.log(`✅ Container found for ${slug}, initializing...`);
        this.initInstance(slug, config);
      }
    });

    observer.observe(document.body, {
      childList: true,
      subtree: true
    });

    // Timeout de 10 segundos
    setTimeout(() => {
      observer.disconnect();
      console.error(`❌ Timeout waiting for container: ${config.container}`);
    }, 10000);
  };

  // ==========================================
  // NORMALIZACIÓN DE CONFIG
  // ==========================================

  ogFramework.normalizeConfig = function(config) {
    // Defaults para propiedades críticas
    const defaults = {
      slug: 'default',
      version: '1.0.0',
      environment: 'production',
      isDevelopment: false,
      baseUrl: '/',
      frameworkUrl: 'framework/',
      publicUrl: window.location.origin + '/',
      frameworkPath: 'framework',
      container: '#app',
      defaultView: 'middle:dashboard/dashboard',
      i18n: { enabled: false },
      auth: { enabled: false },
      routes: {},
      cache: {},
      custom: {}
    };

    // Merge: defaults primero, luego config (config sobrescribe defaults)
    const normalized = { ...defaults, ...config };

    // Para objetos anidados, hacer merge profundo
    if (config.i18n) {
      normalized.i18n = { ...defaults.i18n, ...config.i18n };
    }
    if (config.auth) {
      normalized.auth = { ...defaults.auth, ...config.auth };
    }
    if (config.routes) {
      normalized.routes = { ...defaults.routes, ...config.routes };
    }
    if (config.cache) {
      normalized.cache = { ...defaults.cache, ...config.cache };
    }
    if (config.custom) {
      normalized.custom = { ...defaults.custom, ...config.custom };
    }

    return normalized;
  };

  // ==========================================
  // GESTIÓN DE CONTEXTO ACTIVO
  // ==========================================

  ogFramework.setActiveContext = function(slug) {
    if (!this.configs[slug]) {
      throw new Error('No configuration found for: ' + slug);
    }

    this.activeConfig = this.configs[slug];

    // Resetear prefix del cache para que use el nuevo slug
    if (window.ogCache && typeof window.ogCache.resetPrefix === 'function') {
      window.ogCache.resetPrefix();
    }

    // Solo mantener appConfig para compatibilidad temporal
    window.appConfig = this.activeConfig;
  };

  ogFramework.getInstance = function(slug) {
    return this.instances[slug] || null;
  };

  ogFramework.getConfig = function(slug) {
    return this.configs[slug] || null;
  };

  // ==========================================
  // MANEJO DE ERRORES
  // ==========================================

  ogFramework.showError = function(error, config) {
    const container = document.querySelector(config?.container || '#app');
    if (container) {
      container.innerHTML =
        '<div style="padding: 20px; color: #dc3545; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 5px; margin: 20px;">' +
          '<h2>Error de Carga</h2>' +
          '<p><strong>Instancia:</strong> ' + (config?.slug || 'unknown') + '</p>' +
          '<p><strong>Detalle:</strong> ' + error.message + '</p>' +
          '<button onclick="location.reload()" style="padding: 10px 20px; background: #dc3545; color: white; border: none; border-radius: 3px; cursor: pointer;">Recargar</button>' +
        '</div>';
    }
  };

  // ==========================================
  // COMPATIBILIDAD CON CÓDIGO LEGACY
  // ==========================================

  // Método init para compatibilidad con index.html actual
  ogFramework.init = function(configKey) {
    if (!window[configKey]) {
      throw new Error('Configuration "' + configKey + '" not found in window');
    }

    const config = window[configKey];
    const slug = config.slug;

    if (!slug) {
      throw new Error('Config must have a "slug" property');
    }

    return this.register(slug, config);
  };

  // ==========================================
  // API CONTEXTUAL: ogApp()
  // ==========================================

  function createContextualAPI(config) {
    const apiClass = window.ogFramework?.core?.api;
    if (!apiClass) return null;

    return {
      get: (e, opts = {}) => apiClass.request(e, { ...opts, _context: config }),
      post: (e, d, opts = {}) => apiClass.request(e, { method: 'POST', body: JSON.stringify(d), ...opts, _context: config }),
      put: (e, d, opts = {}) => apiClass.request(e, { method: 'PUT', body: JSON.stringify(d), ...opts, _context: config }),
      delete: (e, opts = {}) => apiClass.request(e, { method: 'DELETE', ...opts, _context: config })
    };
  }

  // Proxy global
  const ogAppProxy = new Proxy({}, {
    get: function(target, prop) {
      if (prop === 'config') return window.ogFramework?.activeConfig;
      if (prop === 'getInstance') return (slug) => window.ogFramework?.getInstance(slug);
      if (prop === 'getConfig') return (slug) => window.ogFramework?.getConfig(slug);
      if (prop === 'setActiveContext') return (slug) => window.ogFramework?.setActiveContext(slug);
      if (window.ogFramework?.core?.[prop]) return window.ogFramework.core[prop];
      if (window.ogFramework?.components?.[prop]) return window.ogFramework.components[prop];
      if (window.ogFramework?.utils?.[prop]) return window.ogFramework.utils[prop];
      if (window.ogFramework?.[prop]) return window.ogFramework[prop];
      return undefined;
    }
  });

  // Función callable
  window.ogApp = function(slug) {
    if (!slug) return ogAppProxy;

    const config = window.ogFramework?.configs?.[slug];
    if (!config) {
      console.warn('ogApp: Config not found for slug "' + slug + '"');
      return ogAppProxy;
    }

    return new Proxy({}, {
      get: function(target, prop) {
        if (prop === 'config') return config;
        if (prop === 'api') return createContextualAPI(config);
        if (window.ogFramework?.core?.[prop]) return window.ogFramework.core[prop];
        if (window.ogFramework?.components?.[prop]) return window.ogFramework.components[prop];
        if (window.ogFramework?.utils?.[prop]) return window.ogFramework.utils[prop];
        return undefined;
      }
    });
  };

  // Copiar propiedades del proxy
  ['config', 'getInstance', 'getConfig', 'setActiveContext'].forEach(prop => {
    Object.defineProperty(window.ogApp, prop, {
      get: () => ogAppProxy[prop],
      enumerable: true
    });
  });

  // Exponer globalmente
  window.ogFramework = ogFramework;
  ogFramework._initialized = true;

  // ==========================================
  // HELPERS SEMÁNTICOS GLOBALES
  // ==========================================

  window.ogModule = function(moduleName) {
    if (!moduleName) {
      console.warn('ogModule: moduleName is required');
      return null;
    }

    const module = window.ogFramework?.core?.[moduleName];

    if (!module) {
      const globalName = 'og' + moduleName.charAt(0).toUpperCase() + moduleName.slice(1);
      const fallback = window[globalName];

      if (!fallback) {
        console.warn(`ogModule: "${moduleName}" not found in core or window.${globalName}`);
      }

      return fallback || null;
    }

    return module;
  };

  window.ogComponent = function(componentName) {
    if (!componentName) {
      console.warn('ogComponent: componentName is required');
      return null;
    }

    const component = window.ogFramework?.components?.[componentName];

    if (!component) {
      const globalName = 'og' + componentName.charAt(0).toUpperCase() + componentName.slice(1);
      const fallback = window[globalName];

      if (!fallback) {
        console.warn(`ogComponent: "${componentName}" not found in components or window.${globalName}`);
      }

      return fallback || null;
    }

    return component;
  };

  console.log('📦 ogFramework v' + ogFramework.version + ' initialized');
  console.log('✨ Use ogFramework.register(slug, config) to add instances');

})(window, document);