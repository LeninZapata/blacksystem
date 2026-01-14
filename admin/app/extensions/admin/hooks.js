class adminHooks {
  // Hook para el dashboard
  static hook_dashboard() {
    ogLogger.debug('ext:admin', 'hook_dashboard ejecutado');

    return [
      {
        id: 'admin-usuarios-resumen',
        type: 'html',
        order: 5,
        content: `
          <div class="dashboard-widget" style="border: 2px solid #3b82f6; padding: 15px; border-radius: 8px; background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); margin: 10px 0; box-shadow: 0 2px 8px rgba(59, 130, 246, 0.2);">
            <h3 style="margin: 0 0 10px 0; color: #1e40af; display: flex; align-items: center; gap: 8px;">
              👥 Gestión de Usuarios
            </h3>
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 12px;">
              <div style="background: white; padding: 12px; border-radius: 6px; text-align: center;">
                <div style="font-size: 1.8rem; font-weight: bold; color: #3b82f6;">42</div>
                <div style="font-size: 0.85rem; color: #6b7280; margin-top: 4px;">Total Usuarios</div>
              </div>
              <div style="background: white; padding: 12px; border-radius: 6px; text-align: center;">
                <div style="font-size: 1.8rem; font-weight: bold; color: #10b981;">38</div>
                <div style="font-size: 0.85rem; color: #6b7280; margin-top: 4px;">Activos</div>
              </div>
              <div style="background: white; padding: 12px; border-radius: 6px; text-align: center;">
                <div style="font-size: 1.8rem; font-weight: bold; color: #ef4444;">4</div>
                <div style="font-size: 0.85rem; color: #6b7280; margin-top: 4px;">Inactivos</div>
              </div>
            </div>
            <div style="background: #fef3c7; border-left: 3px solid #f59e0b; padding: 8px 12px; border-radius: 4px; margin-bottom: 12px;">
              <strong style="color: #92400e;">📊 Estadística:</strong>
              <span style="color: #78350f; font-size: 0.9rem;"> 5 usuarios conectados ahora</span>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
              <button class="btn btn-sm btn-primary" onclick="ogView.loadView('admin|sections/admin-panel'); ogToast.info('Cargando panel...')" style="width: 100%;">
                📋 Panel Admin
              </button>
              <button class="btn btn-sm btn-success" onclick="ogModal.open('core:user/forms/user-form', {title: '➕ Nuevo Usuario', width: '90%', maxWidth: '900px'})" style="width: 100%;">
                ➕ Crear Usuario
              </button>
            </div>
          </div>
        `
      }
    ];
  }

  // 🎯 Hook para admin-panel (la vista sections/admin-panel.json)
  static hook_adminPanel() {
    return [
      // Hook HTML
      {
        id: 'admin-panel-welcome',
        type: 'html',
        order: 1,
        content: `
          <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 1.5rem;">
            <h2>¡Bienvenido al Panel!</h2>
          </div>
        `
      },

      // Hook Component (Widget)
      {
        id: 'admin-panel-stats',
        type: 'component',
        component: 'widget',
        order: 2,
        config: {
          columns: 3,
          widgets: [
            {
              title: '👥 Usuarios',
              html: '<div style="text-align:center;font-size:2rem;">42</div>'
            },
            {
              title: '🔐 Roles',
              html: '<div style="text-align:center;font-size:2rem;">8</div>'
            },
            {
              title: '📊 Sesiones',
              html: '<div style="text-align:center;font-size:2rem;">15</div>'
            }
          ]
        }
      },
      
      // Hook HTML (Botones)
      {
        id: 'admin-panel-actions',
        type: 'html',
        order: 3,
        content: `
          <div style="background: #fff3cd; padding: 1rem; border-radius: 6px;">
            <strong>⚡ Acciones Rápidas</strong>
            <div style="display: flex; gap: 0.5rem; margin-top: 0.5rem;">
              <button class="btn btn-primary btn-sm" onclick="ogToast.info('Crear usuario')">
                ➕ Crear Usuario
              </button>
              <button class="btn btn-secondary btn-sm" onclick="ogToast.info('Ver roles')">
                🔐 Gestionar Roles
              </button>
            </div>
          </div>
        `
      }
    ];
  }

  // 🎯 Ejemplo de hook para el tab específico de usuarios dentro de admin-panel
  static hook_adminPanelUsers() {
    ogLogger.debug('ext:admin', 'hook_adminPanelUsers ejecutado');

    return [
      {
        id: 'admin-users-stats',
        type: 'html',
        order: 1,
        content: `
          <div style="background: #e0f2fe; border: 1px solid #0284c7; padding: 1rem; border-radius: 6px; margin-bottom: 1rem;">
            <strong style="color: #075985;">📊 Estadísticas de Usuarios (Hook):</strong>
            <div style="margin-top: 0.5rem; display: flex; gap: 2rem; font-size: 0.9rem;">
              <span>✅ Activos: <strong>38</strong></span>
              <span>❌ Inactivos: <strong>4</strong></span>
              <span>🆕 Nuevos esta semana: <strong>7</strong></span>
            </div>
          </div>
        `
      }
    ];
  }
}

// Registrar globalmente
window.adminHooks = adminHooks;