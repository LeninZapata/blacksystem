// scaleRuleStats.js - Gráfica de cambios de presupuesto
class scaleRuleStats {
  static currentAssetId = null;
  static currentRange = 'today';
  static chart = null;
  static assetsCache = [];

  static async init() {
    ogLogger.debug('ext:automation', 'Inicializando estadísticas de escala');
    await this.loadChartJS();
    await this.loadAssets();
  }

  static async loadChartJS() {
    if (window.Chart) return;
    return new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js';
      script.onload = () => resolve();
      script.onerror = reject;
      document.head.appendChild(script);
    });
  }

  static async loadAssets() {
    try {
      const res = await ogApi.get('/api/productAdAsset?per_page=1000&is_active=1');
      const assets = res.success ? res.data : [];
      
      this.assetsCache = assets;
      
      const selector = document.getElementById('asset-selector');
      if (!selector) return;

      selector.innerHTML = '<option value="">Selecciona un activo...</option>';
      assets.forEach(asset => {
        const option = document.createElement('option');
        option.value = asset.id;
        option.textContent = `${asset.ad_asset_name} (${asset.ad_platform})`;
        option.dataset.adAssetId = asset.ad_asset_id;
        option.dataset.adAssetType = asset.ad_asset_type;  // ← AGREGADO
        option.dataset.platform = asset.ad_platform;
        selector.appendChild(option);
      });

      if (assets.length > 0) {
        this.currentAssetId = assets[0].id;
        selector.value = this.currentAssetId;
        await this.loadData();
      }
    } catch (error) {
      ogLogger.error('ext:automation', 'Error cargando activos:', error);
    }
  }

  static async changeAsset(assetId) {
    this.currentAssetId = assetId;
    if (assetId) {
      await this.loadData();
    } else {
      this.clearChart();
      this.clearTimeline();
    }
  }

  static async changeRange(range) {
    this.currentRange = range;
    if (this.currentAssetId) {
      await this.loadData();
    }
  }

  static async loadData() {
    if (!this.currentAssetId) return;

    const container = document.getElementById('chart-budget-changes');
    if (!container) return;

    container.innerHTML = '<div class="chart-loading"><div class="spinner"></div><p>Cargando...</p></div>';

    try {
      const res = await ogApi.get(`/api/adAutoScale/stats/budget-changes?asset_id=${this.currentAssetId}&range=${this.currentRange}`);

      if (!res.success || !res.data || res.data.length === 0) {
        container.innerHTML = this.getEmptyHTML();
        this.clearTimeline();
        return;
      }

      this.renderChart(res.data);
      this.renderTimeline(res.data);

    } catch (error) {
      ogLogger.error('ext:automation', 'Error cargando datos:', error);
      container.innerHTML = this.getErrorHTML();
    }
  }

  static renderChart(data) {
    const container = document.getElementById('chart-budget-changes');
    
    let html = `
      <div class="chart-card">
        <div class="chart-header">
          <h3 class="chart-title">💰 Cambios de Presupuesto</h3>
        </div>
        <div class="chart-stats">
          <div class="stat-box">
            <div class="stat-value">${data.length}</div>
            <div class="stat-label">Cambios Totales</div>
          </div>
          <div class="stat-box">
            <div class="stat-value">$${data[0]?.budget_before || 0}</div>
            <div class="stat-label">Presupuesto Inicial</div>
          </div>
          <div class="stat-box">
            <div class="stat-value">$${data[data.length - 1]?.budget_after || 0}</div>
            <div class="stat-label">Presupuesto Actual</div>
          </div>
          <div class="stat-box">
            <div class="stat-value">${this.calculateTotalIncrease(data)}</div>
            <div class="stat-label">Cambio Total</div>
          </div>
        </div>
        <div class="chart-canvas-wrapper">
          <canvas id="canvas-budget-chart"></canvas>
        </div>
      </div>`;

    container.innerHTML = html;

    const labels = data.map(item => this.formatTime(item.executed_at));
    const budgets = data.map(item => parseFloat(item.budget_after));
    
    this.createChart('canvas-budget-chart', labels, budgets, data);
  }

  static createChart(canvasId, labels, budgets, rawData) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return;

    if (this.chart) {
      this.chart.destroy();
    }

    this.chart = new Chart(ctx, {
      type: 'line',
      data: {
        labels,
        datasets: [{
          label: 'Presupuesto ($)',
          data: budgets,
          borderColor: 'rgba(52, 152, 219, 1)',
          backgroundColor: 'rgba(52, 152, 219, 0.1)',
          borderWidth: 3,
          tension: 0.1,
          fill: true,
          pointRadius: 6,
          pointHoverRadius: 8,
          pointBackgroundColor: rawData.map(item => 
            item.action_type === 'increase_budget' ? '#27ae60' : 
            item.action_type === 'decrease_budget' ? '#e74c3c' : '#95a5a6'
          ),
          pointBorderColor: '#fff',
          pointBorderWidth: 2
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: true,
        aspectRatio: 2.5,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              title: (context) => {
                const idx = context[0].dataIndex;
                return this.formatDateTime(rawData[idx].executed_at);
              },
              label: (context) => {
                const idx = context.dataIndex;
                const item = rawData[idx];
                return [
                  `Presupuesto: $${item.budget_after}`,
                  `Cambio: $${item.budget_change} (${item.action_type})`,
                  `Anterior: $${item.budget_before}`
                ];
              }
            }
          }
        },
        scales: {
          y: {
            beginAtZero: false,
            ticks: {
              callback: (value) => '$' + value.toFixed(2)
            },
            title: {
              display: true,
              text: 'Presupuesto (USD)'
            }
          },
          x: {
            ticks: {
              maxRotation: 45,
              minRotation: 45
            }
          }
        }
      }
    });
  }

  static renderTimeline(data) {
    const container = document.getElementById('timeline-budget-changes');
    if (!container) return;

    let html = `
      <div class="timeline-card">
        <div class="timeline-header">
          <h3>📅 Línea de Tiempo</h3>
        </div>
        <div class="timeline-list">`;

    data.forEach((item, index) => {
      const isIncrease = item.action_type === 'increase_budget';
      const isDecrease = item.action_type === 'decrease_budget';
      const isPause = item.action_type === 'pause';
      const isManual = item.execution_source === 'manual';

      const icon = isIncrease ? '📈' : isDecrease ? '📉' : '⏸️';
      const colorClass = isIncrease ? 'increase' : isDecrease ? 'decrease' : 'pause';
      const changeText = isIncrease || isDecrease ? `$${item.budget_before} → $${item.budget_after}` : 'Pausado';

      // Parsear conditions_result para mostrar qué grupo cumplió
      let conditionsHTML = '';
      if (item.conditions_result) {
        try {
          const conditionsResult = typeof item.conditions_result === 'string'
            ? JSON.parse(item.conditions_result)
            : item.conditions_result;

          if (Array.isArray(conditionsResult) && conditionsResult.length > 0) {
            conditionsHTML = this.formatConditionsResult(conditionsResult);
          }
        } catch (e) {
          ogLogger.debug('ext:automation', 'Error parseando conditions_result:', e);
        }
      }

      html += `
        <div class="timeline-item ${colorClass}">
          <div class="timeline-icon">${icon}</div>
          <div class="timeline-content">
            <div class="timeline-time">${this.formatDateTime(item.executed_at)}</div>
            <div class="timeline-action">
              ${this.getActionLabel(item.action_type)}
              ${isManual ? '<span class="badge-manual">✋ MANUAL</span>' : ''}
            </div>
            <div class="timeline-change">${changeText}</div>
            ${item.rule_name && !isManual ? `<div class="timeline-rule">Regla: ${item.rule_name}</div>` : ''}
            ${conditionsHTML}
          </div>
          <div class="timeline-badge ${colorClass}">${item.budget_change >= 0 ? '+' : ''}$${item.budget_change}</div>
        </div>`;
    });

    html += `</div></div>`;
    container.innerHTML = html;
  }

  static calculateTotalIncrease(data) {
    if (data.length === 0) return '$0.00';
    const first = parseFloat(data[0].budget_before);
    const last = parseFloat(data[data.length - 1].budget_after);
    const change = last - first;
    return (change >= 0 ? '+' : '') + '$' + change.toFixed(2);
  }

  static getActionLabel(actionType) {
    const labels = {
      'increase_budget': 'Aumento de Presupuesto',
      'decrease_budget': 'Disminución de Presupuesto',
      'pause': 'Pausa de Activo'
    };
    return labels[actionType] || actionType;
  }

  static formatTime(datetime) {
    const date = new Date(datetime);
    return date.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' });
  }

  static formatDateTime(datetime) {
    const date = new Date(datetime);
    return date.toLocaleString('es-ES', {
      day: '2-digit',
      month: 'short',
      hour: '2-digit',
      minute: '2-digit'
    });
  }

  static getEmptyHTML() {
    return `
      <div class="chart-card">
        <div class="chart-header">
          <h3 class="chart-title">💰 Cambios de Presupuesto</h3>
        </div>
        <div class="chart-empty">
          Sin datos de cambios de presupuesto para este período
        </div>
      </div>`;
  }

  static getErrorHTML() {
    return `
      <div class="chart-card">
        <div class="chart-header">
          <h3 class="chart-title">💰 Cambios de Presupuesto</h3>
        </div>
        <div class="chart-error">
          ❌ Error al cargar datos
        </div>
      </div>`;
  }

  static clearChart() {
    const container = document.getElementById('chart-budget-changes');
    if (container) container.innerHTML = this.getEmptyHTML();
    if (this.chart) {
      this.chart.destroy();
      this.chart = null;
    }
  }

  static clearTimeline() {
    const container = document.getElementById('timeline-budget-changes');
    if (container) container.innerHTML = '';
  }

  static openBudgetAdjustModal() {
    if (!this.currentAssetId) {
      ogComponent('toast').warning('Debes seleccionar una cuenta publicitaria primero');
      return;
    }

    const selectElement = document.getElementById('asset-selector');
    if (!selectElement) {
      ogComponent('toast').error('Error: No se encontró el selector de activos');
      return;
    }

    const selectedOption = selectElement.options[selectElement.selectedIndex];
    if (!selectedOption || !selectedOption.value) {
      ogComponent('toast').error('Error: No hay activo seleccionado');
      return;
    }

    const adAssetId = selectedOption.dataset.adAssetId;
    const adAssetType = selectedOption.dataset.adAssetType;  // ← CAPTURAR

    if (!adAssetId) {
      ogComponent('toast').error('Error: El activo no tiene ID válido');
      ogLogger.error('ext:automation', 'dataset.adAssetId está vacío', selectedOption);
      return;
    }

    if (!adAssetType) {
      ogComponent('toast').error('Error: El activo no tiene tipo válido');
      ogLogger.error('ext:automation', 'dataset.adAssetType está vacío', selectedOption);
      return;
    }

    budgetAdjust.open(adAssetId, adAssetType, this.currentAssetId); 
  }

  static getAssetData(assetId) {
    const selectElement = document.getElementById('asset-selector');
    if (!selectElement) {
      ogLogger.error('ext:automation', 'No se encontró el select #asset-selector');
      return null;
    }

    const selectedOption = selectElement.options[selectElement.selectedIndex];
    if (!selectedOption || !selectedOption.value) {
      ogLogger.error('ext:automation', 'No hay opción seleccionada');
      return null;
    }

    const assetData = {
      id: assetId,
      ad_asset_id: selectedOption.dataset.adAssetId,
      ad_asset_type: selectedOption.dataset.adAssetType || 'adset',
      ad_platform: selectedOption.dataset.platform || 'facebook'
    };

    ogLogger.debug('ext:automation', 'Asset data extraído:', assetData);

    return assetData;
  }

    
  static formatConditionsResult(groupsMet) {
    if (!Array.isArray(groupsMet) || groupsMet.length === 0) return '';

    let html = '<div class="conditions-details">';
    html += '<div class="conditions-header">✅ Condiciones cumplidas:</div>';

    groupsMet.forEach((group, idx) => {
      const groupNum = group.group_index || (idx + 1);
      const logic = group.logic || 'AND';

      html += `<div class="condition-group">`;
      html += `<div class="group-label">Grupo ${groupNum} (${logic}):</div>`;

      if (group.metrics_evaluated) {
        html += '<div class="metrics-list">';

        for (const [metricName, metricData] of Object.entries(group.metrics_evaluated)) {
          const metricLabel = this.getMetricLabel(metricName);
          const icon = metricData.met ? '✓' : '✗';
          const metClass = metricData.met ? 'met' : 'not-met';

          html += `
            <div class="metric-item ${metClass}">
              <span class="metric-icon">${icon}</span>
              <span class="metric-name">${metricLabel}:</span>
              <span class="metric-value">${this.formatMetricValue(metricName, metricData.value)}</span>
              <span class="metric-operator">${metricData.operator}</span>
              <span class="metric-threshold">${this.formatMetricValue(metricName, metricData.threshold)}</span>
            </div>`;
        }

        html += '</div>';
      }

      html += '</div>';
    });

    html += '</div>';
    return html;
  }

    
  /**
   * Obtiene label legible de métrica
   */
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

  /**
   * Formatea valor de métrica según su tipo
   */
  static formatMetricValue(metric, value) {
    if (value === null || value === undefined) return '--';

    // Métricas monetarias
    if (['spend', 'cost_per_result', 'cpc', 'cpm'].includes(metric)) {
      return '$' + parseFloat(value).toFixed(2);
    }

    // Porcentajes
    if (['ctr'].includes(metric)) {
      return parseFloat(value).toFixed(2) + '%';
    }

    // ROAS y frecuencia (multiplicadores)
    if (['roas', 'frequency'].includes(metric)) {
      return parseFloat(value).toFixed(2) + 'x';
    }

    // Enteros (results, impressions, reach, clicks)
    if (['results', 'impressions', 'reach', 'clicks'].includes(metric)) {
      return parseInt(value).toLocaleString();
    }

    // Default: 2 decimales
    return parseFloat(value).toFixed(2);
  }

}

window.scaleRuleStats = scaleRuleStats;