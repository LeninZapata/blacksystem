class ogModal {
  static modals = new Map();
  static counter = 0;


  static open(resource, options = {}) {
    const modalId = `modal-${++this.counter}`;

    // Crear overlay
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.id = modalId;

    // Crear contenedor
    const container = document.createElement('div');
    container.className = 'modal-container';
    container.style.width = options.width || '80%';
    container.style.maxWidth = options.maxWidth || '900px';

    // Header
    const header = document.createElement('div');
    header.className = 'modal-header';
    const processedTitle = this.processI18nInString(options.title) || __('com.modal.title');
    header.innerHTML = `
      <h3>${processedTitle}</h3>
      <button class="modal-close" onclick="ogModal.close('${modalId}')">&times;</button>
    `;

    // Content
    const content = document.createElement('div');
    content.className = 'modal-content';
    content.innerHTML = `<div class="modal-loading">${__('com.modal.loading')}</div>`;

    // Footer (opcional)
    const footer = document.createElement('div');
    footer.className = 'modal-footer';
    if (options.footer) {
      footer.innerHTML = this.processI18nInString(options.footer);
    } else if (options.showFooter !== false) {
      footer.innerHTML = `
        <button class="btn btn-secondary" onclick="ogModal.close('${modalId}')">${__('com.modal.close')}</button>
      `;
    }

    // Ensamblar
    container.appendChild(header);
    container.appendChild(content);
    if (options.showFooter !== false || options.footer) {
      container.appendChild(footer);
    }
    overlay.appendChild(container);
    document.body.appendChild(overlay);

    // Guardar referencia
    this.modals.set(modalId, overlay);

    // Cargar contenido (retorna Promise)
    const loadPromise = this.loadContent(modalId, resource, options);

    return { modalId, loadPromise };
  }

  static async loadContent(modalId, resource, options) {
    const content = document.querySelector(`#${modalId} .modal-content`);

    try {
      // HTML directo
      if (options.html) {
        content.innerHTML = resource;
        return;
      }

      // CORE: Formulario del core con prefijo "core:"
      if (typeof resource === 'string' && resource.startsWith('core:')) {
        const corePath = resource.replace('core:', '');
        const afterRenderCallback = options.afterRender || null;

        await ogModule('form').load(corePath, content, null, true, afterRenderCallback);
        return;
      }

      // Formulario de extension con prefijo "extension:"
      if (typeof resource === 'string' && resource.startsWith('extension:')) {
        const pluginPath = resource.replace('extension:', '');
        const afterRenderCallback = options.afterRender || null;
        await ogModule('form').load(pluginPath, content, null, false, afterRenderCallback);
        return;
      }

      // Formulario de extension (legacy): "ejemplos|forms/login"
      if (typeof resource === 'string' && resource.includes('|forms/')) {
        const [extensionName, formPath] = resource.split('|');
        const formName = formPath.replace('forms/', '');

        // ✅ FIX: Pasar afterRender callback
        const afterRenderCallback = options.afterRender || null;
        await ogModule('form').load(`${extensionName}/${formName}`, content, null, false, afterRenderCallback);
        return;
      }

      // Formulario del core (sin prefijo): "user/forms/user-form" o "auth/forms/login-form"
      if (typeof resource === 'string' && resource.includes('/forms/')) {
        await ogModule('form').load(resource, content);
        return;
      }

      // Vista del core con prefijo "core:"
      if (typeof resource === 'string' && resource.startsWith('core:sections/')) {
        const corePath = resource.replace('core:sections/', '');
        const afterRenderCallback = options.afterRender || null;
        await ogModule('view').loadView(corePath, content, null, null, afterRenderCallback);
        return;
      }

      // Vista de extension: "user|sections/user"
      if (typeof resource === 'string' && resource.includes('|')) {
        const [extension, viewPath] = resource.split('|');
        const afterRenderCallback = options.afterRender || null;
        await ogModule('view').loadView(`${viewPath}`, content, extension, null, afterRenderCallback);
        return;
      }

      // Vista simple: "dashboard" o "sections/user"
      if (typeof resource === 'string') {
        const afterRenderCallback = options.afterRender || null;
        await ogModule('view').loadView(resource, content, null, null, afterRenderCallback);
        return;
      }

      // Objeto con configuración
      if (typeof resource === 'object') {
        if (resource.view) {
          const afterRenderCallback = options.afterRender || null;
          await ogModule('view').loadView(resource.view, content, null, null, afterRenderCallback);
          return;
        }
      }

      throw new Error('Formato de recurso no válido');

    } catch (error) {
      ogLogger.error('com:modal', 'Error cargando contenido:', error);
      content.innerHTML = `
        <div class="alert alert-danger">
          <h4>${__('com.modal.error')}</h4>
          <p>${__('com.modal.error_loading', { error: error.message })}</p>
        </div>
      `;
    }
  }

  static close(modalId) {
    const overlay = this.modals.get(modalId);

    if (overlay) {
      overlay.remove();
      this.modals.delete(modalId);
      // ogComponent('modal')?.clearCache();
    }
  }

  static closeAll() {
    this.modals.forEach((overlay, id) => this.close(id));
  }

  static async openWithData(resource, options = {}) {
    const dataLoader = ogModule('dataLoader');
    const hook = ogModule('hook');
    const toast = ogComponent('toast');
    // form no se usa directamente aquí

    if (!options.id) {
      ogLogger.warn('com:modal', 'No se especificó ID para cargar datos');
      const result = this.open(resource, options);
      return result.modalId;
    }

    const { modalId, loadPromise } = this.open(resource, options);

    let extensionName = null;
    if (resource.includes('|')) {
      extensionName = resource.split('|')[0];
    }

    let dataLoaderConfig = options.dataLoader;

    if (!dataLoaderConfig) {
      const button = event?.target?.closest('[data-loader-config]');
      if (button) {
        const configStr = button.getAttribute('data-loader-config');
        dataLoaderConfig = JSON.parse(configStr.replace(/&quot;/g, '"'));
      }
    }

    if (!dataLoaderConfig && extensionName) {
      const pluginConfig = hook?.getPluginConfig(extensionName);
      if (pluginConfig?.backend) {
        dataLoaderConfig = {
          type: 'auto',
          api: {
            enabled: pluginConfig.backend.enabled,
            endpoint: pluginConfig.backend.endpoints?.show?.path?.replace('{id}', options.id)
          },
          mock: pluginConfig.mockData
        };
      }
    }

    if (dataLoaderConfig && dataLoader) {
      try {
        await loadPromise;
        const data = await dataLoader.loadDetail(dataLoaderConfig, options.id, extensionName);

        const modalContent = document.querySelector(`#${modalId} .modal-content`);
        const formElement = modalContent?.querySelector('form');

        if (!formElement) {
          ogLogger.error('com:modal', 'No se encontró ningún formulario en el modal');
          if (toast) {
            ogToast.error(__('com.modal.form_not_found'));
          }
          return modalId;
        }

        const realFormId = formElement.getAttribute('id');

        ogModule('form')?.fill(realFormId, data, modalContent);

      } catch (error) {
        ogLogger.error('com:modal', 'Error cargando datos', error);
        if (toast) {
          ogToast.error(__('com.modal.error_loading_data'));
        }
      }
    } else {
      ogLogger.warn('com:modal', 'No se pudo cargar datos (sin dataLoader)');
    }

    return modalId;
  }

  static waitForForm(formId, timeout = 3000) {
    return new Promise((resolve, reject) => {
      const startTime = Date.now();

      const checkForm = () => {
        const formElement = document.getElementById(formId);

        if (formElement) {
          const elapsed = Date.now() - startTime;
          resolve(formElement);
        } else if (Date.now() - startTime >= timeout) {
          ogLogger.error('com:modal', `Timeout - Formulario "${formId}" no encontrado`);
          reject(new Error(`Formulario ${formId} no encontrado`));
        } else {
          requestAnimationFrame(checkForm);
        }
      };

      checkForm();
    });
  }

  // Procesar cadenas i18n en contenido (usa i18n.processString)
  static processI18nInString(str) {
    const i18n = ogModule('i18n');
    return i18n ? i18n.processString(str) : str;
  }
}

// ✅ Exponer GLOBALMENTE como ogModal (usado en onclick de HTML)
window.ogModal = ogModal;

// Registrar en ogFramework
if (typeof window.ogFramework !== 'undefined') {
  window.ogFramework.components.modal = ogModal;
}

// Cerrar modal al hacer clic en el overlay
document.addEventListener('click', (e) => {
  if (e.target.classList.contains('modal-overlay')) {
    const modalId = e.target.id;
    ogModal.close(modalId);
  }
});