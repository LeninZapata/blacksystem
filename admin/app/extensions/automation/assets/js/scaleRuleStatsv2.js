// scaleRuleStatsv2.js - Nueva versión del sistema de estadísticas de escalado
class scaleRuleStatsv2 {
  static currentFilters = {
    assetId: null,
    dateRange: 'today',
    customDate: null
  };

  static assets = [];
  static chart = null;

  static async init() {
    ogLogger.debug('ext:automation', 'Inicializando Movimientos de Escala V2');
    await this.loadAssets();
    this.attachEventListeners();
  }

  // Cargar lista de activos publicitarios desde API
  static async loadAssets() {
    try {
      const response = await ogApi.get('/api/productAdAsset?per_page=1000&is_active=1');
      
      if (response && response.success !== false) {
        this.assets = Array.isArray(response) ? response : (response.data || []);
        this.populateAssetSelect();
        
        ogLogger.debug('ext:automation', 'Activos publicitarios cargados:', this.assets.length);
      } else {
        ogLogger.error('ext:automation', 'Error al cargar activos:', response);
        this.showAssetError();
      }
    } catch (error) {
      ogLogger.error('ext:automation', 'Error al cargar activos:', error);
      this.showAssetError();
    }
  }

  // Poblar select de activos
  static populateAssetSelect() {
    const selectAsset = document.getElementById('filter-asset-v2');
    if (!selectAsset) return;

    selectAsset.innerHTML = '<option value="">Selecciona un activo...</option>';

    if (this.assets.length === 0) {
      const option = document.createElement('option');
      option.value = '';
      option.textContent = 'No hay activos disponibles';
      option.disabled = true;
      selectAsset.appendChild(option);
      return;
    }

    this.assets.forEach(asset => {
      const option = document.createElement('option');
      option.value = asset.id;
      
      // Construir nombre legible
      const assetTypeLabel = this.getAssetTypeLabel(asset.ad_asset_type);
      const platformLabel = this.getPlatformLabel(asset.ad_platform);
      option.textContent = `${asset.ad_asset_name || asset.ad_asset_id} [${assetTypeLabel} - ${platformLabel}]`;
      
      selectAsset.appendChild(option);
    });
  }

  // Handler cuando cambia el activo seleccionado
  static async onAssetChange(assetId) {
    ogLogger.debug('ext:automation', 'Activo cambiado:', assetId);
    this.currentFilters.assetId = assetId || null;
    await this.loadStats();
  }

  // Handler cuando cambia el rango de fechas
  static onDateRangeChange(range) {
    ogLogger.debug('ext:automation', 'Rango de fechas cambiado:', range);
    this.currentFilters.dateRange = range;
    this.loadStats();
  }

  // Attachar event listeners
  static attachEventListeners() {
    // Listener para cambios en el radio button group de fechas
    const dateInputs = document.querySelectorAll('input[name="scale_date_range"]');
    dateInputs.forEach(input => {
      input.addEventListener('change', (e) => {
        if (e.target.checked) {
          const value = e.target.value;
          
          // Mostrar/ocultar el input de fecha personalizada
          const customDateContainer = document.getElementById('scale-custom-date-container');
          if (value === 'custom_date') {
            if (customDateContainer) {
              customDateContainer.style.display = 'block';
              // Establecer fecha de hoy por defecto si no hay fecha seleccionada
              const customDateInput = document.getElementById('scale-custom-date-input');
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
    const customDateInput = document.getElementById('scale-custom-date-input');
    if (customDateInput) {
      customDateInput.addEventListener('change', (e) => {
        this.currentFilters.customDate = e.target.value;
        // Solo recargar si el radio de fecha personalizada está seleccionado
        const customRadio = document.getElementById('scale-range-custom');
        if (customRadio && customRadio.checked) {
          this.loadStats();
        }
      });
    }
  }

  // Cargar estadísticas con los filtros actuales
  static async loadStats() {
    const { assetId, dateRange, customDate } = this.currentFilters;

    if (!assetId) {
      this.showNoFilters();
      return;
    }

    ogLogger.debug('ext:automation', 'Cargando estadísticas de escalado:', this.currentFilters);

    const container = document.getElementById('scale-stats-container-v2');
    if (!container) return;
    
    // Mostrar indicador de carga
    container.innerHTML = `
      <div class="og-text-center">
        <div class="alert alert-info">
          <strong>⏳ Cargando estadísticas...</strong>
        </div>
      </div>
    `;

    try {
      // Determinar el rango a usar
      let apiRange = dateRange;
      
      // Si es fecha personalizada, convertir a formato de API
      if (dateRange === 'custom_date' && customDate) {
        apiRange = `custom:${customDate}`;
      }

      // Decidir qué tipo de gráfica usar
      // Gráficas horarias solo para: today, yesterday, custom_date
      const useHourlyChart = ['today', 'yesterday', 'custom_date'].includes(dateRange);

      let response;
      if (useHourlyChart) {
        // Llamar API de datos por hora
        response = await ogApi.get(`/api/adAutoScale/stats/budget-changes?asset_id=${assetId}&range=${apiRange}`);
      } else {
        // Llamar API de datos diarios
        response = await ogApi.get(`/api/adAutoScale/stats/budget-changes-daily?asset_id=${assetId}&range=${apiRange}`);
      }
      
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

      const data = response.data || [];

      if (data.length === 0) {
        container.innerHTML = `
          <div class="og-p-1">
            <div class="alert alert-info">
              <strong>ℹ️ Sin datos</strong>
              <div class="og-text-sm og-mt-1">No hay movimientos de presupuesto para este período</div>
            </div>
          </div>
        `;
        return;
      }

      // Renderizar gráfica y resumen según el tipo
      if (useHourlyChart) {
        this.renderHourlyChartAndSummary(data);
      } else {
        this.renderDailyChartAndSummary(data);
      }

    } catch (error) {
      ogLogger.error('ext:automation', 'Error al cargar estadísticas:', error);
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

  // Renderizar gráfica horaria y resumen (con timeline)
  static renderHourlyChartAndSummary(data) {
    const container = document.getElementById('scale-stats-container-v2');
    if (!container) return;

    // Calcular estadísticas
    const totalChanges = data.length;
    const firstBudget = parseFloat(data[0]?.budget_before || 0);
    const lastBudget = parseFloat(data[data.length - 1]?.budget_after || 0);
    const totalChange = lastBudget - firstBudget;

    // Contar tipos de cambios
    let increments = 0;
    let decrements = 0;
    let pauses = 0;

    data.forEach(item => {
      if (item.action_type === 'increase_budget') increments++;
      else if (item.action_type === 'decrease_budget') decrements++;
      else if (item.action_type === 'pause') pauses++;
    });

    // Renderizar HTML
    container.innerHTML = `
      <div class="">
        <!-- Gráfica -->
        <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-2 og-mb-3">
          <div class="og-mb-3">
            <h3 class="og-text-lg og-font-semibold og-mb-1">
              💰 Cambios de Presupuesto (Por Hora)
            </h3>
            <div class="og-text-sm og-text-gray-600">
              ${this.formatRangeLabel(this.currentFilters.dateRange)}
            </div>
          </div>
          <div style="position: relative; height: 400px;">
            <canvas id="chartBudgetChanges"></canvas>
          </div>
        </div>

        <!-- Grid de Resumen -->
        <div class="og-grid og-cols-5 og-gap-sm">
          <!-- Cambios Totales -->
          <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-3">
            <div class="og-text-xs og-text-gray-500 og-mb-1">CAMBIOS TOTALES</div>
            <div class="og-text-2xl og-font-bold og-text-blue-600">
              ${totalChanges}
            </div>
            <div class="og-text-xs og-text-gray-500 og-mt-1">
              📈 ${increments} | 📉 ${decrements} | ⏸️ ${pauses}
            </div>
          </div>

          <!-- Presupuesto Inicial -->
          <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-3">
            <div class="og-text-xs og-text-gray-500 og-mb-1">PRESUPUESTO INICIAL</div>
            <div class="og-text-2xl og-font-bold og-text-gray-600">
              $${firstBudget.toFixed(2)}
            </div>
          </div>

          <!-- Presupuesto Actual -->
          <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-3">
            <div class="og-text-xs og-text-gray-500 og-mb-1">PRESUPUESTO ACTUAL</div>
            <div class="og-text-2xl og-font-bold og-text-blue-600">
              $${lastBudget.toFixed(2)}
            </div>
          </div>

          <!-- Cambio Total -->
          <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-3">
            <div class="og-text-xs og-text-gray-500 og-mb-1">CAMBIO TOTAL</div>
            <div class="og-text-2xl og-font-bold ${totalChange >= 0 ? 'og-text-green-600' : 'og-text-red-600'}">
              ${totalChange >= 0 ? '+' : ''}$${totalChange.toFixed(2)}
            </div>
          </div>

          <!-- Promedio por Cambio -->
          <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-3">
            <div class="og-text-xs og-text-gray-500 og-mb-1">PROMEDIO POR CAMBIO</div>
            <div class="og-text-2xl og-font-bold og-text-purple-600">
              $${(totalChange / totalChanges).toFixed(2)}
            </div>
          </div>
        </div>

        <!-- Timeline de Movimientos -->
        <div class="og-mt-3">
          <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-2">
            <h3 class="og-text-lg og-font-semibold og-mb-3">
              📅 Línea de Tiempo de Cambios
            </h3>
            <div id="timeline-movements-container"></div>
          </div>
        </div>
      </div>
    `;

    // Renderizar gráfica con Chart.js
    this.createHourlyChart(data);
    
    // Renderizar timeline (solo para gráficas horarias)
    this.renderTimeline(data);
  }

  // Renderizar gráfica diaria y resumen (sin timeline)
  static renderDailyChartAndSummary(data) {
    const container = document.getElementById('scale-stats-container-v2');
    if (!container) return;

    // Calcular estadísticas totales
    const totalDays = data.length;
    const totalPositiveRules = data.reduce((sum, day) => sum + day.positive_rules_count, 0);
    const totalNegativeRules = data.reduce((sum, day) => sum + day.negative_rules_count, 0);
    const totalPauses = data.reduce((sum, day) => sum + day.pause_count, 0);
    const totalChanges = totalPositiveRules + totalNegativeRules + totalPauses;
    
    const firstBudget = data.length > 0 ? parseFloat(data[0]?.final_budget || 0) : 0;
    const lastBudget = data.length > 0 ? parseFloat(data[data.length - 1]?.final_budget || 0) : 0;
    const totalChange = lastBudget - firstBudget;

    // Renderizar HTML (sin timeline)
    container.innerHTML = `
      <div class="">
        <!-- Gráfica -->
        <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-2 og-mb-3">
          <div class="og-mb-3">
            <h3 class="og-text-lg og-font-semibold og-mb-1">
              💰 Presupuesto Final por Día
            </h3>
            <div class="og-text-sm og-text-gray-600">
              ${this.formatRangeLabel(this.currentFilters.dateRange)}
            </div>
          </div>
          <div style="position: relative; height: 400px;">
            <canvas id="chartBudgetChanges"></canvas>
          </div>
        </div>

        <!-- Grid de Resumen -->
        <div class="og-grid og-cols-5 og-gap-sm">
          <!-- Días con Datos -->
          <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-3">
            <div class="og-text-xs og-text-gray-500 og-mb-1">DÍAS CON ACTIVIDAD</div>
            <div class="og-text-2xl og-font-bold og-text-blue-600">
              ${totalDays}
            </div>
            <div class="og-text-xs og-text-gray-500 og-mt-1">
              Total de cambios: ${totalChanges}
            </div>
          </div>

          <!-- Reglas Positivas -->
          <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-3">
            <div class="og-text-xs og-text-gray-500 og-mb-1">REGLAS POSITIVAS</div>
            <div class="og-text-2xl og-font-bold og-text-green-600">
              ${totalPositiveRules}
            </div>
            <div class="og-text-xs og-text-gray-500 og-mt-1">
             Aumentos de presupuesto
            </div>
          </div>

          <!-- Reglas Negativas -->
          <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-3">
            <div class="og-text-xs og-text-gray-500 og-mb-1">REGLAS NEGATIVAS</div>
            <div class="og-text-2xl og-font-bold og-text-red-600">
              ${totalNegativeRules}
            </div>
            <div class="og-text-xs og-text-gray-500 og-mt-1">
              Disminuciones
            </div>
          </div>

          <!-- Presupuesto Inicial -->
          <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-3">
            <div class="og-text-xs og-text-gray-500 og-mb-1">PRESUPUESTO INICIAL</div>
            <div class="og-text-2xl og-font-bold og-text-gray-600">
              $${firstBudget.toFixed(2)}
            </div>
          </div>

          <!-- Presupuesto Final -->
          <div class="og-bg-white og-rounded-lg og-border og-border-gray-200 og-p-3">
            <div class="og-text-xs og-text-gray-500 og-mb-1">PRESUPUESTO FINAL</div>
            <div class="og-text-2xl og-font-bold ${totalChange >= 0 ? 'og-text-green-600' : 'og-text-red-600'}">
              $${lastBudget.toFixed(2)}
              <div class="og-text-xs og-text-gray-500 og-mt-1">
                ${totalChange >= 0 ? '+' : ''}$${totalChange.toFixed(2)}
              </div>
            </div>
          </div>
        </div>
      </div>
    `;

    // Renderizar gráfica diaria con Chart.js
    this.createDailyChart(data);
  }

  // Crear gráfica horaria con Chart.js
  static createHourlyChart(data) {
    const ctx = document.getElementById('chartBudgetChanges');
    if (!ctx) {
      ogLogger.error('ext:automation', 'Canvas chartBudgetChanges no encontrado');
      return;
    }

    // Preparar datos para la gráfica
    const labels = data.map(item => this.formatTime(item.executed_at));
    const budgets = data.map(item => parseFloat(item.budget_after));

    // Colores de puntos según el tipo de acción
    const pointColors = data.map(item => {
      if (item.action_type === 'increase_budget') return '#27ae60'; // Verde
      if (item.action_type === 'decrease_budget') return '#e74c3c'; // Rojo
      return '#95a5a6'; // Gris para pause
    });

    // Destruir gráfica anterior si existe
    if (this.chart) {
      this.chart.destroy();
    }

    // Crear nueva gráfica
    this.chart = new Chart(ctx, {
      type: 'line',
      data: {
        labels,
        datasets: [{
          label: 'Presupuesto (USD)',
          data: budgets,
          borderColor: 'rgba(52, 152, 219, 1)',
          backgroundColor: 'rgba(52, 152, 219, 0.1)',
          borderWidth: 3,
          tension: 0.4,
          fill: true,
          pointRadius: 6,
          pointHoverRadius: 8,
          pointBackgroundColor: pointColors,
          pointBorderColor: '#fff',
          pointBorderWidth: 2
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            display: false
          },
          tooltip: {
            callbacks: {
              title: (context) => {
                const idx = context[0].dataIndex;
                return this.formatDateTime(data[idx].executed_at);
              },
              label: (context) => {
                const idx = context.dataIndex;
                const item = data[idx];
                const actionLabel = this.getActionLabel(item.action_type);
                return [
                  `Presupuesto: $${item.budget_after}`,
                  `Cambio: ${item.budget_change >= 0 ? '+' : ''}$${item.budget_change}`,
                  `Anterior: $${item.budget_before}`,
                  `Acción: ${actionLabel}`,
                  item.rule_name ? `Regla: ${item.rule_name}` : 'Manual'
                ];
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
            beginAtZero: false,
            ticks: {
              callback: (value) => '$' + value.toFixed(2),
              font: {
                size: 12
              }
            },
            title: {
              display: true,
              text: 'Presupuesto (USD)',
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
        },
        interaction: {
          intersect: false,
          mode: 'index'
        }
      }
    });
  }

  // Crear gráfica diaria con Chart.js (barras)
  static createDailyChart(data) {
    const ctx = document.getElementById('chartBudgetChanges');
    if (!ctx) {
      ogLogger.error('ext:automation', 'Canvas chartBudgetChanges no encontrado');
      return;
    }

    // Preparar datos para la gráfica
    const labels = data.map(item => this.formatDate(item.date));
    const budgets = data.map(item => parseFloat(item.final_budget));
    const positiveRules = data.map(item => item.positive_rules_count);
    const negativeRules = data.map(item => item.negative_rules_count);

    // Destruir gráfica anterior si existe
    if (this.chart) {
      this.chart.destroy();
    }

    // Crear nueva gráfica de barras
    this.chart = new Chart(ctx, {
      type: 'bar',
      data: {
        labels,
        datasets: [{
          label: 'Presupuesto Final (USD)',
          data: budgets,
          backgroundColor: 'rgba(52, 152, 219, 0.7)',
          borderColor: 'rgba(52, 152, 219, 1)',
          borderWidth: 2,
          borderRadius: 6,
          hoverBackgroundColor: 'rgba(52, 152, 219, 0.9)'
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            display: false
          },
          tooltip: {
            callbacks: {
              title: (context) => {
                const idx = context[0].dataIndex;
                return this.formatDateFull(data[idx].date);
              },
              label: (context) => {
                const idx = context.dataIndex;
                const item = data[idx];
                return [
                  `Presupuesto Final: $${item.final_budget.toFixed(2)}`,
                  ``,
                  `📈 Aumentos: ${item.positive_rules_count} reglas`,
                  `📉 Disminuciones: ${item.negative_rules_count} reglas`,
                  `⏸️ Pausas: ${item.pause_count}`,
                  ``,
                  `Total de cambios: ${item.total_changes}`
                ];
              }
            },
            backgroundColor: 'rgba(0, 0, 0, 0.8)',
            padding: 14,
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
            beginAtZero: false,
            ticks: {
              callback: (value) => '$' + value.toFixed(2),
              font: {
                size: 12
              }
            },
            title: {
              display: true,
              text: 'Presupuesto Final (USD)',
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
  }

  // Obtener label de acción
  static getActionLabel(actionType) {
    const labels = {
      'increase_budget': 'Aumento de Presupuesto',
      'decrease_budget': 'Disminución de Presupuesto',
      'pause': 'Pausa de Activo'
    };
    return labels[actionType] || actionType;
  }

  // Formatear tiempo (solo hora)
  static formatTime(datetime) {
    const date = new Date(datetime);
    return date.toLocaleTimeString('es-ES', { 
      hour: '2-digit', 
      minute: '2-digit' 
    });
  }

  // Formatear fecha corta (DD MMM)
  static formatDate(dateString) {
    const date = new Date(dateString + 'T00:00:00');
    return date.toLocaleDateString('es-ES', { 
      day: '2-digit', 
      month: 'short' 
    });
  }

  // Formatear fecha completa
  static formatDateFull(dateString) {
    const date = new Date(dateString + 'T00:00:00');
    return date.toLocaleDateString('es-ES', { 
      day: '2-digit', 
      month: 'long',
      year: 'numeric'
    });
  }

  // Formatear fecha y hora completa
  static formatDateTime(datetime) {
    const date = new Date(datetime);
    return date.toLocaleString('es-ES', {
      day: '2-digit',
      month: 'short',
      hour: '2-digit',
      minute: '2-digit'
    });
  }

  // Renderizar timeline de movimientos
  static renderTimeline(data) {
    const container = document.getElementById('timeline-movements-container');
    if (!container) return;

    if (data.length === 0) {
      container.innerHTML = '<p class="og-text-center og-text-gray-500 og-p-3">No hay movimientos registrados</p>';
      return;
    }

    let html = '<div class="timeline-list">';

    data.forEach((item, index) => {
      const isManual = item.execution_source === 'manual';
      const isIncrease = item.action_type === 'increase_budget';
      const isDecrease = item.action_type === 'decrease_budget';
      const isPause = item.action_type === 'pause';
      
      // Determinar clase de color
      let colorClass = 'neutral';
      if (isIncrease) colorClass = 'increase';
      else if (isDecrease) colorClass = 'decrease';
      else if (isPause) colorClass = 'pause';

      // Icono según tipo
      const icon = isIncrease ? '📈' : isDecrease ? '📉' : isPause ? '⏸️' : '⚙️';

      // Cambio de presupuesto
      const budgetBefore = parseFloat(item.budget_before || 0);
      const budgetAfter = parseFloat(item.budget_after || 0);
      const budgetChange = parseFloat(item.budget_change || 0);

      // Debug: Log para ver qué está llegando
      if (index === 0) {
        ogLogger.debug('ext:automation', 'Timeline item sample:', {
          execution_source: item.execution_source,
          has_conditions_result: !!item.conditions_result,
          conditions_result_type: typeof item.conditions_result,
          conditions_result: item.conditions_result,
          has_metrics: !!item.metrics_snapshot
        });
      }

      // Parsear métricas
      const metrics = item.metrics_snapshot ? 
        (typeof item.metrics_snapshot === 'string' ? JSON.parse(item.metrics_snapshot) : item.metrics_snapshot) 
        : {};

      html += `
        <div class="timeline-item ${colorClass}">
          <div class="timeline-grid">
            <!-- COLUMNA 1: DATOS -->
            <div class="timeline-col-data">
              <div class="data-row">
                <span class="data-icon">${icon}</span>
                <span class="data-time">${this.formatDateTime(item.executed_at)}</span>
              </div>
              
              <div class="data-row">
                <span class="data-label">Acción:</span>
                <span class="data-value">${this.getActionLabel(item.action_type)}</span>
                ${isManual ? '<span class="badge-manual">MANUAL</span>' : '<span class="badge-auto">AUTO</span>'}
              </div>
              
              <div class="data-row">
                <span class="data-label">Cambio:</span>
                <span class="data-value data-change ${colorClass}">${budgetChange >= 0 ? '+' : ''}$${budgetChange.toFixed(2)}</span>
              </div>
              
              <div class="data-row">
                <span class="data-label">Presupuesto:</span>
                <span class="data-value">$${budgetBefore.toFixed(2)} → $${budgetAfter.toFixed(2)}</span>
              </div>
              
              ${this.renderMetricsCompact(metrics)}
              
              ${item.rule_name ? `
                <div class="data-row">
                  <span class="data-label">Regla:</span>
                  <span class="data-value">${item.rule_name}</span>
                </div>
              ` : ''}
            </div>
            
            <!-- COLUMNA 2: CONDICIONES -->
            <div class="timeline-col-conditions">
              ${(() => {
                // Validar que no sea manual y que tenga conditions_result
                if (isManual) {
                  return '<div class="no-conditions">Sin condiciones automáticas (Manual)</div>';
                }
                
                if (!item.conditions_result) {
                  return '<div class="no-conditions">Sin condiciones automáticas</div>';
                }
                
                // Intentar parsear si es string
                let conditionsObj = item.conditions_result;
                if (typeof conditionsObj === 'string') {
                  try {
                    conditionsObj = JSON.parse(conditionsObj);
                  } catch (e) {
                    ogLogger.error('ext:automation', 'Error parsing conditions_result:', e);
                    return '<div class="no-conditions">Error parseando condiciones</div>';
                  }
                }
                
                // Validar estructura
                if (!conditionsObj || typeof conditionsObj !== 'object') {
                  return '<div class="no-conditions">Condiciones inválidas (no es objeto)</div>';
                }
                
                if (!conditionsObj.details) {
                  return '<div class="no-conditions">Sin detalles de condiciones</div>';
                }
                
                return scaleRuleStatsv2.renderConditions(item, metrics);
              })()}
            </div>
          </div>
        </div>
      `;
    });

    html += '</div>';
    container.innerHTML = html;
  }

  // Renderizar condiciones evaluadas
  static renderConditions(item, metrics) {
    // Esta función asume que ya se validó que item.conditions_result existe y tiene details
    try {
      const conditions = typeof item.conditions_result === 'string' 
        ? JSON.parse(item.conditions_result) 
        : item.conditions_result;

      if (!conditions || !conditions.details) {
        ogLogger.warn('ext:automation', 'renderConditions llamado sin details válido');
        return '<div class="no-conditions">Sin detalles de condiciones</div>';
      }

      const details = conditions.details;

      let html = '<div class="conditions-wrapper">';
      html += '<div class="conditions-header">🎯 Condiciones</div>';

      // Mostrar cada grupo de condiciones
      if (Array.isArray(details)) {
        details.forEach((group, idx) => {
          // Determinar si el grupo se cumplió
          const groupMet = group.result === true;
          const groupIcon = groupMet ? '✅' : '❌';
          
          html += `
            <div class="condition-group ${groupMet ? 'met' : 'not-met'}">
              <div class="group-title">
                <span class="group-icon">${groupIcon}</span>
                <span>Grupo ${idx + 1}</span>
              </div>
              ${this.renderGroupConditions(group, metrics)}
            </div>
          `;
        });
      }

      html += '</div>';
      return html;

    } catch (error) {
      ogLogger.error('ext:automation', 'Error parseando condiciones:', error);
      return `<div class="error-conditions">Error: ${error.message}</div>`;
    }
  }

  // Renderizar condiciones de un grupo
  static renderGroupConditions(group, metrics) {
    if (!group || !group.details) return '';

    let html = '<div class="conditions-list">';

    // Si details es un array de condiciones
    if (Array.isArray(group.details)) {
      group.details.forEach(cond => {
        if (cond.details && cond.details.operator) {
          const d = cond.details;
          const metricKey = this.extractMetricName(cond.condition);
          const metricName = this.getMetricLabel(metricKey);
          const operator = this.getOperatorSymbol(d.operator);
          
          // Obtener valor actual desde metrics_snapshot
          const currentValue = metrics && metrics[metricKey] !== undefined ? metrics[metricKey] : d.left;
          
          // Extraer threshold desde la condición original
          let threshold = d.right;
          if (threshold === undefined || threshold === null) {
            // Intentar extraer desde cond.condition
            threshold = this.extractThreshold(cond.condition, d.operator);
          }
          
          // Formatear valores según el tipo de métrica
          const formattedCurrent = this.formatMetricValue(metricKey, currentValue);
          const formattedThreshold = this.formatMetricValue(metricKey, threshold);
          
          html += `
            <div class="condition-item ${cond.result ? 'met' : 'not-met'}">
              <span class="cond-icon">${cond.result ? '✓' : '✗'}</span>
              <span class="cond-text">${metricName}: ${formattedCurrent} ${operator} ${formattedThreshold}</span>
            </div>
          `;
        }
      });
    }

    html += '</div>';
    return html;
  }

  // Extraer nombre de métrica desde la condición
  static extractMetricName(condition) {
    if (!condition) return 'metric';
    
    // Intentar acceder directamente al objeto
    if (typeof condition === 'object') {
      // Buscar en operadores
      const operators = ['>=', '<=', '>', '<', '==', '!=', '===', '!=='];
      for (let op of operators) {
        if (condition[op] && Array.isArray(condition[op])) {
          // La variable está en el primer elemento del array
          const leftSide = condition[op][0];
          if (leftSide && leftSide.var) {
            return leftSide.var;
          }
        }
      }
      
      // Si tiene var directamente
      if (condition.var) {
        return condition.var;
      }
    }
    
    // Fallback: usar regex en el string JSON
    const condStr = JSON.stringify(condition);
    const varMatch = condStr.match(/"var"\s*:\s*"([^"]+)"/);
    return varMatch ? varMatch[1] : 'metric';
  }

  // Extraer threshold (valor de comparación) desde la condición
  static extractThreshold(condition, operator) {
    if (!condition) return null;
    
    // Intentar acceder directamente al objeto
    if (typeof condition === 'object') {
      // Si el operador está definido, buscar en ese operador específico
      if (operator && condition[operator] && Array.isArray(condition[operator])) {
        // El threshold está en el segundo elemento del array
        return condition[operator][1];
      }
      
      // Si no, buscar en cualquier operador
      const operators = ['>=', '<=', '>', '<', '==', '!=', '===', '!=='];
      for (let op of operators) {
        if (condition[op] && Array.isArray(condition[op]) && condition[op].length > 1) {
          // El threshold está en el segundo elemento del array
          return condition[op][1];
        }
      }
    }
    
    return null;
  }

  // Obtener símbolo del operador
  static getOperatorSymbol(operator) {
    const symbols = {
      '>=': '≥',
      '<=': '≤',
      '>': '>',
      '<': '<',
      '==': '=',
      '!=': '≠',
      '===': '=',
      '!==': '≠'
    };
    return symbols[operator] || operator;
  }

  // Obtener label de métrica
  static getMetricLabel(metric) {
    const labels = {
      'roas': 'ROAS',
      'cost_per_result': 'Costo/Resultado',
      'frequency': 'Frecuencia',
      'spend': 'Gasto',
      'results': 'Resultados',
      'impressions': 'Impresiones',
      'reach': 'Alcance',
      'ctr': 'CTR',
      'cpc': 'CPC',
      'cpm': 'CPM',
      'clicks': 'Clicks'
    };
    return labels[metric] || metric;
  }

  // Renderizar métricas compactas en columna de datos
  static renderMetricsCompact(metrics) {
    if (!metrics || Object.keys(metrics).length === 0) return '';

    let html = '';
    
    // Mostrar métricas principales
    const mainMetrics = ['roas', 'cost_per_result', 'results', 'spend', 'frequency', 'ctr'];
    mainMetrics.forEach(key => {
      if (metrics[key] !== undefined) {
        html += `
          <div class="data-row">
            <span class="data-label">${this.getMetricLabel(key)}:</span>
            <span class="data-value">${this.formatMetricValue(key, metrics[key])}</span>
          </div>
        `;
      }
    });

    return html;
  }

  // Formatear valor de métrica
  static formatMetricValue(metric, value) {
    if (value === null || value === undefined) return '-';

    // Métricas monetarias
    if (['spend', 'cost_per_result', 'cpc', 'cpm'].includes(metric)) {
      return '$' + parseFloat(value).toFixed(2);
    }

    // Porcentajes
    if (['ctr'].includes(metric)) {
      return parseFloat(value).toFixed(2) + '%';
    }

    // ROAS y frecuencia
    if (['roas', 'frequency'].includes(metric)) {
      return parseFloat(value).toFixed(2) + 'x';
    }

    // Enteros
    if (['results', 'impressions', 'reach', 'clicks'].includes(metric)) {
      return parseInt(value).toLocaleString();
    }

    return parseFloat(value).toFixed(2);
  }

  // Mostrar mensaje cuando no hay activo seleccionado
  static showNoFilters() {
    const container = document.getElementById('scale-stats-container-v2');
    if (!container) return;

    container.innerHTML = `
      <div class="og-text-center og-text-gray-500 og-p-4">
        <div class="og-mb-2" style="font-size: 2rem;">🎬</div>
        <div class="og-mb-1" style="font-weight: 500;">Selecciona un activo publicitario</div>
        <div style="font-size: 0.9rem;">Elige un activo para comenzar a ver las estadísticas de escalado.</div>
      </div>
    `;
  }

  // Mostrar error al cargar activos
  static showAssetError() {
    const selectAsset = document.getElementById('filter-asset-v2');
    if (selectAsset) {
      selectAsset.innerHTML = '<option value="">Error al cargar activos</option>';
    }
  }

  // Obtener label legible del tipo de activo
  static getAssetTypeLabel(type) {
    const labels = {
      'campaign': 'Campaña',
      'adset': 'Conjunto',
      'ad': 'Anuncio'
    };
    return labels[type] || type;
  }

  // Obtener label legible de la plataforma
  static getPlatformLabel(platform) {
    const labels = {
      'facebook': 'Facebook',
      'google': 'Google',
      'tiktok': 'TikTok'
    };
    return labels[platform] || platform;
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
window.scaleRuleStatsv2 = scaleRuleStatsv2;
