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
  eventsAttached: false, // Bandera para evitar múltiples adjuntos
  
  // ========================================
  // INICIALIZACIÓN
  // ========================================
  
  /**
   * Inicializar módulo
   */
  init() {
    console.log('budgetResetStats.init()');
    this.loadAssets();
    
    // Solo adjuntar eventos una vez
    if (!this.eventsAttached) {
      this.attachEventListeners();
      this.eventsAttached = true;
    }
  },
  
  /**
   * Cargar lista de activos publicitarios
   */
  async loadAssets() {
    try {
      const response = await ogApi.get('/api/productAdAsset?per_page=1000&is_active=1&status=1');
      
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
      const countryCode = (asset.country_code || '').toUpperCase();
      const flag = countryCode.length === 2
        ? countryCode.split('').map(c => String.fromCodePoint(0x1F1E6 + c.charCodeAt(0) - 65)).join('')
        : '';
      option.textContent = `${flag ? flag + ' ' : ''}${asset.ad_asset_name || asset.ad_asset_id} [${assetTypeLabel} - ${platformLabel}]`;
      
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
   * Adjuntar eventos usando delegación de eventos
   */
  attachEventListeners() {
    // Usar delegación de eventos en document para que funcione incluso si los elementos se recargan
    document.addEventListener('change', (e) => {
      // Radio buttons de rango de fechas
      if (e.target.name === 'reset_date_range') {
        this.onRangeChange(e.target.value);
      }
      
      // Input de fecha personalizada
      if (e.target.id === 'reset-custom-date-input') {
        this.onCustomDateChange(e.target.value);
      }
    });
    
    console.log('budgetResetStats: Event listeners adjuntados con delegación de eventos');
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

      // Calcular fechas en el timezone del usuario (igual que el header X-User-Timezone de ogApi)
      const auth = typeof ogModule === 'function' ? ogModule('auth') : null;
      const userTz = auth?.userPreferences?.timezone || Intl.DateTimeFormat().resolvedOptions().timeZone;
      const toTzISO = d => new Intl.DateTimeFormat('en-CA', { timeZone: userTz }).format(d);
      const todayInTz = toTzISO(new Date());
      const yesterdayInTz = (() => {
        const d = new Date(todayInTz + 'T12:00:00');
        d.setDate(d.getDate() - 1);
        return toTzISO(d);
      })();

      // Para rangos de un solo día usar /hourly (mismo cálculo que la gráfica de Profit por Hora).
      // Para yesterday_today también usar /hourly por cada día (daily da resultados distintos).
      // Para rangos multi-día usar /daily.
      const singleDayRanges = ['today', 'yesterday'];
      const isSingleDay = singleDayRanges.includes(this.currentRange) || this.currentRange === 'custom_date';
      const isYesterdayToday = this.currentRange === 'yesterday_today';

      const resetsUrl = `/api/adAutoScale/stats/budget-resets-daily?asset_id=${this.currentAssetId}&range=${this.currentRange}`;

      let profitData;
      let profitSummary;

      if (isYesterdayToday) {
        // Llamar hourly para cada día en paralelo junto con los reseteos
        const [yesterdayRes, todayRes, resetsResponse] = await Promise.all([
          ogApi.get(`/api/profit/hourly?date=${yesterdayInTz}&product_id=${productId}`),
          ogApi.get(`/api/profit/hourly?date=${todayInTz}&product_id=${productId}`),
          ogApi.get(resetsUrl)
        ]);

        console.log('Yesterday profit response:', yesterdayRes);
        console.log('Today profit response:', todayRes);
        console.log('Resets response:', resetsResponse);

        if (!yesterdayRes?.success || !todayRes?.success) {
          ogToast.error('Error al cargar datos de profit');
          this.showError();
          return;
        }
        if (!resetsResponse?.success) {
          ogToast.error('Error al cargar datos de reseteos');
          this.showError();
          return;
        }

        const ySummary = yesterdayRes.summary || {};
        const tSummary = todayRes.summary   || {};
        const totalRevenue = (ySummary.total_revenue || 0) + (tSummary.total_revenue || 0);
        const totalSpend   = (ySummary.total_spend   || 0) + (tSummary.total_spend   || 0);

        profitData = [
          { date: yesterdayInTz, profit: ySummary.total_profit || 0 },
          { date: todayInTz,     profit: tSummary.total_profit || 0 }
        ];
        profitSummary = {
          total_profit:  (ySummary.total_profit || 0) + (tSummary.total_profit || 0),
          total_revenue: totalRevenue,
          total_spend:   totalSpend,
          avg_roas:      totalSpend > 0 ? (totalRevenue / totalSpend).toFixed(2) : '0.00'
        };

        const resetsData = resetsResponse.data || [];
        const combinedData = this.combineData(profitData, resetsData);
        console.log('Combined data:', combinedData);
        this.renderChart(combinedData, profitSummary);
        return;
      }

      // Construir URL de profit para otros rangos
      let profitUrl;
      let targetDate = null;
      if (isSingleDay) {
        if (this.currentRange === 'yesterday') {
          targetDate = yesterdayInTz;
        } else if (this.currentRange === 'custom_date' && this.customDate) {
          targetDate = this.customDate;
        } else {
          targetDate = todayInTz;
        }
        profitUrl = `/api/profit/hourly?date=${targetDate}&product_id=${productId}`;
      } else {
        profitUrl = `/api/profit/daily?range=${this.currentRange}&product_id=${productId}`;
      }

      // Llamar a ambos endpoints en paralelo
      const [profitResponse, resetsResponse] = await Promise.all([
        ogApi.get(profitUrl),
        ogApi.get(resetsUrl)
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

      // Normalizar respuesta de profit: hourly devuelve {data:[{hour,...}], summary:{...}},
      // daily devuelve {data:[{date, profit,...}], summary:{...}}.
      if (isSingleDay) {
        profitSummary = profitResponse.summary || { total_profit: 0, total_revenue: 0, total_spend: 0, avg_roas: 0 };
        profitData = profitSummary
          ? [{ date: profitResponse.filters?.date || targetDate, profit: profitSummary.total_profit }]
          : [];
      } else {
        profitData    = profitResponse.data    || [];
        profitSummary = profitResponse.summary || {};
      }

      const resetsData = resetsResponse.data || [];
      const combinedData = this.combineData(profitData, resetsData);
      console.log('Combined data:', combinedData);
      this.renderChart(combinedData, profitSummary);

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

    // Crear mapa de reseteos por fecha (budget_after + reset_kind)
    const resetsMap = {};
    resetsData.forEach(reset => {
      resetsMap[reset.date] = { budget_after: reset.budget_after, reset_kind: reset.reset_kind || 'base' };
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
      budget_reset: resetsMap[date]?.budget_after || null,
      reset_kind: resetsMap[date]?.reset_kind || null
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
                const idx = context.dataIndex;
                const value = context.parsed.y;
                if (context.datasetIndex === 1) {
                  // Línea de presupuesto reset
                  if (value === null) return 'Presupuesto Reset: Sin datos';
                  const kind = data[idx].reset_kind;
                  const kindLabel = kind === 'profit' ? 'Reset Profit' : 'Reset Base';
                  return `${kindLabel}: $${value.toFixed(2)}`;
                }
                const label = context.dataset.label || '';
                if (value === null) return label + ': Sin datos';
                return label + ': $' + value.toFixed(2);
              },
              afterLabel: (context) => {
                const idx = context.dataIndex;
                if (context.datasetIndex === 1 && data[idx].reset_kind === 'profit') {
                  return '  ↑ Ajustado por profit del día anterior';
                }
                return null;
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
