class adminHooks {
  // Hook para el dashboard
  static hook_dashboard() {
    // logger.debug('ext:admin', 'hook_dashboard ejecutado');

    return [ ];
  }

  // 🎯 Hook para admin-panel (la vista sections/admin-panel.json)
  static hook_adminPanel() {
    return [ ];
  }

  // 🎯 Ejemplo de hook para el tab específico de usuarios dentro de admin-panel
  static hook_adminPanelUsers() {
    // logger.debug('ext:admin', 'hook_adminPanelUsers ejecutado');

    return [  ];
  }
}

// Registrar globalmente
window.adminHooks = adminHooks;