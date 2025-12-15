window.appConfig = {
  environment: IS_DEV ? 'development' : 'production',
  version: VERSION,
  isDevelopment: IS_DEV,
  frameworkPath: 'framework',

  i18n: {
    enabled: true,
    defaultLang: 'es',
    availableLangs: ['es', 'en'],
    refreshOnChange: false
  },

  auth: {
    enabled: true,
    loginView: 'auth/login',
    redirectAfterLogin: 'dashboard/dashboard',
    storageKey: 'factory_auth',
    tokenTTL: 24 * 60 * 60 * 1000,
    sessionCheckInterval: 2*60*1000, // 2 minutos
    api: {
      login: '/api/auth/login',
      logout: '/api/auth/logout',
      me: '/api/user/profile'
    }
  },

  routes: {
    coreViews: 'framework/js/views',
    extensionViews: 'extensions/{extensionName}/views',
    coreModels: 'framework/js/models',
    components: 'framework/js/components',
    utils: 'framework/js/utils',
    validation: 'framework/js/validation'
  },

  cache: {
    modals: !IS_DEV,
    forms: !IS_DEV,
    views: !IS_DEV,
    viewNavigation: !!IS_DEV, // creo que es view tabs
    validation: !IS_DEV,
    ttl: 60 * 60 * 1000
  }
};

const SCRIPTS_TO_LOAD = [
  // Core
  'js/core/logger.js',
  'js/core/action.js',
  'js/core/cache.js',
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
  // 'js/components/grouper.js',
  'js/components/modal.js',
  'js/components/tabs.js',
  'js/components/widget.js',
  'js/components/dataTable.js',
];

async function initializeApp() {
  try {
    const cacheBuster = `?v=${window.VERSION}`;

    const scriptPromises = SCRIPTS_TO_LOAD.map(url =>
      fetch(FRAMEWORK_URL + url + cacheBuster).then(r => {
        if (!r.ok) throw new Error(`Failed to load ${url}`);
        return r.text();
      })
    );

    const scripts = await Promise.all(scriptPromises);
    scripts.forEach(scriptContent => new Function(scriptContent)());

    // Inicializar i18n
    if (window.appConfig.i18n?.enabled && window.i18n) {
      await i18n.init(window.appConfig.i18n);
      logger.success('main', `Idioma ${i18n.getLang()} cargado`);
    }

    // Inicializar auth
    if (window.appConfig.auth?.enabled && window.auth) {
      logger.info('main', 'Inicializando autenticación...');
      await auth.init(window.appConfig.auth);

      if (!auth.isAuthenticated()) {
        logger.warn('main', 'Usuario no autenticado, redirigiendo al login');
        return;
      }

      logger.success('main', 'Usuario autenticado');
      await auth.showApp();
    }

    // Cleanup
    window.cache?.cleanup?.();

    logger.success('main', 'Sistema inicializado correctamente');

  } catch (error) {
    logger.error('main', 'Error crítico al inicializar:', error);
    document.getElementById('app').innerHTML = `
      <div style="padding: 20px; color: #dc3545; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 5px; margin: 20px;">
        <h2>Error de Carga</h2>
        <p><strong>Detalle:</strong> ${error.message}</p>
        <button onclick="location.reload()" style="padding: 10px 20px; background: #dc3545; color: white; border: none; border-radius: 3px; cursor: pointer;">
          Recargar
        </button>
      </div>
    `;
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initializeApp);
} else {
  initializeApp();
}