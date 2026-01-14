// productGraphAdSpend.js - Gráfica de Gastos Publicitarios
class ProductChartAdSpend {
  static async load(range, containerId) {
    const container = document.getElementById(containerId);
    if (!container) return;

    container.innerHTML = '<div class="chart-loading"><div class="spinner"></div><p>Cargando...</p></div>';

    try {
      const response = await ogApi.get(`/api/adMetrics/spend-by-day?range=${range}`);

      let html = `
        <div class="chart-card">
          <div class="chart-header">
            <h3 class="chart-title">💸 Gastos Publicitarios por Día</h3>
          </div>`;

      if (!response.success || !response.data || response.data.length === 0) {
        html += this.getEmptyStats();
        container.innerHTML = html;
        return;
      }

      const data = response.data;
      const totals = response.totals || {};
      const labels = data.map(item => productStats.formatDate(new Date(item.date)));
      const spend = data.map(item => parseFloat(item.spend));
      const impressions = data.map(item => parseInt(item.impressions));
      const clicks = data.map(item => parseInt(item.clicks));
      const linkClicks = data.map(item => parseInt(item.link_clicks));
      const realPurchases = data.map(item => parseInt(item.real_purchases));
      const realPurchaseValue = data.map(item => parseFloat(item.real_purchase_value));

      const safeTotals = {
        spend: totals.spend || 0,
        real_purchases: totals.real_purchases || 0,
        real_purchase_value: totals.real_purchase_value || 0,
        roas: totals.roas || 0,
        link_clicks: totals.link_clicks || 0,
        results: totals.results || 0
      };

      html += this.getStatsHTML(safeTotals);
      html += `
        <div class="chart-canvas-wrapper">
          <canvas id="canvas-${containerId}"></canvas>
        </div>
      </div>`;

      container.innerHTML = html;
      this.createChart(`canvas-${containerId}`, labels, spend, impressions, clicks,
                      linkClicks, realPurchases, realPurchaseValue);

    } catch (error) {
      ogLogger.error('ext:infoproduct', 'Error en gastos publicitarios:', error);
      container.innerHTML = this.getErrorHTML();
    }
  }

  static getEmptyStats() {
    return `
      <div class="chart-stats">
        <div class="stat-box">
          <div class="stat-value">$0.00</div>
          <div class="stat-label">Gasto Total</div>
        </div>
        <div class="stat-box">
          <div class="stat-value">0</div>
          <div class="stat-label">Ventas</div>
        </div>
        <div class="stat-box">
          <div class="stat-value">0x</div>
          <div class="stat-label">ROAS (sin datos)</div>
        </div>
        <div class="stat-box">
          <div class="stat-value">0</div>
          <div class="stat-label">Click botón WhatsApp</div>
        </div>
        <div class="stat-box">
          <div class="stat-value">0</div>
          <div class="stat-label">Chat Iniciados</div>
        </div>
      </div>
      <div class="chart-canvas-wrapper">
        <div class="chart-empty">Sin datos de gastos publicitarios para este período</div>
      </div>
    </div>`;
  }

  static getStatsHTML(totals) {
    // Calcular pérdida/ganancia
    const profit = totals.real_purchase_value - totals.spend;
    const profitPercent = totals.spend > 0 ? (profit / totals.spend) * 100 : 0;
    const isProfit = profitPercent >= 0;
    const profitLabel = isProfit 
      ? `${Math.abs(profitPercent).toFixed(0)}% ganancia` 
      : `${Math.abs(profitPercent).toFixed(0)}% pérdida`;
    
    return `
      <div class="chart-stats">
        <div class="stat-box">
          <div class="stat-value">$${totals.spend.toFixed(2)}</div>
          <div class="stat-label">Gasto Total</div>
        </div>
        <div class="stat-box">
          <div class="stat-value">${totals.real_purchases}</div>
          <div class="stat-label">Ventas</div>
        </div>
        <div class="stat-box">
          <div class="stat-value">${totals.roas.toFixed(2)}x</div>
          <div class="stat-label">ROAS (${profitLabel})</div>
        </div>
        <div class="stat-box">
          <div class="stat-value">${totals.link_clicks.toLocaleString()}</div>
          <div class="stat-label">Click botón WhatsApp</div>
        </div>
        <div class="stat-box">
          <div class="stat-value">${totals.results.toLocaleString()}</div>
          <div class="stat-label">Chat iniciados</div>
        </div>
      </div>`;
  }

  static getErrorHTML() {
    return `
      <div class="chart-card">
        <div class="chart-header">
          <h3 class="chart-title">💸 Gastos Publicitarios por Día</h3>
        </div>
        <div class="chart-error">❌ Error al cargar gastos publicitarios</div>
      </div>`;
  }

  static createChart(canvasId, labels, spend, impressions, clicks, linkClicks, purchases, purchaseValue) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return;

    if (productStats.charts[canvasId]) {
      productStats.charts[canvasId].destroy();
    }

    // Si no hay datos, no crear el gráfico
    if (spend.length === 0 || spend.every(val => val === 0)) {
      return;
    }

    productStats.charts[canvasId] = new Chart(ctx, {
      type: 'bar',
      data: {
        labels,
        datasets: [
          {
            type: 'bar',
            label: 'Gasto ($)',
            data: spend,
            backgroundColor: 'rgba(231, 76, 60, 0.8)',
            borderColor: 'rgba(231, 76, 60, 1)',
            borderWidth: 1,
            yAxisID: 'y',
            order: 4
          },
          {
            type: 'line',
            label: 'Impresiones',
            data: impressions,
            borderColor: 'rgba(52, 152, 219, 1)',
            backgroundColor: 'rgba(52, 152, 219, 0.1)',
            borderWidth: 2,
            tension: 0.4,
            fill: false,
            yAxisID: 'y1',
            order: 3,
            pointRadius: 4,
            pointHoverRadius: 6
          },
          {
            type: 'line',
            label: 'Clicks',
            data: clicks,
            borderColor: 'rgba(155, 89, 182, 1)',
            backgroundColor: 'rgba(155, 89, 182, 0.1)',
            borderWidth: 2,
            tension: 0.4,
            fill: false,
            yAxisID: 'y1',
            order: 2,
            pointRadius: 4,
            pointHoverRadius: 6
          },
          {
            type: 'line',
            label: 'Clicks Enlace',
            data: linkClicks,
            borderColor: 'rgba(230, 126, 34, 1)',
            backgroundColor: 'rgba(230, 126, 34, 0.1)',
            borderWidth: 2,
            tension: 0.4,
            fill: false,
            yAxisID: 'y1',
            order: 2,
            pointRadius: 4,
            pointHoverRadius: 6,
            borderDash: [5, 5]
          },
          {
            type: 'line',
            label: 'Ventas',
            data: purchases,
            borderColor: 'rgba(46, 204, 113, 1)',
            backgroundColor: 'rgba(46, 204, 113, 0.1)',
            borderWidth: 2,
            tension: 0.4,
            fill: false,
            yAxisID: 'y2',
            order: 1,
            pointRadius: 4,
            pointHoverRadius: 6
          },
          {
            type: 'line',
            label: 'Ingresos ($)',
            data: purchaseValue,
            borderColor: 'rgba(241, 196, 15, 1)',
            backgroundColor: 'rgba(241, 196, 15, 0.1)',
            borderWidth: 2,
            tension: 0.4,
            fill: false,
            yAxisID: 'y',
            order: 1,
            pointRadius: 4,
            pointHoverRadius: 6,
            borderDash: [5, 5]
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: true,
        aspectRatio: window.innerWidth < 768 ? 1 : 2,
        interaction: {
          mode: 'index',
          intersect: false
        },
        plugins: {
          legend: { display: true, position: 'top' },
          tooltip: {
            callbacks: {
              label: function(context) {
                let label = context.dataset.label || '';
                const value = context.parsed.y;
                if (context.datasetIndex === 0) {
                  return `${label}: $${value.toFixed(2)}`;
                } else if (context.datasetIndex === 4) {
                  return `${label}: ${value} ventas`;
                } else if (context.datasetIndex === 5) {
                  return `${label}: $${value.toFixed(2)}`;
                } else {
                  return `${label}: ${value.toLocaleString()}`;
                }
              },
              footer: function(tooltipItems) {
                const idx = tooltipItems[0].dataIndex;
                const gastoDelDia = spend[idx];
                const ingresosDelDia = purchaseValue[idx];
                const linksDelDia = linkClicks[idx];
                const clicksDelDia = clicks[idx];
                const roas = gastoDelDia > 0 ? (ingresosDelDia / gastoDelDia).toFixed(2) : '0.00';
                const linkRate = clicksDelDia > 0 ? ((linksDelDia / clicksDelDia) * 100).toFixed(1) : '0.0';
                return [`ROAS: ${roas}x`, `% Clicks Enlace: ${linkRate}%`];
              }
            }
          }
        },
        scales: {
          x: { ticks: { maxRotation: 45, minRotation: 45 } },
          y: {
            type: 'linear',
            position: 'left',
            beginAtZero: true,
            title: { display: true, text: 'Gasto / Ingresos ($)' },
            ticks: { callback: function(value) { return '$' + value.toFixed(0); } }
          },
          y1: {
            type: 'linear',
            position: 'right',
            beginAtZero: true,
            title: { display: true, text: 'Impresiones / Clicks' },
            grid: { drawOnChartArea: false },
            ticks: { callback: function(value) { return value.toLocaleString(); } }
          },
          y2: {
            type: 'linear',
            position: 'right',
            beginAtZero: true,
            display: false,
            grid: { drawOnChartArea: false }
          }
        }
      }
    });
  }
}

// Registrar en window para que productStats.js lo detecte
window.ProductChartAdSpend = ProductChartAdSpend;