class endpoints {
  static initialized = false;
  static endpointsData = null;

  // ✅ Método que view.js ejecuta automáticamente después del render
  static async init() {
    logger.info('🚀 endpoints.init() ejecutado automáticamente por view.js');

    if (this.initialized) {
      logger.info('⚠️ endpoints ya estaba inicializado, reiniciando...');
    }

    // Verificar que el container exista
    const container = document.getElementById('endpoints-container');

    if (!container) {
      logger.warn('⚠️ Container endpoints-container no encontrado');
      return;
    }

    logger.info('✅ Container encontrado, cargando endpoints...');

    // Cargar endpoints de forma asíncrona
    await this.loadEndpoints(container);

    this.initialized = true;
  }

  static async loadEndpoints(container) {
    // Mostrar mensaje de carga
    container.innerHTML = `
      <div style="text-align: center; padding: 3rem; color: #64748b;">
        <div style="font-size: 2rem; margin-bottom: 1rem;">⏳</div>
        <div>Cargando endpoints...</div>
      </div>
    `;

    try {
      const cacheKey = 'endpoints_routes_list';
      const cacheTTL = 60 * 60 * 1000; // 1 hora

      // Intentar obtener del caché primero
      let data = cache.get(cacheKey);

      if (data) {
        logger.info('✅ Endpoints obtenidos desde caché');
        this.endpointsData = data;
      } else {
        logger.info('📡 Cargando endpoints desde API...');
        
        // Hacer petición al endpoint
        const response = await api.get('/api/system/routes');

        if (!response.success) {
          throw new Error(response.error || 'Error al cargar endpoints');
        }

        this.endpointsData = response.data;
        
        // Guardar en caché
        cache.set(cacheKey, response.data, cacheTTL);
        logger.info('✅ Endpoints guardados en caché por 1 hora');
      }

      // Renderizar la lista con el modo guardado o por defecto 'method'
      const savedViewMode = localStorage.getItem('endpoints_view_mode') || 'method';
      this.renderEndpoints(container, savedViewMode);

    } catch (error) {
      logger.error('❌ Error cargando endpoints:', error);
      container.innerHTML = `
        <div style="background: #fee; padding: 1.5rem; border-radius: 8px; border-left: 4px solid #c00;">
          <h4 style="margin: 0 0 0.5rem; color: #c00;">❌ Error al cargar endpoints</h4>
          <p style="margin: 0; color: #666;">${error.message}</p>
        </div>
      `;
    }
  }

  static renderEndpoints(container, viewMode = 'method') {
    if (!this.endpointsData) {
      container.innerHTML = '<p>No hay datos disponibles</p>';
      return;
    }

    const { routes, stats } = this.endpointsData;

    // Agrupar rutas según el modo de vista
    const grouped = viewMode === 'resource' 
      ? this.groupByResource(routes) 
      : this.groupByMethod(routes);

    const html = `
      <!-- Tabs para cambiar vista -->
      <div style="margin-bottom: 2rem; border-bottom: 2px solid #e5e7eb;">
        <div style="display: flex; gap: 0.5rem;">
          <button 
            onclick="endpoints.changeView('method')"
            style="padding: 0.75rem 1.5rem; border: none; background: ${viewMode === 'method' ? '#3b82f6' : 'transparent'}; color: ${viewMode === 'method' ? 'white' : '#64748b'}; border-bottom: 3px solid ${viewMode === 'method' ? '#3b82f6' : 'transparent'}; cursor: pointer; font-weight: 600; transition: all 0.2s;"
            onmouseover="if('${viewMode}' !== 'method') this.style.background='#f1f5f9'"
            onmouseout="if('${viewMode}' !== 'method') this.style.background='transparent'"
          >
            📊 Por Método HTTP
          </button>
          <button 
            onclick="endpoints.changeView('resource')"
            style="padding: 0.75rem 1.5rem; border: none; background: ${viewMode === 'resource' ? '#3b82f6' : 'transparent'}; color: ${viewMode === 'resource' ? 'white' : '#64748b'}; border-bottom: 3px solid ${viewMode === 'resource' ? '#3b82f6' : 'transparent'}; cursor: pointer; font-weight: 600; transition: all 0.2s;"
            onmouseover="if('${viewMode}' !== 'resource') this.style.background='#f1f5f9'"
            onmouseout="if('${viewMode}' !== 'resource') this.style.background='transparent'"
          >
            📁 Por Recurso
          </button>
        </div>
      </div>

      <!-- Estadísticas -->
      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 2rem;">
        <div style="background: #f0f9ff; padding: 1rem; border-radius: 8px; border-left: 4px solid #0284c7;">
          <div style="font-size: 2rem; font-weight: bold; color: #0369a1;">${stats.total}</div>
          <div style="color: #64748b; font-size: 0.9rem;">Total Endpoints</div>
        </div>
        <div style="background: #f0fdf4; padding: 1rem; border-radius: 8px; border-left: 4px solid #16a34a;">
          <div style="font-size: 2rem; font-weight: bold; color: #15803d;">${stats.by_method.GET || 0}</div>
          <div style="color: #64748b; font-size: 0.9rem;">GET</div>
        </div>
        <div style="background: #fef3c7; padding: 1rem; border-radius: 8px; border-left: 4px solid #f59e0b;">
          <div style="font-size: 2rem; font-weight: bold; color: #d97706;">${stats.by_method.POST || 0}</div>
          <div style="color: #64748b; font-size: 0.9rem;">POST</div>
        </div>
        <div style="background: #fee2e2; padding: 1rem; border-radius: 8px; border-left: 4px solid #dc2626;">
          <div style="font-size: 2rem; font-weight: bold; color: #b91c1c;">${stats.by_method.DELETE || 0}</div>
          <div style="color: #64748b; font-size: 0.9rem;">DELETE</div>
        </div>
        <div style="background: #e0e7ff; padding: 1rem; border-radius: 8px; border-left: 4px solid #6366f1;">
          <div style="font-size: 2rem; font-weight: bold; color: #4f46e5;">${stats.by_method.PUT || 0}</div>
          <div style="color: #64748b; font-size: 0.9rem;">PUT</div>
        </div>
      </div>

      <!-- Tabla de endpoints -->
      <div style="background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
        ${Object.keys(grouped).map(groupKey => `
          <div style="border-bottom: 1px solid #e5e7eb;">
            <h3 style="margin: 0; padding: 1rem; background: ${viewMode === 'method' ? this.getMethodColor(groupKey) : '#6366f1'}; color: white;">
              ${viewMode === 'method' ? groupKey : groupKey.toUpperCase()} (${grouped[groupKey].length})
            </h3>
            <table style="width: 100%; border-collapse: collapse;">
              <thead style="background: #f9fafb;">
                <tr>
                  ${viewMode === 'resource' ? '<th style="padding: 0.75rem; text-align: left; font-weight: 600; color: #374151; width: 80px;">Método</th>' : ''}
                  <th style="padding: 0.75rem; text-align: left; font-weight: 600; color: #374151;">Path</th>
                  <th style="padding: 0.75rem; text-align: left; font-weight: 600; color: #374151;">Description</th>
                  <th style="padding: 0.75rem; text-align: left; font-weight: 600; color: #374151;">Middleware</th>
                  <th style="padding: 0.75rem; text-align: left; font-weight: 600; color: #374151;">Source</th>
                  <th style="padding: 0.75rem; text-align: center; font-weight: 600; color: #374151; width: 60px;">Copiar</th>
                </tr>
              </thead>
              <tbody>
                ${grouped[groupKey].map(route => `
                  <tr style="border-top: 1px solid #e5e7eb;">
                    ${viewMode === 'resource' ? `
                      <td style="padding: 0.75rem;">
                        <span style="background: ${this.getMethodColor(route.method)}; color: white; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">
                          ${route.method}
                        </span>
                      </td>
                    ` : ''}
                    <td style="padding: 0.75rem; font-family: monospace; font-size: 0.85rem; color: #1f2937;">
                      ${route.path}
                    </td>
                    <td style="padding: 0.75rem; color: #6b7280; font-size: 0.9rem;">
                      ${route.description || '-'}
                    </td>
                    <td style="padding: 0.75rem;">
                      ${route.middleware.length > 0 
                        ? route.middleware.map(m => `<span style="background: #dbeafe; color: #1e40af; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.75rem; margin-right: 0.25rem;">${m}</span>`).join('')
                        : '<span style="color: #9ca3af;">-</span>'
                      }
                    </td>
                    <td style="padding: 0.75rem;">
                      <span style="background: #f3f4f6; color: #374151; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.75rem;">
                        ${route.source}
                      </span>
                    </td>
                    <td style="padding: 0.75rem; text-align: center;">
                      <button 
                        onclick="endpoints.copyToClipboard('${route.path}')" 
                        style="background: #3b82f6; color: white; border: none; padding: 0.4rem 0.6rem; border-radius: 4px; cursor: pointer; font-size: 0.75rem; transition: background 0.2s;"
                        onmouseover="this.style.background='#2563eb'" 
                        onmouseout="this.style.background='#3b82f6'"
                        title="Copiar ruta al portapapeles"
                      >
                        📋
                      </button>
                    </td>
                  </tr>
                `).join('')}
              </tbody>
            </table>
          </div>
        `).join('')}
      </div>
    `;

    container.innerHTML = html;
    logger.info('✅ Endpoints renderizados en el container');
  }

  static groupByMethod(routes) {
    const grouped = {};
    
    routes.forEach(route => {
      if (!grouped[route.method]) {
        grouped[route.method] = [];
      }
      grouped[route.method].push(route);
    });

    // Ordenar por método (GET, POST, PUT, DELETE)
    const order = ['GET', 'POST', 'PUT', 'DELETE'];
    const sorted = {};
    order.forEach(method => {
      if (grouped[method]) {
        sorted[method] = grouped[method];
      }
    });

    return sorted;
  }

  static groupByResource(routes) {
    const grouped = {};
    
    routes.forEach(route => {
      // Extraer el recurso: /api/sessions/stats -> sessions
      const pathParts = route.path.split('/').filter(p => p);
      
      // Si el path empieza con /api/, tomar el siguiente segmento
      let resource = 'otros';
      if (pathParts.length >= 2 && pathParts[0] === 'api') {
        resource = pathParts[1];
      } else if (pathParts.length >= 1) {
        resource = pathParts[0];
      }
      
      if (!grouped[resource]) {
        grouped[resource] = [];
      }
      grouped[resource].push(route);
    });

    // Ordenar alfabéticamente
    const sorted = {};
    Object.keys(grouped).sort().forEach(key => {
      sorted[key] = grouped[key];
    });

    return sorted;
  }

  static changeView(mode) {
    logger.info(`🔄 Cambiando vista a: ${mode}`);
    
    const container = document.getElementById('endpoints-container');
    if (!container) {
      logger.warn('⚠️ Container endpoints-container no encontrado');
      return;
    }

    // Guardar preferencia en localStorage
    localStorage.setItem('endpoints_view_mode', mode);
    
    // Re-renderizar con el nuevo modo
    this.renderEndpoints(container, mode);
  }

  static getMethodColor(method) {
    const colors = {
      'GET': '#16a34a',
      'POST': '#f59e0b',
      'PUT': '#6366f1',
      'DELETE': '#dc2626'
    };
    return colors[method] || '#64748b';
  }

  // Forzar recarga sin caché
  static async forceReload() {
    logger.info('🔄 Forzando recarga sin caché...');
    
    const container = document.getElementById('endpoints-container');
    if (!container) {
      logger.warn('⚠️ Container endpoints-container no encontrado');
      return;
    }

    // Eliminar del caché
    cache.delete('endpoints_routes_list');
    logger.info('✅ Caché eliminado');

    // Recargar datos
    await this.loadEndpoints(container);
  }

  // Copiar ruta al portapapeles
  static copyToClipboard(path) {
    // Construir URL completa
    const apiBaseUrl = window.PUBLIC_URL || window.location.origin + '/';
    const fullUrl = apiBaseUrl + path.replace(/^\//, ''); // Remover / inicial si existe
    
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(fullUrl)
        .then(() => {
          logger.info('✅ URL copiada:', fullUrl);
          // Mostrar notificación temporal (opcional)
          this.showCopyNotification();
        })
        .catch(err => {
          logger.error('❌ Error al copiar:', err);
          // Fallback: método antiguo
          this.fallbackCopyToClipboard(fullUrl);
        });
    } else {
      // Fallback para navegadores antiguos
      this.fallbackCopyToClipboard(fullUrl);
    }
  }

  // Fallback para copiar en navegadores sin Clipboard API
  static fallbackCopyToClipboard(text) {
    const textArea = document.createElement('textarea');
    textArea.value = text;
    textArea.style.position = 'fixed';
    textArea.style.left = '-9999px';
    document.body.appendChild(textArea);
    textArea.select();
    
    try {
      document.execCommand('copy');
      logger.info('✅ Ruta copiada (fallback):', text);
      this.showCopyNotification();
    } catch (err) {
      logger.error('❌ Error al copiar (fallback):', err);
    }
    
    document.body.removeChild(textArea);
  }

  // Mostrar notificación de copiado
  static showCopyNotification() {
    const notification = document.createElement('div');
    notification.innerHTML = '✅ Ruta copiada al portapapeles';
    notification.style.cssText = `
      position: fixed;
      top: 20px;
      right: 20px;
      background: #10b981;
      color: white;
      padding: 1rem 1.5rem;
      border-radius: 8px;
      box-shadow: 0 4px 6px rgba(0,0,0,0.1);
      z-index: 10000;
      animation: slideIn 0.3s ease-out;
    `;
    
    document.body.appendChild(notification);
    
    setTimeout(() => {
      notification.style.animation = 'slideOut 0.3s ease-out';
      setTimeout(() => {
        document.body.removeChild(notification);
      }, 300);
    }, 2000);
  }
}

// ✅ Exportar al scope global (obligatorio)
window.endpoints = endpoints;
