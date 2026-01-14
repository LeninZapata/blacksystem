// productGraphNewClients.js - Gráfica de Prospectos Nuevos por Día
class ProductChartNewClients {
  static async load(range, containerId) {
    const container = document.getElementById(containerId);
    if (!container) return;

    container.innerHTML = '<div class="chart-loading"><div class="spinner"></div><p>Cargando...</p></div>';

    try {
      const response = await ogApi.get(`/api/client/stats/new-by-day?range=${range}`);
      
      // Siempre mostrar la estructura completa
      let html = `
        <div class="chart-card">
          <div class="chart-header">
            <h3 class="chart-title">👥 Prospectos Nuevos por Día (con Conversión)</h3>
          </div>`;

      if (!response.success || !response.data || response.data.length === 0) {
        html += this.getEmptyStats();
        container.innerHTML = html;
        return;
      }

      const data = response.data;
      const labels = data.map(item => productStats.formatDate(new Date(item.date)));
      const newClients = data.map(item => parseInt(item.new_clients));
      const convertedClients = data.map(item => parseInt(item.converted_clients));
      const notConverted = newClients.map((total, idx) => total - convertedClients[idx]);

      const totalClients = newClients.reduce((sum, val) => sum + val, 0);
      const totalConverted = convertedClients.reduce((sum, val) => sum + val, 0);
      const conversionRate = totalClients > 0 ? ((totalConverted / totalClients) * 100) : 0;

      html += this.getStatsHTML(totalClients, totalConverted, conversionRate);
      html += `
        <div class="chart-canvas-wrapper">
          <canvas id="canvas-${containerId}"></canvas>
        </div>
      </div>`;

      container.innerHTML = html;
      this.createChart(`canvas-${containerId}`, labels, notConverted, convertedClients);

    } catch (error) {
      ogLogger.error('ext:infoproduct', 'Error en prospectos nuevos:', error);
      container.innerHTML = this.getErrorHTML();
    }
  }

  static getEmptyStats() {
    return `
      <div class="chart-stats">
        <div class="stat-box">
          <div class="stat-value">0</div>
          <div class="stat-label">Total Prospectos</div>
        </div>
        <div class="stat-box">
          <div class="stat-value">0</div>
          <div class="stat-label">Convirtieron</div>
        </div>
        <div class="stat-box">
          <div class="stat-value">0</div>
          <div class="stat-label">No Convirtieron</div>
        </div>
        <div class="stat-box">
          <div class="stat-value">0%</div>
          <div class="stat-label">% Conversión</div>
        </div>
      </div>
      <div class="chart-canvas-wrapper">
        <div class="chart-empty">Sin datos para este período</div>
      </div>
    </div>`;
  }

  static getStatsHTML(totalClients, totalConverted, conversionRate) {
    return `
      <div class="chart-stats">
        <div class="stat-box">
          <div class="stat-value">${totalClients}</div>
          <div class="stat-label">Total Prospectos</div>
        </div>
        <div class="stat-box">
          <div class="stat-value">${totalConverted}</div>
          <div class="stat-label">Convirtieron</div>
        </div>
        <div class="stat-box">
          <div class="stat-value">${totalClients - totalConverted}</div>
          <div class="stat-label">No Convirtieron</div>
        </div>
        <div class="stat-box">
          <div class="stat-value">${conversionRate.toFixed(1)}%</div>
          <div class="stat-label">% Conversión</div>
        </div>
      </div>`;
  }

  static getErrorHTML() {
    return `
      <div class="chart-card">
        <div class="chart-header">
          <h3 class="chart-title">👥 Prospectos Nuevos por Día (con Conversión)</h3>
        </div>
        <div class="chart-error">❌ Error al cargar</div>
      </div>`;
  }

  static createChart(canvasId, labels, notConverted, converted) {
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
            label: 'No Convirtieron',
            data: notConverted,
            backgroundColor: 'rgba(149, 165, 166, 0.8)',
            borderColor: 'rgba(149, 165, 166, 1)',
            borderWidth: 1,
            stack: 'prospectos'
          },
          {
            label: 'Convirtieron',
            data: converted,
            backgroundColor: 'rgba(46, 204, 113, 0.8)',
            borderColor: 'rgba(46, 204, 113, 1)',
            borderWidth: 1,
            stack: 'prospectos'
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
                const idx = context.dataIndex;
                const value = context.parsed.y;
                const total = notConverted[idx] + converted[idx];
                const percent = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                return `${context.dataset.label}: ${value} (${percent}%)`;
              },
              footer: function(tooltipItems) {
                const idx = tooltipItems[0].dataIndex;
                const total = notConverted[idx] + converted[idx];
                const conv = converted[idx];
                const convRate = total > 0 ? ((conv / total) * 100).toFixed(1) : 0;
                return [
                  `Total: ${total} prospectos`,
                  `Conversión: ${convRate}%`
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
            title: { display: true, text: 'Prospectos' },
            ticks: { precision: 0 }
          }
        }
      }
    });
  }
}

// Registrar en window para que productStats.js lo detecte
window.ProductChartNewClients = ProductChartNewClients;