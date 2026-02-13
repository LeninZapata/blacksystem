// productChartSalesHourly.js - Gráfica de ventas por hora (Chart.js)
(function() {
  'use strict';

  // Configuración
  const config = {
    canvasId: 'chartSalesHourly',
    chartInstance: null,
    defaultOptions: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        y: {
          beginAtZero: true,
          ticks: {
            callback: function(value) {
              return '$' + value.toLocaleString('es-MX', { minimumFractionDigits: 2 });
            }
          }
        },
        x: {
          grid: {
            display: false
          }
        }
      },
      plugins: {
        legend: {
          display: false
        },
        tooltip: {
          callbacks: {
            label: function(context) {
              const index = context.dataIndex;
              const dataset = context.dataset;
              const revenue = dataset.data[index];
              const qty = dataset.salesCount[index] || 0;
              
              return [
                `Ventas: ${qty}`,
                `Ingresos: $${revenue.toLocaleString('es-MX', { minimumFractionDigits: 2 })}`
              ];
            }
          }
        }
      }
    }
  };

  // Renderizar gráfica
  function render(data = []) {
    const canvas = document.getElementById(config.canvasId);
    if (!canvas) {
      console.error('Canvas #chartSalesHourly no encontrado');
      return;
    }

    // Destruir instancia previa
    if (config.chartInstance) {
      config.chartInstance.destroy();
      config.chartInstance = null;
    }

    // Preparar datos
    const labels = data.map(d => `${String(d.hour).padStart(2, '0')}:00`);
    const revenues = data.map(d => d.revenue);
    const salesCounts = data.map(d => d.sales_count);

    // Crear gráfica
    const ctx = canvas.getContext('2d');
    config.chartInstance = new Chart(ctx, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          label: 'Ingresos',
          data: revenues,
          salesCount: salesCounts, // Guardamos las cantidades para el tooltip
          backgroundColor: 'rgba(59, 130, 246, 0.8)', // blue-500
          borderColor: 'rgba(59, 130, 246, 1)',
          borderWidth: 1,
          borderRadius: 4
        }]
      },
      options: config.defaultOptions
    });
  }

  // Destruir gráfica
  function destroy() {
    if (config.chartInstance) {
      config.chartInstance.destroy();
      config.chartInstance = null;
    }
  }

  // Exponer API global
  window.productChartSalesHourly = {
    render,
    destroy
  };

})();
