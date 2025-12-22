class hook {
  static hooks = new Map();
  static loadedHooks = new Set();
  static pluginRegistry = new Map();
  static pluginRegistryOriginal = new Map(); // ← Copia sin filtrar
  static menuItems = [];

  static async loadPluginHooks() {
    const startTime = performance.now();
    this.menuItems = [];

    try {
      const cacheBuster = `?v=${window.VERSION}`;
      const registry = await api.get('extensions/index.json' + cacheBuster);

      if (!registry?.extensions || !Array.isArray(registry.extensions)) {
        logger.warn('core:hook', 'extensions/index.json vacío o mal formado');
        return;
      }

      const pluginLoadPromises = registry.extensions.map(async (pluginInfo) => {
        const pluginConfig = await this.loadPluginConfig(pluginInfo.name);

        if (pluginConfig?.enabled) {
          this.pluginRegistry.set(pluginInfo.name, pluginConfig);

          // ✅ Guardar copia original (sin filtrar)
          this.pluginRegistryOriginal.set(pluginInfo.name, JSON.parse(JSON.stringify(pluginConfig)));

          if (pluginConfig.autoload) {
            await this.loadExtensionScript(pluginInfo.name, pluginConfig.autoload);
          }

          if (pluginConfig.scripts?.length > 0) {
            // Normalizar rutas antes de cargar
            const normalizedScripts = this.normalizeResources(pluginConfig.scripts);
            const normalizedStyles = this.normalizeResources(pluginConfig.styles || []);

            await this.loadPluginResources(normalizedScripts, normalizedStyles);

            // Actualizar en pluginConfig para que los menús usen las rutas normalizadas
            pluginConfig.scripts = normalizedScripts;
            pluginConfig.styles = normalizedStyles;
          }

          await this.loadPluginLanguages(pluginInfo.name);

          if (pluginConfig.name === 'dashboard') return;

          if (pluginConfig.hasMenu && pluginConfig.menu) {
            const menuItem = {
              id: pluginConfig.name,
              title: pluginConfig.menu.title,
              icon: pluginConfig.menu.icon,
              order: pluginConfig.menu.order || 100
            };

            // Preservar role del menú principal si existe
            if (pluginConfig.menu.role) {
              menuItem.role = pluginConfig.menu.role;
            }

            if (pluginConfig.menu.items?.length > 0) {
              menuItem.items = this.processMenuItems(
                pluginConfig.menu.items,
                pluginConfig.name,
                pluginConfig.scripts || [],
                pluginConfig.styles || []
              );
            } else if (pluginConfig.menu.view) {
              menuItem.view = pluginConfig.menu.view;
              if (pluginConfig.scripts) menuItem.scripts = pluginConfig.scripts;
              if (pluginConfig.styles) menuItem.styles = pluginConfig.styles;
            }

            this.menuItems.push(menuItem);
          }

          if (pluginConfig.hasHooks) {
            await this.loadPluginHook(pluginInfo.name);
          }

          if (pluginConfig.menu?.preloadViews === true) {
            await this.preloadPluginViews(pluginInfo.name, pluginConfig);
          }
        }
      });

      await Promise.all(pluginLoadPromises);
      this.menuItems.sort((a, b) => (a.order || 999) - (b.order || 999));

    } catch (error) {
      logger.error('core:hook', 'Error cargando extensions:', error);
    }
  }

  static async loadPluginLanguages(extensionName) {
    if (!window.i18n) return;
    const currentLang = i18n.getLang();
    const loaded = await this.tryLoadPluginLang(extensionName, currentLang);
    if (loaded) {
      const pluginConfig = this.pluginRegistry.get(extensionName);
      if (pluginConfig) {
        pluginConfig.hasLanguages = true;
        pluginConfig.loadedLanguages = [currentLang];
      }
    }
  }

  static async tryLoadPluginLang(extensionName, lang) {
    try {
      const langPath = `${window.BASE_URL}extensions/${extensionName}/lang/${lang}.json`;
      const cacheBuster = `?v=${window.VERSION}`;

      // Usar loader.loadJson con opción optional y silent
      const translations = await loader.loadJson(langPath + cacheBuster, {
        optional: true,
        silent: true
      });

      if (!translations) return false;

      // Guardar traducciones
      if (!i18n.exntesionTranslations.has(extensionName)) {
        i18n.exntesionTranslations.set(extensionName, new Map());
      }
      i18n.exntesionTranslations.get(extensionName).set(lang, translations);
      cache.set(`i18n_extension_${extensionName}_${lang}_v${window.VERSION}`, translations, 60 * 60 * 1000);

      logger.success('core:hook', `✅ Idioma ${lang} cargado para ${extensionName}`);
      return true;
    } catch (error) {
      return false;
    }
  }

  static async preloadPluginViews(extensionName, pluginConfig) {
    if (!pluginConfig.menu?.items) return;
    const viewsToPreload = [];
    const collectViews = (items) => {
      items.forEach(item => {
        if (item.view) viewsToPreload.push(item.view);
        if (item.items?.length > 0) collectViews(item.items);
      });
    };
    collectViews(pluginConfig.menu.items);

    for (const viewPath of viewsToPreload) {
      try {
        const basePath = window.appConfig?.routes?.extensionViews?.replace('{extensionName}', extensionName) || `extensions/${extensionName}/views`;
        const fullPath = `${window.BASE_URL}${basePath}/${viewPath}.json`;
        const cacheBuster = `?v=${window.VERSION}`;
        const response = await fetch(fullPath + cacheBuster);
        if (response.ok) {
          const viewData = await response.json();
          const cacheKey = `view_${extensionName}_${viewPath.replace(/\//g, '_')}`;
          window.cache?.set(cacheKey, viewData);
        }
      } catch (error) {}
    }
  }

  static async loadExtensionScript(extensionName, scriptFile) {
    try {
      const scriptPath = `extensions/${extensionName}/${scriptFile}`;
      const cacheBuster = `?v=${window.VERSION}`;
      const response = await fetch(`${window.BASE_URL}${scriptPath}${cacheBuster}`);
      if (!response.ok) return;
      const scriptContent = await response.text();
      new Function(scriptContent)();
    } catch (error) {
      logger.error('core:hook', `Error cargando autoload ${extensionName}:`, error.message);
    }
  }

  static async loadPluginResources(scripts = [], styles = []) {
    if (window.loader && typeof loader.loadResources === 'function') {
      try {
        await loader.loadResources(scripts, styles);
      } catch (error) {}
    }
  }

  // Normalizar rutas de recursos (agregar 'extensions/' si no lo tiene)
  static normalizeResourcePath(path) {
    if (!path) return path;

    // Si ya empieza con 'extensions/', dejarlo como está
    if (path.startsWith('extensions/')) {
      return path;
    }

    // Si NO empieza con 'extensions/', agregarlo
    return `extensions/${path}`;
  }

  // Normalizar array de recursos
  static normalizeResources(resources = []) {
    return resources.map(path => this.normalizeResourcePath(path));
  }

  static processMenuItems(items, parentPlugin = '', extensionScripts = [], extensionStyles = []) {
    return items
      .map(item => {
        const processedItem = {
          id: item.id,
          title: item.title,
          order: item.order || 999
        };

        // Preservar role si existe
        if (item.role) {
          processedItem.role = item.role;
        }

        // Normalizar scripts/styles del item
        const itemScripts = this.normalizeResources(item.scripts || []);
        const combinedScripts = [...extensionScripts, ...itemScripts];
        if (combinedScripts.length > 0) processedItem.scripts = combinedScripts;
        const itemStyles = this.normalizeResources(item.styles || []);
        const combinedStyles = [...extensionStyles, ...itemStyles];
        if (combinedStyles.length > 0) processedItem.styles = combinedStyles;
        if (item.preloadViews !== undefined) processedItem.preloadViews = item.preloadViews;
        if (item.items?.length > 0) {
          processedItem.items = this.processMenuItems(item.items, parentPlugin, extensionScripts, extensionStyles);
        } else if (item.view) {
          processedItem.view = item.view;
        }
        return processedItem;
      })
      .sort((a, b) => (a.order || 100) - (b.order || 100));
  }

  // Filtrar menús solo por extensions enabled=true
  static getMenuItems() {
    const menuItems = [];

    // Reconstruir menuItems desde pluginRegistry (que ya está filtrado por auth.js)
    for (const [extensionName, pluginConfig] of this.pluginRegistry) {
      // Solo incluir extensions habilitados
      if (pluginConfig.enabled !== true) {
        continue;
      }

      // Si el extension tiene menú, agregarlo
      if (pluginConfig.hasMenu && pluginConfig.menu) {
        const menuItem = {
          id: pluginConfig.name || extensionName,
          title: pluginConfig.menu.title,
          icon: pluginConfig.menu.icon,
          order: pluginConfig.menu.order || 100
        };

        // Preservar role del menú principal si existe
        if (pluginConfig.menu.role) {
          menuItem.role = pluginConfig.menu.role;
        }

        // Usar los items YA FILTRADOS del pluginConfig.menu
        if (pluginConfig.menu.items?.length > 0) {
          menuItem.items = pluginConfig.menu.items;
        } else if (pluginConfig.menu.view) {
          menuItem.view = pluginConfig.menu.view;
          if (pluginConfig.scripts) menuItem.scripts = pluginConfig.scripts;
          if (pluginConfig.styles) menuItem.styles = pluginConfig.styles;
        }

        menuItems.push(menuItem);
      }
    }

    // Ordenar por order
    menuItems.sort((a, b) => (a.order || 999) - (b.order || 999));

    return menuItems;
  }

  // ✅ NUEVO: Obtener TODOS los extensions (sin filtrar por permisos)
  static getAllExtensionsForPermissions() {
    const extensions = [];

    // Usar la copia original (sin filtrar)
    for (const [name, config] of this.pluginRegistryOriginal) {
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

  static async loadPluginConfig(extensionName) {
    try {
      const cacheBuster = `?v=${window.VERSION}`;
      return await api.get(`extensions/${extensionName}/index.json${cacheBuster}`);
    } catch (error) {
      return { name: extensionName, enabled: false, hasHooks: false };
    }
  }

  static async loadPluginHook(extensionName) {
    try {
      const hookPath = `extensions/${extensionName}/hooks.js`;
      const cacheBuster = `?v=${window.VERSION}`;
      const response = await fetch(`${window.BASE_URL}${hookPath}${cacheBuster}`);
      if (!response.ok) return;
      const scriptContent = await response.text();
      new Function(scriptContent)();
      const hookClassName = `${extensionName}Hooks`;
      if (window[hookClassName]) {
        this.loadedHooks.add(extensionName);
      }
    } catch (error) {}
  }

  static getPluginConfig(extensionName) {
    return this.pluginRegistry.get(extensionName);
  }

  static isExtensionEnabled(extensionName) {
    const config = this.pluginRegistry.get(extensionName);
    return config ? config.enabled === true : false;
  }

  static getEnabledExtensions() {
    const enabled = [];
    for (const [name, config] of this.pluginRegistry) {
      if (config.enabled === true) {
        enabled.push({ name, ...config });
      }
    }
    return enabled;
  }

  static hasPluginLanguages(extensionName) {
    const config = this.pluginRegistry.get(extensionName);
    return config?.hasLanguages || false;
  }

  static getPluginLanguages(extensionName) {
    const config = this.pluginRegistry.get(extensionName);
    return config?.loadedLanguages || [];
  }

  static execute(hookName, defaultData = []) {
    // Extraer el nombre base (sin el prefijo "hook_")
    let baseName = hookName;
    let hasPrefix = false;

    if (hookName.startsWith('hook_')) {
      baseName = hookName.replace('hook_', '');
      hasPrefix = true;
    }

    // Normalizar el nombre si contiene guiones
    // "admin-panel" → "adminPanel"
    const normalizedName = this.normalizeHookName(baseName);

    // Reconstruir el nombre completo del hook
    const finalHookName = hasPrefix ? `hook_${normalizedName}` : normalizedName;

    let results = [...defaultData];

    // Iterar sobre todos los extensions cargados
    for (const extensionName of this.loadedHooks) {
      if (!this.isExtensionEnabled(extensionName)) continue;

      const hookClass = window[`${extensionName}Hooks`];

      if (hookClass && typeof hookClass[finalHookName] === 'function') {
        try {
          const hookResult = hookClass[finalHookName]();

          if (Array.isArray(hookResult)) {
            const itemsWithOrder = hookResult.map(item => ({
              order: item.order || 999,
              ...item
            }));
            results = [...results, ...itemsWithOrder];
          } else {
            logger.warn('core:hook', `${extensionName}.${finalHookName}() no retornó un array`);
          }
        } catch (error) {
          logger.error('core:hook', `Error ejecutando ${extensionName}.${finalHookName}():`, error);
        }
      }
    }

    // Ordenar por campo "order"
    results.sort((a, b) => (a.order || 999) - (b.order || 999));

    return results;
  }

  static async renderHookResult(hookResult, container) {
    if (!hookResult || !container) return;

    if (hookResult.type === 'html') {
      // Renderizar HTML directo
      const wrapper = document.createElement('div');
      wrapper.id = hookResult.id;
      wrapper.innerHTML = hookResult.content;
      container.appendChild(wrapper);
    }
    else if (hookResult.type === 'component') {
      // Renderizar componente
      const wrapper = document.createElement('div');
      wrapper.id = hookResult.id;
      container.appendChild(wrapper);

      const componentName = hookResult.component;

      // Verificar que el componente existe
      if (window[componentName] && typeof window[componentName].render === 'function') {
        try {
          await window[componentName].render(hookResult.config, wrapper);
        } catch (error) {
          logger.error('core:hook', `Error renderizando componente "${componentName}":`, error);
          wrapper.innerHTML = `<div style="padding:1rem;background:#fee;border:1px solid #fcc;border-radius:4px;">${__('core.hook.error.loading_component', { component: componentName })}</div>`;
        }
      } else {
        logger.error('core:hook', `Componente "${componentName}" no encontrado`);
        wrapper.innerHTML = `<div style="padding:1rem;background:#fee;border:1px solid #fcc;border-radius:4px;">${__('core.hook.error.component_not_available', { component: componentName })}</div>`;
      }
    }
  }

  // Método helper para renderizar múltiples hooks en un contenedor
  static async renderHooks(hookName, containerId, defaultData = []) {
    const container = document.getElementById(containerId);
    if (!container) {
      logger.error('core:hook', `Container "${containerId}" no encontrado`);
      return;
    }

    const results = this.execute(hookName, defaultData);

    for (const result of results) {
      await this.renderHookResult(result, container);
    }
  }

  static normalizeHookName(viewId) {
    return viewId.replace(/-([a-z0-9])/g, (match, char) => char.toUpperCase());
  }
}

window.hook = hook;