class productStats {
  static currentRange = 'last_7_days';
  static charts = {};

  static async init() {
    ogLogger.debug('ext:infoproduct', 'Inicializando estadísticas de productos');
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
    // Cargar todos los gráficos de forma asincrónica
    Promise.all([
      this.loadVentasPorDia(),
      this.loadVentasPorProducto(),
      this.loadVentasProductoPastel(),
      this.loadChatsPorProducto(),
      this.loadSeguimientos()
    ]).catch(error => {
      ogLogger.error('ext:infoproduct', 'Error cargando gráficos:', error);
    });
  }

  static async changeRange(range) {
    this.currentRange = range;
    await this.loadAllCharts();
  }

  // 1. Ventas por día
  static async loadVentasPorDia() {
    const container = document.getElementById('chart-ventas-por-dia');
    if (!container) return;

    container.innerHTML = '<div class="chart-loading"><div class="spinner"></div><p>Cargando...</p></div>';

    try {
      const response = await ogApi.get(`/api/sale/conversion-stats?range=${this.currentRange}`);
      
      if (!response.success || !response.data || response.data.length === 0) {
        container.innerHTML = '<div class="chart-empty">Sin datos para este período</div>';
        return;
      }

      const data = response.data;
      const labels = data.map(item => this.formatDateLabel(new Date(item.date)));
      const initiated = data.map(item => parseInt(item.initiated));
      const confirmedDirect = data.map(item => parseInt(item.confirmed_direct));
      const confirmedFunnel = data.map(item => parseInt(item.confirmed_funnel));

      const html = `
        <div class="chart-card">
          <div class="chart-header">
            <h3 class="chart-title">📈 Conversión de Ventas por Día</h3>
            <p style="font-size: 12px; color: #666; margin-top: 5px;">Ventas por fecha de pago • Verde oscuro: directas • Verde claro: seguimiento</p>
          </div>
          <div class="chart-stats">
            <div class="stat-box">
              <div class="stat-value">${this.sumArray(data, 'initiated')}</div>
              <div class="stat-label">Chats Iniciados</div>
            </div>
            <div class="stat-box">
              <div class="stat-value">${this.sumArray(data, 'confirmed_total')}</div>
              <div class="stat-label">Ventas Pagadas</div>
            </div>
            <div class="stat-box">
              <div class="stat-value">${this.sumArray(data, 'confirmed_direct')}</div>
              <div class="stat-label">Directas (60%)</div>
            </div>
            <div class="stat-box">
              <div class="stat-value">${this.sumArray(data, 'confirmed_funnel')}</div>
              <div class="stat-label">Seguimiento (40%)</div>
            </div>
            <div class="stat-box">
              <div class="stat-value">$${this.formatMoney(this.sumArray(data, 'total_amount'))}</div>
              <div class="stat-label">Ingresos</div>
            </div>
            <div class="stat-box">
              <div class="stat-value">${this.calculateConversionRateNew(data)}%</div>
              <div class="stat-label">Conversión</div>
            </div>
          </div>
          <div class="chart-canvas-wrapper">
            <canvas id="canvas-ventas-dia"></canvas>
          </div>
        </div>
      `;

      container.innerHTML = html;
      
      // Crear gráfico con barras apiladas
      this.createStackedBarChart('canvas-ventas-dia', labels, {
        initiated: initiated,
        confirmedDirect: confirmedDirect,
        confirmedFunnel: confirmedFunnel
      });

    } catch (error) {
      ogLogger.error('ext:infoproduct', 'Error en ventas por día:', error);
      container.innerHTML = '<div class="chart-error">❌ Error al cargar</div>';
    }
  }

  static createStackedBarChart(canvasId, labels, datasets) {
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
            data: datasets.initiated,
            backgroundColor: 'rgba(52, 152, 219, 0.8)',
            order: 2
          },
          {
            label: 'Ventas Directas',
            data: datasets.confirmedDirect,
            backgroundColor: 'rgba(16, 185, 129, 0.9)',  // Verde oscuro
            stack: 'ventas',
            order: 1
          },
          {
            label: 'Ventas por Seguimiento',
            data: datasets.confirmedFunnel,
            backgroundColor: 'rgba(52, 211, 153, 0.9)',  // Verde claro
            stack: 'ventas',
            order: 1
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: true,
        aspectRatio: window.innerWidth < 768 ? 1 : 2,
        interaction: {
          mode: 'index',
          intersect: false,
        },
        plugins: {
          legend: {
            display: true,
            position: 'top'
          },
          tooltip: {
            callbacks: {
              footer: (items) => {
                const idx = items[0].dataIndex;
                const total = datasets.confirmedDirect[idx] + datasets.confirmedFunnel[idx];
                const initiated = datasets.initiated[idx];
                const rate = initiated > 0 ? ((total / initiated) * 100).toFixed(1) : 0;
                return `\nTasa de conversión: ${rate}%`;
              }
            }
          }
        },
        scales: {
          x: {
            stacked: false,
            ticks: { maxRotation: 45, minRotation: 45 }
          },
          y: {
            stacked: false,
            beginAtZero: true,
            ticks: { precision: 0 }
          }
        }
      }
    });
  }

  static calculateConversionRateNew(data) {
    const total = this.sumArray(data, 'initiated');
    const confirmed = this.sumArray(data, 'confirmed_total');
    return total > 0 ? ((confirmed / total) * 100).toFixed(1) : 0;
  }

  // 2. Chats vs Ventas
  static async loadChatsVsVentas() {
    const container = document.getElementById('chart-chats-vs-ventas');
    if (!container) return;

    container.innerHTML = '<div class="chart-loading"><div class="spinner"></div><p>Cargando...</p></div>';

    try {
      const [chatsRes, ventasRes] = await Promise.all([
        ogApi.get(`/api/chat/stats/by-day?range=${this.currentRange}`),
        ogApi.get(`/api/sale/stats/by-day?range=${this.currentRange}`)
      ]);

      if (!chatsRes.success || !ventasRes.success) throw new Error('Error al cargar datos');

      const labels = ventasRes.data.map(item => this.formatDateLabel(new Date(item.date)));
      const chats = chatsRes.data.map(item => parseInt(item.client_messages || 0));
      const ventas = ventasRes.data.map(item => parseInt(item.confirmed_sales || 0));

      const html = `
        <div class="chart-card">
          <h3 class="chart-title">💬 Chats vs Ventas</h3>
          <div class="chart-canvas-wrapper">
            <canvas id="canvas-chats-ventas"></canvas>
          </div>
        </div>
      `;

      container.innerHTML = html;
      this.createLineChart('canvas-chats-ventas', labels, [
        { label: 'Mensajes de Clientes', data: chats, borderColor: '#3498db', backgroundColor: 'rgba(52, 152, 219, 0.1)' },
        { label: 'Ventas Confirmadas', data: ventas, borderColor: '#2ecc71', backgroundColor: 'rgba(46, 204, 113, 0.1)' }
      ]);

    } catch (error) {
      ogLogger.error('ext:infoproduct', 'Error en chats vs ventas:', error);
      container.innerHTML = '<div class="chart-error">❌ Error al cargar</div>';
    }
  }

  // 3. Ventas por Producto (Barra horizontal)
  static async loadVentasPorProducto() {
    const container = document.getElementById('chart-ventas-por-producto');
    if (!container) return;

    container.innerHTML = '<div class="chart-loading"><div class="spinner"></div><p>Cargando...</p></div>';

    try {
      const response = await ogApi.get(`/api/sale/stats/by-product?range=${this.currentRange}`);
      
      if (!response.success || !response.data || response.data.length === 0) {
        container.innerHTML = '<div class="chart-empty">Sin datos</div>';
        return;
      }

      const data = response.data;
      const labels = data.map(item => item.product_name);
      const values = data.map(item => parseInt(item.confirmed_sales));

      const html = `
        <div class="chart-card">
          <h3 class="chart-title">📦 Ventas por Producto</h3>
          <div class="chart-canvas-wrapper">
            <canvas id="canvas-ventas-producto"></canvas>
          </div>
        </div>
      `;

      container.innerHTML = html;
      this.createHorizontalBarChart('canvas-ventas-producto', labels, values);

    } catch (error) {
      ogLogger.error('ext:infoproduct', 'Error en ventas por producto:', error);
      container.innerHTML = '<div class="chart-error">❌ Error al cargar</div>';
    }
  }

  // 4. Ventas por Producto (Pastel)
  static async loadVentasProductoPastel() {
    const container = document.getElementById('chart-ventas-producto-pastel');
    if (!container) return;

    container.innerHTML = '<div class="chart-loading"><div class="spinner"></div><p>Cargando...</p></div>';

    try {
      const response = await ogApi.get(`/api/sale/stats/by-product?range=${this.currentRange}`);
      
      if (!response.success || !response.data || response.data.length === 0) {
        container.innerHTML = '<div class="chart-empty">Sin datos</div>';
        return;
      }

      const data = response.data;
      const labels = data.map(item => item.product_name);
      const values = data.map(item => parseInt(item.confirmed_sales));

      const html = `
        <div class="chart-card">
          <h3 class="chart-title">🥧 Distribución de Ventas</h3>
          <div class="chart-canvas-wrapper">
            <canvas id="canvas-ventas-pastel"></canvas>
          </div>
        </div>
      `;

      container.innerHTML = html;
      this.createPieChart('canvas-ventas-pastel', labels, values);

    } catch (error) {
      ogLogger.error('ext:infoproduct', 'Error en pastel de ventas:', error);
      container.innerHTML = '<div class="chart-error">❌ Error al cargar</div>';
    }
  }

  // 5. Chats por Producto
  static async loadChatsPorProducto() {
    const container = document.getElementById('chart-chats-por-producto');
    if (!container) return;

    container.innerHTML = '<div class="chart-loading"><div class="spinner"></div><p>Cargando...</p></div>';

    try {
      const response = await ogApi.get(`/api/chat/stats/by-product?range=${this.currentRange}`);
      
      if (!response.success || !response.data || response.data.length === 0) {
        container.innerHTML = '<div class="chart-empty">Sin datos</div>';
        return;
      }

      const data = response.data;
      const labels = data.map(item => item.product_name);
      const values = data.map(item => parseInt(item.client_messages));

      const html = `
        <div class="chart-card">
          <h3 class="chart-title">💬 Mensajes por Producto (chat recibidos de prospecto)</h3>
          <div class="chart-canvas-wrapper">
            <canvas id="canvas-chats-producto"></canvas>
          </div>
        </div>
      `;

      container.innerHTML = html;
      this.createBarChart('canvas-chats-producto', labels, [
        { label: 'Mensajes', data: values, backgroundColor: 'rgba(155, 89, 182, 0.8)' }
      ]);

    } catch (error) {
      ogLogger.error('ext:infoproduct', 'Error en chats por producto:', error);
      container.innerHTML = '<div class="chart-error">❌ Error al cargar</div>';
    }
  }

  // 6. Mensajes Nuevos (initiated)

  // 7. Seguimientos
  static async loadSeguimientos() {
    const container = document.getElementById('chart-seguimientos');
    if (!container) return;

    container.innerHTML = '<div class="chart-loading"><div class="spinner"></div><p>Cargando...</p></div>';

    try {
      const response = await ogApi.get(`/api/followup/stats/by-day?range=${this.currentRange}`);
      
      if (!response.success || !response.data || response.data.length === 0) {
        container.innerHTML = '<div class="chart-empty">Sin datos</div>';
        return;
      }

      const data = response.data;
      const labels = data.map(item => this.formatDateLabel(new Date(item.date)));
      const sent = data.map(item => parseInt(item.sent));
      const pending = data.map(item => parseInt(item.pending));

      const html = `
        <div class="chart-card">
          <h3 class="chart-title">📬 Seguimientos Enviados vs Pendientes</h3>
          <div class="chart-canvas-wrapper">
            <canvas id="canvas-seguimientos"></canvas>
          </div>
        </div>
      `;

      container.innerHTML = html;
      this.createBarChart('canvas-seguimientos', labels, [
        { label: 'Enviados', data: sent, backgroundColor: 'rgba(46, 204, 113, 0.8)' },
        { label: 'Pendientes', data: pending, backgroundColor: 'rgba(241, 196, 15, 0.8)' }
      ]);

    } catch (error) {
      ogLogger.error('ext:infoproduct', 'Error en seguimientos:', error);
      container.innerHTML = '<div class="chart-error">❌ Error al cargar</div>';
    }
  }

  // Helpers para crear gráficos
  static createBarChart(canvasId, labels, datasets) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return;

    if (this.charts[canvasId]) this.charts[canvasId].destroy();

    this.charts[canvasId] = new Chart(ctx, {
      type: 'bar',
      data: { labels, datasets },
      options: {
        responsive: true,
        maintainAspectRatio: true,
        aspectRatio: window.innerWidth < 768 ? 1 : 2,
        plugins: { legend: { display: true, position: 'top' } },
        scales: {
          y: { beginAtZero: true, ticks: { precision: 0 } },
          x: { ticks: { maxRotation: 45, minRotation: 45 } }
        }
      }
    });
  }

  static createLineChart(canvasId, labels, datasets) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return;

    if (this.charts[canvasId]) this.charts[canvasId].destroy();

    datasets = datasets.map(d => ({ ...d, fill: true, tension: 0.4 }));

    this.charts[canvasId] = new Chart(ctx, {
      type: 'line',
      data: { labels, datasets },
      options: {
        responsive: true,
        maintainAspectRatio: true,
        aspectRatio: window.innerWidth < 768 ? 1 : 2,
        plugins: { legend: { display: true, position: 'top' } },
        scales: {
          y: { beginAtZero: true, ticks: { precision: 0 } },
          x: { ticks: { maxRotation: 45, minRotation: 45 } }
        }
      }
    });
  }

  static createHorizontalBarChart(canvasId, labels, data) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return;

    if (this.charts[canvasId]) this.charts[canvasId].destroy();

    this.charts[canvasId] = new Chart(ctx, {
      type: 'bar',
      data: {
        labels,
        datasets: [{ label: 'Ventas', data, backgroundColor: 'rgba(52, 152, 219, 0.8)' }]
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: true,
        aspectRatio: 1.5,
        plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true, ticks: { precision: 0 } } }
      }
    });
  }

  static createPieChart(canvasId, labels, data) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return;

    if (this.charts[canvasId]) this.charts[canvasId].destroy();

    const colors = [
      'rgba(52, 152, 219, 0.8)',
      'rgba(46, 204, 113, 0.8)',
      'rgba(155, 89, 182, 0.8)',
      'rgba(230, 126, 34, 0.8)',
      'rgba(231, 76, 60, 0.8)',
      'rgba(241, 196, 15, 0.8)'
    ];

    this.charts[canvasId] = new Chart(ctx, {
      type: 'pie',
      data: {
        labels,
        datasets: [{
          data,
          backgroundColor: colors.slice(0, labels.length)
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: true,
        aspectRatio: 1.5,
        plugins: {
          legend: { position: 'right' }
        }
      }
    });
  }

  // Helpers
  static formatDateLabel(date) {
    const days = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
    const months = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
    return `${days[date.getDay()]} ${date.getDate()} ${months[date.getMonth()]}`;
  }

  static formatMoney(amount) {
    return parseFloat(amount || 0).toFixed(2);
  }

  static sumArray(data, field) {
    return data.reduce((sum, item) => sum + parseFloat(item[field] || 0), 0);
  }

  static calculateConversionRate(data) {
    const total = this.sumArray(data, 'total_sales');
    const confirmed = this.sumArray(data, 'confirmed_sales');
    return total > 0 ? ((confirmed / total) * 100).toFixed(1) : 0;
  }
}

window.productStats = productStats;