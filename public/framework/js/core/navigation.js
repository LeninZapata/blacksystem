/**
 * Navigation - Sistema de navegación simple
 * Wrapper sobre view.loadView() con historial en memoria
 * Sin URLs, sin hash routing, solo gestión de vistas
 * 
 * Preparado para React Native (mismo API)
 */
class navigation {
  static history = [];
  static currentScreen = null;
  static maxHistory = 50;

  /**
   * Navegar a una vista
   * @param {string} screen - Ruta de la vista (ej: "users/list", "dashboard/dashboard")
   * @param {object} params - Parámetros opcionales
   */
  static navigate(screen, params = {}) {
    if (!screen) {
      logger.warn('core:navigation', 'Screen no especificado');
      return;
    }

    logger.debug('core:navigation', `Navigate to: ${screen}`, params);

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
    if (window.view && typeof view.loadView === 'function') {
      const container = params.container || null;
      const extension = params.extension || null;
      const menuId = params.menuId || null;
      
      view.loadView(screen, container, extension, null, null, menuId);
    } else {
      logger.error('core:navigation', 'view.loadView() no disponible');
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
    if (this.history.length === 0) {
      logger.warn('core:navigation', 'No hay historial para volver');
      return false;
    }

    const previous = this.history.pop();
    logger.debug('core:navigation', `Go back to: ${previous.screen}`);

    this.currentScreen = previous.screen;

    if (window.view && typeof view.loadView === 'function') {
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
    logger.debug('core:navigation', `Replace with: ${screen}`, params);

    // No agregar al historial, solo reemplazar
    this.currentScreen = screen;

    if (window.view && typeof view.loadView === 'function') {
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
    logger.debug('core:navigation', `Reset to: ${screen}`);

    // Limpiar historial
    this.history = [];
    this.currentScreen = screen;

    if (window.view && typeof view.loadView === 'function') {
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
    logger.debug('core:navigation', 'Historial limpiado');
    this.history = [];
  }
}

window.navigation = navigation;