class auth {
  static config = {};
  static user = null;
  static userPermissions = null;
  static userPreferences = null;
  static sessionCheckInterval = null;

  // ============================================
  // INICIALIZACIÓN
  // ============================================

  static async init(config) {
    this.config = {
      enabled: true,
      loginView: 'auth/login',
      redirectAfterLogin: 'dashboard',
      storageKey: 'factory_auth',
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

    logger.debug('core:auth', 'Inicializando autenticación...');

    // Interceptar formulario de login
    this.setupLoginHandler();

    // Verificar si hay sesión válida
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
      logger.debug('core:auth', 'No hay token guardado');
      return false;
    }

    // Verificar si el token está expirado localmente
    if (cache.isExpired(`${this.config.storageKey}_token`)) {
      logger.warn('core:auth', 'Token expirado en cache local');
      this.clearSession();
      return false;
    }

    try {
      const response = await api.get(this.config.api.me);

      if (response.success && response.data) {
        // Actualizar usuario en cache
        cache.setLocal(`${this.config.storageKey}_user`, response.data, this.config.tokenTTL);
        logger.success('core:auth', 'Sesión válida');
        return true;
      }

      logger.warn('core:auth', 'Respuesta inesperada del servidor');
      this.clearSession();
      return false;

    } catch (error) {
      logger.warn('core:auth', 'Token inválido o expirado:', error.message);
      this.clearSession();
      return false;
    }
  }

  static async login(formIdOrCredentials) {
    try {
      logger.debug('core:auth', 'Iniciando login...');

      // Determinar si es formId o credentials
      let credentials;
      if (typeof formIdOrCredentials === 'string') {
        // Es formId, extraer datos del formulario
        const validation = form.validate(formIdOrCredentials);
        if (!validation.success) {
          toast.error(validation.message);
          return { success: false, error: validation.message };
        }
        credentials = validation.data;
      } else {
        // Es un objeto con las credenciales directamente
        credentials = formIdOrCredentials;
      }

      const response = await api.post(this.config.api.login, credentials, { skipAuth: true });

      logger.debug('core:auth', 'Respuesta del servidor:', response);

      if (response.success && response.data) {
        const { token, user, ttl_ms } = response.data;

        if (!token || !user) {
          logger.error('core:auth', 'Respuesta incompleta del servidor');
          return {
            success: false,
            error: __('core.auth.error.server_response')
          };
        }

        // Usar TTL del backend o fallback
        const tokenTTL = ttl_ms || this.config.tokenTTL;

        // Guardar en cache
        cache.setLocal(`${this.config.storageKey}_token`, token, tokenTTL);
        cache.setLocal(`${this.config.storageKey}_user`, user, tokenTTL);

        // Guardar usuario en memoria
        this.user = user;

        logger.success('core:auth', `Login exitoso para: ${user.user}`);
        logger.info('core:auth', `Token expira en: ${Math.round(tokenTTL / 1000 / 60)} minutos`);

        // Cargar permisos y mostrar app
        this.normalizeConfig();
        this.loadUserPermissions();
        await this.showApp();
        this.startSessionMonitoring();

        return { success: true, user, token, ttl_ms: tokenTTL };
      }

      //  toast.error(__('core.auth.error.invalid_credentials'));
      logger.warn('core:auth', 'Credenciales incorrectas');
      return {
        success: false,
        error: response.error || __('core.auth.error.invalid_credentials')
      };

    } catch (error) {
      logger.error('core:auth', 'Error en login:', error.message);
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
        await api.post(this.config.api.logout);
        logger.success('core:auth', 'Logout en backend exitoso');
      } catch (error) {
        logger.warn('core:auth', 'Error en logout:', error.message);
      }
    }

    this.clearAppCaches();
    this.clearSession();
    this.user = null;

    logger.debug('core:auth', 'Sesión cerrada');

    window.location.reload();
  }

  // ============================================
  // MANEJO DEL FORMULARIO DE LOGIN
  // ============================================

  static setupLoginHandler() {
    if (!window.events) {
      logger.error('core:auth', 'events.js no está cargado');
      return;
    }

    events.on('form[data-form-id*="login-form"]', 'submit', async function(e) {
      e.preventDefault();
      e.stopPropagation();
      e.stopImmediatePropagation();

      const form = this;
      const formData = new FormData(form);
      const data = Object.fromEntries(formData);

      // Validar campos requeridos
      if (!data.user || !data.pass) {
        auth.showLoginError(form, __('core.auth.error.required_fields'));
        return;
      }

      const btn = form.querySelector('button[type="submit"]');

      if (btn) {
        btn.disabled = true;
        btn.textContent = __('core.auth.login.loading');
      }

      // Login
      const result = await auth.login(data);

      if (btn) {
        btn.disabled = false;
        btn.textContent = __('core.auth.login.submit_text');
      }

      if (!result.success) {
        logger.warn('core:auth', 'Login falló:', result.error);
        auth.showLoginError(form, result.error || __('core.auth.error.login_failed'));
      }
    }, document);

    logger.debug('core:auth', 'Handler de login registrado');
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
    return cache.getLocal(`${this.config.storageKey}_token`);
  }

  static async getUser() {
    return cache.getLocal(`${this.config.storageKey}_user`);
  }

  static clearSession() {
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

    logger.info('core:auth', `⏱️ Iniciando monitoreo de sesión cada ${intervalSeconds} segundos`);
    logger.info('core:auth', `📡 Endpoint de verificación: ${endpoint}`);

    this.sessionCheckInterval = setInterval(async () => {
      logger.debug('core:auth', '🔍 Verificando sesión en servidor...');
      const result = await this.checkSessionWithServer();

      if (!result.valid) {
        logger.warn('core:auth', 'Sesión inválida detectada');
        this.handleExpiredSession();
        return;
      }

      logger.debug('core:auth', '✅ Sesión válida');

      if (result.updated) {
        logger.info('core:auth', '🔄 Cambios detectados en la sesión, recargando permisos...');

        this.user = result.user;
        this.clearAppCaches();
        this.loadUserPermissions();
        await this.reloadAppAfterPermissionChange();

        // ✅ Firma correcta: toast.show(message, options)
        toast.show('✅ Tus permisos han sido actualizados', {
          type: 'success',
          duration: 3000
        });
      }
    }, this.config.sessionCheckInterval);
  }

  static stopSessionMonitoring() {
    if (this.sessionCheckInterval) {
      clearInterval(this.sessionCheckInterval);
      this.sessionCheckInterval = null;
      logger.debug('core:auth', 'Monitoreo de sesión detenido');
    }
  }

  static async checkSessionWithServer() {
    try {
      const endpoint = this.config.api.me;
      const response = await api.get(endpoint);

      if (response.success && response.data) {
        return {
          valid: true,
          updated: false,
          user: response.data,
          expiresIn: null
        };
      }

      logger.warn('core:auth', 'Respuesta inesperada del servidor:', response);
      return { valid: false };
    } catch (error) {
      if (error.status === 401 || error.response?.status === 401) {
        logger.warn('core:auth', '❌ Sesión inválida (401 Unauthorized)');
        return { valid: false };
      }

      logger.error('core:auth', 'Error verificando sesión:', {
        message: error.message,
        status: error.status
      });

      return { valid: true };
    }
  }

  static handleExpiredSession() {
    this.stopSessionMonitoring();

    if (window.toast) {
      const message = __('core.auth.session.expired');

      toast.show(message, {
        type: 'warning',
        duration: 5000
      });

      logger.warn('core:auth', message);
    }

    setTimeout(() => {
      this.clearAppCaches();
      this.user = null;
      this.clearSession();
      this.showLogin();
    }, 2000);
  }

  // ============================================
  // PERMISOS
  // ============================================

  static loadUserPermissions() {
    if (!this.user || !this.user.config) {
      logger.warn('core:auth', 'No hay configuración de usuario');
      return;
    }

    logger.info('core:auth', '🔐 Iniciando carga de permisos del usuario...');
    logger.debug('core:auth', '👤 Usuario:', this.user.user, '| Role:', this.user.role);

    const config = this.user.config;
    this.userPermissions = config.permissions || {};
    this.userPreferences = config.preferences || {};

    logger.success('core:auth', '✅ Permisos cargados exitosamente');
    logger.debug('core:auth', '📋 Config original (tipo):', typeof config);

    if (this.userPermissions.extensions) {
      const extensionsWithPerms = Object.keys(this.userPermissions.extensions);
      logger.info('core:auth', `📋 Extensions con permisos: [${extensionsWithPerms.map(p => `"${p}"`).join(', ')}]`);
    }

    this.filterExtensionsByPermissions();
  }

  static filterExtensionsByPermissions() {
      // ✅ Si es admin, NO filtrar nada
      if (this.user?.role === 'admin') {
        logger.info('core:auth', '👑 Usuario admin detectado - sin filtrado de permisos');
        return;
      }

      if (!window.hook || !hook.pluginRegistry) {
        logger.warn('core:auth', 'hook.pluginRegistry no disponible');
        return;
      }

      const permissions = this.userPermissions?.extensions || {};

      logger.info('core:auth', '🔍 Iniciando filtrado de extensions por permisos...');

      for (const [extensionName, pluginConfig] of hook.pluginRegistry) {
        const extensionPerms = permissions[extensionName];

        if (!extensionPerms || extensionPerms.enabled === false) {
          pluginConfig.enabled = false;
          logger.warn('core:auth', `❌ Extension deshabilitado: ${extensionName}`);
          continue;
        }

        logger.success('core:auth', `✅ Extension habilitado: ${extensionName}`);

        if (!pluginConfig.hasMenu || !pluginConfig.menu) continue;

        const menuPerms = extensionPerms.menus;

        if (menuPerms === '*') {
          logger.info('core:auth', `  ✨ Acceso total a menús de: ${extensionName}`);
          continue;
        }

        if (!menuPerms || typeof menuPerms !== 'object') {
          pluginConfig.menu.items = [];
          logger.warn('core:auth', `  ⚠️ Sin permisos de menús para: ${extensionName}`);
          continue;
        }

        const originalMenus = [...(pluginConfig.menu.items || [])];
        logger.info('core:auth', `  📂 Menús ANTES del filtrado (${originalMenus.length}): [${originalMenus.map(m => `"${m.id}"`).join(', ')}]`);

        const allowedMenuIds = Object.keys(menuPerms).filter(key => {
          const menuPerm = menuPerms[key];
          if (menuPerm === true) return true;
          if (typeof menuPerm === 'object' && menuPerm.enabled === true) return true;
          return false;
        });

        logger.info('core:auth', `  ✅ Menús permitidos para ${extensionName}: [${allowedMenuIds.map(m => `"${m}"`).join(', ')}]`);

        const filteredMenus = originalMenus.filter(menu => {
          const isAllowed = allowedMenuIds.includes(menu.id);
          if (isAllowed) {
            logger.success('core:auth', `    ✅ Menú "${menu.id}" permitido`);
          } else {
            logger.warn('core:auth', `    ❌ Menú "${menu.id}" bloqueado`);
          }
          return isAllowed;
        });

        pluginConfig.menu.items = filteredMenus;

        logger.info('core:auth', `  📊 Filtrado completado: ${originalMenus.length} → ${filteredMenus.length} menús`);
        logger.info('core:auth', `  📂 Menús DESPUÉS del filtrado: [${filteredMenus.map(m => `"${m.id}"`).join(', ')}]`);
      }

      logger.success('core:auth', '📊 RESUMEN DEL FILTRADO DE EXTENSIONS:');
      for (const [extensionName, pluginConfig] of hook.pluginRegistry) {
        if (pluginConfig.enabled && pluginConfig.hasMenu) {
          const menuCount = pluginConfig.menu.items?.length || 0;
          logger.success('core:auth', `  ✅ ${extensionName}: ${menuCount} menú${menuCount !== 1 ? 's' : ''}`);
        } else if (!pluginConfig.enabled) {
          logger.warn('core:auth', `  ❌ ${extensionName}: deshabilitado`);
        }
      }

      logger.success('core:auth', '✅ Filtrado de extensions completado');
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
        logger.debug('core:auth', 'Config parseado de string a objeto');
      } catch (e) {
        logger.error('core:auth', 'Error parseando config:', e);
        this.user.config = { permissions: {}, preferences: {} };
      }
    }
  }

  // ============================================
  // UI
  // ============================================

  static showLogin() {
    if (window.layout) {
      layout.init('auth');
    }

    document.body.setAttribute('data-view', 'login-view');

    if (window.view) {
      view.loadView(this.config.loginView);
    }
  }

  static async showApp() {
    const layoutExists = document.querySelector('.layout .header');

    if (!layoutExists && window.layout) {
      layout.init('app');
    }

    document.body.setAttribute('data-view', 'app-view');

    // ✅ CARGAR EXTENSIONS ANTES DEL SIDEBAR
    if (window.hook?.loadPluginHooks) {
      logger.info('core:auth', 'Cargando extensions...');
      await hook.loadPluginHooks();

      // Registrar extensions cargados en view
      if (window.view && hook.getEnabledExtensions) {
        const enabledExtensions = hook.getEnabledExtensions();
        view.loadedExtensions = {};

        for (const extension of enabledExtensions) {
          view.loadedExtensions[extension.name] = true;
        }
      }

      // Filtrar por permisos
      this.filterExtensionsByPermissions();

      logger.success('core:auth', 'Extensions cargados y filtrados');
    }

    // ✅ AHORA SÍ INICIALIZAR SIDEBAR (con menús disponibles)
    if (window.sidebar) {
      await sidebar.init();
    }

    if (window.view) {
      const viewToLoad = this.config.redirectAfterLogin || 'dashboard';
      view.loadView(viewToLoad);
    }
  }

  static clearAppCaches() {
    logger.info('core:auth', 'Limpiando caches de aplicación...');

    if (window.view) {
      if (view.viewNavigationCache) view.viewNavigationCache.clear();
      view.views = {};
      view.loadedExtensions = {};
    }

    if (window.form) {
      if (form.schemas) form.schemas.clear();
      if (form.registeredEvents) form.registeredEvents.clear();
    }

    if (window.hook) {
      hook.menuItems = [];
      hook.pluginRegistry = new Map();
      hook.loadedHooks = new Set();
    }

    if (window.sidebar) {
      sidebar.menuItems = [];
    }

    // Limpiar cache de localStorage (vistas, formularios, etc)
    if (window.cache) {
      cache.clear();
      logger.debug('core:auth', 'Cache de localStorage limpiado');
    }

    logger.success('core:auth', 'Caches de aplicación limpiados');
  }

  static async reloadAppAfterPermissionChange() {
    logger.info('core:auth', 'Recargando aplicación con nuevos permisos...');

    if (window.hook?.loadPluginHooks) {
      await hook.loadPluginHooks();

      if (window.view && hook.getEnabledExtensions) {
        const enabledExtensions = hook.getEnabledExtensions();
        view.loadedExtensions = {};

        for (const extension of enabledExtensions) {
          view.loadedExtensions[extension.name] = true;
        }
      }
    }

    this.filterExtensionsByPermissions();

    if (window.sidebar) {
      await sidebar.init();
    }

    logger.success('core:auth', 'Aplicación recargada con nuevos permisos');
  }
}

window.auth = auth;