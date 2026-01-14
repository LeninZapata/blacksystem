class ogSidebar {
  static menuData = {
    menu: []
  };

  static getConfig() {
    return window.ogFramework?.activeConfig || window.appConfig || {};
  }

  static async init() {
    // Solo cargar el menú, NO cargar la vista
    // og-framework.js se encargará de cargar config.defaultView
    await this.loadMenu();
  }

  static async loadMenu() {
    const hook = ogModule('hook');
    const auth = ogModule('auth');
    
    // Detectar si estamos en WordPress
    const isWordPress = typeof window.wp !== 'undefined' || 
                        (typeof ABSPATH !== 'undefined' && ABSPATH.includes('wp-'));

    try {
      if (hook && typeof hook.getMenuItems === 'function') {
        const pluginMenus = hook.getMenuItems();
        
        ogLogger?.info('core:sidebar', `Menús cargados: ${pluginMenus.length}`);

        // Menú base con prefijo middle:
        const baseMenu = [
          {
            id: "dashboard",
            title: "Dashboard",
            icon: "📊",
            view: "middle:dashboard/dashboard",
            order: 1
          }
        ];

        const allMenuItems = [...baseMenu, ...pluginMenus];
        const uniqueMenuItems = this.removeDuplicateMenus(allMenuItems);

        // Solo filtrar por role si NO es WordPress Y auth está habilitado
        let filteredMenuItems;
        if (isWordPress) {
          ogLogger?.info('core:sidebar', '🔓 WordPress detectado - mostrando todos los menús');
          filteredMenuItems = uniqueMenuItems;
        } else if (auth && auth.config?.enabled) {
          ogLogger?.info('core:sidebar', '🔐 Standalone con auth - filtrando menús por permisos');
          filteredMenuItems = this.filterMenusByRole(uniqueMenuItems);
        } else {
          ogLogger?.info('core:sidebar', '🔓 Auth deshabilitado - mostrando todos los menús');
          filteredMenuItems = uniqueMenuItems;
        }

        this.menuData.menu = filteredMenuItems;

      } else {
        ogLogger?.warn('core:sidebar', 'hook.getMenuItems no disponible, usando menú básico');
        this.menuData.menu = [
          {
            id: "dashboard",
            title: "Dashboard",
            icon: "📊",
            view: "middle:dashboard/dashboard"
          }
        ];
      }

      this.renderMenu();

    } catch (error) {
      ogLogger?.error('core:sidebar', 'Error cargando menú:', error);
      this.menuData.menu = [
        {
          id: "dashboard",
          title: "Dashboard",
          icon: "📊",
          view: "middle:dashboard/dashboard"
        }
      ];
      this.renderMenu();
    }
  }

  static removeDuplicateMenus(menuItems) {
    const seen = new Set();
    return menuItems.filter(item => {
      if (seen.has(item.id)) return false;
      seen.add(item.id);
      return true;
    });
  }

  static renderMenu() {
    const sidebar = document.getElementById('sidebar');
    if (!sidebar) return;

    const header = this.generateHeader();
    const menuHTML = this.generateMenuHtml(this.menuData.menu);
    const footer = this.generateFooter();

    sidebar.innerHTML = header + menuHTML + footer;

    this.bindMenuEvents();

    // Ejecutar triggers después de que el DOM esté listo
    if (window.ogTrigger) {
      ogTrigger.execute('sidebar');
    }
  }

  static generateHeader() {
    // CAMBIAR: .sidebar-header → .og-sidebar-header
    return '<div class="og-sidebar-header"></div>';
  }

  static generateFooter() {
    // CAMBIAR: .sidebar-footer → .og-sidebar-footer
    return '<div class="og-sidebar-footer"></div>';
  }

  // Inyectar contenido en zonas del sidebar
  static inject(zone, html) {
    const validZones = ['header', 'footer', 'content'];
    
    if (!validZones.includes(zone)) {
      ogLogger?.error('core:sidebar', `Zona inválida: ${zone}`);
      return false;
    }

    // CAMBIAR: .sidebar-${zone} → .og-sidebar-${zone}
    const container = document.querySelector(`.og-sidebar-${zone}`);
    
    if (!container) {
      ogLogger?.error('core:sidebar', `Contenedor no encontrado: .og-sidebar-${zone}`);
      return false;
    }

    container.innerHTML += html;
    ogLogger?.info('core:sidebar', `✅ Contenido inyectado en og-sidebar-${zone}`);
    return true;
  }

  static generateMenuHtml(menuItems, level = 0) {
    return menuItems.map(item => {
      const hasSubmenu = item.items && item.items.length > 0;
      const isSubmenu = level > 0;
      
      // CAMBIAR: .menu-item → .og-menu-item
      // CAMBIAR: .has-submenu → .og-has-submenu
      // CAMBIAR: .submenu-item → (ya no existe, solo og-menu-item)
      const itemClass = `og-menu-item ${hasSubmenu ? 'og-has-submenu' : ''} level-${level}`;

      const showIcon = !isSubmenu && item.icon;
      // CAMBIAR: .menu-icon → .og-menu-icon
      const iconHtml = showIcon ? `<span class="og-menu-icon">${item.icon}</span>` : '';

      // CAMBIAR: .menu-title → .og-menu-title
      // CAMBIAR: .menu-arrow → .og-menu-arrow
      return `
        <div class="${itemClass}" data-id="${item.id}" data-level="${level}">
          ${iconHtml}
          <span class="og-menu-title">${item.title}</span>
          ${hasSubmenu ? '<span class="og-menu-arrow"></span>' : ''}
        </div>
        ${hasSubmenu ? `
          <div class="og-submenu level-${level + 1}">
            ${this.generateMenuHtml(item.items, level + 1)}
          </div>
        ` : ''}
      `;
    }).join('');
  }

  static bindMenuEvents() {
    const view = ogModule('view');
    // CAMBIAR: .menu-item → .og-menu-item
    const menuItems = document.querySelectorAll('.og-menu-item');

    menuItems.forEach(item => {
      item.addEventListener('click', async (e) => {
        const menuId = item.dataset.id;
        const level = parseInt(item.dataset.level) || 0;
        const menuData = this.findMenuData(menuId, level);

        if (menuData.items && menuData.items.length > 0) {
          this.toggleSubmenu(item);
          e.stopPropagation();
        } else if (menuData.view) {
          this.setActiveMenu(item);

          const extensionName = this.detectPluginFromMenuId(menuId);

          const menuResources = {
            scripts: menuData.scripts || [],
            styles: menuData.styles || []
          };

          this.preloadSiblingViews(menuId, level, extensionName);

          if (extensionName) {
            view.loadView(menuData.view, null, extensionName, menuResources, null, menuId);
          } else {
            view.loadView(menuData.view, null, null, menuResources, null, menuId);
          }

          if (window.innerWidth <= 1024) {
            document.getElementById('sidebar')?.classList.remove('open');
            document.getElementById('sidebar-overlay')?.classList.remove('active');
          }
        }
      });
    });
  }

  static preloadSiblingViews(currentMenuId, currentLevel, extensionName) {
    const parentMenu = this.findParentMenu(currentMenuId, currentLevel);
    const siblings = parentMenu ? parentMenu.items : this.menuData.menu;

    if (!siblings) return;

    let preloadCount = 0;
    siblings.forEach(sibling => {
      if (sibling.id !== currentMenuId && sibling.preloadViews === true && sibling.view) {
        this.preloadView(sibling.view, extensionName);
        preloadCount++;
      }
    });

    if (preloadCount > 0) {
      // (logging opcional)
    }
  }

  static async preloadView(viewPath, extensionName) {
    const cache = ogModule('cache');
    const config = this.getConfig();
    
    try {
      let basePath, fullPath, cacheKey;

      if (extensionName) {
        basePath = config.routes?.extensionViews?.replace('{extensionName}', extensionName) || `extensions/${extensionName}/views`;
        fullPath = `${config.baseUrl || '/'}${basePath}/${viewPath}.json`;
        cacheKey = `view_${extensionName}_${viewPath.replace(/\//g, '_')}`;
      } else {
        basePath = config.routes?.coreViews || 'js/views';
        fullPath = `${config.baseUrl || '/'}${basePath}/${viewPath}.json`;
        cacheKey = `view_${viewPath.replace(/\//g, '_')}`;
      }

      if (cache?.get(cacheKey)) {
        return;
      }

      const cacheBuster = `?v=${config.version || '1.0.0'}`;
      const response = await fetch(fullPath + cacheBuster);

      if (response.ok) {
        const viewData = await response.json();
        cache?.set(cacheKey, viewData);
      }
    } catch (error) {
      ogLogger?.warn('core:sidebar', `No se pudo precargar: ${extensionName ? extensionName + '/' : ''}${viewPath}`);
    }
  }

  static findParentMenu(menuId, level) {
    if (level === 0) {
      return null;
    }

    const findRecursive = (items, targetId, currentLevel = 0) => {
      for (const item of items) {
        if (item.items && item.items.length > 0) {
          const hasChild = item.items.some(child => child.id === targetId);
          if (hasChild && currentLevel === level - 1) {
            return item;
          }
          const found = findRecursive(item.items, targetId, currentLevel + 1);
          if (found) return found;
        }
      }
      return null;
    };

    return findRecursive(this.menuData.menu, menuId);
  }

  static setActiveMenu(activeItem) {
    // CAMBIAR: .menu-item → .og-menu-item
    document.querySelectorAll('.og-menu-item').forEach(item => item.classList.remove('active'));
    activeItem.classList.add('active');
  }

  static detectPluginFromMenuId(menuId) {
    const hook = ogModule('hook');
    
    ogLogger?.debug('core:sidebar', `🔍 detectPluginFromMenuId: menuId="${menuId}"`);
    
    if (!hook || !hook.pluginRegistry) {
      ogLogger?.warn('core:sidebar', `⚠️ hook no disponible`);
      return null;
    }
    
    // Revisar todas las extensiones registradas
    for (const [extensionName, pluginConfig] of hook.pluginRegistry) {
      ogLogger?.debug('core:sidebar', `🔍 Checking if "${menuId}" starts with "${extensionName}-"`);
      if (menuId.startsWith(`${extensionName}-`)) {
        ogLogger?.success('core:sidebar', `✅ Extension detected: ${extensionName}`);
        return extensionName;
      }
    }

    ogLogger?.warn('core:sidebar', `⚠️ No extension detected for menuId: ${menuId}`);
    return null;
  }

  static findMenuData(menuId, level = 0) {
    const findRecursive = (items, targetId, currentLevel = 0) => {
      for (const item of items) {
        if (item.id === targetId && currentLevel === level) {
          return item;
        }
        if (item.items && item.items.length > 0) {
          const found = findRecursive(item.items, targetId, currentLevel + 1);
          if (found) return found;
        }
      }
      return null;
    };

    return findRecursive(this.menuData.menu, menuId) || {};
  }

  static toggleSubmenu(element) {
    const isOpening = !element.classList.contains('open');
    element.classList.toggle('open');

    if (isOpening) {
      const level = parseInt(element.dataset.level) || 0;
      const siblings = document.querySelectorAll(`.og-menu-item.level-${level}`);
      siblings.forEach(sibling => {
        if (sibling !== element) {
          sibling.classList.remove('open');
        }
      });
    }
  }

  static getFirstView() {
    if (!this.menuData || !this.menuData.menu || this.menuData.menu.length === 0) {
      return 'middle:dashboard/dashboard';
    }

    const findFirstView = (items) => {
      for (const item of items) {
        if (item.view) {
          return item.view;
        }
        if (item.items && item.items.length > 0) {
          const view = findFirstView(item.items);
          if (view) return view;
        }
      }
      return null;
    };

    return findFirstView(this.menuData.menu) || 'middle:dashboard/dashboard';
  }

  // Validar acceso por role (igual que form.js)
  static hasRoleAccess(menuItem) {
    // Si el menú no tiene restricción de role, permitir acceso
    if (!menuItem.role) return true;

    const auth = ogModule('auth');
    
    // Si auth no está disponible (deshabilitado), permitir acceso
    if (!auth) {
      ogLogger?.warn('core:sidebar', 'Auth no disponible - permitiendo acceso sin restricciones');
      return true;
    }

    // Obtener role del usuario actual
    const userRole = auth?.user?.role;

    // Si no hay usuario autenticado, denegar acceso
    if (!userRole) return false;

    // Validar si el role coincide
    return userRole === menuItem.role;
  }

  // Filtrar menús por role de forma recursiva
  static filterMenusByRole(menuItems) {
    const auth = ogModule('auth');
    const userRole = auth?.user?.role;
    
    // ✅ Si el usuario es admin, mostrar TODO
    if (userRole === 'admin') {
      ogLogger?.info('core:sidebar', '👑 Usuario admin - mostrando todos los menús sin filtrado');
      return menuItems.sort((a, b) => (a.order || 999) - (b.order || 999));
    }
    
    return menuItems
      .filter(item => this.hasRoleAccess(item))
      .map(item => {
        // Si tiene submenús, filtrarlos también
        if (item.items && item.items.length > 0) {
          return {
            ...item,
            items: this.filterMenusByRole(item.items)
          };
        }
        return item;
      })
      .filter(item => {
        // Si es un menú con submenús y todos fueron filtrados, eliminarlo también
        if (item.items) {
          return item.items.length > 0;
        }
        return true;
      })
      .sort((a, b) => (a.order || 999) - (b.order || 999));
  }
}

// Global
window.ogSidebar = ogSidebar;

// Registrar en ogFramework (preferido)
if (typeof window.ogFramework !== 'undefined') {
  window.ogFramework.core.sidebar = ogSidebar;
}