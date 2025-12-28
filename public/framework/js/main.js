;(function(window, document, undefined) {
  'use strict';

  // ==========================================
  // VERIFICAR SI YA EXISTE ogFramework
  // ==========================================

  // Si ya existe ogFramework, solo registrar la nueva configuración
  if (window.ogFramework && window.ogFramework.scriptsLoaded) {
    console.log('ℹ️ ogFramework already loaded, reusing existing instance');
    // No redeclarar nada, solo exponer la referencia
    return;
  }

  // ==========================================
  // ONLY GROW FRAMEWORK (ogFramework)
  // ==========================================

  var ogFramework = window.ogFramework || {
    version: '1.0.0',
    instances: {},
    configs: {},
    isLoaded: false,
    scriptsLoaded: false,
    activeConfig: null,

    // Namespaces para módulos
    core: {},
    components: {},
    utils: {}
  };

  let SCRIPTS_TO_LOAD = [
    // Core - ogLogger PRIMERO (se usa en todos los demás)
    'js/core/logger.js',

    // Core - Resto en orden de dependencias
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
    'js/core/auth.js',
    'js/core/view.js',
    'js/core/navigation.js',
    'js/core/sidebar.js',
    'js/core/layout.js',

    // Components
    'js/components/langSelector.js',
    'js/components/toast.js',
    'js/components/modal.js',
    'js/components/tabs.js',
    'js/components/widget.js',
    'js/components/grouper.js',
    'js/components/dataTable.js',
  ];

  ogFramework.loadScripts = async function() {
    // Si los scripts ya fueron cargados globalmente, no recargarlos
    if (window.ogFramework && window.ogFramework.scriptsLoaded) {
      console.log('ℹ️ ogFramework scripts already loaded globally, skipping');
      this.isLoaded = true;
      return;
    }

    if (this.isLoaded) {
      console.log('ℹ️ ogFramework scripts already loaded');
      return;
    }

    console.log('📦 Loading ogFramework scripts...');

    try {
      var activeConfig = this.activeConfig;
      if (!activeConfig) {
        throw new Error('No active config found');
      }

      var frameworkUrl = activeConfig.frameworkUrl || 'framework/';
      var cacheBuster = '?v=' + (activeConfig.version || Date.now());

      var scriptPromises = SCRIPTS_TO_LOAD.map(function(url) {
        return fetch(frameworkUrl + url + cacheBuster).then(function(r) {
          if (!r.ok) throw new Error('Failed to load ' + url);
          return r.text();
        });
      });

      var scripts = await Promise.all(scriptPromises);

      scripts.forEach(function(scriptContent) {
        // Ejecutar script - las clases se declararán solo la primera vez
        new Function(scriptContent)();
      });

      // Asegurar que ogLogger esté disponible globalmente SIEMPRE
      if (window.ogLogger) {
        window.ogFramework.logger = window.ogLogger;
        // Alias temporal para compatibilidad
        if (!window.logger) {
          window.logger = window.ogLogger;
        }
        console.log('✅ ogLogger available globally');
      }

      this.isLoaded = true;
      this.scriptsLoaded = true;

      // Marcar globalmente que los scripts fueron cargados
      if (window.ogFramework) {
        window.ogFramework.scriptsLoaded = true;
      }

      console.log('✅ ogFramework scripts loaded successfully');

    } catch (error) {
      console.error('❌ Error loading ogFramework scripts:', error);
      throw error;
    }
  };

  ogFramework.init = async function(configKey) {
    try {
      console.log('🚀 Initializing ogFramework with config:', configKey);

      if (!window[configKey]) {
        throw new Error('Configuration "' + configKey + '" not found in window');
      }

      var config = window[configKey];
      var slug = config.slug;

      if (!slug) {
        throw new Error('Config must have a "slug" property');
      }

      console.log('📋 Config loaded for project:', slug);

      // Normalizar y validar configuración
      var normalizedConfig = this.normalizeConfig(config);

      // Guardar configuración
      this.configs[slug] = normalizedConfig;
      this.activeConfig = normalizedConfig;

      // Setear variables globales de compatibilidad (temporal)
      this.setCompatibilityVars(normalizedConfig);

      // Cargar scripts del framework
      await this.loadScripts();

      // Inicializar instancia
      await this.initInstance(slug, normalizedConfig);

      console.log('✅ ogFramework initialized for:', slug);

    } catch (error) {
      console.error('❌ Error initializing ogFramework for', configKey + ':', error);
      this.showError(error, config);
    }
  };

  ogFramework.normalizeConfig = function(config) {
    return {
      // Identificación
      slug: config.slug,
      version: config.version || '1.0.0',

      // Entorno
      environment: config.environment || 'production',
      isDevelopment: config.isDevelopment || false,

      // Paths
      baseUrl: config.baseUrl || '/',
      frameworkUrl: config.frameworkUrl || 'framework/',
      publicUrl: config.publicUrl || window.location.origin + '/',
      frameworkPath: config.frameworkPath || 'framework',

      // UI
      container: config.container || '#app',

      // Features
      i18n: config.i18n || { enabled: false },
      auth: config.auth || { enabled: false },
      routes: config.routes || {},
      cache: config.cache || {},

      // Custom config
      custom: config.custom || {}
    };
  };

  ogFramework.setCompatibilityVars = function(config) {
    // Variables de compatibilidad - TEMPORAL
    // TODO: Eliminar cuando todos los módulos usen config
    window.BASE_URL = config.baseUrl;
    window.FRAMEWORK_URL = config.frameworkUrl;
    window.PUBLIC_URL = config.publicUrl;
    window.VERSION = config.version;
    window.IS_DEV = config.isDevelopment;
    window.PROYECT_SLUG = config.slug;
    window.appConfig = config;
  };

  ogFramework.initInstance = async function(slug, config) {
    var container = document.querySelector(config.container);

    if (!container) {
      throw new Error('Container "' + config.container + '" not found');
    }

    var instance = {
      slug: slug,
      config: config,
      container: container,
      context: {}
    };

    console.log('🎯 Initializing instance for:', slug);

    // i18n
    if (config.i18n && config.i18n.enabled && ogFramework.core.i18n) {
      await ogFramework.core.i18n.init(config.i18n);
      console.log('✅ i18n loaded:', ogFramework.core.i18n.getLang());
    }

    // Auth
    if (config.auth && config.auth.enabled && ogFramework.core.auth) {
      console.log('🔐 Initializing authentication...');
      await ogFramework.core.auth.init(config.auth);

      if (!ogFramework.core.auth.isAuthenticated()) {
        console.log('⚠️ User not authenticated');
        return;
      }

      console.log('✅ User authenticated');
      await ogFramework.core.auth.showApp();
    } else {
      // Sin auth
      if (ogFramework.core.layout) {
        ogFramework.core.layout.init('app', container);
      }

      if (ogFramework.core.sidebar) {
        await ogFramework.core.sidebar.init();
      }

      if (ogFramework.core.view) {
        ogFramework.core.view.loadView(config.defaultView || 'dashboard');
      }
    }

    // Cleanup cache
    if (ogFramework.core.cache && ogFramework.core.cache.cleanup) {
      ogFramework.core.cache.cleanup();
    }

    // Habilitar debug si está en modo desarrollo
    if (config.isDevelopment && ogFramework.core.cache && ogFramework.core.cache.enableDebug) {
      ogFramework.core.cache.enableDebug(slug);
    }

    this.instances[slug] = instance;
    console.log('✅ Instance created for:', slug);
  };

  ogFramework.showError = function(error, config) {
    var container = document.querySelector((config && config.container) || '#app');
    if (container) {
      container.innerHTML =
        '<div style="padding: 20px; color: #dc3545; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 5px; margin: 20px;">' +
          '<h2>Error de Carga</h2>' +
          '<p><strong>Detalle:</strong> ' + error.message + '</p>' +
          '<button onclick="location.reload()" style="padding: 10px 20px; background: #dc3545; color: white; border: none; border-radius: 3px; cursor: pointer;">Recargar</button>' +
        '</div>';
    }
  };

  ogFramework.getInstance = function(slug) {
    return this.instances[slug] || null;
  };

  ogFramework.getConfig = function(slug) {
    return this.configs[slug] || null;
  };

  ogFramework.setActiveContext = function(slug) {
    if (!this.configs[slug]) {
      throw new Error('No configuration found for: ' + slug);
    }
    this.activeConfig = this.configs[slug];
    window.appConfig = this.configs[slug];
  };

  // Exponer globalmente
  window.ogFramework = ogFramework;

  // ==========================================
  // WRAPPER CORTO: ogApp (usando Proxy + Callable)
  // ==========================================

  // Helper para crear API contextualizada
  function createContextualAPI(config) {
    var apiClass = window.ogFramework?.core?.api;
    if (!apiClass) return null;

    return {
      get: function(endpoint, params) {
        return apiClass.request(endpoint, {
          method: 'GET',
          params: params || {},
          _context: config
        });
      },
      post: function(endpoint, data) {
        return apiClass.request(endpoint, {
          method: 'POST',
          data: data || {},
          _context: config
        });
      },
      put: function(endpoint, data) {
        return apiClass.request(endpoint, {
          method: 'PUT',
          data: data || {},
          _context: config
        });
      },
      delete: function(endpoint) {
        return apiClass.request(endpoint, {
          method: 'DELETE',
          _context: config
        });
      }
    };
  }

  // Proxy global (sin contexto específico)
  var ogAppProxy = new Proxy({}, {
    get: function(target, prop) {
      if (prop === 'config') {
        return window.ogFramework?.activeConfig;
      }

      if (prop === 'getInstance') {
        return function(slug) {
          return window.ogFramework?.getInstance(slug);
        };
      }

      if (prop === 'getConfig') {
        return function(slug) {
          return window.ogFramework?.getConfig(slug);
        };
      }

      if (prop === 'setActiveContext') {
        return function(slug) {
          return window.ogFramework?.setActiveContext(slug);
        };
      }

      if (window.ogFramework?.core?.[prop]) {
        return window.ogFramework.core[prop];
      }

      if (window.ogFramework?.components?.[prop]) {
        return window.ogFramework.components[prop];
      }

      if (window.ogFramework?.utils?.[prop]) {
        return window.ogFramework.utils[prop];
      }

      if (window.ogFramework?.[prop]) {
        return window.ogFramework[prop];
      }

      return undefined;
    }
  });

  // Función callable que retorna instancia contextualizada
  window.ogApp = function(slug) {
    // Sin slug: retornar wrapper global
    if (!slug) {
      return ogAppProxy;
    }

    // Con slug: retornar instancia contextualizada
    var config = window.ogFramework?.configs?.[slug];

    if (!config) {
      console.warn('ogApp: Config not found for slug "' + slug + '"');
      return ogAppProxy;
    }

    // Proxy contextualizado
    return new Proxy({}, {
      get: function(target, prop) {
        if (prop === 'config') {
          return config;
        }

        if (prop === 'api') {
          return createContextualAPI(config);
        }

        if (window.ogFramework?.core?.[prop]) {
          return window.ogFramework.core[prop];
        }

        if (window.ogFramework?.components?.[prop]) {
          return window.ogFramework.components[prop];
        }

        if (window.ogFramework?.utils?.[prop]) {
          return window.ogFramework.utils[prop];
        }

        return undefined;
      }
    });
  };

  // Hacer que ogApp tenga las propiedades del proxy global
  Object.keys(ogAppProxy).forEach(function(key) {
    try {
      Object.defineProperty(window.ogApp, key, {
        get: function() { return ogAppProxy[key]; },
        enumerable: true
      });
    } catch(e) {
      // Ignorar errores en propiedades no configurables
    }
  });

  // Copiar propiedades del proxy al ogApp para acceso directo
  var propsToProxy = ['config', 'getInstance', 'getConfig', 'setActiveContext'];
  propsToProxy.forEach(function(prop) {
    Object.defineProperty(window.ogApp, prop, {
      get: function() { return ogAppProxy[prop]; },
      enumerable: true
    });
  });

  console.log('📦 ogFramework v' + ogFramework.version + ' ready');
  console.log('✨ Use ogApp("slug") for contextual access');
  console.log('✨ Use ogApp.* for global access');
  console.log('🔍 ogLogger available globally for logging');

})(window, document);