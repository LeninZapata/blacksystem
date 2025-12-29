class logs {
      static currentLevel = 'all';
    static currentLayer = 'all';
  static initialized = false;
  static logsData = null;
  static currentFilter = 'today'; // today, yesterday, 7days, 15days, 30days

  // Método que view.js ejecuta automáticamente después del render
  static async init() {

    if (this.initialized) {
      ogLogger.info('ext:admin:logs','⚠️ logs ya estaba inicializado, reiniciando...');
    }

    // Verificar que el container exista
    const container = document.getElementById('logs-container');

    if (!container) {
      ogLogger:warn('⚠️ Container logs-container no encontrado');
      return;
    }

    // Cargar logs de forma asíncrona (por defecto: hoy)
    await this.loadLogs(container, 0);

    this.initialized = true;
  }

  // Filtrar por fecha
  static async filterByDate(daysAgo) {
    ogLogger.info('ext:admin:logs',`📅 Filtrando logs: ${daysAgo === 0 ? 'Hoy' : daysAgo === 1 ? 'Ayer' : `Hace ${daysAgo} días`}`);

    const container = document.getElementById('logs-container');
    if (!container) {
      ogLogger:warn('⚠️ Container logs-container no encontrado');
      return;
    }

    // Actualizar botones activos
    document.querySelectorAll('[data-filter]').forEach(btn => {
      btn.style.background = '#334155';
      btn.style.color = '#e2e8f0';
    });

    const activeBtn = daysAgo === 0 ? 'today' : daysAgo === 1 ? 'yesterday' : `${daysAgo}days`;
    const btn = document.querySelector(`[data-filter="${activeBtn}"]`);
    if (btn) {
      btn.style.background = '#3b82f6';
      btn.style.color = 'white';
    }

    // Resetear filtros acumulativos al cambiar fecha
    this.currentLayer = 'all';
    this.currentLevel = 'all';
    this.updateLayerToolbar();
    this.updateLevelToolbar();
    // Cargar logs con el filtro
    await this.loadLogs(container, daysAgo);
  }

  static async loadLogs(container, daysAgo = 0) {
    // Mostrar mensaje de carga
    container.innerHTML = `
      <div style="text-align: center; padding: 3rem; color: #94a3b8; background: #1e293b; border-radius: 8px;">
        <div style="font-size: 2rem; margin-bottom: 1rem;">⏳</div>
        <div>Cargando logs...</div>
      </div>
    `;

    try {

      // Usar PROYECT_SLUG para las keys de caché
      const filterKey = daysAgo === 0 ? 'today' : daysAgo === 1 ? 'yesterday' : `${daysAgo}days`;
      const cacheKey = PROYECT_SLUG + '_logs_' + filterKey;
      const cacheTTL = 5 * 60 * 1000; // 5 minutos

      // Intentar obtener del caché primero
      let data = ogCache.get(cacheKey);

      if (data) {
        ogLogger.info('ext:admin:logs',`✅ Logs obtenidos desde caché (${filterKey})`);
        this.logsData = data;
      } else {
        let endpoint = '/api/logs';

        // Determinar endpoint según filtro
        if (daysAgo === 0) {
          // Hoy
          endpoint = '/api/logs/today';
        } else if (daysAgo === 1) {
          // Ayer
          const yesterday = new Date();
          yesterday.setDate(yesterday.getDate() - 1);
          const year = yesterday.getFullYear();
          const month = String(yesterday.getMonth() + 1).padStart(2, '0');
          const day = String(yesterday.getDate()).padStart(2, '0');
          endpoint = `/api/logs/${year}/${month}/${day}`;
        } else {
          // Hace X días (usar search con rango)
          const today = new Date();
          const fromDate = new Date();
          fromDate.setDate(today.getDate() - daysAgo);

          const toStr = today.toISOString().split('T')[0];
          const fromStr = fromDate.toISOString().split('T')[0];

          endpoint = `/api/logs/search?from=${fromStr}&to=${toStr}&limit=1000`;
        }

        // Hacer petición al endpoint
        const response = await ogApi.get(endpoint);

        if (!response.success) {
          throw new Error(response.error || 'Error al cargar logs');
        }

        this.logsData = response.data;

        // Guardar en caché
        ogCache.set(cacheKey, response.data, cacheTTL);
      }

      // Renderizar los logs con el filtro de layer actual
      this.renderLogs(container);

    } catch (error) {
      ogLogger.error('❌ Error cargando logs:', error);
      container.innerHTML = `
        <div style="background: #7f1d1d; padding: 1.5rem; border-radius: 8px; border-left: 4px solid #ef4444;">
          <h4 style="margin: 0 0 0.5rem; color: #fca5a5;">❌ Error al cargar logs</h4>
          <p style="margin: 0; color: #fecaca;">${error.message}</p>
        </div>
      `;
    }
  }

  static renderLogs(container) {
    if (!this.logsData || !this.logsData.logs) {
      container.innerHTML = '<p style="color: #94a3b8;">No hay logs disponibles</p>';
      return;
    }

    let { logs, count } = this.logsData;
    // Filtrar por layer si aplica
    if (this.currentLayer && this.currentLayer !== 'all') {
      logs = logs.filter(l => (l.layer || '').toLowerCase() === this.currentLayer);
    }
    // Filtrar por nivel si aplica
    if (this.currentLevel && this.currentLevel !== 'all') {
      logs = logs.filter(l => (l.level || '').toLowerCase() === this.currentLevel);
    }

    const html = `
      <!-- Stats -->
      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1rem; margin-bottom: 2rem;">
        <div style="background: #1e293b; padding: 1rem; border-radius: 8px; border-left: 4px solid #3b82f6;">
          <div style="font-size: 1.5rem; font-weight: bold; color: #60a5fa;">${count}</div>
          <div style="color: #94a3b8; font-size: 0.85rem;">Total Logs</div>
        </div>
        <div style="background: #1e293b; padding: 1rem; border-radius: 8px; border-left: 4px solid #10b981;">
          <div style="font-size: 1.5rem; font-weight: bold; color: #34d399;">${logs.filter(l => l.level === 'INFO').length}</div>
          <div style="color: #94a3b8; font-size: 0.85rem;">INFO</div>
        </div>
        <div style="background: #1e293b; padding: 1rem; border-radius: 8px; border-left: 4px solid #f59e0b;">
          <div style="font-size: 1.5rem; font-weight: bold; color: #fbbf24;">${logs.filter(l => l.level === 'WARNING').length}</div>
          <div style="color: #94a3b8; font-size: 0.85rem;">WARNING</div>
        </div>
        <div style="background: #1e293b; padding: 1rem; border-radius: 8px; border-left: 4px solid #ef4444;">
          <div style="font-size: 1.5rem; font-weight: bold; color: #f87171;">${logs.filter(l => l.level === 'ERROR').length}</div>
          <div style="color: #94a3b8; font-size: 0.85rem;">ERROR</div>
        </div>
        <div style="background: #1e293b; padding: 1rem; border-radius: 8px; border-left: 4px solid #475569;">
          <div style="font-size: 1.5rem; font-weight: bold; color: #94a3b8;">${logs.filter(l => l.level === 'DEBUG').length}</div>
          <div style="color: #94a3b8; font-size: 0.85rem;">DEBUG</div>
        </div>
      </div>

      <!-- Filtro por tag -->
      <div style="margin-bottom: 1.5rem; display: flex; align-items: center; gap: 1rem;">
        <label style="color: #e2e8f0; font-size: 0.95rem; font-weight: 500;">🏷️ Tag:</label>
        <input id="logs-tag-input" type="text" placeholder="Escribe el tag..." style="padding: 0.4rem 0.8rem; border-radius: 6px; border: 1px solid #334155; background: #1e293b; color: #e2e8f0; font-size: 0.9rem;" />
        <button id="logs-tag-btn" style="padding: 0.4rem 1.2rem; border-radius: 6px; background: #3b82f6; color: white; border: none; font-weight: 500; cursor: pointer;">Buscar</button>
      </div>

      <!-- Logs Container -->
      <div style="background: #0f172a; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.3); max-height: 800px; overflow-y: auto;">
        <div style="padding: 1rem; border-bottom: 1px solid #334155; background: #1e293b; display: flex; align-items: center; justify-content: space-between;">
          <h4 style="margin: 0; color: #e2e8f0; font-size: 1rem;">📜 Registros del Sistema</h4>
          <button id="logs-reload-btn" style="margin-left: auto; padding: 0.4rem 1.2rem; border-radius: 6px; background: #3b82f6; color: white; border: none; font-weight: 500; cursor: pointer; font-size: 0.95rem;">🔄 Recargar Logs</button>
          <button id="logs-delete-btn" style="margin-left: 0.5rem; padding: 0.4rem 1.2rem; border-radius: 6px; background: transparent; color: white; border: 1px dashed #ef4444; font-weight: 500; cursor: pointer; font-size: 0.95rem;">🗑️ Eliminar Log</button>
        </div>
        <div style="font-family: 'Consolas', 'Monaco', 'Courier New', monospace; font-size: 0.875rem;" id="logs-list-container">
          ${logs.map(log => this.renderLogLine(log)).join('')}
        </div>
      </div>
    `;

    container.innerHTML = html;
    this.updateLayerToolbar();
    this.updateLevelToolbar();

    // Filtro por tag
    const tagInput = document.getElementById('logs-tag-input');
    const tagBtn = document.getElementById('logs-tag-btn');
    const logsListContainer = document.getElementById('logs-list-container');
    if (tagBtn && tagInput && logsListContainer) {
      tagBtn.onclick = () => {
        const tagValue = tagInput.value.trim().toLowerCase();
        let filteredLogs = logs;
        if (tagValue) {
          filteredLogs = logs.filter(l => Array.isArray(l.tags) && l.tags.some(t => t.toLowerCase() === tagValue));
        }
        logsListContainer.innerHTML = filteredLogs.map(log => this.renderLogLine(log)).join('');
      };
      tagInput.onkeydown = (e) => {
        if (e.key === 'Enter') tagBtn.click();
      };
    }

    // Botón Recargar Logs
    const reloadBtn = document.getElementById('logs-reload-btn');
    if (reloadBtn) {
      reloadBtn.onclick = () => this.forceReload();
    }

    // Botón Eliminar Log
    const deleteBtn = document.getElementById('logs-delete-btn');
    if (deleteBtn) {
      deleteBtn.onclick = async () => {
        deleteBtn.disabled = true;
        deleteBtn.innerText = 'Eliminando...';
        try {
          const res = await ogApi.get('/api/cleanup/storage/logs');
          if (res.success) {
            ogToast.success('Logs eliminados correctamente');
            await this.forceReload();
          } else {
            ogToast.error(res.error || 'Error al eliminar logs');
          }
        } catch (err) {
          ogToast.error('Error al eliminar logs');
        }
        deleteBtn.disabled = false;
        deleteBtn.innerText = '🗑️ Eliminar Log';
      };
    }
  }

  // Actualiza el estado visual de los botones de nivel
  static updateLevelToolbar() {
    const allBtns = document.querySelectorAll('[data-level]');
    allBtns.forEach(btn => {
      if (btn.getAttribute('data-level') === this.currentLevel) {
        btn.style.background = '#3b82f6';
        btn.style.color = 'white';
      } else {
        btn.style.background = '#334155';
        btn.style.color = '#e2e8f0';
      }
    });
  }

  // Filtro por nivel usando el ogCache actual
  static filterByLevel(level) {
    this.currentLevel = level;
    const container = document.getElementById('logs-container');
    if (!container) return;
    this.renderLogs(container);
  }

  // Actualiza el estado visual de los botones de layer
  static updateLayerToolbar() {
    const allBtns = document.querySelectorAll('[data-layer]');
    allBtns.forEach(btn => {
      if (btn.getAttribute('data-layer') === this.currentLayer) {
        btn.style.background = '#3b82f6';
        btn.style.color = 'white';
      } else {
        btn.style.background = '#334155';
        btn.style.color = '#e2e8f0';
      }
    });
  }

  // Filtro por layer usando el ogCache actual
  static filterByLayer(layer) {
    this.currentLayer = layer;
    const container = document.getElementById('logs-container');
    if (!container) return;
    this.renderLogs(container);
  }

  static renderLogLine(log) {
    const levelColors = {
      'DEBUG': { bg: '#1e293b', text: '#94a3b8', badge: '#475569' },
      'INFO': { bg: '#0c4a6e36', text: '#7dd3fc', badge: '#0d567c' },
      'SUCCESS': { bg: '#036e5236', text: '#6ee7b7', badge: '#0d6c4f' },
      'WARNING': { bg: '#78350f36', text: '#fbbf24', badge: '#9b5300' },
      'ERROR': { bg: '#7f1d1d33', text: '#fca5a5', badge: '#4b2020' }
    };

    const color = levelColors[log.level] || levelColors['DEBUG'];
    const contextStr = log.context ? JSON.stringify(log.context, null, 2) : '';

    // Renderiza la fila de log con la columna de línea al final y badges de tags
    // Si sequence === 1, agrega margin-bottom
    const extraMargin = log.sequence === 1 ? 'margin-bottom: 1.5rem;' : '';
    return `
      <div class="log-register" style="padding: 0.75rem 1rem; border-bottom: 1px solid #292020ff; background: ${color.bg}; transition: background 0.2s; ${extraMargin}"
           onmouseover="this.style.background='#1e293b'"
           onmouseout="this.style.background='${color.bg}'">
        <div style="display: flex; gap: 0.75rem; align-items: flex-start;">
          <span style="background: ${color.badge}; color: white; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600; min-width: 70px; text-align: center;">
            <span style="font-size: 0.65rem; color:#898989;">${log.sequence}</span>
            ${log.level}
          </span>
          <span style="color: #64748b; min-width: 140px; font-size: 0.8rem;">
            ${log.timestamp}
          </span>
          <span style="color: #3b82f6; min-width: 100px; font-size: 0.8rem;">
            ${log.layer || '-'}
          </span>
          <span style="color: #8b5cf6; min-width: 120px; font-size: 0.8rem;">
            ${log.module}
          </span>
          <span style="color: ${color.text}; flex: 1; display: flex; align-items: center; gap: 0.5rem;">
            <span>${log.message}</span>
            ${Array.isArray(log.tags) && log.tags.length > 0 ? `
              <span style="display: flex; gap: 0.25rem; align-items: center;">
                ${log.tags.map(tag => `
                  <span style="background: rgba(0,0,0,0.1); color: #ffffff42; font-size: 0.7rem; padding: 0.1rem 0.5rem; border-radius: 6px; font-weight: 500;">${tag}</span>
                `).join('')}
              </span>
            ` : ''}
          </span>
          ${log.location ? `<span style=\"color: #475569; min-width: 90px; font-size: 0.75rem; text-align: right;\"> ${log.location}</span>` : ''}
        </div>
        ${contextStr ? `
          <div style=\"margin-top: 0.5rem; padding: 0.5rem; background: #0f172a; border-radius: 4px; border-left: 3px solid ${color.badge};\">
            <pre style=\"margin: 0; color: #94a3b8; font-size: 0.75rem; overflow-x: auto;\">${contextStr}</pre>
          </div>
        ` : ''}
      </div>
    `;
  }

  // Forzar recarga sin caché
  static async forceReload() {
    ogLogger.info('ext:admin:logs','🔄 Forzando recarga sin caché...');

    const container = document.getElementById('logs-container');
    if (!container) {
      ogLogger:warn('⚠️ Container logs-container no encontrado');
      return;
    }


    // Eliminar todos los caches de logs usando PROYECT_SLUG
    ogCache.delete(PROYECT_SLUG + '_logs_today');
    ogCache.delete(PROYECT_SLUG + '_logs_yesterday');
    ogCache.delete(PROYECT_SLUG + '_logs_7days');
    ogCache.delete(PROYECT_SLUG + '_logs_15days');
    ogCache.delete(PROYECT_SLUG + '_logs_30days');
    ogLogger.info('ext:admin:logs','✅ Caché eliminado');

    // Determinar cuál filtro está activo
    const activeBtn = document.querySelector('[data-filter][style*="rgb(59, 130, 246)"]');
    let daysAgo = 0;

    if (activeBtn) {
      const filter = activeBtn.getAttribute('data-filter');
      if (filter === 'yesterday') daysAgo = 1;
      else if (filter === '7days') daysAgo = 7;
      else if (filter === '15days') daysAgo = 15;
      else if (filter === '30days') daysAgo = 30;
    }

    // Recargar datos
    await this.loadLogs(container, daysAgo);
  }
}

// Exportar al scope global (obligatorio)
window.logs = logs;
