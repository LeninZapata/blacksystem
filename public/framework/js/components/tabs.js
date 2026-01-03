class ogTabs {
  static tabCache = new Map();
  static extensionContextMap = new Map(); // Guardar contexto por ID de tabs

  static async render(tabsData, container) {
    this.tabCache.clear();

    if (!tabsData || !tabsData.tabs || !container) return;

    // Obtener el contexto de extensión del contenedor padre para preservarlo
    const viewContainer = container.closest('[data-extension-context]');
    const extensionContext = viewContainer?.getAttribute('data-extension-context') || null;

    // Guardar el contexto para este tabs específico
    this.extensionContextMap.set(tabsData.id, extensionContext);

    const tabsHTML = `
      <div class="tabs-component">
        <div class="tabs-header">
          ${tabsData.tabs.map(tab => `
            <button class="tab-btn" data-tab="${tab.id}">
              ${tab.title}
            </button>
          `).join('')}
        </div>
        <div class="tab-content" id="tab-content-${tabsData.id}"></div>
      </div>
    `;

    container.innerHTML = tabsHTML;
    this.bindTabEvents(tabsData, container);

    // Detectar overflow para mostrar degradado
    this.checkOverflow(container);

    // Recheck on window resize
    window.addEventListener('resize', () => this.checkOverflow(container));

    const firstTab = tabsData.tabs[0];
    if (firstTab) {
      await this.loadTabContent(tabsData, firstTab.id, container);
    }

    // Precargar todas las tabs si está habilitado
    if (tabsData.preloadAllTabs === true) {
      await this.preloadAllTabs(tabsData, container);
    }
  }

  // Detectar overflow en ambos lados
  static checkOverflow(container) {
    const tabsHeader = container.querySelector('.tabs-header');
    if (!tabsHeader) return;

    const updateOverflow = () => {
      const scrollLeft = tabsHeader.scrollLeft;
      const scrollWidth = tabsHeader.scrollWidth;
      const clientWidth = tabsHeader.clientWidth;

      // Hay overflow si el contenido es más ancho que el contenedor
      const hasOverflow = scrollWidth > clientWidth;

      if (!hasOverflow) {
        tabsHeader.classList.remove('has-overflow-left', 'has-overflow-right');
        return;
      }

      // Detectar si está al inicio (no hay scroll a la izquierda)
      const isAtStart = scrollLeft <= 5;

      // Detectar si está al final (no hay más contenido a la derecha)
      const isAtEnd = scrollLeft + clientWidth >= scrollWidth - 5;

      // Agregar/quitar clases según posición
      if (isAtStart) {
        tabsHeader.classList.remove('has-overflow-left');
      } else {
        tabsHeader.classList.add('has-overflow-left');
      }

      if (isAtEnd) {
        tabsHeader.classList.remove('has-overflow-right');
      } else {
        tabsHeader.classList.add('has-overflow-right');
      }
    };

    // Ejecutar al cargar
    updateOverflow();

    // Actualizar en scroll
    tabsHeader.addEventListener('scroll', updateOverflow);
  }

  static async preloadAllTabs(tabsData, container) {
    const tabsToPreload = tabsData.tabs.slice(1);

    for (const tab of tabsToPreload) {
      try {
        await this.loadTabContentSilent(tabsData, tab.id, container);
      } catch (error) {
        ogLogger.error('com:tabs', `Error precargando tab ${tab.id}:`, error);
      }
    }
  }

  static async loadTabContentSilent(tabsData, tabId, container) {
    const tab = tabsData.tabs.find(t => t.id === tabId);
    if (!tab) return;

    const cacheKey = `${tabsData.id}-${tabId}`;

    if (this.tabCache.has(cacheKey)) {
      return;
    }

    try {
      const renderedContent = this.renderContent(tab.content);
      const tempContainer = document.createElement('div');
      tempContainer.innerHTML = renderedContent;

      await this.loadDynamicComponents(tabsData.id, tempContainer);
      this.tabCache.set(cacheKey, tempContainer);
    } catch (error) {
      ogLogger.error('com:tabs', `Error cargando tab ${tabId}:`, error);
    }
  }

  static bindTabEvents(tabsData, container) {
    const tabButtons = container.querySelectorAll('.tab-btn');

    tabButtons.forEach(btn => {
      btn.addEventListener('click', async (e) => {
        const tabId = btn.dataset.tab;

        tabButtons.forEach(b => b.classList.remove('active'));
        btn.classList.add('active');

        // Scroll suave al tab activo si está fuera de vista
        btn.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });

        await this.loadTabContent(tabsData, tabId, container);
      });
    });

    if (tabButtons[0]) {
      tabButtons[0].classList.add('active');
    }
  }

  static async loadTabContent(tabsData, tabId, container) {
    const tabContent = container.querySelector('.tab-content');
    const tab = tabsData.tabs.find(t => t.id === tabId);

    if (!tab) {
      tabContent.innerHTML = `<div class="tab-error">${__('com.tabs.not_found')}</div>`;
      return;
    }

    const cacheKey = `${tabsData.id}-${tabId}`;
    const isFirstLoad = !this.tabCache.has(cacheKey);

    if (this.tabCache.has(cacheKey)) {
      const cachedNode = this.tabCache.get(cacheKey);
      tabContent.innerHTML = '';
      tabContent.appendChild(cachedNode);
      return;
    }

    tabContent.innerHTML = `<div class="tab-loading">${__('com.tabs.loading')}</div>`;

    try {
      const renderedContent = this.renderContent(tab.content);

      const tempContainer = document.createElement('div');
      tempContainer.innerHTML = renderedContent;

      await this.loadDynamicComponents(tabsData.id, tempContainer);

      this.tabCache.set(cacheKey, tempContainer);

      tabContent.innerHTML = '';
      tabContent.appendChild(tempContainer);

      if (isFirstLoad && tab.onLoad) {
        this.executeCallback(tab.onLoad);
      }

    } catch (error) {
      ogLogger.error('com:tabs', `Error cargando tab ${tabId}:`, error);
      tabContent.innerHTML = `<div class="tab-error">${__('com.tabs.error_loading')}</div>`;
    }
  }

  static renderContent(content) {
    if (typeof content === 'string') return content;

    if (Array.isArray(content)) {
      return content
        .filter(item => item != null)
        .map(item => this.renderContentItem(item))
        .join('');
    }

    if (content == null) return '';
    return this.renderContentItem(content);
  }

  static renderContentItem(item) {
    if (item == null) return '';
    if (typeof item === 'string') return item;
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
      return item.content || '';
    }

    return '';
  }

  static async loadDynamicComponents(tabsId, container) {
    // Obtener el contexto guardado para este tabs específico
    const extensionContext = this.extensionContextMap.get(tabsId) || null;

    const formContainers = container.querySelectorAll('.dynamic-form[data-form-json]');

    for (const formContainer of formContainers) {
      const formJson = formContainer.dataset.formJson;

      try {
        let formPath = formJson;

        // Si hay contexto de extensión y el formJson no incluye '|'
        if (extensionContext && !formJson.includes('|')) {
          // Remover el prefijo de extensión si ya está presente
          const cleanFormJson = formJson.startsWith(`${extensionContext}/`)
            ? formJson.substring(extensionContext.length + 1)
            : formJson;

          formPath = `${extensionContext}|forms/${cleanFormJson}`;
        }

        await ogModule('form').load(formPath, formContainer);
      } catch (error) {
        ogLogger.error('com:tabs', 'Error cargando formulario:', error);
        formContainer.innerHTML = `<div class="error">${__('com.tabs.error_form', { form: formJson })}</div>`;
      }
    }

    // Cargar componentes dinámicos
    const componentContainers = container.querySelectorAll('.dynamic-component[data-component]');

    for (const compContainer of componentContainers) {
      const componentName = compContainer.dataset.component;
      const configStr = compContainer.dataset.config;

      try {
        const config = configStr ? JSON.parse(configStr) : {};

        // Usar ogComponent para buscar el componente
        const component = ogComponent(componentName);

        if (component && typeof component.render === 'function') {
          await component.render(config, compContainer);
        } else {
          ogLogger.warn('com:tabs', `Componente ${componentName} no encontrado`);
          compContainer.innerHTML = `<div class="error">${__('com.tabs.component_not_available', { component: componentName })}</div>`;
        }
      } catch (error) {
        ogLogger.error('com:tabs', `Error cargando componente ${componentName}:`, error);
        compContainer.innerHTML = `<div class="error">${__('com.tabs.error_component', { component: componentName })}</div>`;
      }
    }
  }

  static clearCache() {
    this.tabCache.clear();
  }

  static executeCallback(callback) {
    try {
      // Si contiene paréntesis, asumir que es código ejecutable
      if (callback.includes('(') || callback.includes(';')) {
        new Function(callback)();
        return;
      }

      // Intentar ejecutar como referencia a función (ej: productStats.onGastosTabLoaded)
      const parts = callback.split('.');
      let fn = window;

      for (const part of parts) {
        fn = fn[part];
        if (!fn) {
          ogLogger.warn('com:tabs', `Callback no encontrado: ${callback}`);
          return;
        }
      }

      if (typeof fn === 'function') {
        fn();
      } else {
        ogLogger.warn('com:tabs', `${callback} no es una función`);
      }
    } catch (error) {
      ogLogger.error('com:tabs', `Error ejecutando callback ${callback}:`, error);
    }
  }
}

// Exponer GLOBALMENTE como ogTabs
window.ogTabs = ogTabs;

// Registrar en ogFramework (preferido)
if (typeof window.ogFramework !== 'undefined') {
  window.ogFramework.components.tabs = ogTabs;
}