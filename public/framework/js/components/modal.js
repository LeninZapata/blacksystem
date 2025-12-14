class modal {
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
    header.innerHTML = `
      <h3>${options.title || __('com.modal.title')}</h3>
      <button class="modal-close" onclick="modal.close('${modalId}')">&times;</button>
    `;

    // Content
    const content = document.createElement('div');
    content.className = 'modal-content';
    content.innerHTML = `<div class="modal-loading">${__('com.modal.loading')}</div>`;

    // Footer (opcional)
    const footer = document.createElement('div');
    footer.className = 'modal-footer';
    if (options.footer) {
      footer.innerHTML = options.footer;
    } else if (options.showFooter !== false) {
      footer.innerHTML = `
        <button class="btn btn-secondary" onclick="modal.close('${modalId}')">${__('com.modal.close')}</button>
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
        logger.debug('com:modal', `Cargando formulario core: "${corePath}"`);

        const afterRenderCallback = options.afterRender || null;
        await form.load(corePath, content, null, true, afterRenderCallback);
        return;
      }

      // Formulario de extension con prefijo "extension:"
      if (typeof resource === 'string' && resource.startsWith('extension:')) {
        const pluginPath = resource.replace('extension:', '');
        logger.debug('com:modal', `Cargando formulario extension: "${pluginPath}"`);

        const afterRenderCallback = options.afterRender || null;
        await form.load(pluginPath, content, null, false, afterRenderCallback);
        return;
      }

      // Formulario de extension (legacy): "ejemplos|forms/login"
      if (typeof resource === 'string' && resource.includes('|forms/')) {
        const [extensionName, formPath] = resource.split('|');
        const formName = formPath.replace('forms/', '');
        logger.debug('com:modal', `Cargando formulario extension: "${extensionName}/${formName}"`);
        
        // ✅ FIX: Pasar afterRender callback
        const afterRenderCallback = options.afterRender || null;
        await form.load(`${extensionName}/${formName}`, content, null, false, afterRenderCallback);
        return;
      }

      // Formulario del core (sin prefijo): "user/forms/user-form" o "auth/forms/login-form"
      if (typeof resource === 'string' && resource.includes('/forms/')) {
        logger.debug('com:modal', `Cargando formulario: "${resource}"`);
        await form.load(resource, content);
        return;
      }

      // Vista del core con prefijo "core:"
      if (typeof resource === 'string' && resource.startsWith('core:sections/')) {
        const corePath = resource.replace('core:sections/', '');
        logger.debug('com:modal', `Cargando vista core: "${corePath}"`);

        const afterRenderCallback = options.afterRender || null;
        await view.loadView(corePath, content, null, null, afterRenderCallback);
        return;
      }

      // Vista de extension: "user|sections/user"
      if (typeof resource === 'string' && resource.includes('|')) {
        const [extension, viewPath] = resource.split('|');
        logger.debug('com:modal', `Cargando vista extension: "${extension}/${viewPath}"`);

        const afterRenderCallback = options.afterRender || null;
        await view.loadView(`${viewPath}`, content, extension, null, afterRenderCallback);
        return;
      }

      // Vista simple: "dashboard" o "sections/user"
      if (typeof resource === 'string') {
        logger.debug('com:modal', `Cargando vista: "${resource}"`);

        const afterRenderCallback = options.afterRender || null;
        await view.loadView(resource, content, null, null, afterRenderCallback);
        return;
      }

      // Objeto con configuración
      if (typeof resource === 'object') {
        if (resource.view) {
          const afterRenderCallback = options.afterRender || null;
          await view.loadView(resource.view, content, null, null, afterRenderCallback);
          return;
        }
      }

      throw new Error('Formato de recurso no válido');

    } catch (error) {
      logger.error('com:modal', 'Error cargando contenido:', error);
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
      tabs.clearCache();
    }
  }

  static closeAll() {
    this.modals.forEach((overlay, id) => this.close(id));
  }

  static async openWithData(resource, options = {}) {
    logger.debug('com:modal', 'openWithData', { resource, options });

    if (!options.id) {
      logger.warn('com:modal', 'No se especificó ID para cargar datos');
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
        logger.debug('com:modal', 'DataLoader encontrado en botón');
      }
    }

    if (!dataLoaderConfig && extensionName) {
      const pluginConfig = window.hook?.getPluginConfig(extensionName);
      if (pluginConfig?.backend) {
        dataLoaderConfig = {
          type: 'auto',
          api: {
            enabled: pluginConfig.backend.enabled,
            endpoint: pluginConfig.backend.endpoints?.show?.path?.replace('{id}', options.id)
          },
          mock: pluginConfig.mockData
        };
        logger.debug('com:modal', 'DataLoader construido desde extension config');
      }
    }

    if (dataLoaderConfig && window.dataLoader) {
      try {
        await loadPromise;
        logger.debug('com:modal', 'Contenido cargado');

        const data = await dataLoader.loadDetail(dataLoaderConfig, options.id, extensionName);
        logger.debug('com:modal', 'Datos cargados', data);

        const modalContent = document.querySelector(`#${modalId} .modal-content`);
        const formElement = modalContent?.querySelector('form');

        if (!formElement) {
          logger.error('com:modal', 'No se encontró ningún formulario en el modal');
          if (window.toast) {
            toast.error(__('com.modal.form_not_found'));
          }
          return modalId;
        }

        const realFormId = formElement.getAttribute('id');
        logger.debug('com:modal', `Formulario encontrado con ID: "${realFormId}"`);

        if (window.form) {
          form.fill(realFormId, data, modalContent);
        }

      } catch (error) {
        logger.error('com:modal', 'Error cargando datos', error);
        if (window.toast) {
          toast.error(__('com.modal.error_loading_data'));
        }
      }
    } else {
      logger.warn('com:modal', 'No se pudo cargar datos (sin dataLoader)');
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
          logger.debug('com:modal', `Formulario encontrado después de ${elapsed}ms`);
          resolve(formElement);
        } else if (Date.now() - startTime >= timeout) {
          logger.error('com:modal', `Timeout - Formulario "${formId}" no encontrado`);
          reject(new Error(`Formulario ${formId} no encontrado`));
        } else {
          requestAnimationFrame(checkForm);
        }
      };

      checkForm();
    });
  }
}

window.modal = modal;

// Cerrar modal al hacer clic en el overlay
document.addEventListener('click', (e) => {
  if (e.target.classList.contains('modal-overlay')) {
    const modalId = e.target.id;
    modal.close(modalId);
  }
});