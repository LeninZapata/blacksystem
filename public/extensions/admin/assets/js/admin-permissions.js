class adminPermissions {
  // Inicializar permisos vacíos (nuevo usuario)
  static init(formId) {
    const config = {
      permissions: { extensions: {} },
      preferences: { theme: 'light', language: 'es', notifications: true }
    };
    setTimeout(() => this.render(config), 100);
  }

  // Cargar permisos existentes (editar)
  static load(formId, userData) {
    const config = typeof userData.config === 'string'
      ? JSON.parse(userData.config)
      : (userData.config || {});

    const normalized = {
      permissions: config.permissions || { extensions: {} },
      preferences: config.preferences || { theme: 'light', language: 'es', notifications: true }
    };

    setTimeout(() => this.render(normalized), 200);
  }

  // Renderizar componente
  static render(config) {
    const container = document.getElementById('permissions-container');
    if (!container || !window.permissions) return;

    // ✅ Obtener TODOS los extensions disponibles (sin filtrar por permisos del usuario actual)
    const allExtensions = this.getallExtensions();

    ogLogger.debug('ext:admin-permissions', `Renderizando selector con ${allExtensions.length} extensions`);

    permissions.render('permissions-container', config, allExtensions);
  }

  // Obtener TODOS los extensions disponibles en el sistema
  static getallExtensions() {
    // Usar método especial que retorna la copia original sin filtrar
    ;
    if (window.ogFramework?.core?.hook?.getallExtensionsForPermissions) {
      return hook.getallExtensionsForPermissions();
    }

    // Fallback: Si no existe el método, intentar con pluginRegistry normal
    if (!window.ogFramework?.core?.hook?.pluginRegistry) {
      ogLogger.warn('ext:admin-permissions', 'hook.pluginRegistry no disponible');
      return [];
    }

    const extensions = [];
    for (const [name, config] of window.ogFramework?.core?.hook?.pluginRegistry) {
      extensions.push({
        name,
        hasMenu: config.hasMenu || false,
        hasViews: config.hasViews || false,
        menu: config.menu || null,
        description: config.description || ''
      });
    }

    return extensions;
  }

  // Obtener datos del selector
  static getData() {
    const selector = document.querySelector('.permissions-selector');
    if (!selector?.id) return { permissions: { extensions: {} }};
    const input = document.getElementById(`${selector.id}-data`);
    return input?.value ? JSON.parse(input.value) : { permissions: { extensions: {} }};
  }
}

window.adminPermissions = adminPermissions;