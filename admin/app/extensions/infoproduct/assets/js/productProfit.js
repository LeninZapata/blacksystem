/**
 * productProfit.js
 * Controlador principal para la pestaña de Profit
 * Gestiona filtros, carga de datos y renderizado de gráficas
 */

class productProfit {
  static currentFilters = {
    botId: null,
    productId: null,
    dateRange: 'today',
    customDate: null
  };

  static bots = [];
  static products = [];

  /**
   * Inicializar módulo
   */
  static async init() {
    ogLogger.info('ext:infoproduct', 'Inicializando módulo Profit');

    // Cargar bots
    await this.loadBots();

    // Configurar event listeners
    this.setupEventListeners();

    // Cargar stats iniciales si hay bot seleccionado
    if (this.currentFilters.botId) {
      await this.loadStats();
    } else {
      this.showNoFilters();
    }
  }

  /**
   * Configurar event listeners
   */
  static setupEventListeners() {
    // Cambio de bot
    const selectBot = document.getElementById('profit-filter-bot');
    if (selectBot) {
      selectBot.addEventListener('change', async (e) => {
        this.currentFilters.botId = e.target.value;
        this.currentFilters.productId = null;
        
        // Recargar productos
        await this.loadProducts();
        
        // Recargar stats
        if (this.currentFilters.botId) {
          await this.loadStats();
        } else {
          this.showNoFilters();
        }
      });
    }

    // Cambio de producto
    const selectProduct = document.getElementById('profit-filter-product');
    if (selectProduct) {
      selectProduct.addEventListener('change', async (e) => {
        this.currentFilters.productId = e.target.value;
        await this.loadStats();
      });
    }

    // Radio buttons de rango de fecha
    const rangeRadios = document.querySelectorAll('input[name="profit_date_range"]');
    rangeRadios.forEach(radio => {
      radio.addEventListener('change', async (e) => {
        if (e.target.checked) {
          const value = e.target.value;
          
          // Mostrar/ocultar el input de fecha personalizada
          const customDateContainer = document.getElementById('profit-custom-date-container');
          if (value === 'custom_date') {
            if (customDateContainer) {
              customDateContainer.style.display = 'block';
              // Establecer fecha de hoy por defecto si no hay fecha seleccionada
              const customDateInput = document.getElementById('profit-custom-date-input');
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
          
          this.currentFilters.dateRange = value;
          await this.loadStats();
        }
      });
    });
    
    // Listener para el input de fecha personalizada
    const customDateInput = document.getElementById('profit-custom-date-input');
    if (customDateInput) {
      customDateInput.addEventListener('change', async (e) => {
        this.currentFilters.customDate = e.target.value;
        // Solo recargar si el radio de fecha personalizada está seleccionado
        const customRadio = document.getElementById('profit-range-custom');
        if (customRadio && customRadio.checked) {
          await this.loadStats();
        }
      });
    }
  }

  /**
   * Cargar lista de bots
   */
  static async loadBots() {
    try {
      const response = await ogApi.get('/api/bot?status=1');
      
      if (!response || !response.success) {
        this.showBotError();
        return;
      }

      this.bots = response.data || [];

      if (this.bots.length === 0) {
        this.showNoBots();
        return;
      }

      // Renderizar select de bots
      const selectBot = document.getElementById('profit-filter-bot');
      if (selectBot) {
        selectBot.innerHTML = '<option value="">Seleccionar bot...</option>';
        
        this.bots.forEach(bot => {
          const option = document.createElement('option');
          option.value = bot.id;
          option.textContent = `${bot.name} (${bot.number})`;
          selectBot.appendChild(option);
        });

        // Auto-seleccionar si solo hay un bot
        if (this.bots.length === 1) {
          selectBot.value = this.bots[0].id;
          this.currentFilters.botId = this.bots[0].id;
          await this.loadProducts();
        }
      }

    } catch (error) {
      ogLogger.error('ext:infoproduct', 'Error al cargar bots:', error);
      this.showBotError();
    }
  }

  /**
   * Cargar lista de productos del bot seleccionado
   */
  static async loadProducts() {
    const selectProduct = document.getElementById('profit-filter-product');
    if (!selectProduct) return;

    // Resetear select
    selectProduct.innerHTML = '<option value="">Todos los productos</option>';
    this.products = [];

    if (!this.currentFilters.botId) return;

    try {
      const response = await ogApi.get(`/api/product?bot_id=${this.currentFilters.botId}&status=1`);
      
      if (!response || !response.success) {
        ogLogger.error('ext:infoproduct', 'Error al cargar productos');
        return;
      }

      this.products = response.data || [];

      // Renderizar opciones
      this.products.forEach(product => {
        const option = document.createElement('option');
        option.value = product.id;
        option.textContent = product.name;
        selectProduct.appendChild(option);
      });

    } catch (error) {
      ogLogger.error('ext:infoproduct', 'Error al cargar productos:', error);
    }
  }

  /**
   * Cargar y renderizar estadísticas
   */
  static async loadStats() {
    const { botId, productId, dateRange } = this.currentFilters;

    if (!botId) {
      this.showNoFilters();
      return;
    }

    // Decidir si mostrar gráfica por hora o por día
    // HOY y AYER usan gráfica horaria (hoy con is_latest=1, ayer con último snapshot de cada hora)
    // Días anteriores y rangos múltiples usan gráfica diaria (datos consolidados)
    if (dateRange === 'today' || dateRange === 'yesterday' || dateRange === 'custom_date') {
      await this.loadHourlyChart();
    } else {
      // Para rangos de múltiples días, usar gráfica diaria
      await this.loadDailyChart();
    }
  }

  /**
   * Cargar y renderizar gráfica por hora
   */
  static async loadHourlyChart() {
    const { botId, productId, dateRange, customDate } = this.currentFilters;
    const container = document.getElementById('profit-charts-container');
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
      const response = await ogApi.get(`/api/profit/hourly?${queryParams}`);
      
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

      // Calcular resumen
      const data = response.data || [];
      let totalProfit = 0;
      let totalRevenue = 0;
      let totalSpend = 0;

      // El backend siempre envía un summary calculado desde datos horarios
      if (response.summary) {
        totalProfit = parseFloat(response.summary.total_profit || 0);
        totalRevenue = parseFloat(response.summary.total_revenue || 0);
        totalSpend = parseFloat(response.summary.total_spend || 0);
      } else if (data.length > 0) {
        // Fallback: sumar revenue y purchases directamente de todos los registros del chart
        // NOTA: el revenue en el chart es acumulado, así que tomamos el último valor para spend
        // y sumamos revenue incremental (diferencia entre registros consecutivos)
        let maxSpendItem = data[0];
        data.forEach(item => {
          if (parseFloat(item.spend || 0) > parseFloat(maxSpendItem.spend || 0)) {
            maxSpendItem = item;
          }
        });
        
        // El último revenue acumulado del chart puede no incluir ventas en horas sin ads
        // Solo usamos este fallback cuando no hay summary (situación excepcional)
        totalProfit = parseFloat(maxSpendItem.profit || 0);
        totalRevenue = parseFloat(maxSpendItem.revenue || 0);
        totalSpend = parseFloat(maxSpendItem.spend || 0);
      }

      const avgRoas = totalSpend > 0 ? (totalRevenue / totalSpend).toFixed(2) : '0.00';

      // Renderizar contenedor de gráfica + resumen
      container.innerHTML = `
        <div class="">
          <!-- Gráfica -->
          <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-2 og-mb-3">
            <div class="og-mb-3">
              <h3 class="og-text-lg og-font-semibold og-mb-1">
                💰 Profit por Hora
              </h3>
              <div class="og-text-sm og-text-gray-600">
                ${this.formatRangeLabel(dateRange)} - ${targetDate}
              </div>
            </div>
            <div style="position: relative; height: 400px;">
              <canvas id="chartProfitHourly"></canvas>
            </div>
          </div>

          <!-- Grid de Resumen -->
          <div class="og-grid og-cols-4 og-gap-sm">
            <!-- Profit -->
            <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-3">
              <div class="og-text-xs og-text-gray-500 og-mb-1">PROFIT</div>
              <div class="og-text-2xl og-font-bold ${totalProfit >= 0 ? 'og-text-green-600' : 'og-text-red-600'}">
                $${totalProfit.toLocaleString('es-MX', { minimumFractionDigits: 2 })}
              </div>
            </div>

            <!-- ROAS -->
            <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-3">
              <div class="og-text-xs og-text-gray-500 og-mb-1">ROAS</div>
              <div class="og-text-2xl og-font-bold og-text-purple-600">
                ${avgRoas}x
              </div>
            </div>

            <!-- Ingreso -->
            <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-3">
              <div class="og-text-xs og-text-gray-500 og-mb-1">INGRESO</div>
              <div class="og-text-2xl og-font-bold og-text-blue-600">
                $${totalRevenue.toLocaleString('es-MX', { minimumFractionDigits: 2 })}
              </div>
            </div>

            <!-- Gasto Publicitario -->
            <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-3">
              <div class="og-text-xs og-text-gray-500 og-mb-1">GASTO PUBLICITARIO</div>
              <div class="og-text-2xl og-font-bold og-text-red-600">
                $${totalSpend.toLocaleString('es-MX', { minimumFractionDigits: 2 })}
              </div>
            </div>
          </div>
        </div>
      `;

      // Renderizar gráfica con Chart.js
      if (window.productChartProfitHourly) {
        window.productChartProfitHourly.render(response.data || []);
      } else {
        ogLogger.error('ext:infoproduct', 'productChartProfitHourly no está cargado');
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

  /**
   * Cargar y renderizar gráfica por día con resumen
   */
  static async loadDailyChart() {
    const { botId, productId, dateRange } = this.currentFilters;
    const container = document.getElementById('profit-charts-container');
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
      const response = await ogApi.get(`/api/profit/daily?${queryParams}`);
      
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
      const totalProfit = summary.total_profit || 0;
      const totalRevenue = summary.total_revenue || 0;
      const totalSpend = summary.total_spend || 0;
      const avgRoas = summary.avg_roas || 0;

      // Renderizar contenedor de gráfica + resumen
      container.innerHTML = `
        <div class="">
          <!-- Gráfica -->
          <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-2 og-mb-3">
            <div class="og-mb-3">
              <h3 class="og-text-lg og-font-semibold og-mb-1">
                💰 Profit por Día
              </h3>
              <div class="og-text-sm og-text-gray-600">
                ${this.formatRangeLabel(dateRange)}
              </div>
            </div>
            <div style="position: relative; height: 400px;">
              <canvas id="chartProfitDaily"></canvas>
            </div>
          </div>

          <!-- Grid de Resumen -->
          <div class="og-grid og-cols-4 og-gap-sm">
            <!-- Profit -->
            <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-3">
              <div class="og-text-xs og-text-gray-500 og-mb-1">PROFIT</div>
              <div class="og-text-2xl og-font-bold ${totalProfit >= 0 ? 'og-text-green-600' : 'og-text-red-600'}">
                $${totalProfit.toLocaleString('es-MX', { minimumFractionDigits: 2 })}
              </div>
            </div>

            <!-- ROAS -->
            <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-3">
              <div class="og-text-xs og-text-gray-500 og-mb-1">ROAS</div>
              <div class="og-text-2xl og-font-bold og-text-purple-600">
                ${avgRoas}x
              </div>
            </div>

            <!-- Ingreso -->
            <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-3">
              <div class="og-text-xs og-text-gray-500 og-mb-1">INGRESO</div>
              <div class="og-text-2xl og-font-bold og-text-blue-600">
                $${totalRevenue.toLocaleString('es-MX', { minimumFractionDigits: 2 })}
              </div>
            </div>

            <!-- Gasto Publicitario -->
            <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-3">
              <div class="og-text-xs og-text-gray-500 og-mb-1">GASTO PUBLICITARIO</div>
              <div class="og-text-2xl og-font-bold og-text-red-600">
                $${totalSpend.toLocaleString('es-MX', { minimumFractionDigits: 2 })}
              </div>
            </div>
          </div>
        </div>
      `;

      // Renderizar gráfica con Chart.js
      if (window.productChartProfitDaily) {
        window.productChartProfitDaily.render(response.data || []);
      } else {
        ogLogger.error('ext:infoproduct', 'productChartProfitDaily no está cargado');
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

  /**
   * Mostrar mensaje cuando no hay bots
   */
  static showNoBots() {
    const container = document.getElementById('profit-charts-container');
    if (!container) return;

    container.innerHTML = `
      <div class="alert alert-danger alert-filled og-text-center">
        <div style="font-size: 2rem;" class="og-mb-2">🤖</div>
        <strong>No hay bots disponibles</strong>
        <div class="og-text-gray-600 og-mt-1" style="font-size: 0.9rem;">Crea un bot para poder ver las estadísticas.</div>
      </div>
    `;
  }

  /**
   * Mostrar error al cargar bots
   */
  static showBotError() {
    const selectBot = document.getElementById('profit-filter-bot');
    if (selectBot) {
      selectBot.innerHTML = '<option value="">Error al cargar bots</option>';
    }
  }

  /**
   * Mostrar mensaje cuando no hay filtros seleccionados
   */
  static showNoFilters() {
    const container = document.getElementById('profit-charts-container');
    if (!container) return;

    container.innerHTML = `
      <div class="og-text-center og-text-gray-500 og-p-4">
        <div class="og-mb-2" style="font-size: 2rem;">🔍</div>
        <div class="og-mb-1" style="font-weight: 500;">Selecciona los filtros</div>
        <div style="font-size: 0.9rem;">Elige un bot para comenzar a ver las estadísticas.</div>
      </div>
    `;
  }

  /**
   * Formatear label del rango de fechas
   */
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

  /**
   * Convertir Date a string en formato YYYY-MM-DD usando zona horaria local
   */
  static getLocalDateString(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
  }
}

// Registrar en window para acceso global
window.productProfit = productProfit;
