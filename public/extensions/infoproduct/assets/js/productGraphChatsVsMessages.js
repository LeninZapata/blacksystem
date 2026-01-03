// productGraphChatsVsMessages.js - Gráfica de Actividad de Mensajes
class ProductChartChatsVsMessages {
  static async load(range, containerId) {
    const container = document.getElementById(containerId);
    if (!container) return;

    container.innerHTML = '<div class="chart-loading"><div class="spinner"></div><p>Cargando...</p></div>';

    try {
      const response = await ogApi.get(`/api/chat/stats/messages-activity?range=${range}`);

      let html = `
        <div class="chart-card">
          <div class="chart-header">
            <h3 class="chart-title">💬 Actividad de Mensajes</h3>
          </div>`;

      if (!response.success || !response.data || response.data.length === 0) {
        html += this.getEmptyStats();
        container.innerHTML = html;
        return;
      }

      const data = response.data;
      const labels = data.map(item => productStats.formatDate(new Date(item.date)));
      const totalMessages = data.map(item => parseInt(item.total_messages));
      const newChats = data.map(item => parseInt(item.new_chats));
      const followups = data.map(item => parseInt(item.followups_scheduled));

      const total = totalMessages.reduce((a, b) => a + b, 0);
      const totalChats = newChats.reduce((a, b) => a + b, 0);
      const totalFollowups = followups.reduce((a, b) => a + b, 0);

      html += this.getStatsHTML(total, totalChats, totalFollowups, data.length);
      html += `
        <div class="chart-canvas-wrapper">
          <canvas id="canvas-${containerId}"></canvas>
        </div>
      </div>`;

      container.innerHTML = html;
      this.createChart(`canvas-${containerId}`, labels, totalMessages, newChats, followups);

    } catch (error) {
      ogLogger.error('ext:infoproduct', 'Error en actividad de mensajes:', error);
      container.innerHTML = this.getErrorHTML();
    }
  }

  static getEmptyStats() {
    return `
      <div class="chart-stats">
        <div class="stat-box">
          <div class="stat-value">0</div>
          <div class="stat-label">Total Mensajes</div>
        </div>
        <div class="stat-box">
          <div class="stat-value">0</div>
          <div class="stat-label">Chats Nuevos</div>
        </div>
        <div class="stat-box">
          <div class="stat-value">0</div>
          <div class="stat-label">Seguimientos</div>
        </div>
        <div class="stat-box">
          <div class="stat-value">0</div>
          <div class="stat-label">Promedio/Día</div>
        </div>
      </div>
      <div class="chart-canvas-wrapper">
        <div class="chart-empty">Sin datos para este período</div>
      </div>
    </div>`;
  }

  static getStatsHTML(total, totalChats, totalFollowups, daysCount) {
    return `
      <div class="chart-stats">
        <div class="stat-box">
          <div class="stat-value">${total}</div>
          <div class="stat-label">Total Mensajes</div>
        </div>
        <div class="stat-box">
          <div class="stat-value">${totalChats}</div>
          <div class="stat-label">Chats Nuevos</div>
        </div>
        <div class="stat-box">
          <div class="stat-value">${totalFollowups}</div>
          <div class="stat-label">Seguimientos</div>
        </div>
        <div class="stat-box">
          <div class="stat-value">${(total / daysCount).toFixed(1)}</div>
          <div class="stat-label">Promedio/Día</div>
        </div>
      </div>`;
  }

  static getErrorHTML() {
    return `
      <div class="chart-card">
        <div class="chart-header">
          <h3 class="chart-title">💬 Actividad de Mensajes</h3>
        </div>
        <div class="chart-error">❌ Error al cargar</div>
      </div>`;
  }

  static createChart(canvasId, labels, totalMessages, newChats, followups) {
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
            label: 'Total Mensajes',
            data: totalMessages,
            backgroundColor: 'rgba(52, 152, 219, 0.8)',
            borderColor: 'rgba(52, 152, 219, 1)',
            borderWidth: 1,
            order: 2
          },
          {
            type: 'line',
            label: 'Chats Nuevos',
            data: newChats,
            borderColor: 'rgba(46, 204, 113, 1)',
            backgroundColor: 'rgba(46, 204, 113, 0.1)',
            borderWidth: 2,
            tension: 0.4,
            fill: false,
            order: 1,
            pointRadius: 4,
            pointHoverRadius: 6
          },
          {
            type: 'line',
            label: 'Seguimientos',
            data: followups,
            borderColor: 'rgba(231, 76, 60, 1)',
            backgroundColor: 'rgba(231, 76, 60, 0.1)',
            borderWidth: 2,
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
              label: function(context) {
                const idx = context.dataIndex;
                let label = context.dataset.label || '';
                const value = context.parsed.y;

                if (context.datasetIndex === 0) {
                  return `${label}: ${value} mensajes`;
                } else if (context.datasetIndex === 1) {
                  const percent = totalMessages[idx] > 0 ? ((value / totalMessages[idx]) * 100).toFixed(1) : 0;
                  return `${label}: ${value} (${percent}%)`;
                } else {
                  const percent = totalMessages[idx] > 0 ? ((value / totalMessages[idx]) * 100).toFixed(1) : 0;
                  return `${label}: ${value} (${percent}%)`;
                }
              }
            }
          }
        },
        scales: {
          y: {
            beginAtZero: true,
            title: { display: true, text: 'Cantidad de Mensajes' },
            ticks: { precision: 0 }
          },
          x: {
            ticks: { maxRotation: 45, minRotation: 45 }
          }
        }
      }
    });
  }
}

// Registrar en window para que productStats.js lo detecte
window.ProductChartChatsVsMessages = ProductChartChatsVsMessages;