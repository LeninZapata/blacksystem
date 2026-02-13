// productStatsv2.js - Nueva versión del sistema de estadísticas con filtros mejorados
class productStatsv2 {
  static currentFilters = {
    botId: null,
    productId: null,
    dateRange: 'today'
  };

  static bots = [];
  static products = [];

  static async init() {
    ogLogger.debug('ext:infoproduct', 'Inicializando Ventas V2');
    await this.loadBots();
    this.attachEventListeners();
  }

  // Cargar lista de bots desde API
  static async loadBots() {
    try {
      const response = await ogApi.get('/api/bot');
      
      if (response && response.success !== false) {
        this.bots = Array.isArray(response) ? response : (response.data || []);
        this.populateBotSelect();
        
        // Seleccionar el primer bot automáticamente
        if (this.bots.length > 0) {
          const firstBotId = this.bots[0].id;
          this.currentFilters.botId = firstBotId;
          document.getElementById('filter-bot').value = firstBotId;
          await this.loadProducts(firstBotId);
          
          // Cargar estadísticas automáticamente con los filtros por defecto
          await this.loadStats();
        } else {
          ogLogger.warn('ext:infoproduct', 'No se encontraron bots disponibles');
          this.showNoBots();
        }
      } else {
        ogLogger.error('ext:infoproduct', 'Error al cargar bots:', response);
        this.showBotError();
      }
    } catch (error) {
      ogLogger.error('ext:infoproduct', 'Error al cargar bots:', error);
      this.showBotError();
    }
  }

  // Poblar select de bots
  static populateBotSelect() {
    const selectBot = document.getElementById('filter-bot');
    if (!selectBot) return;

    selectBot.innerHTML = '';

    if (this.bots.length === 0) {
      selectBot.innerHTML = '<option value="">No hay bots disponibles</option>';
      return;
    }

    this.bots.forEach(bot => {
      const option = document.createElement('option');
      option.value = bot.id;
      option.textContent = `${bot.name} (${bot.number})`;
      selectBot.appendChild(option);
    });
  }

  // Cargar productos filtrados por bot
  static async loadProducts(botId) {
    if (!botId) {
      this.products = [];
      this.populateProductSelect();
      return;
    }

    try {
      const response = await ogApi.get(`/api/product?bot_id=${botId}`);
      
      if (response && response.success !== false) {
        this.products = Array.isArray(response) ? response : (response.data || []);
        this.populateProductSelect();
        
        ogLogger.debug('ext:infoproduct', `Productos cargados para bot ${botId}:`, this.products.length);
      } else {
        ogLogger.error('ext:infoproduct', 'Error al cargar productos:', response);
        this.products = [];
        this.populateProductSelect();
      }
    } catch (error) {
      ogLogger.error('ext:infoproduct', 'Error al cargar productos:', error);
      this.products = [];
      this.populateProductSelect();
    }
  }

  // Poblar select de productos
  static populateProductSelect() {
    const selectProduct = document.getElementById('filter-product');
    if (!selectProduct) return;

    selectProduct.innerHTML = '<option value="">Todos los productos</option>';

    if (this.products.length === 0) {
      const option = document.createElement('option');
      option.value = '';
      option.textContent = 'No hay productos disponibles';
      option.disabled = true;
      selectProduct.appendChild(option);
      return;
    }

    this.products.forEach(product => {
      const option = document.createElement('option');
      option.value = product.id;
      option.textContent = product.name || `Producto ${product.id}`;
      selectProduct.appendChild(option);
    });
  }

  // Handler cuando cambia el bot seleccionado
  static async onBotChange(botId) {
    ogLogger.debug('ext:infoproduct', 'Bot cambiado:', botId);
    this.currentFilters.botId = botId;
    this.currentFilters.productId = null;
    
    // Resetear select de productos
    const selectProduct = document.getElementById('filter-product');
    if (selectProduct) selectProduct.value = '';
    
    // Cargar productos del nuevo bot
    await this.loadProducts(botId);
    
    // Recargar estadísticas
    this.loadStats();
  }

  // Handler cuando cambia el producto seleccionado
  static onProductChange(productId) {
    ogLogger.debug('ext:infoproduct', 'Producto cambiado:', productId);
    this.currentFilters.productId = productId || null;
    this.loadStats();
  }

  // Handler cuando cambia el rango de fechas
  static onDateRangeChange(range) {
    ogLogger.debug('ext:infoproduct', 'Rango de fechas cambiado:', range);
    this.currentFilters.dateRange = range;
    this.loadStats();
  }

  // Attachar event listeners
  static attachEventListeners() {
    // Listener para cambios en el radio button group de fechas
    const dateInputs = document.querySelectorAll('input[name="date_range"]');
    dateInputs.forEach(input => {
      input.addEventListener('change', (e) => {
        if (e.target.checked) {
          this.onDateRangeChange(e.target.value);
        }
      });
    });
  }

  // Cargar estadísticas con los filtros actuales
  static async loadStats() {
    const { botId, productId, dateRange } = this.currentFilters;

    if (!botId) {
      this.showNoFilters();
      return;
    }

    ogLogger.debug('ext:infoproduct', 'Cargando estadísticas:', this.currentFilters);

    const container = document.getElementById('stats-charts-container-v2');
    if (!container) return;
    
    // Mostrar indicador de carga
    container.innerHTML = `
      <div class="og-text-center">
        <div class="alert alert-info">
          <strong>⏳ Cargando estadísticas...</strong>
        </div>
      </div>
    `;

    // Si es today o yesterday, mostrar gráfica horaria
    if (dateRange === 'today' || dateRange === 'yesterday') {
      await this.loadHourlyChart();
    } else {
      // Para otros rangos, mostrar gráfica diaria con resumen
      await this.loadDailyChart();
    }
  }

  // Cargar y renderizar gráfica de ventas por hora
  static async loadHourlyChart() {
    const { botId, productId, dateRange } = this.currentFilters;
    const container = document.getElementById('stats-charts-container-v2');
    if (!container) return;

    // Calcular fecha según rango
    const today = new Date();
    let targetDate;
    
    if (dateRange === 'today') {
      targetDate = today.toISOString().split('T')[0];
    } else if (dateRange === 'yesterday') {
      const yesterday = new Date(today);
      yesterday.setDate(yesterday.getDate() - 1);
      targetDate = yesterday.toISOString().split('T')[0];
    }

    try {
      // Construir query params
      let queryParams = `date=${targetDate}&bot_id=${botId}`;
      if (productId) {
        queryParams += `&product_id=${productId}`;
      }

      // Llamar API
      const response = await ogApi.get(`/api/sale/stats/hourly?${queryParams}`);
      
      if (!response || !response.success) {
        container.innerHTML = `
          <div class="og-p-1">
            <div class="alert alert-danger">
              <strong>❌ Error al cargar datos</strong>
              <div class="og-text-sm og-mt-1">${response?.error || 'Error desconocido'}</div>
            </div>
          </div>
        `;
        return;
      }

      // Calcular resumen sumando datos por hora
      const data = response.data || [];
      let totalRevenue = 0;
      let directRevenue = 0;
      let remarketingRevenue = 0;
      let upsellRevenue = 0;
      let directCount = 0;
      let remarketingCount = 0;
      let upsellCount = 0;
      let totalConfirmed = 0;
      let totalProspects = 0;

      data.forEach(item => {
        totalRevenue += parseFloat(item.revenue || 0);
        directRevenue += parseFloat(item.direct_revenue || 0);
        remarketingRevenue += parseFloat(item.remarketing_revenue || 0);
        upsellRevenue += parseFloat(item.upsell_revenue || 0);
        directCount += parseInt(item.direct_count || 0);
        remarketingCount += parseInt(item.remarketing_count || 0);
        upsellCount += parseInt(item.upsell_count || 0);
        totalConfirmed += parseInt(item.sales_count || 0);
        totalProspects += parseInt(item.sales_count || 0);
      });

      // Calcular porcentajes
      const directPercent = totalRevenue > 0 ? Math.round((directRevenue / totalRevenue) * 100) : 0;
      const remarketingPercent = totalRevenue > 0 ? Math.round((remarketingRevenue / totalRevenue) * 100) : 0;
      const upsellPercent = totalRevenue > 0 ? Math.round((upsellRevenue / totalRevenue) * 100) : 0;
      const avgConversion = totalProspects > 0 
        ? ((totalConfirmed / totalProspects) * 100).toFixed(2)
        : '0.00';

      // Renderizar contenedor de gráfica + resumen
      container.innerHTML = `
        <div class="">
          <!-- Gráfica -->
          <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-2 og-mb-3">
            <div class="og-mb-3">
              <h3 class="og-text-lg og-font-semibold og-mb-1">
                💰 Ventas por Hora
              </h3>
              <div class="og-text-sm og-text-gray-600">
                ${this.formatRangeLabel(dateRange)} - ${targetDate}
              </div>
            </div>
            <div style="position: relative; height: 400px;">
              <canvas id="chartSalesHourly"></canvas>
            </div>
          </div>

          <!-- Grid de Resumen -->
          <div class="og-grid og-cols-5 og-gap-sm">
            <!-- Total Ingresos -->
            <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-3">
              <div class="og-text-xs og-text-gray-500 og-mb-1">TOTAL INGRESOS</div>
              <div class="og-text-2xl og-font-bold og-text-green-600">
                $${totalRevenue.toLocaleString('es-MX', { minimumFractionDigits: 2 })}
              </div>
            </div>

            <!-- Ventas Directas -->
            <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-3">
              <div class="og-text-xs og-text-gray-500 og-mb-1">VENTAS DIRECTAS (${directCount}) [${directPercent}%]</div>
              <div class="og-text-2xl og-font-bold og-text-blue-600">
                $${directRevenue.toLocaleString('es-MX', { minimumFractionDigits: 2 })}
              </div>
            </div>

            <!-- Remarketing -->
            <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-3">
              <div class="og-text-xs og-text-gray-500 og-mb-1">REMARKETING (${remarketingCount}) [${remarketingPercent}%]</div>
              <div class="og-text-2xl og-font-bold og-text-purple-600">
                $${remarketingRevenue.toLocaleString('es-MX', { minimumFractionDigits: 2 })}
              </div>
            </div>

            <!-- Upsell -->
            <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-3">
              <div class="og-text-xs og-text-gray-500 og-mb-1">UPSELL (${upsellCount}) [${upsellPercent}%]</div>
              <div class="og-text-2xl og-font-bold og-text-indigo-600">
                $${upsellRevenue.toLocaleString('es-MX', { minimumFractionDigits: 2 })}
              </div>
            </div>

            <!-- Conversión -->
            <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-3">
              <div class="og-text-xs og-text-gray-500 og-mb-1">CONVERSIÓN (${totalConfirmed}/${totalProspects})</div>
              <div class="og-text-2xl og-font-bold og-text-orange-600">
                ${avgConversion}%
              </div>
            </div>
          </div>
        </div>
      `;

      // Renderizar gráfica con Chart.js
      if (window.productChartSalesHourly) {
        window.productChartSalesHourly.render(response.data || []);
      } else {
        ogLogger.error('ext:infoproduct', 'productChartSalesHourly no está cargado');
      }

    } catch (error) {
      ogLogger.error('ext:infoproduct', 'Error al cargar gráfica horaria:', error);
      container.innerHTML = `
        <div class="og-p-1">
          <div class="alert alert-danger">
            <strong>❌ Error al cargar gráfica</strong>
            <div class="og-text-sm og-mt-1">${error.message}</div>
          </div>
        </div>
      `;
    }
  }

  // Cargar y renderizar gráfica de ventas por día con resumen
  static async loadDailyChart() {
    const { botId, productId, dateRange } = this.currentFilters;
    const container = document.getElementById('stats-charts-container-v2');
    if (!container) return;

    try {
      // Construir query params
      let queryParams = `range=${dateRange}`;
      if (botId) {
        queryParams += `&bot_id=${botId}`;
      }
      if (productId) {
        queryParams += `&product_id=${productId}`;
      }

      // Llamar API
      const response = await ogApi.get(`/api/sale/stats/revenue-conversion?${queryParams}`);
      
      if (!response || !response.success) {
        container.innerHTML = `
          <div class="og-p-1">
            <div class="alert alert-danger">
              <strong>❌ Error al cargar datos</strong>
              <div class="og-text-sm og-mt-1">${response?.error || 'Error desconocido'}</div>
            </div>
          </div>
        `;
        return;
      }

      const summary = response.summary || {};
      const totalRevenue = summary.total_revenue || 0;
      const directRevenue = summary.direct_revenue || 0;
      const remarketingRevenue = summary.remarketing_revenue || 0;
      const upsellRevenue = summary.upsell_revenue || 0;
      const directCount = summary.direct_count || 0;
      const remarketingCount = summary.remarketing_count || 0;
      const upsellCount = summary.upsell_count || 0;
      const avgConversion = summary.avg_conversion || 0;
      const totalConfirmed = summary.total_confirmed || 0;
      const totalProspects = summary.total_prospects || 0;

      // Calcular porcentajes
      const directPercent = totalRevenue > 0 ? Math.round((directRevenue / totalRevenue) * 100) : 0;
      const remarketingPercent = totalRevenue > 0 ? Math.round((remarketingRevenue / totalRevenue) * 100) : 0;
      const upsellPercent = totalRevenue > 0 ? Math.round((upsellRevenue / totalRevenue) * 100) : 0;

      // Renderizar contenedor de gráfica + resumen
      container.innerHTML = `
        <div class="">
          <!-- Gráfica -->
          <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-2 og-mb-3">
            <div class="og-mb-3">
              <h3 class="og-text-lg og-font-semibold og-mb-1">
                📊 Ventas por Día
              </h3>
              <div class="og-text-sm og-text-gray-600">
                ${this.formatRangeLabel(dateRange)}
              </div>
            </div>
            <div style="position: relative; height: 400px;">
              <canvas id="chartSalesDaily"></canvas>
            </div>
          </div>

          <!-- Grid de Resumen -->
          <div class="og-grid og-cols-5 og-gap-sm">
            <!-- Total Ingresos -->
            <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-3">
              <div class="og-text-xs og-text-gray-500 og-mb-1">TOTAL INGRESOS</div>
              <div class="og-text-2xl og-font-bold og-text-green-600">
                $${totalRevenue.toLocaleString('es-MX', { minimumFractionDigits: 2 })}
              </div>
            </div>

            <!-- Ventas Directas -->
            <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-3">
              <div class="og-text-xs og-text-gray-500 og-mb-1">VENTAS DIRECTAS (${directCount}) [${directPercent}%]</div>
              <div class="og-text-2xl og-font-bold og-text-blue-600">
                $${directRevenue.toLocaleString('es-MX', { minimumFractionDigits: 2 })}
              </div>
            </div>

            <!-- Remarketing -->
            <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-3">
              <div class="og-text-xs og-text-gray-500 og-mb-1">REMARKETING (${remarketingCount}) [${remarketingPercent}%]</div>
              <div class="og-text-2xl og-font-bold og-text-purple-600">
                $${remarketingRevenue.toLocaleString('es-MX', { minimumFractionDigits: 2 })}
              </div>
            </div>

            <!-- Upsell -->
            <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-3">
              <div class="og-text-xs og-text-gray-500 og-mb-1">UPSELL (${upsellCount}) [${upsellPercent}%]</div>
              <div class="og-text-2xl og-font-bold og-text-indigo-600">
                $${upsellRevenue.toLocaleString('es-MX', { minimumFractionDigits: 2 })}
              </div>
            </div>

            <!-- Conversión -->
            <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-3">
              <div class="og-text-xs og-text-gray-500 og-mb-1">CONVERSIÓN (${totalConfirmed}/${totalProspects})</div>
              <div class="og-text-2xl og-font-bold og-text-orange-600">
                ${avgConversion}%
              </div>
            </div>
          </div>
        </div>
      `;

      // Renderizar gráfica con Chart.js
      if (window.productChartSalesDaily) {
        window.productChartSalesDaily.render(response.data || []);
      } else {
        ogLogger.error('ext:infoproduct', 'productChartSalesDaily no está cargado');
      }

    } catch (error) {
      ogLogger.error('ext:infoproduct', 'Error al cargar gráfica diaria:', error);
      container.innerHTML = `
        <div class="og-p-1">
          <div class="alert alert-danger">
            <strong>❌ Error al cargar gráfica</strong>
            <div class="og-text-sm og-mt-1">${error.message}</div>
          </div>
        </div>
      `;
    }
  }

  // Mostrar mensaje cuando no hay bots
  static showNoBots() {
    const container = document.getElementById('stats-charts-container-v2');
    if (!container) return;

    container.innerHTML = `
      <div class="alert alert-danger alert-filled og-text-center">
        <div style="font-size: 2rem;" class="og-mb-2">🤖</div>
        <strong>No hay bots disponibles</strong>
        <div class="og-text-gray-600 og-mt-1" style="font-size: 0.9rem;">Crea un bot para poder ver las estadísticas.</div>
      </div>
    `;
  }

  // Mostrar error al cargar bots
  static showBotError() {
    const selectBot = document.getElementById('filter-bot');
    if (selectBot) {
      selectBot.innerHTML = '<option value="">Error al cargar bots</option>';
    }
  }

  // Mostrar mensaje cuando no hay filtros seleccionados
  static showNoFilters() {
    const container = document.getElementById('stats-charts-container-v2');
    if (!container) return;

    container.innerHTML = `
      <div class="og-text-center og-text-gray-500 og-p-4">
        <div class="og-mb-2" style="font-size: 2rem;">🔍</div>
        <div class="og-mb-1" style="font-weight: 500;">Selecciona los filtros</div>
        <div style="font-size: 0.9rem;">Elige un bot para comenzar a ver las estadísticas.</div>
      </div>
    `;
  }

  // Formatear label del rango de fechas
  static formatRangeLabel(range) {
    const labels = {
      'today': 'Hoy',
      'yesterday': 'Ayer',
      'yesterday_today': 'Ayer y Hoy',
      'last_3_days': 'Hace 3 días',
      'last_7_days': 'Hace 7 días',
      'last_15_days': 'Hace 15 días',
      'this_month': 'Este mes',
      'last_30_days': 'Hace 30 días'
    };
    return labels[range] || range;
  }
}

// Registrar en window para acceso global
window.productStatsv2 = productStatsv2;
