/**
 * Action - Manejador de acciones genéricas
 * Abstrae las acciones para que funcionen en web Y React Native (futuro)
 *
 * Uso:
 * action.handle('navigate:products');
 * action.handle('modal:user-form');
 * action.handle('api:save', {data: {...}});
 */
class ogAction {
  static handle(action, params = {}, context = {}) {
    if (!action || typeof action !== 'string') {
      ogLogger.warn('core:action', 'Acción inválida:', action);
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
    const value = valueParts.join(':');

    if (!type || !value) {
      ogLogger.warn('core:action', 'Formato inválido:', action);
      return;
    }

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
        ogLogger.warn('core:action', `Tipo de acción desconocido: ${type}`);
    }
  }

  static handleNavigate(screen, params, context) {
    const navigation = ogModule('navigation');
    const view = ogModule('view');

    if (navigation && typeof navigation.navigate === 'function') {
      navigation.navigate(screen, {
        container: context.container,
        extension: context.extensionContext,
        menuId: context.menuId,
        ...params
      });
    } else if (view && typeof view.loadView === 'function') {
      view.loadView(screen, context.container || null, context.extensionContext || null, null, null, context.menuId || null);
    }
  }

  static handleModal(viewPath, params, context) {
    const modal = ogComponent('modal');

    if (modal && typeof modal.open === 'function') {
      modal.open(viewPath, params);
    } else {
      ogLogger.error('core:action', 'Modal no disponible');
    }
  }

  static async handleApi(endpoint, params, context) {
    const method = params.method || 'POST';
    const api = ogModule('api');

    if (!api) {
      ogLogger.error('core:action', 'API no disponible');
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
          ogLogger.warn('core:action', `Método HTTP desconocido: ${method}`);
      }
    } catch (error) {
      ogLogger.error('core:action', `Error en API ${endpoint}:`, error);
      throw error;
    }
  }

  static handleCustom(functionName, params, context) {
    if (window[functionName] && typeof window[functionName] === 'function') {
      return window[functionName](params, context);
    }

    ogLogger.warn('core:action', `Función custom no encontrada: ${functionName}`);
  }

  static handleSubmit(handler, params, context) {
    let form = null;

    if (context.button) {
      form = context.button.closest('form');
    }

    if (!form) {
      const openModal = document.querySelector('.modal-overlay:not([style*="display: none"])');
      if (openModal) {
        form = openModal.querySelector('form');
      }
    }

    if (!form) {
      const forms = document.querySelectorAll('form');
      form = Array.from(forms).find(f => f.offsetParent !== null);
    }

    if (!form) {
      ogLogger.error('core:action', 'No se encontró formulario');
      ogComponent('toast')?.error('No se encontró formulario');
      return;
    }

    const formId = form.id;

    const parts = handler.split('.');

    if (parts.length === 2) {
      const [objName, methodName] = parts;
      const obj = window[objName];

      if (obj && typeof obj[methodName] === 'function') {
        return obj[methodName](formId, params);
      }
    }

    ogLogger.error('core:action', `Handler no encontrado: ${handler}`);
    ogComponent('toast')?.error(`Handler no encontrado: ${handler}`);
  }

  static handleMethodCall(methodPath, params, context) {
    try {
      const parts = methodPath.split(':');
      const objectMethod = parts[0];
      const args = parts.slice(1);

      const methodParts = objectMethod.split('.');

      if (methodParts.length === 1) {
        const funcName = methodParts[0];
        console.log(`funcName:`, funcName);
        if (typeof window[funcName] === 'function') {
          return window[funcName](...args);
        } else {
          ogLogger.error('core:action', `Función global no encontrada: ${funcName}`);
          return;
        }
      }

      let obj = window;
      for (let i = 0; i < methodParts.length - 1; i++) {
        obj = obj[methodParts[i]];
        if (!obj) {
          ogLogger.error('core:action', `Objeto no encontrado: ${methodParts.slice(0, i + 1).join('.')}`);
          return;
        }
      }

      const methodName = methodParts[methodParts.length - 1];
      const method = obj[methodName];

      if (typeof method !== 'function') {
        ogLogger.error('core:action', `Método no es una función: ${objectMethod}`);
        return;
      }

      return method.apply(obj, args);

    } catch (error) {
      ogLogger.error('core:action', `Error ejecutando método: ${methodPath}`, error);
    }
  }
}

// Global
window.ogAction = ogAction;

if (typeof window.ogFramework !== 'undefined') {
  window.ogFramework.core.action = ogAction;
}

// Proxy global para uso en HTML onclick
window.actionProxy = {
  handle: (actionStr, params, context) => {
    return window.ogFramework.core.action.handle(actionStr, params, context);
  }
};