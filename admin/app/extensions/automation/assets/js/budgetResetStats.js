/**
 * budgetResetStats.js
 * Gestión de estadísticas de reseteo de presupuesto diario por activo publicitario
 * @version 1.0.0
 */

const budgetResetStats = {
  // ========================================
  // ESTADO
  // ========================================
  currentAssetId: null,
  currentRange: 'yesterday_today',
  customDate: null,
  assets: [],
  chart: null,
  
  // ========================================
  // INICIALIZACIÓN
  // ========================================
  
  /**
   * Inicializar módulo
   */
  init() {
    console.log('budgetResetStats.init()');
    this.loadAssets();
    this.attachEventListeners();
  },
  
  /**
   * Cargar lista de activos publicitarios
   */
  async loadAssets() {
    try {
      const response = await ogApi.get('/api/productAdAsset?per_page=1000&is_active=1');
      
      if (response && response.success !== false) {
        this.assets = Array.isArray(response) ? response : (response.data || []);
        this.populateAssetSelect();
        
        console.log('budgetResetStats - Activos cargados:', this.assets.length);
      } else {
        console.error('budgetResetStats - Error al cargar activos:', response);
        ogToast.error('Error al cargar activos publicitarios');
      }
    } catch (error) {
      console.error('budgetResetStats - Error loadAssets:', error);
      ogToast.error('Error al cargar activos publicitarios');
    }
  },
  
  /**
   * Poblar selector de activos
   */
  populateAssetSelect() {
    const select = document.getElementById('filter-asset-reset');
    if (!select) return;
    
    // Limpiar opciones existentes (excepto la primera)
    select.innerHTML = '<option value="">Selecciona un activo...</option>';
    
    if (this.assets.length === 0) {
      const option = document.createElement('option');
      option.value = '';
      option.textContent = 'No hay activos disponibles';
      option.disabled = true;
      select.appendChild(option);
      return;
    }
    
    // Agregar opciones
    this.assets.forEach(asset => {
      const option = document.createElement('option');
      option.value = asset.id;
      
      // Construir nombre legible
      const assetTypeLabel = this.getAssetTypeLabel(asset.ad_asset_type);
      const platformLabel = this.getPlatformLabel(asset.ad_platform);
      option.textContent = `${asset.ad_asset_name || asset.ad_asset_id} [${assetTypeLabel} - ${platformLabel}]`;
      
      select.appendChild(option);
    });
  },
  
  /**
   * Obtener label de tipo de activo
   */
  getAssetTypeLabel(type) {
    const labels = {
      'campaign': 'Campaña',
      'adset': 'AdSet',
      'ad': 'Anuncio'
    };
    return labels[type] || type;
  },
  
  /**
   * Obtener label de plataforma
   */
  getPlatformLabel(platform) {
    const labels = {
      'facebook': 'Facebook',
      'google': 'Google',
      'tiktok': 'TikTok',
      'instagram': 'Instagram'
    };
    return labels[platform] || platform;
  },
  
  /**
   * Adjuntar eventos
   */
  attachEventListeners() {
    // Radio buttons de rango de fechas
    const rangeRadios = document.querySelectorAll('input[name="reset_date_range"]');
    rangeRadios.forEach(radio => {
      radio.addEventListener('change', (e) => {
        this.onRangeChange(e.target.value);
      });
    });
    
    // Input de fecha personalizada
    const customDateInput = document.getElementById('reset-custom-date-input');
    if (customDateInput) {
      customDateInput.addEventListener('change', (e) => {
        this.onCustomDateChange(e.target.value);
      });
    }
  },
  
  // ========================================
  // MANEJADORES DE EVENTOS
  // ========================================
  
  /**
   * Al cambiar de activo
   */
  onAssetChange(assetId) {
    console.log('budgetResetStats.onAssetChange:', assetId);
    
    if (!assetId) {
      this.currentAssetId = null;
      this.showPlaceholder();
      return;
    }
    
    this.currentAssetId = assetId;
    this.loadStats();
  },
  
  /**
   * Al cambiar rango de fechas
   */
  onRangeChange(range) {
    console.log('budgetResetStats.onRangeChange:', range);
    this.currentRange = range;
    
    // Mostrar/ocultar contenedor de fecha personalizada
    const customContainer = document.getElementById('reset-custom-date-container');
    if (customContainer) {
      customContainer.style.display = range === 'custom_date' ? 'block' : 'none';
    }
    
    // Si hay activo seleccionado, recargar stats
    if (this.currentAssetId) {
      this.loadStats();
    }
  },
  
  /**
   * Al cambiar fecha personalizada
   */
  onCustomDateChange(date) {
    console.log('budgetResetStats.onCustomDateChange:', date);
    this.customDate = date;
    
    // Si hay activo seleccionado, recargar stats
    if (this.currentAssetId) {
      this.loadStats();
    }
  },
  
  // ========================================
  // CARGA DE DATOS
  // ========================================
  
  /**
   * Cargar estadísticas de reseteo
   */
  async loadStats() {
    if (!this.currentAssetId) {
      this.showPlaceholder();
      return;
    }
    
    try {
      this.showLoading();
      
      // Obtener datos del activo seleccionado
      const asset = this.assets.find(a => a.id == this.currentAssetId);
      if (!asset) {
        ogToast.error('Activo no encontrado');
        this.showError();
        return;
      }
      
      const productId = asset.product_id;
      
      console.log('loadStats - Asset:', { assetId: this.currentAssetId, productId, range: this.currentRange });
      
      // Llamar a ambos endpoints en paralelo
      const [profitResponse, resetsResponse] = await Promise.all([
        // 1. Profit diario (desde ProfitStatsHandler)
        ogApi.get(`/api/profit/daily?range=${this.currentRange}&product_id=${productId}`),
        
        // 2. Reseteos de presupuesto (desde AdAutoScaleStatsHandler)
        ogApi.get(`/api/adAutoScale/stats/budget-resets-daily?asset_id=${this.currentAssetId}&range=${this.currentRange}`)
      ]);
      
      console.log('Profit response:', profitResponse);
      console.log('Resets response:', resetsResponse);
      
      // Validar respuestas
      if (!profitResponse || !profitResponse.success) {
        ogToast.error('Error al cargar datos de profit');
        this.showError();
        return;
      }
      
      if (!resetsResponse || !resetsResponse.success) {
        ogToast.error('Error al cargar datos de reseteos');
        this.showError();
        return;
      }
      
      const profitData = profitResponse.data || [];
      const resetsData = resetsResponse.data || [];
      
      // Combinar datos por fecha
      const combinedData = this.combineData(profitData, resetsData);
      
      console.log('Combined data:', combinedData);
      
      // Renderizar gráfica
      this.renderChart(combinedData, profitResponse.summary);
      
    } catch (error) {
      console.error('Error loadStats:', error);
      ogToast.error('Error al cargar estadísticas');
      this.showError();
    }
  },
  
  /**
   * Combinar datos de profit y reseteos por fecha
   */
  combineData(profitData, resetsData) {
    // Crear mapa de profit por fecha
    const profitMap = {};
    profitData.forEach(profit => {
      profitMap[profit.date] = profit.profit;
    });
    
    // Crear mapa de reseteos por fecha
    const resetsMap = {};
    resetsData.forEach(reset => {
      resetsMap[reset.date] = reset.budget_after;
    });
    
    // Obtener todas las fechas únicas (de profit y reseteos)
    const allDates = new Set([
      ...profitData.map(p => p.date),
      ...resetsData.map(r => r.date)
    ]);
    
    // Crear array combinado con todas las fechas
    const combined = Array.from(allDates).map(date => ({
      date,
      profit: profitMap[date] !== undefined ? profitMap[date] : 0,
      budget_reset: resetsMap[date] || null
    }));
    
    // Ordenar por fecha
    combined.sort((a, b) => new Date(a.date) - new Date(b.date));
    
    return combined;
  },
  
  // ========================================
  // RENDERIZADO
  // ========================================
  
  /**
   * Mostrar placeholder inicial
   */
  showPlaceholder() {
    const container = document.getElementById('budget-reset-stats-container');
    if (!container) return;
    
    container.innerHTML = `
      <p class="og-text-center og-text-gray-500 og-p-4">
        Selecciona un activo publicitario para ver los reseteos de presupuesto
      </p>
    `;
  },
  
  /**
   * Mostrar loading
   */
  showLoading() {
    const container = document.getElementById('budget-reset-stats-container');
    if (!container) return;
    
    container.innerHTML = `
      <div class="og-text-center og-p-4">
        <div class="spinner-border text-primary" role="status">
          <span class="sr-only">Cargando...</span>
        </div>
        <p class="og-text-gray-500 og-mt-2">Cargando estadísticas...</p>
      </div>
    `;
  },
  
  /**
   * Mostrar error
   */
  showError() {
    const container = document.getElementById('budget-reset-stats-container');
    if (!container) return;
    
    container.innerHTML = `
      <div class="og-text-center og-p-4">
        <p class="og-text-red-500">❌ Error al cargar las estadísticas</p>
        <button class="btn btn-secondary btn-sm og-mt-2" onclick="budgetResetStats.loadStats()">
          🔄 Reintentar
        </button>
      </div>
    `;
  },
  
  /**
   * Renderizar gráfica combinada
   */
  renderChart(data, summary) {
    const container = document.getElementById('budget-reset-stats-container');
    if (!container) return;
    
    if (data.length === 0) {
      container.innerHTML = `
        <div class="og-p-4">
          <div class="alert alert-info">
            <strong>ℹ️ Sin datos</strong>
            <div class="og-text-sm og-mt-1">No hay datos disponibles para este período</div>
          </div>
        </div>
      `;
      return;
    }
    
    // Renderizar HTML
    container.innerHTML = `
      <div class="">
        <!-- Resumen -->
        <div class="og-grid og-cols-4 og-gap-sm og-mb-3">
          <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-3">
            <div class="og-text-xs og-text-gray-500 og-mb-1">PROFIT TOTAL</div>
            <div class="og-text-2xl og-font-bold ${summary.total_profit >= 0 ? 'og-text-green-600' : 'og-text-red-600'}">
              ${summary.total_profit >= 0 ? '+' : ''}$${summary.total_profit.toFixed(2)}
            </div>
          </div>
          
          <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-3">
            <div class="og-text-xs og-text-gray-500 og-mb-1">GASTO TOTAL ADS</div>
            <div class="og-text-2xl og-font-bold og-text-red-600">
              $${summary.total_spend.toFixed(2)}
            </div>
          </div>
          
          <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-3">
            <div class="og-text-xs og-text-gray-500 og-mb-1">VENTAS TOTALES</div>
            <div class="og-text-2xl og-font-bold og-text-blue-600">
              $${summary.total_revenue.toFixed(2)}
            </div>
          </div>
          
          <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-3">
            <div class="og-text-xs og-text-gray-500 og-mb-1">ROAS PROMEDIO</div>
            <div class="og-text-2xl og-font-bold og-text-purple-600">
              ${summary.avg_roas}x
            </div>
          </div>
        </div>
        
        <!-- Gráfica -->
        <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-2">
          <div class="og-mb-3">
            <h3 class="og-text-lg og-font-semibold og-mb-1">
              💰 Profit vs Reseteo de Presupuesto
            </h3>
            <div class="og-text-sm og-text-gray-600">
              Barras = Profit | Línea = Presupuesto después del reset
            </div>
          </div>
          <div style="position: relative; height: 450px;">
            <canvas id="chartBudgetResets"></canvas>
          </div>
        </div>
      </div>
    `;
    
    // Crear gráfica
    this.createChart(data);
  },
  
  /**
   * Crear gráfica mixta con Chart.js
   */
  createChart(data) {
    const ctx = document.getElementById('chartBudgetResets');
    if (!ctx) {
      console.error('Canvas chartBudgetResets no encontrado');
      return;
    }
    
    // Preparar datos
    const labels = data.map(item => this.formatDate(item.date));
    const profitData = data.map(item => item.profit);
    const budgetData = data.map(item => item.budget_reset);
    
    // Destruir gráfica anterior si existe
    if (this.chart) {
      this.chart.destroy();
    }
    
    // Crear nueva gráfica mixta
    this.chart = new Chart(ctx, {
      type: 'bar',
      data: {
        labels,
        datasets: [
          {
            type: 'bar',
            label: 'Profit ($)',
            data: profitData,
            backgroundColor: profitData.map(p => p >= 0 ? 'rgba(52, 211, 153, 0.7)' : 'rgba(239, 68, 68, 0.7)'),
            borderColor: profitData.map(p => p >= 0 ? 'rgba(52, 211, 153, 1)' : 'rgba(239, 68, 68, 1)'),
            borderWidth: 2,
            borderRadius: 6,
            yAxisID: 'y'
          },
          {
            type: 'line',
            label: 'Presupuesto Reset ($)',
            data: budgetData,
            borderColor: 'rgba(59, 130, 246, 1)',
            backgroundColor: 'rgba(59, 130, 246, 0.1)',
            borderWidth: 3,
            tension: 0.4,
            fill: false,
            pointRadius: 6,
            pointHoverRadius: 8,
            pointBackgroundColor: 'rgba(59, 130, 246, 1)',
            pointBorderColor: '#fff',
            pointBorderWidth: 2,
            yAxisID: 'y'
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: {
          mode: 'index',
          intersect: false
        },
        plugins: {
          legend: {
            display: true,
            position: 'top',
            labels: {
              font: {
                size: 13
              },
              padding: 15
            }
          },
          tooltip: {
            callbacks: {
              title: (context) => {
                const idx = context[0].dataIndex;
                return this.formatDateFull(data[idx].date);
              },
              label: (context) => {
                const label = context.dataset.label || '';
                const value = context.parsed.y;
                if (value === null) return label + ': Sin datos';
                return label + ': $' + value.toFixed(2);
              }
            },
            backgroundColor: 'rgba(0, 0, 0, 0.8)',
            padding: 12,
            titleFont: {
              size: 14,
              weight: 'bold'
            },
            bodyFont: {
              size: 13
            }
          }
        },
        scales: {
          y: {
            beginAtZero: true,
            ticks: {
              callback: (value) => '$' + value.toFixed(2),
              font: {
                size: 12
              }
            },
            title: {
              display: true,
              text: 'Monto (USD)',
              font: {
                size: 13,
                weight: 'bold'
              }
            },
            grid: {
              color: 'rgba(0, 0, 0, 0.05)'
            }
          },
          x: {
            ticks: {
              maxRotation: 45,
              minRotation: 45,
              font: {
                size: 11
              }
            },
            grid: {
              display: false
            }
          }
        }
      }
    });
  },
  
  /**
   * Formatear fecha corta (DD MMM)
   */
  formatDate(dateString) {
    const date = new Date(dateString + 'T00:00:00');
    return date.toLocaleDateString('es-ES', { 
      day: '2-digit', 
      month: 'short' 
    });
  },
  
  /**
   * Formatear fecha completa
   */
  formatDateFull(dateString) {
    const date = new Date(dateString + 'T00:00:00');
    return date.toLocaleDateString('es-ES', { 
      day: '2-digit', 
      month: 'long',
      year: 'numeric'
    });
  },
  
  // ========================================
  // UTILIDADES
  // ========================================
  
  /**
   * Refrescar datos
   */
  refresh() {
    if (this.currentAssetId) {
      this.loadStats();
    }
  }
};
