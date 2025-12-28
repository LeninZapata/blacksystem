/**
 * Navigation - Sistema de navegación simple
 * Wrapper sobre view.loadView() con historial en memoria
 * Sin URLs, sin hash routing, solo gestión de vistas
 * 
 * Preparado para React Native (mismo API)
 */
class ogNavigation {
  static history = [];
  static currentScreen = null;
  static maxHistory = 50;

  static getModules() {
    return {
      view: window.ogFramework?.core?.view || window.view,
    };
  }

  /**
   * Navegar a una vista
   * @param {string} screen - Ruta de la vista (ej: "users/list", "dashboard/dashboard")
   * @param {object} params - Parámetros opcionales
   */
  static navigate(screen, params = {}) {
    const { view } = this.getModules();
    
    if (!screen) {
      ogLogger?.warn('core:navigation', 'Screen no especificado');
      return;
    }

    // Guardar en historial
    if (this.currentScreen) {
      this.history.push({
        screen: this.currentScreen,
        timestamp: Date.now()
      });

      // Limitar tamaño del historial
      if (this.history.length > this.maxHistory) {
        this.history.shift();
      }
    }

    // Actualizar pantalla actual
    this.currentScreen = screen;

    // Web: usar view.loadView()
    if (view && typeof view.loadView === 'function') {
      const container = params.container || null;
      const extension = params.extension || null;
      const menuId = params.menuId || null;
      
      view.loadView(screen, container, extension, null, null, menuId);
    } else {
      ogLogger?.error('core:navigation', 'view.loadView() no disponible');
    }

    // React Native (futuro):
    // if (params.navigation) {
    //   params.navigation.navigate(screen, params);
    // }
  }

  /**
   * Volver a la vista anterior
   */
  static goBack() {
    const { view } = this.getModules();
    
    if (this.history.length === 0) {
      ogLogger?.warn('core:navigation', 'No hay historial para volver');
      return false;
    }

    const previous = this.history.pop();

    this.currentScreen = previous.screen;
    
    if (view && typeof view.loadView === 'function') {
      view.loadView(previous.screen);
    }

    return true;
  }

  /**
   * Verificar si se puede volver atrás
   */
  static canGoBack() {
    return this.history.length > 0;
  }

  /**
   * Reemplazar la vista actual (sin agregar al historial)
   */
  static replace(screen, params = {}) {
    // No agregar al historial, solo reemplazar
    this.currentScreen = screen;

    const { view } = this.getModules();
    
    if (view && typeof view.loadView === 'function') {
      const container = params.container || null;
      const extension = params.extension || null;
      const menuId = params.menuId || null;
      
      view.loadView(screen, container, extension, null, null, menuId);
    }
  }

  /**
   * Resetear navegación (útil para logout)
   */
  static reset(screen = 'dashboard/dashboard') {
    // Limpiar historial
    this.history = [];
    this.currentScreen = screen;

    const { view } = this.getModules();
    
    if (view && typeof view.loadView === 'function') {
      view.loadView(screen);
    }
  }

  /**
   * Obtener pantalla actual
   */
  static getCurrentScreen() {
    return this.currentScreen;
  }

  /**
   * Obtener historial completo
   */
  static getHistory() {
    return [...this.history];
  }

  /**
   * Limpiar historial sin navegar
   */
  static clearHistory() {
    this.history = [];
  }
}

// Global
window.ogNavigation = ogNavigation;

// Registrar en ogFramework (preferido)
if (typeof window.ogFramework !== 'undefined') {
  window.ogFramework.core.navigation = ogNavigation;
}