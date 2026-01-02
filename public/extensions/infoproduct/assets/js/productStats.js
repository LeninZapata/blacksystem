class productStats {
  static currentRange = 'last_7_days';
  static charts = {};

  static async init() {
    ogLogger.debug('ext:infoproduct', 'Inicializando estadísticas');
    await this.loadChartJS();
    await this.loadAllCharts();
  }

  static async loadChartJS() {
    if (window.Chart) return;
    
    return new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js';
      script.onload = () => {
        ogLogger.debug('ext:infoproduct', 'Chart.js cargado');
        resolve();
      };
      script.onerror = reject;
      document.head.appendChild(script);
    });
  }

  static async loadAllCharts() {
    // Cargar todas las gráficas
    Promise.all([
      this.loadNewClientsByDay(),
      this.loadChatsVsMessages()
      // Aquí se agregarán más gráficas
    ]).catch(error => {
      ogLogger.error('ext:infoproduct', 'Error cargando gráficos:', error);
    });
  }

  static async changeRange(range) {
    this.currentRange = range;
    await this.loadAllCharts();
  }

  // ==========================================
  // 1. CLIENTES NUEVOS POR DÍA
  // ==========================================
  
  static async loadNewClientsByDay() {
    const container = document.getElementById('chart-new-clients');
    if (!container) return;

    container.innerHTML = '<div class="chart-loading"><div class="spinner"></div><p>Cargando...</p></div>';

    try {
      const response = await ogApi.get(`/api/client/stats/new-by-day?range=${this.currentRange}`);
      
      if (!response.success || !response.data || response.data.length === 0) {
        container.innerHTML = '<div class="chart-empty">Sin datos para este período</div>';
        return;
      }

      const data = response.data;
      const labels = data.map(item => this.formatDate(new Date(item.date)));
      const values = data.map(item => parseInt(item.new_clients));
      const total = values.reduce((sum, val) => sum + val, 0);

      const html = `
        <div class="chart-card">
          <div class="chart-header">
            <h3 class="chart-title">👥 Clientes Nuevos por Día</h3>
          </div>
          <div class="chart-stats">
            <div class="stat-box">
              <div class="stat-value">${total}</div>
              <div class="stat-label">Total Clientes</div>
            </div>
            <div class="stat-box">
              <div class="stat-value">${(total / data.length).toFixed(1)}</div>
              <div class="stat-label">Promedio/Día</div>
            </div>
            <div class="stat-box">
              <div class="stat-value">${Math.max(...values)}</div>
              <div class="stat-label">Día Pico</div>
            </div>
          </div>
          <div class="chart-canvas-wrapper">
            <canvas id="canvas-new-clients"></canvas>
          </div>
        </div>
      `;

      container.innerHTML = html;
      this.createBarChart('canvas-new-clients', labels, values, 'Clientes Nuevos');

    } catch (error) {
      ogLogger.error('ext:infoproduct', 'Error en clientes nuevos:', error);
      container.innerHTML = '<div class="chart-error">❌ Error al cargar</div>';
    }
  }

  // ==========================================
  // 2. CHATS INICIADOS VS MENSAJES
  // ==========================================
  
  static async loadChatsVsMessages() {
    const container = document.getElementById('chart-chats-vs-messages');
    if (!container) return;

    container.innerHTML = '<div class="chart-loading"><div class="spinner"></div><p>Cargando...</p></div>';

    try {
      const response = await ogApi.get(`/api/chat/stats/chats-vs-messages?range=${this.currentRange}`);
      
      if (!response.success || !response.data || response.data.length === 0) {
        container.innerHTML = '<div class="chart-empty">Sin datos para este período</div>';
        return;
      }

      const data = response.data;
      const labels = data.map(item => this.formatDate(new Date(item.date)));
      const chatsInitiated = data.map(item => parseInt(item.chats_initiated));
      const totalMessages = data.map(item => parseInt(item.total_messages));

      const html = `
        <div class="chart-card">
          <div class="chart-header">
            <h3 class="chart-title">💬 Mensajes (Chats iniciados vs Mensajes)</h3>
          </div>
          <div class="chart-stats">
            <div class="stat-box">
              <div class="stat-value">${chatsInitiated.reduce((a, b) => a + b, 0)}</div>
              <div class="stat-label">Chats Iniciados</div>
            </div>
            <div class="stat-box">
              <div class="stat-value">${totalMessages.reduce((a, b) => a + b, 0)}</div>
              <div class="stat-label">Total Mensajes (P+B)</div>
            </div>
            <div class="stat-box">
              <div class="stat-value">${(totalMessages.reduce((a, b) => a + b, 0) / chatsInitiated.reduce((a, b) => a + b, 0)).toFixed(1)}</div>
              <div class="stat-label">Promedio Msg/Chat</div>
            </div>
          </div>
          <div class="chart-canvas-wrapper">
            <canvas id="canvas-chats-messages"></canvas>
          </div>
        </div>
      `;

      container.innerHTML = html;
      this.createDoubleBarChart('canvas-chats-messages', labels, chatsInitiated, totalMessages);

    } catch (error) {
      ogLogger.error('ext:infoproduct', 'Error en chats vs mensajes:', error);
      container.innerHTML = '<div class="chart-error">❌ Error al cargar</div>';
    }
  }

  // ==========================================
  // HELPERS PARA GRÁFICAS
  // ==========================================
  
  static createBarChart(canvasId, labels, data, label = 'Datos') {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return;

    if (this.charts[canvasId]) this.charts[canvasId].destroy();

    this.charts[canvasId] = new Chart(ctx, {
      type: 'bar',
      data: {
        labels,
        datasets: [{
          label,
          data,
          backgroundColor: 'rgba(52, 152, 219, 0.8)',
          borderColor: 'rgba(52, 152, 219, 1)',
          borderWidth: 1
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: true,
        aspectRatio: window.innerWidth < 768 ? 1 : 2,
        plugins: {
          legend: { display: false }
        },
        scales: {
          y: {
            beginAtZero: true,
            ticks: { precision: 0 }
          },
          x: {
            ticks: { maxRotation: 45, minRotation: 45 }
          }
        }
      }
    });
  }

  static createDoubleBarChart(canvasId, labels, data1, data2) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return;

    if (this.charts[canvasId]) this.charts[canvasId].destroy();

    this.charts[canvasId] = new Chart(ctx, {
      type: 'bar',
      data: {
        labels,
        datasets: [
          {
            label: 'Chats Iniciados',
            data: data1,
            backgroundColor: 'rgba(52, 152, 219, 0.8)',
            borderColor: 'rgba(52, 152, 219, 1)',
            borderWidth: 1
          },
          {
            label: 'Mensajes (P+B)',
            data: data2,
            backgroundColor: 'rgba(46, 204, 113, 0.8)',
            borderColor: 'rgba(46, 204, 113, 1)',
            borderWidth: 1
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: true,
        aspectRatio: window.innerWidth < 768 ? 1 : 2,
        plugins: {
          legend: { display: true, position: 'top' }
        },
        scales: {
          y: {
            beginAtZero: true,
            ticks: { precision: 0 }
          },
          x: {
            ticks: { maxRotation: 45, minRotation: 45 }
          }
        }
      }
    });
  }

  static formatDate(date) {
    const days = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
    const months = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
    return `${days[date.getDay()]} ${date.getDate()} ${months[date.getMonth()]}`;
  }
}

window.productStats = productStats;