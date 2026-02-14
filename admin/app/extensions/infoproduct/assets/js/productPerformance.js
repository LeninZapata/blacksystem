// productPerformance.js - Sistema de estadísticas de rendimiento publicitario
class productPerformance {
  static currentFilters = {
    botId: null,
    productId: null,
    dateRange: 'today',
    customDate: null
  };

  static bots = [];
  static products = [];

  static async init() {
    ogLogger.debug('ext:infoproduct', 'Inicializando Rendimiento');
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
          
          const botSelect = document.getElementById('perf-filter-bot');
          if (botSelect) {
            botSelect.value = firstBotId;
          }
          
          await this.loadProducts(firstBotId);
          
          // Cargar estadísticas automáticamente
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
    const selectBot = document.getElementById('perf-filter-bot');
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
    const selectProduct = document.getElementById('perf-filter-product');
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
    const selectProduct = document.getElementById('perf-filter-product');
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
    const dateInputs = document.querySelectorAll('input[name="perf_date_range"]');
    dateInputs.forEach(input => {
      input.addEventListener('change', (e) => {
        if (e.target.checked) {
          const value = e.target.value;
          
          // Mostrar/ocultar el input de fecha personalizada
          const customDateContainer = document.getElementById('perf-custom-date-container');
          if (value === 'custom_date') {
            if (customDateContainer) {
              customDateContainer.style.display = 'block';
              // Establecer fecha de hoy por defecto si no hay fecha seleccionada
              const customDateInput = document.getElementById('perf-custom-date-input');
              if (customDateInput && !customDateInput.value) {
                customDateInput.value = this.getLocalDateString(new Date());
                this.currentFilters.customDate = customDateInput.value;
              }
            }
          } else {
            if (customDateContainer) {
              customDateContainer.style.display = 'none';
            }
            this.currentFilters.customDate = null;
          }
          
          this.onDateRangeChange(value);
        }
      });
    });
    
    // Listener para el input de fecha personalizada
    const customDateInput = document.getElementById('perf-custom-date-input');
    if (customDateInput) {
      customDateInput.addEventListener('change', (e) => {
        this.currentFilters.customDate = e.target.value;
        // Solo recargar si el radio de fecha personalizada está seleccionado
        const customRadio = document.getElementById('perf-range-custom');
        if (customRadio && customRadio.checked) {
          this.loadStats();
        }
      });
    }
  }

  // Cargar estadísticas con los filtros actuales
  static async loadStats() {
    const { botId, productId, dateRange } = this.currentFilters;

    if (!botId) {
      this.showNoFilters();
      return;
    }

    ogLogger.debug('ext:infoproduct', 'Cargando estadísticas de rendimiento:', this.currentFilters);

    const container = document.getElementById('performance-charts-container');
    if (!container) return;
    
    // Mostrar indicador de carga
    container.innerHTML = `
      <div class="og-text-center">
        <div class="alert alert-info">
          <strong>⏳ Cargando estadísticas...</strong>
        </div>
      </div>
    `;

    // Si es today, yesterday o custom_date, mostrar gráfica horaria
    if (dateRange === 'today' || dateRange === 'yesterday' || dateRange === 'custom_date') {
      await this.loadHourlyChart();
    } else {
      // Para otros rangos, mostrar gráfica diaria
      await this.loadDailyChart();
    }
  }

  // Cargar y renderizar gráfica por hora
  static async loadHourlyChart() {
    const { botId, productId, dateRange, customDate } = this.currentFilters;
    const container = document.getElementById('performance-charts-container');
    if (!container) return;

    // Calcular fecha según rango usando fecha local del navegador
    const today = new Date();
    let targetDate;
    
    if (dateRange === 'today') {
      targetDate = this.getLocalDateString(today);
    } else if (dateRange === 'yesterday') {
      const yesterday = new Date(today);
      yesterday.setDate(yesterday.getDate() - 1);
      targetDate = this.getLocalDateString(yesterday);
    } else if (dateRange === 'custom_date') {
      // Usar la fecha personalizada seleccionada
      targetDate = customDate || this.getLocalDateString(today);
    }

    try {
      // Construir query params
      let queryParams = `date=${targetDate}&bot_id=${botId}`;
      if (productId) {
        queryParams += `&product_id=${productId}`;
      }

      // Llamar API
      const response = await ogApi.get(`/api/performance/metrics-hourly?${queryParams}`);
      
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
      let totalChats = 0;
      let totalClicks = 0;
      let totalReach = 0;

      data.forEach(item => {
        totalChats += parseInt(item.chats_initiated || 0);
        totalClicks += parseInt(item.whatsapp_clicks || 0);
        totalReach += parseInt(item.reach || 0);
      });

      const clickToChatRate = totalClicks > 0 
        ? ((totalChats / totalClicks) * 100).toFixed(2)
        : '0.00';

      // Renderizar contenedor de gráfica + resumen
      container.innerHTML = `
        <div class="">
          <!-- Gráfica -->
          <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-2 og-mb-3">
            <div class="og-mb-3">
              <h3 class="og-text-lg og-font-semibold og-mb-1">
                📈 Rendimiento por Hora
              </h3>
              <div class="og-text-sm og-text-gray-600">
                ${this.formatRangeLabel(dateRange)} - ${targetDate}
              </div>
            </div>
            <div style="position: relative; height: 400px;">
              <canvas id="chartPerformanceHourly"></canvas>
            </div>
          </div>

          <!-- Nota informativa -->
          <div class="alert alert-info alert-filled og-mb-3">
            <strong>⏱️ Datos de Facebook Ads</strong>
            Las métricas de rendimiento (Alcance, Chats, Clics) provienen directamente de Facebook Ads y se actualizan automáticamente cada hora en el sistema.
          </div>

          <!-- Grid de Resumen -->
          <div class="og-grid og-cols-4 og-gap-sm">
            <!-- Chats Iniciados -->
            <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-3">
              <div class="og-text-xs og-text-gray-500 og-mb-1">CHATS INICIADOS</div>
              <div class="og-text-2xl og-font-bold og-text-green-600">
                ${totalChats.toLocaleString()}
              </div>
            </div>

            <!-- Clics en WhatsApp -->
            <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-3">
              <div class="og-text-xs og-text-gray-500 og-mb-1">CLICS EN WHATSAPP</div>
              <div class="og-text-2xl og-font-bold og-text-blue-600">
                ${totalClicks.toLocaleString()}
              </div>
            </div>

            <!-- Alcance -->
            <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-3">
              <div class="og-text-xs og-text-gray-500 og-mb-1">ALCANCE TOTAL</div>
              <div class="og-text-2xl og-font-bold og-text-purple-600">
                ${totalReach.toLocaleString()}
              </div>
            </div>

            <!-- Tasa Clic a Chat -->
            <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-3">
              <div class="og-text-xs og-text-gray-500 og-mb-1">TASA CLIC → CHAT</div>
              <div class="og-text-2xl og-font-bold og-text-orange-600">
                ${clickToChatRate}%
              </div>
            </div>
          </div>
        </div>
      `;

      // Renderizar gráfica con Chart.js
      if (window.productChartPerformanceHourly) {
        window.productChartPerformanceHourly.render(response.data || []);
      } else {
        ogLogger.error('ext:infoproduct', 'productChartPerformanceHourly no está cargado');
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

  // Cargar y renderizar gráfica por día con resumen
  static async loadDailyChart() {
    const { botId, productId, dateRange } = this.currentFilters;
    const container = document.getElementById('performance-charts-container');
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
      const response = await ogApi.get(`/api/performance/metrics-daily?${queryParams}`);
      
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
      const totalChats = summary.total_chats_initiated || 0;
      const totalClicks = summary.total_whatsapp_clicks || 0;
      const totalReach = summary.total_reach || 0;
      const clickToChatRate = summary.click_to_chat_rate || 0;

      // Renderizar contenedor de gráfica + resumen
      container.innerHTML = `
        <div class="">
          <!-- Gráfica -->
          <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-2 og-mb-3">
            <div class="og-mb-3">
              <h3 class="og-text-lg og-font-semibold og-mb-1">
                📈 Rendimiento por Día
              </h3>
              <div class="og-text-sm og-text-gray-600">
                ${this.formatRangeLabel(dateRange)}
              </div>
            </div>
            <div style="position: relative; height: 400px;">
              <canvas id="chartPerformanceDaily"></canvas>
            </div>
          </div>

          <!-- Nota informativa -->
          <div class="alert alert-info alert-filled og-mb-3">
            <strong>⏱️ Datos de Facebook Ads</strong>
            Las métricas de rendimiento (Alcance, Chats, Clics) provienen directamente de Facebook Ads y se actualizan automáticamente cada hora en el sistema.
          </div>

          <!-- Grid de Resumen -->
          <div class="og-grid og-cols-4 og-gap-sm">
            <!-- Chats Iniciados -->
            <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-3">
              <div class="og-text-xs og-text-gray-500 og-mb-1">CHATS INICIADOS</div>
              <div class="og-text-2xl og-font-bold og-text-green-600">
                ${totalChats.toLocaleString()}
              </div>
            </div>

            <!-- Clics en WhatsApp -->
            <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-3">
              <div class="og-text-xs og-text-gray-500 og-mb-1">CLICS EN WHATSAPP</div>
              <div class="og-text-2xl og-font-bold og-text-blue-600">
                ${totalClicks.toLocaleString()}
              </div>
            </div>

            <!-- Alcance -->
            <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-3">
              <div class="og-text-xs og-text-gray-500 og-mb-1">ALCANCE TOTAL</div>
              <div class="og-text-2xl og-font-bold og-text-purple-600">
                ${totalReach.toLocaleString()}
              </div>
            </div>

            <!-- Tasa Clic a Chat -->
            <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-3">
              <div class="og-text-xs og-text-gray-500 og-mb-1">TASA CLIC → CHAT</div>
              <div class="og-text-2xl og-font-bold og-text-orange-600">
                ${clickToChatRate}%
              </div>
            </div>
          </div>
        </div>
      `;

      // Renderizar gráfica con Chart.js
      if (window.productChartPerformanceDaily) {
        window.productChartPerformanceDaily.render(response.data || []);
      } else {
        ogLogger.error('ext:infoproduct', 'productChartPerformanceDaily no está cargado');
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
    const container = document.getElementById('performance-charts-container');
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
    const selectBot = document.getElementById('perf-filter-bot');
    if (selectBot) {
      selectBot.innerHTML = '<option value="">Error al cargar bots</option>';
    }
  }

  // Mostrar mensaje cuando no hay filtros seleccionados
  static showNoFilters() {
    const container = document.getElementById('performance-charts-container');
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
      'last_30_days': 'Hace 30 días',
      'custom_date': 'Día específico'
    };
    return labels[range] || range;
  }

  // Convertir Date a string en formato YYYY-MM-DD usando zona horaria local
  static getLocalDateString(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
  }
}

// Registrar en window para acceso global
window.productPerformance = productPerformance;
