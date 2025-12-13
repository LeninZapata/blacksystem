class dataLoader {
  static async load(config, extensionName = null) {
    const type = config.type || 'auto';

    // Modo AUTO: Detecta automáticamente si usar API o Mock
    if (type === 'auto') {
      return await this.loadAuto(config, extensionName);
    }

    // Modo API explícito
    if (type === 'api') {
      return await this.loadFromApi(config);
    }

    // Modo MOCK explícito
    if (type === 'mock') {
      return await this.loadFromMock(config, extensionName);
    }

    logger.error('core:dataLoader', `Tipo de dataSource no válido: ${type}`);
    return null;
  }

  static async loadAuto(config, extensionName) {
    // 1. Verificar si el extension tiene backend habilitado
    const pluginConfig = extensionName ? window.hook?.getPluginConfig(extensionName) : null;
    const backendEnabled = pluginConfig?.backend?.enabled || false;

    // 2. Verificar si el config tiene API habilitada
    const apiEnabled = config.api?.enabled !== false;

    // 3. Decidir fuente
    if (backendEnabled && apiEnabled && config.api?.endpoint) {
      try {
        return await this.loadFromApi(config.api);
      } catch (error) {
        logger.warn('core:dataLoader', `API falló, fallback a mock: ${error.message}`);
        return await this.loadFromMock(config.mock, extensionName);
      }
    }

    // Fallback a mock
    return await this.loadFromMock(config.mock, extensionName);
  }

  static async loadFromApi(apiConfig) {
    const endpoint = apiConfig.endpoint;
    const method = apiConfig.method || 'GET';

    try {
      let response;

      // ✅ CAMBIO CRÍTICO: Usar api.js en lugar de fetch directo
      if (method === 'GET') {
        response = await api.get(endpoint);
      } else if (method === 'POST') {
        response = await api.post(endpoint, apiConfig.body || {});
      } else if (method === 'PUT') {
        response = await api.put(endpoint, apiConfig.body || {});
      } else if (method === 'DELETE') {
        response = await api.delete(endpoint);
      }

      // Si la respuesta tiene estructura {success, data}
      if (response.success && response.data) {
        return response.data;
      }

      // Si la respuesta es directa
      return response;

    } catch (error) {
      logger.error('core:dataLoader', `Error en API ${endpoint}: ${error.message}`);
      throw error;
    }
  }

  static async loadFromMock(mockConfig, extensionName) {
    if (!mockConfig || !mockConfig.file) {
      logger.error('core:dataLoader', 'No se especificó archivo mock');
      return null;
    }

    try {
      // Construir ruta del archivo mock
      let mockPath;

      if (extensionName) {
        // Mock de extension
        mockPath = `${window.BASE_URL}extensions/${extensionName}/${mockConfig.file}`;
      } else {
        // Mock global
        mockPath = `${window.BASE_URL}${mockConfig.file}`;
      }

      const cacheBuster = `?v=${window.VERSION}`;
      
      // Para archivos mock locales, usar fetch directo (no necesitan auth)
      const response = await fetch(mockPath + cacheBuster);

      if (!response.ok) {
        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
      }

      const data = await response.json();

      // Si tiene filtro (para obtener un registro específico)
      if (mockConfig.filterBy && mockConfig.filterValue) {
        const filtered = Array.isArray(data)
          ? data.find(item => item[mockConfig.filterBy] == mockConfig.filterValue)
          : data;

        return filtered;
      }

      return data;

    } catch (error) {
      logger.error('core:dataLoader', `Error cargando mock: ${error.message}`);
      throw error;
    }
  }

  static async loadList(dataSourceConfig, extensionName) {
    return await this.load(dataSourceConfig, extensionName);
  }

  static async loadDetail(dataLoaderConfig, id, extensionName) {
    // Configurar filtro para mock
    if (dataLoaderConfig.mock) {
      dataLoaderConfig.mock.filterValue = id;

      if (!dataLoaderConfig.mock.filterBy) {
        dataLoaderConfig.mock.filterBy = 'id';
      }
    }

    // Reemplazar {id} en endpoint de API
    if (dataLoaderConfig.api?.endpoint) {
      dataLoaderConfig.api.endpoint = dataLoaderConfig.api.endpoint.replace('{id}', id);
    }

    return await this.load(dataLoaderConfig, extensionName);
  }
}

window.dataLoader = dataLoader;