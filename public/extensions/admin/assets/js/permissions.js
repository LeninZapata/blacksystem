/**
 * Selector de Permisos de Usuario
 * Componente minimalista para asignar permisos por extension/menu/tabs
 */

class permissions {
  static instances = new Map();
  static viewsCache = new Map();

  /**
   * Renderiza el selector de permisos
   * @param {string} containerId - ID del contenedor
   * @param {object} userConfig - Configuración inicial de permisos
   * @param {array} extensionsData - Datos de extensions desde hooks
   */
  static async render(containerId, userConfig = {}, extensionsData = []) {
    const container = document.getElementById(containerId);
    if (!container) {
      ogLogger.error('com:permissions', 'Container no encontrado:', containerId);
      return;
    }

    const config = window.ogFramework?.activeConfig || window.appConfig;
    const version = config?.version || Date.now();
    const permissions = userConfig.permissions || {};
    const selectorId = `permissions-${version}`;

    // Cargar tabs de todas las vistas
    await this.loadAllViewsTabs(extensionsData);

    const html = `
      <div class="permissions-selector" id="${selectorId}">
        <div class="permissions-header">
          <h4>🔐 Permisos por Extension</h4>
          <div class="permissions-actions">
            <button type="button" class="btn-link" onclick="permissions.toggleAll('${selectorId}', true)">
              ✅ Seleccionar Todo
            </button>
            <button type="button" class="btn-link" onclick="permissions.toggleAll('${selectorId}', false)">
              ❌ Desmarcar Todo
            </button>
          </div>
        </div>

        <div class="permissions-list">
          ${extensionsData.map(extension => this.renderPlugin(extension, permissions, selectorId)).join('')}
        </div>

        <input type="hidden" name="config" id="${selectorId}-data" value='${JSON.stringify(config)}'>
      </div>
    `;

    container.innerHTML = html;
    this.instances.set(selectorId, { config, extensionsData });
    this.bindEvents(selectorId);

    ogLogger.success('com:permissions', 'Renderizado exitosamente');
  }

  /**
   * Cargar tabs de todas las vistas de todos los extensions
   */
  static async loadAllViewsTabs(extensionsData) {
    for (const extension of extensionsData) {
      if (!extension.hasMenu || !extension.menu?.items) continue;

      for (const menuItem of extension.menu.items) {
        if (!menuItem.view) continue;

        const viewPath = menuItem.view;
        const cacheKey = `${extension.name}:${viewPath}`;

        // Si ya está en caché, skip
        if (this.viewsCache.has(cacheKey)) continue;

        try {
          // Cargar el JSON de la vista
          const viewData = await this.loadViewJson(extension.name, viewPath);

          // Extraer tabs si existen
          if (viewData?.tabs && Array.isArray(viewData.tabs)) {
            this.viewsCache.set(cacheKey, viewData.tabs);
          } else {
            // Sin tabs, guardar null
            this.viewsCache.set(cacheKey, null);
          }
        } catch (error) {
          ogLogger.error('com:permissions', `Error cargando ${extension.name}/${viewPath}:`, error.message);
          this.viewsCache.set(cacheKey, null);
        }
      }
    }
  }

  /**
   * Cargar JSON de una vista
   */
  static async loadViewJson(extensionName, viewPath) {
    // Obtener baseUrl del config actual
    const config = window.ogFramework?.activeConfig || window.appConfig;
    const baseUrl = config?.baseUrl || '/';

    // Construir URL: {baseUrl}extensions/{extension}/views/{viewPath}.json
    const url = `${baseUrl}extensions/${extensionName}/views/${viewPath}.json`;

    // ogLogger?.debug('com:permissions', `🔍 Cargando vista: ${url}`);

    const response = await fetch(url);
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }

    return await response.json();
  }

  /**
   * Renderiza un extension con sus menus y tabs
   */
  static renderPlugin(extension, permissions, selectorId) {
    const extensionPerms = permissions.extensions?.[extension.name] || {};
    const isEnabled = extensionPerms.enabled !== false;
    const pluginId = `${selectorId}-extension-${extension.name}`;

    return `
      <div class="permission-extension" data-extension="${extension.name}">
        <div class="permission-extension-header">
          <label class="permission-checkbox">
            <input type="checkbox"
                   class="extension-toggle"
                   data-extension="${extension.name}"
                   ${isEnabled ? 'checked' : ''}>
            <span class="extension-name">📦 ${extension.name}</span>
          </label>
          <button type="button"
                  class="btn-collapse"
                  data-target="${pluginId}"
                  onclick="permissions.toggleCollapse('${pluginId}')">
            ${isEnabled ? '▼' : '▶'}
          </button>
        </div>

        <div class="permission-extension-content ${isEnabled ? 'open' : ''}" id="${pluginId}">
          ${extension.hasMenu ? this.renderMenusWithTabs(extension, extensionPerms, selectorId) : ''}
        </div>
      </div>
    `;
  }

  /**
   * Renderiza los menus de un extension CON sus tabs
   */
  static renderMenusWithTabs(extension, extensionPerms, selectorId) {
    if (!extension.menu?.items) return '';

    const menusPerms = extensionPerms.menus || {};
    const allMenus = menusPerms === '*';

    return `
      <div class="permission-section">
        <div class="permission-section-header">
          <span>📋 Menús</span>
          <label class="permission-checkbox-sm">
            <input type="checkbox"
                   class="section-toggle-all"
                   data-extension="${extension.name}"
                   data-section="menus"
                   ${allMenus ? 'checked' : ''}>
            <span>Todos</span>
          </label>
        </div>
        <div class="permission-items">
          ${extension.menu.items.map(item => this.renderMenuItem(item, extension, menusPerms, allMenus, selectorId)).join('')}
        </div>
      </div>
    `;
  }

  /**
   * Renderiza un item de menú CON sus tabs (si tiene)
   */
  static renderMenuItem(menuItem, extension, menusPerms, allMenus, selectorId) {
    const menuPerms = menusPerms[menuItem.id] || {};

    // Determinar si el menu debe estar checked
    let isMenuChecked = false;

    if (allMenus) {
      // Si "Todos los menús" está marcado
      isMenuChecked = true;
    } else if (menuPerms === true) {
      // Menú simple sin tabs
      isMenuChecked = true;
    } else if (typeof menuPerms === 'object') {
      // Menú con tabs
      if (menuPerms.tabs === '*') {
        // Todas las tabs marcadas
        isMenuChecked = true;
      } else if (menuPerms.tabs && typeof menuPerms.tabs === 'object') {
        // Verificar si hay al menos 1 tab en true
        const hasAnyTabChecked = Object.values(menuPerms.tabs).some(value => value === true);
        isMenuChecked = hasAnyTabChecked;

        ogLogger.debug('com:permissions', `Menú "${menuItem.id}": hasAnyTabChecked=${hasAnyTabChecked}, tabs:`, menuPerms.tabs);
      }
    }

    // Obtener tabs de la vista
    const cacheKey = `${extension.name}:${menuItem.view}`;
    const tabs = this.viewsCache.get(cacheKey);

    const hasExpandableTabs = tabs && tabs.length > 0;
    const tabsId = `${selectorId}-tabs-${extension.name}-${menuItem.id}`;
    const isExpanded = isMenuChecked && hasExpandableTabs;

    return `
      <div class="permission-item-wrapper">
        <div class="permission-item-header">
          <label class="permission-item">
            <input type="checkbox"
                   class="menu-checkbox"
                   data-extension="${extension.name}"
                   data-menu="${menuItem.id}"
                   ${isMenuChecked ? 'checked' : ''}
                   ${allMenus ? 'disabled' : ''}>
            <span>${menuItem.title}</span>
          </label>
          ${hasExpandableTabs ? `
            <button type="button"
                    class="btn-collapse-sm"
                    data-target="${tabsId}"
                    onclick="permissions.toggleCollapse('${tabsId}')">
              ${isExpanded ? '▼' : '▶'}
            </button>
          ` : ''}
        </div>

        ${hasExpandableTabs ? `
          <div class="permission-tabs ${isExpanded ? 'open' : ''}" id="${tabsId}">
            ${this.renderTabs(tabs, extension, menuItem, menuPerms, selectorId)}
          </div>
        ` : ''}
      </div>
    `;
  }

  /**
   * Renderiza las tabs de un menú/vista
   */
  static renderTabs(tabs, extension, menuItem, menuPerms, selectorId) {
    const tabsPerms = typeof menuPerms === 'object' ? menuPerms.tabs || {} : {};
    const allTabs = tabsPerms === '*';

    return `
      <div class="permission-tabs-header">
        <span>📑 Tabs</span>
        <label class="permission-checkbox-xs">
          <input type="checkbox"
                 class="tabs-toggle-all"
                 data-extension="${extension.name}"
                 data-menu="${menuItem.id}"
                 ${allTabs ? 'checked' : ''}>
          <span>Todas</span>
        </label>
      </div>
      <div class="permission-tabs-list">
        ${tabs.map(tab => {
          const isChecked = allTabs || tabsPerms[tab.id] === true;
          return `
            <label class="permission-tab-item">
              <input type="checkbox"
                     class="tab-checkbox"
                     data-extension="${extension.name}"
                     data-menu="${menuItem.id}"
                     data-tab="${tab.id}"
                     ${isChecked ? 'checked' : ''}
                     ${allTabs ? 'disabled' : ''}>
              <span>${tab.title}</span>
            </label>
          `;
        }).join('')}
      </div>
    `;
  }

  /**
   * Bind eventos
   */
  static bindEvents(selectorId) {
    const container = document.getElementById(selectorId);
    if (!container) return;

    // Toggle extension
    container.querySelectorAll('.extension-toggle').forEach(checkbox => {
      checkbox.addEventListener('change', (e) => {
        const extension = e.target.dataset.extension;
        const content = container.querySelector(`#${selectorId}-extension-${extension}`);
        const collapseBtn = e.target.closest('.permission-extension-header').querySelector('.btn-collapse');

        if (e.target.checked) {
          content?.classList.add('open');
          if (collapseBtn) collapseBtn.textContent = '▼';
        } else {
          content?.classList.remove('open');
          if (collapseBtn) collapseBtn.textContent = '▶';
        }

        this.updateConfig(selectorId);
      });
    });

    // Toggle section all (menus)
    container.querySelectorAll('.section-toggle-all').forEach(checkbox => {
      checkbox.addEventListener('change', (e) => {
        const extension = e.target.dataset.extension;
        const menuCheckboxes = container.querySelectorAll(`.menu-checkbox[data-extension="${extension}"]`);

        menuCheckboxes.forEach(cb => {
          cb.disabled = e.target.checked;
          cb.checked = e.target.checked;
        });

        // También deshabilitar todos los tab checkboxes
        const tabCheckboxes = container.querySelectorAll(`.tab-checkbox[data-extension="${extension}"]`);
        tabCheckboxes.forEach(cb => {
          cb.disabled = e.target.checked;
          cb.checked = e.target.checked;
        });

        this.updateConfig(selectorId);
      });
    });

    // Toggle menu checkbox
    container.querySelectorAll('.menu-checkbox').forEach(checkbox => {
      checkbox.addEventListener('change', (e) => {
        const extension = e.target.dataset.extension;
        const menu = e.target.dataset.menu;
        const tabsContainer = e.target.closest('.permission-item-wrapper')?.querySelector('.permission-tabs');

        if (tabsContainer) {
          const collapseBtn = e.target.closest('.permission-item-wrapper')?.querySelector('.btn-collapse-sm');
          if (e.target.checked) {
            tabsContainer.classList.add('open');
            if (collapseBtn) collapseBtn.textContent = '▼';
          } else {
            tabsContainer.classList.remove('open');
            if (collapseBtn) collapseBtn.textContent = '▶';
          }
        }

        this.updateConfig(selectorId);
      });
    });

    // Toggle all tabs
    container.querySelectorAll('.tabs-toggle-all').forEach(checkbox => {
      checkbox.addEventListener('change', (e) => {
        const extension = e.target.dataset.extension;
        const menu = e.target.dataset.menu;
        const tabCheckboxes = container.querySelectorAll(
          `.tab-checkbox[data-extension="${extension}"][data-menu="${menu}"]`
        );

        tabCheckboxes.forEach(cb => {
          cb.disabled = e.target.checked;
          cb.checked = e.target.checked;
        });

        this.updateConfig(selectorId);
      });
    });

    // Toggle individual tab
    container.querySelectorAll('.tab-checkbox').forEach(checkbox => {
      checkbox.addEventListener('change', () => {
        this.updateConfig(selectorId);
      });
    });
  }

  /**
   * Actualizar configuración
   */
  static updateConfig(selectorId) {
    const container = document.getElementById(selectorId);
    if (!container) return;

    const instance = this.instances.get(selectorId);
    if (!instance) return;

    const newConfig = { ...instance.config };
    newConfig.permissions = { extensions: {} };

    // Procesar cada extension
    instance.extensionsData.forEach(extension => {
      const pluginCheckbox = container.querySelector(`.extension-toggle[data-extension="${extension.name}"]`);
      if (!pluginCheckbox) return;

      const extensionData = {
        enabled: pluginCheckbox.checked
      };

      if (pluginCheckbox.checked && extension.hasMenu) {
        // Verificar si "Todos los menús" está marcado
        const allMenusCheckbox = container.querySelector(
          `.section-toggle-all[data-extension="${extension.name}"][data-section="menus"]`
        );

        if (allMenusCheckbox?.checked) {
          extensionData.menus = '*';
        } else {
          // Procesar cada menú individualmente
          extensionData.menus = {};

          extension.menu?.items.forEach(menuItem => {
            const menuCheckbox = container.querySelector(
              `.menu-checkbox[data-extension="${extension.name}"][data-menu="${menuItem.id}"]`
            );

            if (menuCheckbox?.checked) {
              // Verificar si tiene tabs
              const tabsToggleAll = container.querySelector(
                `.tabs-toggle-all[data-extension="${extension.name}"][data-menu="${menuItem.id}"]`
              );

              if (tabsToggleAll) {
                // Tiene tabs
                if (tabsToggleAll.checked) {
                  // Todas las tabs
                  extensionData.menus[menuItem.id] = { enabled: true, tabs: '*' };
                } else {
                  // Tabs individuales
                  const tabsData = {};
                  container.querySelectorAll(
                    `.tab-checkbox[data-extension="${extension.name}"][data-menu="${menuItem.id}"]`
                  ).forEach(tabCheckbox => {
                    if (tabCheckbox.checked) {
                      tabsData[tabCheckbox.dataset.tab] = true;
                    }
                  });

                  extensionData.menus[menuItem.id] = {
                    enabled: true,
                    tabs: Object.keys(tabsData).length > 0 ? tabsData : {}
                  };
                }
              } else {
                // No tiene tabs, solo el menú
                extensionData.menus[menuItem.id] = true;
              }
            }
          });
        }
      }

      newConfig.permissions.extensions[extension.name] = extensionData;
    });

    // Actualizar hidden input
    const hiddenInput = document.getElementById(`${selectorId}-data`);
    if (hiddenInput) {
      hiddenInput.value = JSON.stringify(newConfig);
    }

    // Actualizar preview en la tab "📊 JSON Preview"
    const preview = document.getElementById('config-preview');
    if (preview) {
      preview.textContent = JSON.stringify(newConfig, null, 2);
    }

    instance.config = newConfig;
  }

  /**
   * Toggle collapse
   */
  static toggleCollapse(targetId) {
    const target = document.getElementById(targetId);
    if (!target) return;

    const button = document.querySelector(`[data-target="${targetId}"]`);
    target.classList.toggle('open');

    if (button) {
      button.textContent = target.classList.contains('open') ? '▼' : '▶';
    }
  }

  /**
   * Toggle all
   */
  static toggleAll(selectorId, checked) {
    const container = document.getElementById(selectorId);
    if (!container) return;

    // Extension toggles
    container.querySelectorAll('.extension-toggle').forEach(cb => {
      cb.checked = checked;
      cb.dispatchEvent(new Event('change'));
    });

    // Section toggles
    container.querySelectorAll('.section-toggle-all').forEach(cb => {
      cb.checked = checked;
      cb.dispatchEvent(new Event('change'));
    });
  }
}

window.permissions = permissions;