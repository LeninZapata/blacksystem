// productGraphSalesDirectRemarketing.js - Gráfica de Ventas Directas vs Remarketing
class ProductChartSalesDirectRemarketing {
  static async load(range, containerId) {
    const container = document.getElementById(containerId);
    if (!container) return;

    container.innerHTML = '<div class="chart-loading"><div class="spinner"></div><p>Cargando...</p></div>';

    try {
      const response = await ogApi.get(`/api/sale/stats/direct-vs-remarketing?range=${range}`);
      
      let html = `
        <div class="chart-card">
          <div class="chart-header">
            <h3 class="chart-title">🎯 Ventas por Origen (Directas vs Remarketing)</h3>
          </div>`;

      if (!response.success || !response.data || response.data.length === 0) {
        html += this.getEmptyStats();
        container.innerHTML = html;
        return;
      }

      const data = response.data;
      const labels = data.map(item => productStats.formatDate(new Date(item.date)));
      const directRevenue = data.map(item => parseFloat(item.direct_revenue));
      const remarketingRevenue = data.map(item => parseFloat(item.remarketing_revenue));
      const directCount = data.map(item => parseInt(item.direct_count));
      const remarketingCount = data.map(item => parseInt(item.remarketing_count));
      const totalSales = data.map(item => parseInt(item.total_sales || 0));
      const conversionRate = data.map(item => parseFloat(item.conversion_rate || 0));

      const totalDirect = directRevenue.reduce((a, b) => a + b, 0);
      const totalRemarketing = remarketingRevenue.reduce((a, b) => a + b, 0);
      const totalDirectCount = directCount.reduce((a, b) => a + b, 0);
      const totalRemarketingCount = remarketingCount.reduce((a, b) => a + b, 0);
      const totalRevenue = totalDirect + totalRemarketing;
      const totalConfirmed = totalDirectCount + totalRemarketingCount;
      const totalProspects = totalSales.reduce((a, b) => a + b, 0);
      const avgConversion = totalProspects > 0 ? ((totalConfirmed / totalProspects) * 100) : 0;

      html += this.getStatsHTML(totalRevenue, totalDirect, totalDirectCount, 
                               totalRemarketing, totalRemarketingCount, 
                               avgConversion, totalConfirmed, totalProspects);
      html += `
        <div class="chart-canvas-wrapper">
          <canvas id="canvas-${containerId}"></canvas>
        </div>
      </div>`;

      container.innerHTML = html;
      this.createChart(`canvas-${containerId}`, labels, directRevenue, remarketingRevenue, 
                      directCount, remarketingCount, totalSales, conversionRate);

    } catch (error) {
      ogLogger.error('ext:infoproduct', 'Error en ventas directas vs remarketing:', error);
      container.innerHTML = this.getErrorHTML();
    }
  }

  static getEmptyStats() {
    return `
      <div class="chart-stats">
        <div class="stat-box">
          <div class="stat-value">$0.00</div>
          <div class="stat-label">Total Ingresos</div>
        </div>
        <div class="stat-box">
          <div class="stat-value">$0.00</div>
          <div class="stat-label">Ventas Directas (0) [0%]</div>
        </div>
        <div class="stat-box">
          <div class="stat-value">$0.00</div>
          <div class="stat-label">Remarketing (0) [0%]</div>
        </div>
        <div class="stat-box">
          <div class="stat-value">0%</div>
          <div class="stat-label">Conversión (0/0)</div>
        </div>
      </div>
      <div class="chart-canvas-wrapper">
        <div class="chart-empty">Sin datos para este período</div>
      </div>
    </div>`;
  }

  static getStatsHTML(totalRevenue, totalDirect, totalDirectCount, 
                     totalRemarketing, totalRemarketingCount, 
                     avgConversion, totalConfirmed, totalProspects) {
    return `
      <div class="chart-stats">
        <div class="stat-box">
          <div class="stat-value">$${totalRevenue.toFixed(2)}</div>
          <div class="stat-label">Total Ingresos</div>
        </div>
        <div class="stat-box">
          <div class="stat-value">$${totalDirect.toFixed(2)}</div>
          <div class="stat-label">Ventas Directas (${totalDirectCount}) [${((totalDirect/totalRevenue)*100).toFixed(1)}%]</div>
        </div>
        <div class="stat-box">
          <div class="stat-value">$${totalRemarketing.toFixed(2)}</div>
          <div class="stat-label">Remarketing (${totalRemarketingCount}) [${((totalRemarketing/totalRevenue)*100).toFixed(1)}%]</div>
        </div>
        <div class="stat-box">
          <div class="stat-value">${avgConversion.toFixed(1)}%</div>
          <div class="stat-label">Conversión (${totalConfirmed}/${totalProspects})</div>
        </div>
      </div>`;
  }

  static getErrorHTML() {
    return `
      <div class="chart-card">
        <div class="chart-header">
          <h3 class="chart-title">🎯 Ventas por Origen (Directas vs Remarketing)</h3>
        </div>
        <div class="chart-error">❌ Error al cargar</div>
      </div>`;
  }

  static createChart(canvasId, labels, directData, remarketingData, directCount, remarketingCount, totalSales, conversionRate) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return;

    if (productStats.charts[canvasId]) {
      productStats.charts[canvasId].destroy();
    }

    productStats.charts[canvasId] = new Chart(ctx, {
      type: 'bar',
      data: {
        labels,
        datasets: [
          {
            type: 'bar',
            label: 'Ventas Directas',
            data: directData,
            backgroundColor: 'rgba(52, 152, 219, 0.8)',
            borderColor: 'rgba(52, 152, 219, 1)',
            borderWidth: 1,
            stack: 'ventas',
            yAxisID: 'y',
            order: 2
          },
          {
            type: 'bar',
            label: 'Remarketing',
            data: remarketingData,
            backgroundColor: 'rgba(46, 204, 113, 0.8)',
            borderColor: 'rgba(46, 204, 113, 1)',
            borderWidth: 1,
            stack: 'ventas',
            yAxisID: 'y',
            order: 2
          },
          {
            type: 'line',
            label: 'Conversión (%)',
            data: conversionRate,
            borderColor: 'rgba(231, 76, 60, 1)',
            backgroundColor: 'rgba(231, 76, 60, 0.1)',
            borderWidth: 2,
            yAxisID: 'y1',
            tension: 0.4,
            fill: false,
            order: 1,
            pointRadius: 4,
            pointHoverRadius: 6
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
              title: function(tooltipItems) {
                return tooltipItems[0].label;
              },
              label: function(context) {
                const idx = context.dataIndex;
                const datasetIndex = context.datasetIndex;
                
                if (datasetIndex === 0) {
                  const value = directData[idx];
                  const count = directCount[idx];
                  const total = directData[idx] + remarketingData[idx];
                  const percent = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                  return `Directas: $${value.toFixed(2)} (${count} ventas) [${percent}%]`;
                } else if (datasetIndex === 1) {
                  const value = remarketingData[idx];
                  const count = remarketingCount[idx];
                  const total = directData[idx] + remarketingData[idx];
                  const percent = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                  return `Remarketing: $${value.toFixed(2)} (${count} ventas) [${percent}%]`;
                } else {
                  return `Conversión: ${conversionRate[idx].toFixed(1)}%`;
                }
              },
              afterBody: function(tooltipItems) {
                const idx = tooltipItems[0].dataIndex;
                const prospects = totalSales[idx];
                const confirmed = directCount[idx] + remarketingCount[idx];
                const total = directData[idx] + remarketingData[idx];
                const conversion = prospects > 0 ? ((confirmed / prospects) * 100).toFixed(1) : 0;
                return [
                  '',
                  `Total día: $${total.toFixed(2)}`,
                  `Prospectos: ${prospects}`,
                  `Confirmados: ${confirmed} (${conversion}%)`
                ];
              }
            }
          }
        },
        scales: {
          x: {
            stacked: true,
            ticks: { maxRotation: 45, minRotation: 45 }
          },
          y: {
            stacked: true,
            beginAtZero: true,
            position: 'left',
            title: { display: true, text: 'Ingresos ($)' },
            ticks: {
              callback: function(value) {
                return '$' + value.toFixed(0);
              }
            }
          },
          y1: {
            type: 'linear',
            position: 'right',
            beginAtZero: true,
            max: 100,
            title: { display: true, text: 'Conversión (%)' },
            grid: { drawOnChartArea: false },
            ticks: {
              callback: function(value) {
                return value + '%';
              }
            }
          }
        }
      }
    });
  }
}

// Registrar en window para que productStats.js lo detecte
window.ProductChartSalesDirectRemarketing = ProductChartSalesDirectRemarketing;