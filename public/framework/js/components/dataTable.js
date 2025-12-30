class ogDatatable {
  static tables = new Map();
  static counter = 0;



  static getComponent(){
    return {

    }
  }

  static getConfig() {
    return window.ogFramework?.activeConfig || window.appConfig || {};
  }

  static async render(config, container) {
    // IMPORTANTE: view.js pasa (elemento, config) pero tabs.js pasa (config, elemento)
    // Detectar cuál es cuál y corregir automáticamente
    let actualConfig = config;
    let actualContainer = container;

    // Si el primer parámetro es un elemento DOM, están invertidos
    if (config && typeof config.appendChild === 'function') {
      actualContainer = config;
      actualConfig = container || {};
    }

    // Normalizar alias (list, FlatList → datatable)
    if (actualConfig && actualConfig.type) {
      const typeAliases = {
        'list': 'datatable',
        'FlatList': 'datatable',
        'flatlist': 'datatable'
      };

      const normalizedType = typeAliases[actualConfig.type.toLowerCase()];

      if (normalizedType) {
        actualConfig = { ...actualConfig, type: normalizedType };

        // Mapear propiedades de 'list' a 'datatable'
        if (actualConfig.data && !actualConfig.source) {
          let source = actualConfig.data;

          // Si NO empieza con "extensions/", agregarla
          if (!source.startsWith('extensions/') && !source.startsWith('api/')) {
            source = `extensions/${source}`;
          }

          actualConfig.source = source;
        }

        // Remover propiedades específicas de RN (no aplican en web)
        delete actualConfig.renderItem;
        delete actualConfig.keyExtractor;
      }
    }

    if (!actualContainer || typeof actualContainer.appendChild !== 'function') {
      ogLogger.error('com:datatable', 'Container inválido - debe ser un elemento DOM', actualContainer);
      return;
    }

    const tableId = `datatable-${++this.counter}`;
    const extensionName = actualConfig.extensionName || this.detectPluginName(actualContainer);
    const data = await this.loadData(actualConfig, extensionName);

    this.tables.set(tableId, { config: actualConfig, data, extensionName, container: actualContainer });

    const html = this.generateHtml(tableId, actualConfig, data);
    actualContainer.innerHTML = html;

    this.bindEvents(tableId);
  }

  static detectPluginName(container) {
    const hook = ogModule('hook');
    const view = ogModule('view');

    if (!container || typeof container.closest !== 'function') {
      ogLogger.warn('com:datatable', 'Container inválido en detectPluginName');
      return null;
    }

    const viewContainer = container.closest('[data-extension]');
    if (viewContainer?.dataset.extension) return viewContainer.dataset.extension;

    const activeView = document.querySelector('.view-container[data-view]');
    if (activeView?.dataset.view) {
      const viewPath = activeView.dataset.view;
      const parts = viewPath.split('/');
      if (parts.length > 1 && hook?.isExtensionEnabled(parts[0])) {
        return parts[0];
      }
    }

    if (view?.currentPlugin) return view.currentPlugin;

    const pluginClass = Array.from(container.classList || [])
      .find(cls => cls.startsWith('extension-'));
    if (pluginClass) return pluginClass.replace('extension-', '');

    return null;
  }

  static async loadData(config, extensionName) {
    const source = config.source;
    const api = ogModule('api');
    const cache = ogModule('cache');

    if (!source) {
      ogLogger.warn('com:datatable', 'No source specified');
      return [];
    }

    // Generar clave de caché
    const cacheKey = `datatable_${source.replace(/[^a-zA-Z0-9]/g, '_')}`;
    const cacheTTL = config.cacheTTL || 5 * 60 * 1000; // 5 minutos default

    try {
      // Detectar tipo de source
      const isApi = source.startsWith('api/');
      const isExternal = source.startsWith('http://') || source.startsWith('https://');
      const isAbsolute = source.startsWith('/');
      const hasExtensions = source.startsWith('extensions/');

      let finalUrl;
      let data;

      if (isApi) {
        // API → usar api.get()
        const response = await api.get(source);
        data = this.normalizeData(response);

        // Guardar en caché
        cache.set(cacheKey, data, cacheTTL);

      } else if (isExternal) {
        // URL externa → usar tal cual
        finalUrl = source;

      } else if (isAbsolute) {
        // Ruta absoluta → usar tal cual
        const config = this.getConfig();
        finalUrl = `${config.baseUrl || '/'}${source.substring(1)}`;

      } else if (hasExtensions) {
        // Ya tiene "extensions/" → usar tal cual
        const config = this.getConfig();
        finalUrl = `${config.baseUrl || '/'}${source}`;

      } else {
        // Ruta relativa → agregar "extensions/" automáticamente
        const config = this.getConfig();
        finalUrl = `${config.baseUrl || '/'}extensions/${source}`;
      }

      // Para archivos JSON (no APIs)
      if (finalUrl) {
        const config = this.getConfig();
        const cacheBuster = `?v=${config.version || '1.0.0'}`;
        const response = await fetch(finalUrl + cacheBuster);

        if (!response.ok) {
          throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }

        data = await response.json();
        data = this.normalizeData(data);

        // Guardar en caché
        cache.set(cacheKey, data, cacheTTL);
      }

      return data;

    } catch (error) {
      ogLogger.error('com:datatable', `Error cargando datos desde ${source}:`, error);

      // Intentar obtener del caché si falla la petición
      const cachedData = cache.get(cacheKey);
      if (cachedData) {
        ogLogger.warn('com:datatable', `Usando datos cacheados debido a error: ${cacheKey}`);
        return cachedData;
      }

      return [];
    }
  }

  static normalizeData(response) {
    // Si la respuesta es null o undefined, retornar array vacío
    if (!response) {
      return [];
    }

    // Si ya es un array, retornarlo directamente
    if (Array.isArray(response)) {
      return response;
    }

    // Si es un objeto con propiedad 'data' (formato API común)
    if (typeof response === 'object' && response.data !== undefined) {
      if (Array.isArray(response.data)) {
        return response.data;
      }
      // Si response.data existe pero NO es array, retornar vacío
      ogLogger.warn('com:datatable', 'response.data exists but is not an array', response.data);
      return [];
    }

    // Si es un objeto con propiedad 'results' (otro formato API común)
    if (typeof response === 'object' && response.results !== undefined) {
      if (Array.isArray(response.results)) {
        return response.results;
      }
      ogLogger.warn('com:datatable', 'response.results exists but is not an array', response.results);
      return [];
    }

    // Si es un objeto con propiedad 'items'
    if (typeof response === 'object' && response.items !== undefined) {
      if (Array.isArray(response.items)) {
        return response.items;
      }
      ogLogger.warn('com:datatable', 'response.items exists but is not an array', response.items);
      return [];
    }

    // ✅ NUEVO: Si es un objeto con solo 'success' (sin data), retornar vacío
    if (typeof response === 'object' && response.success !== undefined && !response.data) {
      ogLogger.warn('com:datatable', 'Response has success but no data property - returning empty array', response);
      return [];
    }

    // Si es un objeto simple CON campos de datos (no solo success), convertirlo a array
    if (typeof response === 'object' && Object.keys(response).length > 0) {
      // Verificar si tiene campos que parecen datos de entidad (id, name, etc)
      const hasDataFields = Object.keys(response).some(key =>
        !['success', 'message', 'error', 'status'].includes(key)
      );

      if (hasDataFields) {
        return [response];
      }
    }

    // Cualquier otra cosa, retornar array vacío
    ogLogger.warn('com:datatable', 'Unexpected response format - returning empty array', response);
    return [];
  }

  static generateHtml(tableId, config, data) {
    const columns = this.processColumns(config.columns || []);
    const hasActions = config.actions && Object.keys(config.actions).length > 0;
    const isEmpty = !Array.isArray(data) || data.length === 0;

    // Calcular colspan total (columnas + acciones si existen)
    const totalColumns = columns.length + (hasActions ? 1 : 0);

    return `
      <div class="datatable-container" id="${tableId}" data-datatable="${tableId}">
        <div class="table-responsive">
          <table class="table">
            <thead>
              <tr>
                ${columns.map(col => `<th>${col.headerLabel}</th>`).join('')}
                ${hasActions ? `<th>${__('com.datatable.actions')}</th>` : ''}
              </tr>
            </thead>
            <tbody>
              ${isEmpty
                ? `<tr><td colspan="${totalColumns}" style="text-align: center; padding: 2rem; color: #6b7280;">${__('com.datatable.no_data')}</td></tr>`
                : data.map(row => this.renderRow(row, columns, config.actions)).join('')
              }
            </tbody>
          </table>
        </div>
      </div>
    `;
  }

  static processColumns(columns) {
    if (Array.isArray(columns)) {
      return columns.map(col => {
        if (typeof col === 'string') {
          return {
            field: col,
            headerLabel: this.formatHeader(col),
            name: col,
            sortable: false,
            width: null,
            align: 'left'
          };
        } else if (typeof col === 'object') {
          const field = Object.keys(col)[0];
          const params = col[field];

          return {
            field: field,
            headerLabel: this.translateLabel(params.name || field),
            name: params.name || field,
            sortable: params.sortable || false,
            width: params.width || null,
            align: params.align || 'left',
            format: params.format || null,
            ...params
          };
        }
      });
    }

    if (typeof columns === 'object' && !Array.isArray(columns)) {
      return Object.entries(columns).map(([field, params]) => {
        if (typeof params === 'string') {
          return {
            field: field,
            headerLabel: this.translateLabel(params),
            name: params,
            sortable: false,
            width: null,
            align: 'left'
          };
        } else {
          return {
            field: field,
            headerLabel: this.translateLabel(params.name || field),
            name: params.name || field,
            sortable: params.sortable || false,
            width: params.width || null,
            align: params.align || 'left',
            format: params.format || null,
            ...params
          };
        }
      });
    }

    return [];
  }

  static translateLabel(label) {
    const i18n = ogModule('i18n');
    if (!label || typeof label !== 'string') return '';

    if (!label.startsWith('i18n:')) return label;

    const key = label.replace('i18n:', '');

    // Si usa el nuevo formato i18n:extension.key
    if (i18n && typeof i18n.t === 'function') {
      const translation = i18n.t(key);
      if (translation !== key) return translation;
    }

    ogLogger.warn('com:datatable', `Traducción no encontrada: ${key}`);
    return this.formatHeader(key.split('.').pop());
  }

  static renderRow(row, columns, actions) {
    const hasActions = actions && Object.keys(actions).length > 0;

    return `
      <tr>
        ${columns.map(col => this.renderCell(row, col)).join('')}
        ${hasActions ? `<td>${this.renderActions(row, actions)}</td>` : ''}
      </tr>
    `;
  }

  static renderCell(row, column) {
    let value = row[column.field] || '';

    if (column.format) {
      value = this.formatValue(value, column.format, row);
    }

    const style = [];
    if (column.width) style.push(`width: ${column.width}`);
    if (column.align) style.push(`text-align: ${column.align}`);

    const styleAttr = style.length > 0 ? ` style="${style.join('; ')}"` : '';

    return `<td${styleAttr}>${value}</td>`;
  }

  static formatValue(value, format, row) {
    if (!value) return '';

    if (typeof format === 'function') {
      return format(value, row);
    }

    switch (format) {
      case 'date':
        return this.formatDate(value);

      case 'datetime':
        return this.formatDateTime(value);

      case 'money':
        return this.formatMoney(value);

      case 'boolean':
        return value ? __('com.datatable.yes') : __('com.datatable.no');

      case 'uppercase':
        return String(value).toUpperCase();

      case 'lowercase':
        return String(value).toLowerCase();

      case 'capitalize':
        return String(value).charAt(0).toUpperCase() + String(value).slice(1);

      default:
        return value;
    }
  }

  static formatDate(value) {
    try {
      const date = new Date(value);
      return date.toLocaleDateString();
    } catch {
      return value;
    }
  }

  static formatDateTime(value) {
    try {
      const date = new Date(value);
      return date.toLocaleString();
    } catch {
      return value;
    }
  }

  static formatMoney(value) {
    try {
      return new Intl.NumberFormat('es-EC', {
        style: 'currency',
        currency: 'USD'
      }).format(value);
    } catch {
      return value;
    }
  }

  static renderActions(row, actions) {
    return Object.entries(actions).map(([key, action]) => {
      // Validar acceso por rol
      if (!this.hasRoleAccess(action)) return '';

      // Traducir label
      const actionLabel = this.translateLabel(action.name);

      // Determinar click handler (PRIORIDAD: action > onclick)
      let clickHandler = '';

      if (action.action) {
        // Usar actionHandler con variables reemplazadas
        const actionStr = this.replaceVars(action.action, row);
        // Escapar comillas simples para uso en HTML
        const escapedAction = actionStr.replace(/'/g, "\\'");
        clickHandler = `actionHandler.handle('${escapedAction}')`;

      } else if (action.onclick) {
        // Usar onclick tradicional (backward compatibility)
        clickHandler = this.replaceVars(action.onclick, row);

      } else {
        // Fallback: log de warning
        clickHandler = `console.warn('No action defined for ${key}')`;
      }

      // Construir data attributes (para dataLoader si existe)
      let dataAttrs = '';
      if (action.dataLoader) {
        const loaderConfig = JSON.stringify(action.dataLoader).replace(/"/g, '&quot;');
        dataAttrs = ` data-loader-config="${loaderConfig}" data-row-id="${row.id || row.ID || ''}"`;
      }

      return `<button class="btn btn-sm btn-secondary" onclick="${clickHandler}"${dataAttrs}>${actionLabel}</button>`;
    }).join(' ').trim();
  }

  static replaceVars(str, row) {
    return str.replace(/\{(\w+)\}/g, (match, key) => {
      if (row[key] === undefined) {
        ogLogger.warn('com:datatable', `Key "${key}" no encontrada en row`);
        return match;
      }

      const value = String(row[key])
        .replace(/\\/g, '\\\\')
        .replace(/'/g, "\\'")
        .replace(/"/g, '\\"')
        .replace(/\n/g, '\\n')
        .replace(/\r/g, '\\r');

      return value;
    });
  }

  static formatHeader(col) {
    return col.charAt(0).toUpperCase() + col.slice(1).replace(/_/g, ' ');
  }

  static bindEvents(tableId) {
    // Detectar overflow para mostrar sombra
    setTimeout(() => {
      this.checkTableOverflow(tableId);
    }, 100);

    // Recheck on window resize
    window.addEventListener('resize', () => this.checkTableOverflow(tableId));
  }

  static checkTableOverflow(tableId) {
    const container = document.getElementById(tableId);
    if (!container) return;

    const wrapper = container.querySelector('.table-responsive');
    if (!wrapper) return;

    // Verificar si hay overflow horizontal
    const hasOverflow = wrapper.scrollWidth > wrapper.clientWidth;

    if (hasOverflow) {
      wrapper.classList.add('has-overflow');
    } else {
      wrapper.classList.remove('has-overflow');
    }

    // Actualizar en scroll para ocultar sombra cuando llega al final
    wrapper.addEventListener('scroll', () => {
      const isAtEnd = wrapper.scrollLeft + wrapper.clientWidth >= wrapper.scrollWidth - 5;

      if (isAtEnd) {
        wrapper.classList.remove('has-overflow');
      } else if (hasOverflow) {
        wrapper.classList.add('has-overflow');
      }
    }, { once: false });
  }

  static async refresh(tableId) {
    const table = this.tables.get(tableId);
    if (!table) {
      ogLogger.warn('com:datatable', `Tabla ${tableId} no encontrada`);
      return;
    }

    const { config, extensionName, container } = table;
    const data = await this.loadData(config, extensionName);

    this.tables.set(tableId, { config, data, extensionName, container });

    const html = this.generateHtml(tableId, config, data);
    container.innerHTML = html;

    this.bindEvents(tableId);
  }

  static async refreshFirst() {
    const firstTable = document.querySelector('[data-datatable]');
    if (!firstTable) {
      ogLogger.warn('com:datatable', 'No se encontró ninguna tabla');
      return;
    }

    const tableId = firstTable.getAttribute('data-datatable');
    await this.refresh(tableId);
  }

  static hasRoleAccess(action) {
    const auth = ogModule('auth');

    // Si la acción no tiene restricción de role, permitir acceso
    if (!action.role) return true;

    // Obtener role del usuario actual
    const userRole = auth?.user?.role;

    // Si no hay usuario autenticado, denegar acceso
    if (!userRole) return false;

    // Validar si el role coincide
    return userRole === action.role;
  }

  // ============================================
  // HELPERS DE CACHÉ
  // ============================================

  // Obtener datos cacheados de una tabla por source
  static getCached(source) {
    const cache = ogModule('cache');
    const cacheKey = `datatable_${source.replace(/[^a-zA-Z0-9]/g, '_')}`;
    const cached = cache.get(cacheKey);

    // Si es null o undefined, retornar null
    if (!cached) return null;

    // Si ya es un array, retornarlo directamente
    if (Array.isArray(cached)) return cached;

    // Si es un objeto con propiedad data (algunas APIs)
    if (cached.data && Array.isArray(cached.data)) return cached.data;

    // Si es un objeto simple, convertirlo a array
    if (typeof cached === 'object') return [cached];

    return null;
  }

  // Buscar un registro en caché por ID
  static findInCache(source, id, idField = 'id') {
    const data = this.getCached(source);
    if (!data || !Array.isArray(data)) return null;
    return data.find(item => item[idField] == id);
  }

  // Filtrar registros en caché
  static filterInCache(source, filterFn) {
    const data = this.getCached(source);
    if (!data || !Array.isArray(data)) return [];
    return data.filter(filterFn);
  }

  // Limpiar caché de una tabla específica
  static clearCache(source) {
    const cache = ogModule('cache');
    const cacheKey = `datatable_${source.replace(/[^a-zA-Z0-9]/g, '_')}`;
    cache.delete(cacheKey);
  }
}

// ✅ Exponer GLOBALMENTE como ogModal (usado en onclick de HTML)
window.ogDatatable = ogDatatable;

// Registrar en ogFramework (preferido)
if (typeof window.ogFramework !== 'undefined') {
  window.ogFramework.components.datatable = ogDatatable;
}