class ogSidebar {
  static menuData = {
    menu: []
  };



  static getConfig() {
    return window.ogFramework?.activeConfig || window.appConfig || {};
  }

  static async init() {
    const view = ogModule('view');
    
    await this.loadMenu();

    const firstView = this.getFirstView();
    if (firstView) {
      if (view && typeof view.loadView === 'function') {
        await view.loadView(firstView);
      }
    }
  }

  static async loadMenu() {
    const hook = ogModule('hook');
    
    try {
      if (hook && typeof hook.getMenuItems === 'function') {
        const pluginMenus = hook.getMenuItems();
        
        ogLogger?.info('core:sidebar', `Menús cargados: ${pluginMenus.length}`);

        const baseMenu = [
          {
            id: "dashboard",
            title: "Dashboard",
            icon: "📊",
            view: "dashboard/dashboard",
            order: 1
          }
        ];

        const allMenuItems = [...baseMenu, ...pluginMenus];
        const uniqueMenuItems = this.removeDuplicateMenus(allMenuItems);

        // Filtrar menús por role del usuario
        const filteredMenuItems = this.filterMenusByRole(uniqueMenuItems);

        this.menuData.menu = filteredMenuItems;

      } else {
        ogLogger?.warn('core:sidebar', 'hook.getMenuItems no disponible, usando menú básico');
        this.menuData.menu = [
          {
            id: "dashboard",
            title: "Dashboard",
            icon: "📊",
            view: "dashboard/dashboard"
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
          view: "dashboard/dashboard"
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
    return '<div class="sidebar-header"></div>';
  }

  static generateFooter() {
    return '<div class="sidebar-footer"></div>';
  }

  // Inyectar contenido en zonas del sidebar
  static inject(zone, html) {
    const validZones = ['header', 'footer', 'content'];
    
    if (!validZones.includes(zone)) {
      ogLogger?.error('core:sidebar', `Zona inválida: ${zone}`);
      return false;
    }

    const container = document.querySelector(`.sidebar-${zone}`);
    
    if (!container) {
      ogLogger?.error('core:sidebar', `Contenedor no encontrado: .sidebar-${zone}`);
      return false;
    }

    container.innerHTML += html;
    ogLogger?.info('core:sidebar', `✅ Contenido inyectado en sidebar-${zone}`);
    return true;
  }

  static generateMenuHtml(menuItems, level = 0) {
    return menuItems.map(item => {
      const hasSubmenu = item.items && item.items.length > 0;
      const isSubmenu = level > 0;
      const itemClass = `menu-item ${hasSubmenu ? 'has-submenu' : ''} ${isSubmenu ? 'submenu-item' : ''} level-${level}`;

      const showIcon = !isSubmenu && item.icon;
      const iconHtml = showIcon ? `<span class="menu-icon">${item.icon}</span>` : '';

      return `
        <div class="${itemClass}" data-id="${item.id}" data-level="${level}">
          ${iconHtml}
          <span class="menu-title">${item.title}</span>
          ${hasSubmenu ? '<span class="menu-arrow"></span>' : ''}
        </div>
        ${hasSubmenu ? `
          <div class="submenu level-${level + 1}">
            ${this.generateMenuHtml(item.items, level + 1)}
          </div>
        ` : ''}
      `;
    }).join('');
  }

  static bindMenuEvents() {
    const view = ogModule('view');
    const menuItems = document.querySelectorAll('.menu-item');

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
    document.querySelectorAll('.menu-item').forEach(item => item.classList.remove('active'));
    activeItem.classList.add('active');
  }

  static detectPluginFromMenuId(menuId) {
    const view = ogModule('view');
    
    for (const [extensionName, pluginConfig] of Object.entries(view.loadedExtensions)) {
      if (menuId.startsWith(`${extensionName}-`)) {
        return extensionName;
      }
    }

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
      const siblings = document.querySelectorAll(`.menu-item.level-${level}`);
      siblings.forEach(sibling => {
        if (sibling !== element) {
          sibling.classList.remove('open');
        }
      });
    }
  }

  static getFirstView() {
    if (!this.menuData || !this.menuData.menu || this.menuData.menu.length === 0) {
      return 'dashboard/dashboard';
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

    return findFirstView(this.menuData.menu) || 'dashboard/dashboard';
  }

  // Validar acceso por role (igual que form.js)
  static hasRoleAccess(menuItem) {
    const auth = ogModule('auth');
    
    // Si el menú no tiene restricción de role, permitir acceso
    if (!menuItem.role) return true;

    // Obtener role del usuario actual
    const userRole = auth?.user?.role;

    // Si no hay usuario autenticado, denegar acceso
    if (!userRole) return false;

    // Validar si el role coincide
    return userRole === menuItem.role;
  }

  // Filtrar menús por role de forma recursiva
  static filterMenusByRole(menuItems) {
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
      .sort((a, b) => (a.order || 999) - (b.order || 999)); // ✅ Ordenar por order
  }
}

// Global
window.ogSidebar = ogSidebar;

// Registrar en ogFramework (preferido)
if (typeof window.ogFramework !== 'undefined') {
  window.ogFramework.core.sidebar = ogSidebar;
}