/**
 * Action - Manejador de acciones genéricas
 * Abstrae las acciones para que funcionen en web Y React Native (futuro)
 *
 * Uso:
 * action.handle('navigate:products');
 * action.handle('modal:user-form');
 * action.handle('api:save', {data: {...}});
 */
class action {
  static handle(action, params = {}, context = {}) {
    if (!action || typeof action !== 'string') {
      logger.warn('core:action', 'Acción inválida:', action);
      return;
    }

    // Parsear query params si existen
    const [fullAction, queryString] = action.split('?');
    if (queryString) {
      const urlParams = new URLSearchParams(queryString);
      urlParams.forEach((val, key) => {
        params[key] = val;
      });
    }

    const [type, ...valueParts] = fullAction.split(':');
    const value = valueParts.join(':'); // Por si el valor tiene ':'

    if (!type || !value) {
      logger.warn('core:action', 'Formato inválido:', action);
      return;
    }

    logger.debug('core:action', `Ejecutando: ${type}:${value}`, params);

    switch(type.toLowerCase()) {
      case 'navigate':
        return this.handleNavigate(value, params, context);

      case 'modal':
        return this.handleModal(value, params, context);

      case 'api':
        return this.handleApi(value, params, context);

      case 'call':
      case 'method':
        return this.handleMethodCall(value, params, context);
      case 'submit':
        return this.handleSubmit(value, params, context);

      case 'custom':
        return this.handleCustom(value, params, context);

      default:
        logger.warn('core:action', `Tipo de acción desconocido: ${type}`);
    }
  }

  static handleNavigate(screen, params, context) {
    logger.debug('core:action', `Navigate to: ${screen}`, params);

    // Usar navigation si está disponible
    if (window.navigation && typeof navigation.navigate === 'function') {
      navigation.navigate(screen, {
        container: context.container,
        extension: context.extensionContext,
        menuId: context.menuId,
        ...params
      });
    } else {
      // Fallback: view.loadView() directo
      if (window.view && typeof view.loadView === 'function') {
        view.loadView(screen, context.container || null, context.extensionContext || null, null, null, context.menuId || null);
      }
    }
  }

  static handleModal(viewPath, params, context) {
    logger.debug('core:action', `Open modal: ${viewPath}`, params);

    if (window.modal && typeof modal.open === 'function') {
      modal.open(viewPath, params);
    } else {
      logger.error('core:action', 'Modal no disponible');
    }
  }

  static async handleApi(endpoint, params, context) {
    logger.debug('core:action', `API call: ${endpoint}`, params);

    const method = params.method || 'POST';

    if (!window.api) {
      logger.error('core:action', 'API no disponible');
      return;
    }

    try {
      switch(method.toUpperCase()) {
        case 'GET':
          return await api.get(endpoint, params);
        case 'POST':
          return await api.post(endpoint, params.data || params);
        case 'PUT':
          return await api.put(endpoint, params.data || params);
        case 'DELETE':
          return await api.delete(endpoint);
        default:
          logger.warn('core:action', `Método HTTP desconocido: ${method}`);
      }
    } catch (error) {
      logger.error('core:action', `Error en API ${endpoint}:`, error);
      throw error;
    }
  }

  static handleCustom(functionName, params, context) {
    logger.debug('core:action', `Custom function: ${functionName}`, params);

    // Buscar función en window
    if (window[functionName] && typeof window[functionName] === 'function') {
      return window[functionName](params, context);
    }

    logger.warn('core:action', `Función custom no encontrada: ${functionName}`);
  }

  static handleSubmit(handler, params, context) {
    logger.debug('core:action', `Submit: ${handler}`);

    // Obtener formulario de múltiples formas
    let form = null;

    // 1. Si viene desde context.button
    if (context.button) {
      form = context.button.closest('form');
    }

    // 2. Si hay un modal abierto, buscar el form dentro del modal
    if (!form) {
      const openModal = document.querySelector('.modal-overlay:not([style*="display: none"])');
      if (openModal) {
        form = openModal.querySelector('form');
      }
    }

    // 3. Buscar cualquier form visible en la página
    if (!form) {
      const forms = document.querySelectorAll('form');
      form = Array.from(forms).find(f => f.offsetParent !== null);
    }

    if (!form) {
      logger.error('core:action', 'No se encontró formulario');
      if (window.toast) toast.error('No se encontró formulario');
      return;
    }

    const formId = form.id;
    logger.debug('core:action', `Formulario encontrado: ${formId}`);

    // Separar objeto.método
    const parts = handler.split('.');

    if (parts.length === 2) {
      const [objName, methodName] = parts;
      const obj = window[objName];

      if (obj && typeof obj[methodName] === 'function') {
        logger.debug('core:action', `Llamando: ${handler}(${formId})`);
        return obj[methodName](formId, params);
      }
    }

    logger.error('core:action', `Handler no encontrado: ${handler}`);
    if (window.toast) toast.error(`Handler no encontrado: ${handler}`);
  }

  static handleMethodCall(methodPath, params, context) {
    try {
      // Formato: "object.method:param1:param2:param3"
      // Ejemplo: "toast.success:Guardado correctamente"
      // Ejemplo: "toast.info:Usuario actualizado:5000"

      const parts = methodPath.split(':');
      const objectMethod = parts[0]; // "toast.success"
      const args = parts.slice(1); // ["Guardado correctamente"]

      // Separar objeto y método
      const methodParts = objectMethod.split('.');

      if (methodParts.length === 1) {
        // Función global simple: "alert:Hola"
        const funcName = methodParts[0];
        if (typeof window[funcName] === 'function') {
          logger.debug('core:action', `Llamando función global: ${funcName}`, args);
          return window[funcName](...args);
        } else {
          logger.error('core:action', `Función global no encontrada: ${funcName}`);
          return;
        }
      }

      // Objeto.método: "toast.success"
      let obj = window;
      for (let i = 0; i < methodParts.length - 1; i++) {
        obj = obj[methodParts[i]];
        if (!obj) {
          logger.error('core:action', `Objeto no encontrado: ${methodParts.slice(0, i + 1).join('.')}`);
          return;
        }
      }

      const methodName = methodParts[methodParts.length - 1];
      const method = obj[methodName];

      if (typeof method !== 'function') {
        logger.error('core:action', `Método no es una función: ${objectMethod}`);
        return;
      }

      logger.debug('core:action', `Llamando: ${objectMethod}(${args.join(', ')})`);
      return method.apply(obj, args);

    } catch (error) {
      logger.error('core:action', `Error ejecutando método: ${methodPath}`, error);
    }
  }
}

window.action = action;

// Crear proxy global para uso en HTML onclick
window.actionProxy = {
  handle: (actionStr, params, context) => {
    return action.handle(actionStr, params, context);
  }
};