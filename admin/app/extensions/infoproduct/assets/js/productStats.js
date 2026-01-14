// productStats.js - Archivo principal que coordina todas las gráficas
class productStats {
  static currentRange = 'today';
  static charts = {};
  static chartModules = {};
  static gastosTabLoaded = false;

  static async init() {
    ogLogger.debug('ext:infoproduct', 'Inicializando estadísticas');
    await this.loadChartJS();
    await this.registerChartModules();
    await this.loadAllCharts();
    this.syncRangeSelectors();
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

  static async registerChartModules() {
    // Registrar módulos de gráficas disponibles
    this.chartModules = {
      'new-clients': window.ProductChartNewClients || null,
      'chats-vs-messages': window.ProductChartChatsVsMessages || null,
      'sales-direct-remarketing': window.ProductChartSalesDirectRemarketing || null,
      'ad-spend': window.ProductChartAdSpend || null
    };
  }

  static async loadAllCharts() {
    // Cargar solo las gráficas de la tab Ventas
    const promises = [
      this.loadChart('sales-direct-remarketing', 'chart-sales-direct-remarketing'),
      this.loadChart('chats-vs-messages', 'chart-chats-vs-messages'),
      this.loadChart('new-clients', 'chart-new-clients')
    ];

    await Promise.allSettled(promises).catch(error => {
      ogLogger.error('ext:infoproduct', 'Error cargando gráficos:', error);
    });
  }

  static async onGastosTabLoaded() {
    ogLogger.debug('ext:infoproduct', 'Cargando gráfica de gastos');
    this.gastosTabLoaded = true;
    await this.loadChart('ad-spend', 'chart-ad-spend');
  }

  static async loadChart(chartType, containerId) {
    const chartModule = this.chartModules[chartType];
    if (!chartModule || typeof chartModule.load !== 'function') {
      ogLogger.error('ext:infoproduct', `Módulo de gráfica no encontrado: ${chartType}`);
      return;
    }

    try {
      await chartModule.load(this.currentRange, containerId);
    } catch (error) {
      ogLogger.error('ext:infoproduct', `Error cargando gráfica ${chartType}:`, error);
    }
  }

  static async changeRange(range) {
    this.currentRange = range;
    this.syncRangeSelectors();
    await this.loadAllCharts();
    
    // Si la tab de gastos ya fue cargada, recargarla también
    if (this.gastosTabLoaded) {
      await this.loadChart('ad-spend', 'chart-ad-spend');
    }
  }

  static syncRangeSelectors() {
    // Sincronizar todos los selectores de rango
    document.querySelectorAll('select[onchange*="productStats.changeRange"]').forEach(select => {
      if (select.value !== this.currentRange) {
        select.value = this.currentRange;
      }
    });
  }

  static formatDate(date) {
    const days = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
    const months = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
    return `${days[date.getDay()]} ${date.getDate()} ${months[date.getMonth()]}`;
  }

  static destroyChart(canvasId) {
    if (this.charts[canvasId]) {
      this.charts[canvasId].destroy();
      delete this.charts[canvasId];
    }
  }
}

// Registrar en window para acceso global
window.productStats = productStats;

// Inicializar cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', () => {
  productStats.init().catch(error => {
    ogLogger.error('ext:infoproduct', 'Error inicializando estadísticas:', error);
  });
});