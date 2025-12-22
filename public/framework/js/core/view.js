class view {
  static views = {};
  static loadedExtensions = {};
  static viewNavigationCache = new Map();
  static lastSessionCheck = 0;
  static SESSION_CHECK_INTERVAL = 30000;

  static async loadView(viewName, container = null, extensionContext = null, menuResources = null, afterRender = null, menuId = null) {
    // Manejar notación extension|path (ej: botws|sections/botws-listado)
    if (viewName.includes('|')) {
      const [targetExtension, targetPath] = viewName.split('|');
      extensionContext = targetExtension;
      viewName = targetPath;
    }

    const navCacheKey = `nav_${extensionContext || 'core'}_${viewName}`;

    if (!container && window.appConfig?.cache?.viewNavigation) {
      if (this.viewNavigationCache.has(navCacheKey)) {
        logger.info('core:view', `✅ Cache viewNavigation: usando HTML cacheado para "${viewName}"`);
        const cachedData = this.viewNavigationCache.get(navCacheKey);
        const content = document.getElementById('content');
        if (content) {
          content.innerHTML = cachedData.html;
          document.body.setAttribute('data-view', cachedData.viewId);
          document.body.className = document.body.className
            .split(' ')
            .filter(c => !c.startsWith('layout-'))
            .join(' ');
          if (cachedData.layout) {
            document.body.classList.add(`layout-${cachedData.layout}`);
          }
          await this.reInitializeCachedView(cachedData);
          if (typeof afterRender === 'function') {
            try {
              afterRender(cachedData.viewId, content);
            } catch (error) {
              logger.error('core:view', 'Error en afterRender:', error);
            }
          }
          return;
        }
      }
    }

    let basePath;
    let cacheKey;

    const frameworkPath = window.appConfig?.frameworkPath || 'framework';

    if (extensionContext) {
      basePath = `extensions/${extensionContext}/views`;
      cacheKey = `extension_view_${extensionContext}_${viewName.replace(/\//g, '_')}_v${window.VERSION}`;
    }
    else if (viewName.startsWith('core:')) {
      viewName = viewName.replace('core:', '');
      basePath = `${frameworkPath}/js/views`;
      cacheKey = `core_view_${viewName.replace(/\//g, '_')}_v${window.VERSION}`;
    }
    else if (viewName.includes('/')) {
      const parts = viewName.split('/');
      const firstPart = parts[0];
      const isExtension = window.hook?.isExtensionEnabled?.(firstPart);
      if (isExtension) {
        basePath = `extensions/${firstPart}/views`;
        const restPath = parts.slice(1).join('/');
        viewName = restPath || viewName;
        cacheKey = `extension_view_${firstPart}_${viewName.replace(/\//g, '_')}_v${window.VERSION}`;
        extensionContext = firstPart;
      } else {
        basePath = `${frameworkPath}/js/views`;
        cacheKey = `core_view_${viewName.replace(/\//g, '_')}_v${window.VERSION}`;
      }
    }
    else {
      basePath = `${frameworkPath}/js/views`;
      cacheKey = `core_view_${viewName.replace(/\//g, '_')}_v${window.VERSION}`;
    }

    try {
      // Solo leer del caché si está habilitado
      let viewData = window.appConfig?.cache?.views ? cache.get(cacheKey) : null;

      if (viewData) {
        logger.info('core:view', `✅ Cache views: usando caché para "${viewName}"`);
      }

      if (!viewData) {
        const cacheBuster = `?t=${window.VERSION}`;
        const url = `${window.BASE_URL}${basePath}/${viewName}.json${cacheBuster}`;
        const response = await fetch(url);

        if (!response.ok) {
          if (container) {
            throw new Error(__('core.view.not_found', { view: viewName }));
          }
          const found = await this.findViewInExtensions(viewName, container);
          if (!found) {
            throw new Error(__('core.view.not_found', { view: viewName }));
          }
          return;
        }

        viewData = await response.json();

        if (window.appConfig?.cache?.views) {
          cache.set(cacheKey, viewData);
          logger.info('core:view', `💾 Cache views: guardando en caché "${viewName}"`);
        } else {
          logger.info('core:view', `⚠️ Cache views: NO cacheando "${viewName}" (deshabilitado)`);
        }
      }

      if (viewData.tabs && extensionContext) {
        const effectiveMenuId = menuId || viewData.id;
        viewData.tabs = this.filterTabsByPermissions(viewData.tabs, extensionContext, effectiveMenuId);
      }

      const combinedData = this.combineResources(viewData, menuResources);

      if (container) {
        this.renderViewInContainer(combinedData, container, extensionContext);
      } else {
        this.renderView(combinedData, extensionContext);
      }

      await this.loadAndInitResources(combinedData);

      if (typeof afterRender === 'function') {
        const content = container || document.getElementById('content');
        try {
          afterRender(combinedData.id, content);
        } catch (error) {
          logger.error('core:view', 'Error en afterRender:', error);
        }
      }

      if (!container && window.appConfig?.cache?.viewNavigation) {
        const content = document.getElementById('content');
        if (content) {
          this.viewNavigationCache.set(navCacheKey, {
            html: content.innerHTML,
            viewId: combinedData.id,
            layout: combinedData.layout,
            viewData: combinedData
          });
          logger.info('core:view', `💾 Cache viewNavigation: guardando HTML renderizado para "${viewName}"`);
        }
      } else if (!container) {
        logger.info('core:view', `⚠️ Cache viewNavigation: NO cacheando HTML para "${viewName}" (deshabilitado)`);
      }

    } catch (error) {
      logger.error('core:view', `Error cargando vista ${viewName}:`, error);
      this.renderError(viewName, container);
    }
  }

  static filterTabsByPermissions(tabs, extensionName, menuId) {
    if (window.auth?.user?.role === 'admin') return tabs;

    if (!window.auth?.userPermissions?.extensions) {
      logger.warn('core:view', 'Usuario sin permisos - ocultando tabs');
      return [];
    }

    const extensionPerms = window.auth.userPermissions.extensions[extensionName];

    if (!extensionPerms || extensionPerms.enabled === false) {
      logger.warn('core:view', `Extension ${extensionName} sin permisos`);
      return [];
    }

    if (!extensionPerms.menus || extensionPerms.menus === '*') {
      return tabs;
    }

    const menuPerms = extensionPerms.menus[menuId];

    if (menuPerms === true) {
      return tabs;
    }

    if (!menuPerms || typeof menuPerms !== 'object') {
      logger.warn('core:view', `Sin permisos para menú ${menuId}`);
      return [];
    }

    if (menuPerms.enabled === false) {
      logger.warn('core:view', `Menú ${menuId} deshabilitado`);
      return [];
    }

    if (menuPerms.tabs === '*') {
      return tabs;
    }

    if (!menuPerms.tabs || (Array.isArray(menuPerms.tabs) && menuPerms.tabs.length === 0)) {
      logger.warn('core:view', `Sin permisos de tabs para ${menuId}`);
      return [];
    }

    if (typeof menuPerms.tabs === 'object' && !Array.isArray(menuPerms.tabs)) {
      const filteredTabs = tabs.filter(tab => menuPerms.tabs[tab.id] === true);
      logger.info('core:view', `Tabs filtradas para ${menuId}: ${filteredTabs.length}/${tabs.length} visibles`);

      if (filteredTabs.length === 0) {
        logger.warn('core:view', `Ninguna tab tiene permiso en ${menuId}. Permisos:`, menuPerms.tabs);
      }

      return filteredTabs;
    }

    logger.warn('core:view', `Configuración de tabs no válida para ${menuId}`);
    return [];
  }

  static combineResources(viewData, menuResources) {
    if (!menuResources || (!menuResources.scripts?.length && !menuResources.styles?.length)) {
      return viewData;
    }

    const combined = { ...viewData };

    if (menuResources.scripts?.length > 0) {
      combined.scripts = [...new Set([
        ...(viewData.scripts || []),
        ...menuResources.scripts
      ])];
    }

    if (menuResources.styles?.length > 0) {
      combined.styles = [...new Set([
        ...(viewData.styles || []),
        ...menuResources.styles
      ])];
    }

    return combined;
  }

  static async loadAndInitResources(viewData) {
    await this.loadViewResources(viewData);
    await new Promise(resolve => setTimeout(resolve, 1));
    await this.initViewComponents(viewData);
  }

  static async loadViewResources(viewData) {
    if (viewData.scripts || viewData.styles) {
      try {
        // Normalizar rutas agregando 'extensions/' si no lo tienen
        const normalizeResources = (resources = []) => {
          return resources.map(path => {
            if (!path) return path;
            // Si ya empieza con 'extensions/', dejarlo como está
            if (path.startsWith('extensions/')) return path;
            // Si NO empieza con 'extensions/', agregarlo
            return `extensions/${path}`;
          });
        };

        const normalizedScripts = normalizeResources(viewData.scripts || []);
        const normalizedStyles = normalizeResources(viewData.styles || []);

        await loader.loadResources(normalizedScripts, normalizedStyles);
      } catch (error) {
        logger.error('core:view', 'Error cargando recursos:', error);
      }
    }
  }

  static async findViewInExtensions(viewName, container) {
    const extensions = Object.keys(window.hook?.extensions || {});
    for (const extensionName of extensions) {
      try {
        const url = `${window.BASE_URL}extensions/${extensionName}/views/${viewName}.json?t=${window.VERSION}`;
        const response = await fetch(url);
        if (response.ok) {
          const viewData = await response.json();
          this.renderView(viewData);
          await this.loadViewResources(viewData);
          return true;
        }
      } catch (error) {
        continue;
      }
    }
    return false;
  }

  static renderView(viewData, extensionContext = null) {
    const content = document.getElementById('content');
    document.body.setAttribute('data-view', viewData.id);
    document.body.className = document.body.className
      .split(' ')
      .filter(c => !c.startsWith('layout-'))
      .join(' ');
    if (viewData.layout) {
      document.body.classList.add(`layout-${viewData.layout}`);
    }

    const hooksHTML = this.processHooksForHTML(viewData);
    content.innerHTML = this.generateViewHTML(viewData, hooksHTML, extensionContext);
    this.setupView(viewData);
  }

  static renderViewInContainer(viewData, container, extensionContext = null) {
    const hooksHTML = this.processHooksForHTML(viewData);
    container.innerHTML = this.generateViewHTML(viewData, hooksHTML, extensionContext);
    this.setupView(viewData, container);
  }

  static generateViewHTML(viewData, hooksHTML = null, extensionContext = null) {
    const hooksBeforeHTML = hooksHTML?.before || '';
    const hooksAfterHTML = hooksHTML?.after || '';
    const extensionAttr = extensionContext ? ` data-extension-context="${extensionContext}"` : '';

    return `
      <div class="view-container" data-view="${viewData.id}"${extensionAttr}>
        ${hooksBeforeHTML}

        ${viewData.header ? `
          <div class="view-header">
            ${viewData.header.title ? `<h1>${viewData.header.title}</h1>` : ''}
            ${viewData.header.subtitle ? `<p>${viewData.header.subtitle}</p>` : ''}
          </div>
        ` : ''}

        ${viewData.tabs ? `
          <div class="view-tabs-container" data-view-id="${viewData.id}"></div>
        ` : `
          <div class="view-content">
            ${this.renderContent(viewData.content)}
          </div>
        `}

        ${viewData.statusbar ? `
          <div class="view-statusbar">
            ${this.renderContent(viewData.statusbar)}
          </div>
        ` : ''}

        ${hooksAfterHTML}
      </div>
    `;
  }

  static async setupView(viewData, container = null) {
    const viewContainer = container || document.getElementById('content');

    await this.renderHookComponents(viewContainer);

    if (viewData.tabs) {
      const tabsContainer = viewContainer.querySelector('.view-tabs-container');
      if (tabsContainer) {
        await tabs.render(viewData, tabsContainer);
      }
    } else {
      await this.loadDynamicComponents(viewContainer);
    }

    setTimeout(() => this.initFormValidation(), 0);
  }

  static async initViewComponents(viewData) {
    if (!viewData.scripts || viewData.scripts.length === 0) {
      return;
    }

    viewData.scripts.forEach((scriptPath) => {
      const componentName = this.extractComponentName(scriptPath);
      if (componentName && window[componentName]) {
        if (typeof window[componentName].init === 'function') {
          try {
            window[componentName].init();
          } catch (error) {
            logger.error('core:view', `Error ejecutando ${componentName}.init():`, error);
          }
        }
      }
    });
  }

  static extractComponentName(scriptPath) {
    const fileName = scriptPath.split('/').pop().replace('.js', '');
    const possibleNames = [
      fileName,
      `ejemplo${fileName.charAt(0).toUpperCase() + fileName.slice(1)}`,
      fileName.charAt(0).toUpperCase() + fileName.slice(1)
    ];

    for (const name of possibleNames) {
      if (window[name]) {
        return name;
      }
    }

    return null;
  }

  static renderContent(content) {
    if (typeof content === 'string') {
      return content;
    }
    if (Array.isArray(content)) {
      return content.map(item => this.renderContentItem(item)).join('');
    }
    return this.renderContentItem(content);
  }

  static renderContentItem(item) {
    if (item == null) return '';
    if (typeof item === 'string') return this.processI18nInString(item);
    if (typeof item !== 'object') return '';

    const formJson = item.form_json || item.formJson;
    if (item.type === 'form' && formJson) {
      return `<div class="dynamic-form" data-form-json="${formJson}"></div>`;
    }

    if (item.type === 'component' && item.component) {
      const configJson = JSON.stringify(item.config || {}).replace(/"/g, '&quot;');
      return `<div class="dynamic-component" data-component="${item.component}" data-config="${configJson}"></div>`;
    }

    if (item.type === 'html') {
      return this.processI18nInString(item.content || '');
    }

    return '';
  }

  // Procesar cadenas i18n en contenido HTML
  static processI18nInString(str) {
    if (!str || typeof str !== 'string') return str;
    
    // Reemplazar {i18n:key} o {i18n:key|param1:value1|param2:value2}
    return str.replace(/\{i18n:([^}]+)\}/g, (match, content) => {
      const parts = content.split('|');
      const key = parts[0];
      const params = {};
      
      // Procesar parámetros opcionales
      for (let i = 1; i < parts.length; i++) {
        const [paramKey, paramValue] = parts[i].split(':');
        if (paramKey && paramValue) {
          params[paramKey] = paramValue;
        }
      }
      
      return window.i18n ? i18n.t(key, params) : key;
    });
  }

  static async reInitializeCachedView(cachedData) {
    const viewData = cachedData.viewData;
    if (!viewData) return;

    if (viewData.tabs) {
      const tabsContainer = document.querySelector('.view-tabs-container');
      if (tabsContainer) {
        await tabs.render(viewData, tabsContainer);
      }
    } else {
      await this.loadDynamicComponents(document.getElementById('content'));
    }

    setTimeout(() => this.initFormValidation(), 0);
  }

  static async loadDynamicComponents(container) {
    // Obtener el contexto de extensión del contenedor padre
    const viewContainer = container.closest('[data-extension-context]');
    const extensionContext = viewContainer?.getAttribute('data-extension-context') || null;

    const dynamicForms = container.querySelectorAll('.dynamic-form');
    
    dynamicForms.forEach(async el => {
      const formJson = el.getAttribute('data-form-json');
      
      if (formJson && window.form) {
        // Si hay contexto de extensión y el formJson no incluye '|', agregarlo
        const formPath = (extensionContext && !formJson.includes('|')) 
          ? `${extensionContext}|forms/${formJson}` 
          : formJson;
        
        await form.load(formPath, el);
      }
    });

    const dynamicComponents = container.querySelectorAll('.dynamic-component');
    dynamicComponents.forEach(el => {
      const componentName = el.getAttribute('data-component');
      const configStr = el.getAttribute('data-config');
      const config = configStr ? JSON.parse(configStr.replace(/&quot;/g, '"')) : {};

      if (componentName && window[componentName]) {
        if (typeof window[componentName].render === 'function') {
          window[componentName].render(config, el);
        } else if (typeof window[componentName].init === 'function') {
          window[componentName].init(el, config);
        }
      }
    });
  }

  static initFormValidation() {
    const formElements = document.querySelectorAll('form[data-validation]');
    formElements.forEach(formEl => {
      if (window.form && typeof form.validate === 'function') {
        const formId = formEl.id;
        if (formId) {
          form.initValidation?.(formId);
        }
      }
    });
  }

  static renderError(viewName, container = null) {
    const errorHTML = `
      <div class="view-error">
        <h2>Error cargando vista</h2>
        <p>No se pudo cargar la vista: <strong>${viewName}</strong></p>
      </div>
    `;

    if (container) {
      container.innerHTML = errorHTML;
    } else {
      const content = document.getElementById('content');
      if (content) {
        content.innerHTML = errorHTML;
      }
    }
  }

  // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  // HOOKS SYSTEM - Agrupado al final
  // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

  static processHooksForHTML(viewData) {
    if (!viewData.id || !window.hook) return null;

    const allHooks = hook.execute(`hook_${viewData.id}`, []);
    if (allHooks.length === 0) return null;

    const hooksBeforeView = allHooks.filter(h => h.context === 'view' && h.position === 'before');
    const hooksAfterView = allHooks.filter(h => h.context === 'view' && h.position === 'after');
    const hooksForTabs = allHooks.filter(h => h.context === 'tab');
    const hooksForContent = allHooks.filter(h => h.context === 'content' || !h.context);

    if (hooksForTabs.length > 0 && Array.isArray(viewData.tabs)) {
      this.mergeHooksIntoTabs(viewData, hooksForTabs);
    }

    if (hooksForContent.length > 0 && Array.isArray(viewData.content)) {
      this.mergeHooksIntoContent(viewData, hooksForContent);
    }

    return {
      before: this.generateHooksHTML(hooksBeforeView),
      after: this.generateHooksHTML(hooksAfterView)
    };
  }

  static generateHooksHTML(hooks) {
    if (!hooks || hooks.length === 0) return '';

    hooks.sort((a, b) => (a.order || 999) - (b.order || 999));

    return hooks.map(hook => {
      if (hook.type === 'html') {
        return `<div id="${hook.id}" class="hook-item hook-html">${hook.content || ''}</div>`;
      } else if (hook.type === 'component') {
        const config = JSON.stringify(hook.config || {}).replace(/"/g, '&quot;');
        return `<div id="${hook.id}" class="hook-item hook-component" data-component="${hook.component}" data-config="${config}"></div>`;
      }
      return '';
    }).join('');
  }

  static async renderHookComponents(viewContainer) {
    const componentHooks = viewContainer.querySelectorAll('.hook-component[data-component]');
    if (componentHooks.length === 0) return;

    for (const hookElement of componentHooks) {
      const componentName = hookElement.dataset.component;
      const configStr = hookElement.dataset.config || '{}';

      try {
        const config = JSON.parse(configStr.replace(/&quot;/g, '"'));

        if (window[componentName]?.render) {
          await window[componentName].render(config, hookElement);
        } else {
          logger.warn('core:view', `Componente ${componentName} no encontrado`);
          hookElement.innerHTML = `<div style="padding:1rem;background:#fee;border:1px solid #fcc;border-radius:4px;">Componente ${componentName} no disponible</div>`;
        }
      } catch (error) {
        logger.error('core:view', `Error renderizando hook component ${componentName}:`, error);
        hookElement.innerHTML = `<div style="padding:1rem;background:#fee;border:1px solid #fcc;border-radius:4px;">Error: ${componentName}</div>`;
      }
    }
  }

  static mergeHooksIntoTabs(viewData, hooks) {
    const hooksByTab = {};

    hooks.forEach(hook => {
      const target = hook.target;
      if (target) {
        if (!hooksByTab[target]) hooksByTab[target] = [];
        hooksByTab[target].push(hook);
      }
    });

    viewData.tabs = viewData.tabs.map(tab => {
      const tabHooks = hooksByTab[tab.id];

      if (tabHooks && tabHooks.length > 0 && Array.isArray(tab.content)) {
        // Crear un Set con los IDs de items que ya están en el tab
        const existingIds = new Set(tab.content.map(item => item.id).filter(Boolean));

        // Filtrar hooks que no estén ya en el tab
        const newHooks = tabHooks.filter(hook => {
          if (!hook.id) return true;
          if (existingIds.has(hook.id)) {
            return false;
          }
          return true;
        });

        if (newHooks.length === 0) {
          return tab;
        }

        const existingContent = tab.content.map(item => ({ order: item.order || 999, ...item }));
        const hooksWithOrder = newHooks.map(hook => ({ order: hook.order || 999, ...hook }));
        const mixedContent = [...hooksWithOrder, ...existingContent];

        mixedContent.sort((a, b) => (a.order || 999) - (b.order || 999));

        return { ...tab, content: mixedContent };
      }

      return tab;
    });
  }

  static mergeHooksIntoContent(viewData, hooks) {
    // Crear un Set con los IDs de items que ya están en content
    const existingIds = new Set(viewData.content.map(item => item.id).filter(Boolean));

    // Filtrar hooks que no estén ya en content
    const newHooks = hooks.filter(hook => {
      if (!hook.id) return true; // Si no tiene ID, agregarlo
      if (existingIds.has(hook.id)) {
        return false;
      }
      return true;
    });

    if (newHooks.length === 0) {
      return;
    }

    const existingContent = viewData.content.map(item => ({ order: item.order || 999, ...item }));
    const hooksWithOrder = newHooks.map(hook => ({ order: hook.order || 999, ...hook }));
    const mixedContent = [...hooksWithOrder, ...existingContent];

    mixedContent.sort((a, b) => (a.order || 999) - (b.order || 999));

    viewData.content = mixedContent;
  }
}

window.view = view;