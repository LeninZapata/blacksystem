class ogAuth {
  static config = {};
  static user = null;
  static userPermissions = null;
  static userPreferences = null;
  static sessionCheckInterval = null;

  static getModules() {
    return {
      cache: window.ogFramework?.core?.cache,
      api: window.ogFramework?.core?.api,
      view: window.ogFramework?.core?.view,
      form: window.ogFramework?.core?.form,
      hook: window.ogFramework?.core?.hook,
      sidebar: window.ogFramework?.core?.sidebar,
      layout: window.ogFramework?.core?.layout,
      events: window.ogFramework?.core?.events,
      toast: window.ogFramework?.components?.toast,
    };
  }

  // ============================================
  // INICIALIZACIÓN
  // ============================================

  static async init(config) {
    const globalConfig = window.ogFramework?.activeConfig || window.appConfig || {};

    this.config = {
      enabled: true,
      loginView: 'auth/login',
      redirectAfterLogin: 'dashboard',
      storageKey: globalConfig.slug || 'factory_auth',
      sessionCheckInterval: 5 * 60 * 1000,
      tokenTTL: 24 * 60 * 60 * 1000,
      api: {
        login: '/api/user/login',
        logout: '/api/user/logout',
        me: '/api/user/profile'
      },
      ...config
    };

    if (!this.config.enabled) return;

    ogLogger.info('core:auth', 'Inicializando autenticación...');

    this.setupLoginHandler();

    const isAuth = await this.check();

    if (isAuth) {
      this.user = await this.getUser();
      this.normalizeConfig();
      this.loadUserPermissions();
      this.startSessionMonitoring();
      await this.showApp();
    } else {
      this.showLogin();
    }
  }

  // ============================================
  // AUTENTICACIÓN
  // ============================================

  static async check() {
    const token = this.getToken();

    if (!token) {
      return false;
    }

    try {
      const { api } = this.getModules();
      const response = await api.get(this.config.api.me);

      if (response.success && response.data) {
        const { cache } = this.getModules();
        cache.setLocal(`${this.config.storageKey}_user`, response.data, this.config.tokenTTL);
        ogLogger.success('core:auth', 'Sesión válida');
        return true;
      }

      ogLogger.warn('core:auth', 'Respuesta inesperada del servidor');
      this.clearSession();
      return false;

    } catch (error) {
      ogLogger.warn('core:auth', 'Token inválido o expirado:', error.message);
      this.clearSession();
      return false;
    }
  }

  static async login(formIdOrCredentials) {
    try {
      ogLogger.info('core:auth', 'Iniciando login...');
      const { form, api, cache, toast } = this.getModules();

      let credentials;
      if (typeof formIdOrCredentials === 'string') {
        const validation = form.validate(formIdOrCredentials);
        if (!validation.success) {
          ogToast.error(validation.message);
          return { success: false, error: validation.message };
        }
        credentials = validation.data;
      } else {
        credentials = formIdOrCredentials;
      }

      const response = await api.post(this.config.api.login, credentials, { skipAuth: true });

      if (response.success && response.data) {
        const { token, user, ttl_ms } = response.data;

        if (!token || !user) {
          ogLogger.error('core:auth', 'Respuesta incompleta del servidor');
          return {
            success: false,
            error: __('core.auth.error.server_response')
          };
        }

        cache.setLocal(`${this.config.storageKey}_token`, token, ttl_ms);
        cache.setLocal(`${this.config.storageKey}_user`, user, ttl_ms);

        this.user = user;

        ogLogger.success('core:auth', `Login exitoso para: ${user.user}`);
        ogLogger.info('core:auth', `Token expira en: ${Math.round(ttl_ms / 1000 / 60)} minutos`);

        this.normalizeConfig();
        this.loadUserPermissions();
        await this.showApp();
        this.startSessionMonitoring();

        return { success: true, user, token, ttl_ms };
      }

      ogLogger.warn('core:auth', 'Credenciales incorrectas');
      return {
        success: false,
        error: response.error || __('core.auth.error.invalid_credentials')
      };

    } catch (error) {
      ogLogger.error('core:auth', 'Error en login:', error.message);
      return {
        success: false,
        error: __('core.auth.error.connection')
      };
    }
  }

  static async logout() {
    this.stopSessionMonitoring();

    const token = this.getToken();

    if (token) {
      try {
        const { api } = this.getModules();
        await api.post(this.config.api.logout);
        ogLogger.success('core:auth', 'Logout en backend exitoso');
      } catch (error) {
        ogLogger.warn('core:auth', 'Error en logout:', error.message);
      }
    }

    this.clearAppCaches();
    this.clearSession();
    this.user = null;

    ogLogger.info('core:auth', 'Sesión cerrada');

    window.location.reload();
  }

  // ============================================
  // MANEJO DEL FORMULARIO DE LOGIN
  // ============================================

  static setupLoginHandler() {
    const { events } = this.getModules();

    if (!events) {
      ogLogger.error('core:auth', 'events no está cargado');
      return;
    }

    if (this._loginHandlerRegistered) return;
    this._loginHandlerRegistered = true;

    events.on('form[data-form-id*="login-form"]', 'submit', async function(e) {
      e.preventDefault();
      e.stopPropagation();
      e.stopImmediatePropagation();

      const form = this;
      const formData = new FormData(form);
      const data = Object.fromEntries(formData);

      if (!data.user || !data.pass) {
        ogAuth.showLoginError(form, __('core.auth.error.required_fields'));
        return;
      }

      const btn = form.querySelector('button[type="submit"]');

      if (btn) {
        btn.disabled = true;
        btn.textContent = __('core.auth.login.loading');
      }

      const result = await ogAuth.login(data);

      if (btn) {
        btn.disabled = false;
        btn.textContent = __('core.auth.login.submit_text');
      }

      if (!result.success) {
        ogAuth.showLoginError(form, result.error || __('core.auth.error.login_failed'));
      }
    }, document);

    ogLogger.info('core:auth', 'Handler de login registrado');
  }

  static showLoginError(form, message) {
    let error = form.querySelector('.form-error');

    if (!error) {
      error = document.createElement('div');
      error.className = 'form-error';
      form.insertBefore(error, form.firstChild);
    }

    error.innerHTML = `
      <div style="background: #f8d7da; color: #721c24; padding: 12px; border-radius: 4px; border: 1px solid #f5c6cb; margin-bottom: 1rem;">
        ⚠️ ${message}
      </div>
    `;

    setTimeout(() => error.remove(), 5000);
  }

  // ============================================
  // SESIÓN
  // ============================================

  static getToken() {
    const { cache } = this.getModules();
    return cache.getLocal(`${this.config.storageKey}_token`);
  }

  static async getUser() {
    const { cache } = this.getModules();
    return cache.getLocal(`${this.config.storageKey}_user`);
  }

  static clearSession() {
    const { cache } = this.getModules();
    cache.delete(`${this.config.storageKey}_token`);
    cache.delete(`${this.config.storageKey}_user`);
  }

  static isAuthenticated() {
    return !!this.user && !!this.getToken();
  }

  // ============================================
  // MONITOREO DE SESIÓN
  // ============================================

  static startSessionMonitoring() {
    if (this.sessionCheckInterval) {
      clearInterval(this.sessionCheckInterval);
    }

    const intervalSeconds = Math.round(this.config.sessionCheckInterval / 1000);
    const endpoint = this.config.api.me;

    ogLogger.info('core:auth', `⏱️ Iniciando monitoreo de sesión cada ${intervalSeconds} segundos`);
    ogLogger.debug('core:auth', `Endpoint de verificación: ${endpoint}`);

    this.sessionCheckInterval = setInterval(async () => {
      await this.checkSessionWithServer();
    }, this.config.sessionCheckInterval);
  }

  static stopSessionMonitoring() {
    if (this.sessionCheckInterval) {
      clearInterval(this.sessionCheckInterval);
      this.sessionCheckInterval = null;
      ogLogger.info('core:auth', '⏱️ Monitoreo de sesión detenido');
    }
  }

  static async checkSessionWithServer() {
    const token = this.getToken();

    if (!token) {
      ogLogger.warn('core:auth', '🔐 Token no encontrado en verificación periódica');
      this.handleSessionExpired();
      return;
    }

    try {
      const { api } = this.getModules();
      const response = await api.get(this.config.api.me);

      if (response.success && response.data) {
        ogLogger.debug('core:auth', '✅ Sesión válida (verificación periódica)');
      } else {
        ogLogger.warn('core:auth', '⚠️ Respuesta inesperada en verificación de sesión');
        this.handleSessionExpired();
      }

    } catch (error) {
      if (error.message.includes('401') || error.message.includes('Token')) {
        ogLogger.warn('core:auth', '🔐 Sesión expirada detectada en verificación periódica');
        this.handleSessionExpired();
      } else {
        ogLogger.error('core:auth', 'Error en verificación de sesión:', error.message);
      }
    }
  }

  static handleSessionExpired() {
    this.stopSessionMonitoring();
    this.clearSession();
    this.user = null;
    const { toast } = this.getModules();
    if (toast) {
      ogToast.warning(__('core.auth.session_expired'));
    }

    ogLogger.warn('core:auth', '⚠️ Sesión expirada, redirigiendo al login...');

    setTimeout(() => {
      window.location.reload();
    }, 1500);
  }

  // ============================================
  // PERMISOS
  // ============================================

  static loadUserPermissions() {
    console.log(`this.user.config:`, this.user.config);
    if (!this.user || !this.user.config) {
      ogLogger.warn('core:auth', 'No hay configuración de usuario');
      return;
    }

    ogLogger.info('core:auth', '🔐 Iniciando carga de permisos del usuario...');
    ogLogger.info('core:auth', '👤 Usuario:', this.user.user, '| Role:', this.user.role);

    const config = this.user.config;
    this.userPermissions = config.permissions || {};
    this.userPreferences = config.preferences || {};

    ogLogger.success('core:auth', '✅ Permisos cargados exitosamente');

    if (this.userPermissions.extensions) {
      const extensionsWithPerms = Object.keys(this.userPermissions.extensions);
      ogLogger.info('core:auth', `📋 Extensions con permisos: [${extensionsWithPerms.map(p => `"${p}"`).join(', ')}]`);
    }

    this.filterExtensionsByPermissions();
  }

  static filterExtensionsByPermissions() {
    const { hook } = this.getModules();
    if (this.user?.role === 'admin') {
      ogLogger.info('core:auth', '👑 Usuario admin detectado - sin filtrado de permisos');
      return;
    }

    if (!hook || !hook.pluginRegistry) {
      ogLogger.warn('core:auth', 'hook.pluginRegistry no disponible');
      return;
    }

    const permissions = this.userPermissions?.extensions || {};

    ogLogger.info('core:auth', '🔍 Iniciando filtrado de extensions por permisos...');

    for (const [extensionName, pluginConfig] of hook.pluginRegistry) {
      const extensionPerms = permissions[extensionName];

      if (!extensionPerms || extensionPerms.enabled === false) {
        pluginConfig.enabled = false;
        ogLogger.warn('core:auth', `❌ Extension deshabilitado: ${extensionName}`);
        continue;
      }

      ogLogger.success('core:auth', `✅ Extension habilitado: ${extensionName}`);

      if (!pluginConfig.hasMenu || !pluginConfig.menu) continue;

      const menuPerms = extensionPerms.menus;

      if (menuPerms === '*') {
        ogLogger.info('core:auth', `  ✨ Acceso total a menús de: ${extensionName}`);
        continue;
      }

      if (!menuPerms || typeof menuPerms !== 'object') {
        pluginConfig.menu.items = [];
        ogLogger.warn('core:auth', `  ⚠️ Sin permisos de menús para: ${extensionName}`);
        continue;
      }

      const originalMenus = [...(pluginConfig.menu.items || [])];
      ogLogger.info('core:auth', `  📂 Menús ANTES del filtrado (${originalMenus.length}): [${originalMenus.map(m => `"${m.id}"`).join(', ')}]`);

      const allowedMenuIds = Object.keys(menuPerms).filter(key => {
        const menuPerm = menuPerms[key];
        if (menuPerm === true) return true;
        if (typeof menuPerm === 'object' && menuPerm.enabled === true) return true;
        return false;
      });

      ogLogger.info('core:auth', `  ✅ Menús permitidos para ${extensionName}: [${allowedMenuIds.map(m => `"${m}"`).join(', ')}]`);

      const filteredMenus = originalMenus.filter(menu => {
        const isAllowed = allowedMenuIds.includes(menu.id);
        if (isAllowed) {
          ogLogger.success('core:auth', `    ✅ Menú "${menu.id}" permitido`);
        } else {
          ogLogger.warn('core:auth', `    ❌ Menú "${menu.id}" bloqueado`);
        }
        return isAllowed;
      });

      pluginConfig.menu.items = filteredMenus;

      ogLogger.info('core:auth', `  📊 Filtrado completado: ${originalMenus.length} → ${filteredMenus.length} menús`);
      ogLogger.info('core:auth', `  📂 Menús DESPUÉS del filtrado: [${filteredMenus.map(m => `"${m.id}"`).join(', ')}]`);
    }

    ogLogger.success('core:auth', '📊 RESUMEN DEL FILTRADO DE EXTENSIONS:');
    for (const [extensionName, pluginConfig] of hook.pluginRegistry) {
      if (pluginConfig.enabled && pluginConfig.hasMenu) {
        const menuCount = pluginConfig.menu.items?.length || 0;
        ogLogger.success('core:auth', `  ✅ ${extensionName}: ${menuCount} menú${menuCount !== 1 ? 's' : ''}`);
      } else if (!pluginConfig.enabled) {
        ogLogger.warn('core:auth', `  ❌ ${extensionName}: deshabilitado`);
      }
    }

    ogLogger.success('core:auth', '✅ Filtrado de extensions completado');
  }

  static getTabPermissions(menuId) {
    if (!this.userPermissions?.extensions) return null;

    for (const extensionName in this.userPermissions.extensions) {
      const extension = this.userPermissions.extensions[extensionName];

      if (extension.menus && extension.menus[menuId]) {
        const menuPerm = extension.menus[menuId];

        if (menuPerm === true) return '*';
        if (typeof menuPerm === 'object' && menuPerm.tabs) {
          return menuPerm.tabs;
        }
      }
    }

    return null;
  }

  static normalizeConfig() {
    if (!this.user || !this.user.config) return;

    if (typeof this.user.config === 'string') {
      try {
        this.user.config = JSON.parse(this.user.config);
      } catch (e) {
        ogLogger.error('core:auth', 'Error parseando config:', e);
        this.user.config = { permissions: {}, preferences: {} };
      }
    }
  }

  // ============================================
  // UI
  // ============================================

  static showLogin() {
    const { layout, view } = this.getModules();

    if (layout) {
      layout.init('auth');
    }

    document.body.setAttribute('data-view', 'login-view');

    if (view) {
      view.loadView(this.config.loginView);
    }
  }

  static async showApp() {
    const layoutExists = document.querySelector('.layout .header');
    const { layout, hook, view, sidebar } = this.getModules();

    if (!layoutExists && layout) {
      layout.init('app');
    }

    document.body.setAttribute('data-view', 'app-view');

    if (hook?.loadPluginHooks) {
      ogLogger.info('core:auth', 'Cargando extensions...');
      await hook.loadPluginHooks();

      if (view && hook.getEnabledExtensions) {
        const enabledExtensions = hook.getEnabledExtensions();
        view.loadedExtensions = {};

        for (const extension of enabledExtensions) {
          view.loadedExtensions[extension.name] = true;
        }
      }

      this.filterExtensionsByPermissions();

      ogLogger.success('core:auth', 'Extensions cargados y filtrados');
    }

    if (sidebar) {
      await sidebar.init();
    }

    if (view) {
      const viewToLoad = this.config.redirectAfterLogin || 'dashboard';
      view.loadView(viewToLoad);
    }
  }

  static clearAppCaches() {
    ogLogger.info('core:auth', 'Limpiando caches de aplicación...');
    const { view, form, hook, sidebar, cache } = this.getModules();

    if (view) {
      if (view.viewNavigationCache) view.viewNavigationCache.clear();
      view.views = {};
      view.loadedExtensions = {};
    }

    if (form) {
      if (form.schemas) form.schemas.clear();
      if (form.registeredEvents) form.registeredEvents.clear();
    }

    if (hook) {
      hook.menuItems = [];
      hook.pluginRegistry = new Map();
      hook.loadedHooks = new Set();
    }

    if (sidebar) {
      sidebar.menuItems = [];
    }

    if (cache) {
      cache.clear();
      ogLogger.info('core:auth', 'Cache de localStorage limpiado');
    }

    ogLogger.success('core:auth', 'Caches de aplicación limpiados');
  }

  static async reloadAppAfterPermissionChange() {
    ogLogger.info('core:auth', 'Recargando aplicación con nuevos permisos...');
    const { hook, view, sidebar } = this.getModules();

    if (hook?.loadPluginHooks) {
      await hook.loadPluginHooks();

      if (view && hook.getEnabledExtensions) {
        const enabledExtensions = hook.getEnabledExtensions();
        view.loadedExtensions = {};

        for (const extension of enabledExtensions) {
          view.loadedExtensions[extension.name] = true;
        }
      }
    }

    this.filterExtensionsByPermissions();

    if (sidebar) {
      await sidebar.init();
    }

    ogLogger.success('core:auth', 'Aplicación recargada con nuevos permisos');
  }
}

// Mantener en window para compatibilidad
window.ogAuth = ogAuth;

// Registrar en ogFramework (preferido)
if (typeof window.ogFramework !== 'undefined') {
  window.ogFramework.core.auth = ogAuth;
}