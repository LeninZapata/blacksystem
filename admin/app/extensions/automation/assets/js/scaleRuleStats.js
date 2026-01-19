// scaleRuleStats.js - Gráfica de cambios de presupuesto
class scaleRuleStats {
  static currentAssetId = null;
  static currentRange = 'today';
  static chart = null;

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
      
      const selector = document.getElementById('asset-selector');
      if (!selector) return;

      selector.innerHTML = '<option value="">Selecciona un activo...</option>';
      assets.forEach(asset => {
        const option = document.createElement('option');
        option.value = asset.id;
        option.textContent = `${asset.ad_asset_name} (${asset.ad_platform})`;
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

    // Crear gráfica
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
      
      const icon = isIncrease ? '📈' : isDecrease ? '📉' : '⏸️';
      const colorClass = isIncrease ? 'increase' : isDecrease ? 'decrease' : 'pause';
      const changeText = isIncrease || isDecrease ? `$${item.budget_before} → $${item.budget_after}` : 'Pausado';

      html += `
        <div class="timeline-item ${colorClass}">
          <div class="timeline-icon">${icon}</div>
          <div class="timeline-content">
            <div class="timeline-time">${this.formatDateTime(item.executed_at)}</div>
            <div class="timeline-action">${this.getActionLabel(item.action_type)}</div>
            <div class="timeline-change">${changeText}</div>
            ${item.rule_name ? `<div class="timeline-rule">Regla: ${item.rule_name}</div>` : ''}
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
}

window.scaleRuleStats = scaleRuleStats;