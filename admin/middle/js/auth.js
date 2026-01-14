class ogAuth {
  static config = {};
  static user = null;
  static userPermissions = null;
  static userPreferences = null;
  static sessionCheckInterval = null;

  static getConfig() {
    return window.ogFramework?.activeConfig || window.appConfig || {};
  }

  static async init() {
    const config = this.getConfig();

    this.config = {
      enabled: true,
      loginView: 'middle:auth/login',
      redirectAfterLogin: 'middle:dashboard/dashboard',
      storageKey: 'auth',
      sessionCheckInterval: 5 * 60 * 1000,
      tokenTTL: 24 * 60 * 60 * 1000,
      api: {
        login: '/api/auth/login',
        logout: '/api/auth/logout',
        me: '/api/auth/profile'
      },
      ...config.auth  // ← Tomar la configuración de auth del config global
    };

    if (!this.config.enabled) {
      ogLogger.warn('core:auth', 'Auth deshabilitado en configuración');
      return;
    }

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
      ogLogger.info('core:auth', 'No hay sesión activa - mostrando login');
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
      const api = ogModule('api');
      const response = await api.get(this.config.api.me);

      if (response.success && response.data) {
        const cache = ogModule('cache');
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
      const form = ogModule('form');
      const api = ogModule('api');
      const cache = ogModule('cache');
      const toast = ogComponent('toast');

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

      console.log(`credentials:`, credentials);
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

        ogLogger?.debug('core:auth', '🔍 Token guardado:', {
          storageKey: this.config.storageKey,
          key: `${this.config.storageKey}_token`,
          ttl_ms,
          cachePrefix: cache.getPrefix ? cache.getPrefix() : 'N/A'
        });

        this.user = user;

        ogLogger.success('core:auth', `Login exitoso para: ${user.user}`);
        ogLogger.info('core:auth', `Token expira en: ${Math.round(ttl_ms / 1000 / 60)} minutos`);

        this.normalizeConfig();
        this.loadUserPermissions();
        this.startSessionMonitoring();
        
        // Recargar la página para que og-framework inicie todo correctamente
        ogLogger.info('core:auth', 'Recargando página para inicializar aplicación...');
        window.location.reload();

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
        const api = ogModule('api');
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

    const events = ogModule('events');
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
    const cache = ogModule('cache');
    const key = `${this.config.storageKey}_token`;
    const token = cache.getLocal(key);

    return token;
  }

  static async getUser() {
    const cache = ogModule('cache');
    return cache.getLocal(`${this.config.storageKey}_user`);
  }

  static clearSession() {
    const cache = ogModule('cache');
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
      ogLogger?.debug('core:auth', '🔄 Limpiando interval anterior');
    }

    const intervalMs = this.config.sessionCheckInterval;
    const intervalSeconds = Math.round(intervalMs / 1000);
    const endpoint = this.config.api.me;

    ogLogger.info('core:auth', `⏱️ Iniciando monitoreo de sesión cada ${intervalSeconds} segundos (${intervalMs}ms)`);
    ogLogger.info('core:auth', `Endpoint de verificación: ${endpoint}`);

    this.sessionCheckInterval = setInterval(async () => {
      ogLogger?.debug('core:auth', `🔍 Ejecutando verificación periódica (cada ${intervalSeconds}s)`);
      await this.checkSessionWithServer();
    }, intervalMs);

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
      ogComponent('toast')?.warning(__('🔐 Token no encontrado en verificación periódica'));
      this.handleSessionExpired();
      return;
    }

    try {
      const api = ogModule('api');
      const response = await api.get(this.config.api.me);

      if (response.success && response.data) {
        ogLogger.debug('core:auth', '✅ Sesión válida (verificación periódica)');
      } else {
        ogLogger.warn('core:auth', '⚠️ Respuesta inesperada en verificación de sesión');
        ogComponent('toast')?.warning(__('Respuesta inesperada en verificación de sesión'));
        this.handleSessionExpired();
      }

    } catch (error) {
      if (error.message.includes('401') || error.message.includes('Token')) {
        ogLogger.warn('core:auth', '🔐 Sesión expirada detectada en verificación periódica');
        ogComponent('toast')?.warning(__('🔐 Sesión expirada detectada en verificación periódica'));
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
    const toast = ogComponent('toast');
    if (toast && typeof toast.warning === 'function') {
      toast.warning(__('core.auth.session.expired'));
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
    if (!this.user || !this.user.config) {
      ogLogger.warn('core:auth', 'No hay configuración de usuario');
      return;
    }

    ogLogger.info('core:auth', '🔐 Iniciando carga de permisos del usuario...');
    ogLogger.info('core:auth', '👤 Usuario:', this.user.user, '| Role:', this.user.role);

    const config = this.user.config;
    
    // ✅ NORMALIZACIÓN: Soportar tanto 'plugins' como 'extensions'
    const permissions = config.permissions || {};
    
    if (permissions.plugins && !permissions.extensions) {
      ogLogger.info('core:auth', '🔄 Normalizando permissions.plugins → permissions.extensions');
      permissions.extensions = permissions.plugins;
      delete permissions.plugins;
    }
    
    this.userPermissions = permissions;
    this.userPreferences = config.preferences || {};

    ogLogger.success('core:auth', '✅ Permisos cargados exitosamente');

    if (this.userPermissions.extensions) {
      const extensionsWithPerms = Object.keys(this.userPermissions.extensions);
      ogLogger.info('core:auth', `📋 Extensions con permisos: [${extensionsWithPerms.map(p => `"${p}"`).join(', ')}]`);
    }

    this.filterExtensionsByPermissions();
  }

  static filterExtensionsByPermissions() {
    const hook = ogModule('hook');
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
    const layout = ogModule('layout');
    const view = ogModule('view');

    if (layout) {
      layout.init('auth');
    }

    document.body.setAttribute('data-view', 'login-view');

    if (view) {
      view.loadView('middle:auth/login');
    }
  }

  static async showApp() {
    // Este método es llamado DURANTE la inicialización por og-framework.js
    // Solo debe preparar el estado, NO inicializar layout/sidebar/view
    // og-framework.js se encarga de todo eso después
    
    ogLogger?.info('core:auth', '✅ Usuario autenticado - preparando app...');
    
    // Solo establecer el atributo del body
    document.body.setAttribute('data-view', 'app-view');
    
    // NO hacer nada más aquí
    // og-framework.js llamará a:
    // - layout.init()
    // - hook.loadPluginHooks()
    // - sidebar.init()
    // - view.loadView(config.defaultView)
  }

  static clearAppCaches() {
    ogLogger.info('core:auth', 'Limpiando caches de aplicación...');
    const view = ogModule('view');
    const form = ogModule('form');
    const hook = ogModule('hook');
    const sidebar = ogModule('sidebar');
    const cache = ogModule('cache');

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
    const hook = ogModule('hook');
    const view = ogModule('view');
    const sidebar = ogModule('sidebar');

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

  // Inyectar botón de logout en sidebar footer (usado por trigger system)
  static injectLogoutButton(target) {
    const config = window.ogFramework?.activeConfig || window.appConfig || {};
    
    // Si auth está deshabilitado, no inyectar nada
    if (!config.auth?.enabled) {
      ogLogger?.info('core:auth', 'Auth deshabilitado - no se inyecta logout');
      return;
    }

    const user = this.user;
    const userName = user?.user || user?.email || __('core.sidebar.user_default');

    const html = `
      <div class="sidebar-user">
        <span class="user-icon">👤</span>
        <span class="user-name">${userName}</span>
      </div>
      <button class="btn btn-logout" id="btn-logout">
        <span class="logout-icon">🚪</span>
        <span class="logout-text">${__('core.sidebar.logout')}</span>
      </button>
    `;

    const sidebar = ogModule('sidebar');
    if (sidebar && sidebar.inject) {
      const zone = target.split(':')[1]; // Extraer 'footer' de 'sidebar:footer'
      sidebar.inject(zone, html);

      // Bind evento al botón
      setTimeout(() => {
        const logoutBtn = document.getElementById('btn-logout');
        if (logoutBtn) {
          logoutBtn.addEventListener('click', async () => {
            const confirmed = confirm(__('core.sidebar.logout_confirm'));
            if (confirmed) {
              await this.logout();
            }
          });
          ogLogger?.success('core:auth', 'Botón de logout inyectado y enlazado');
        }
      }, 0);
    }
  }
}

// Mantener en window para compatibilidad
window.ogAuth = ogAuth;

// Registrar en ogFramework (preferido)
if (typeof window.ogFramework !== 'undefined') {
  window.ogFramework.core.auth = ogAuth;
}

// Registrar trigger para inyectar logout button
if (typeof window.ogTrigger !== 'undefined') {
  ogTrigger.register('sidebar:footer', 'ogAuth', 'injectLogoutButton');
}