// productChartSalesDaily.js - Gráfica de ventas por día (Chart.js)
(function() {
  'use strict';

  // Configuración
  const config = {
    canvasId: 'chartSalesDaily',
    chartInstance: null,
    defaultOptions: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        y: {
          beginAtZero: true,
          ticks: {
            callback: function(value) {
              return '$' + value.toLocaleString('es-MX', { minimumFractionDigits: 0 });
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
      console.error('Canvas #chartSalesDaily no encontrado');
      return;
    }

    // Destruir instancia previa
    if (config.chartInstance) {
      config.chartInstance.destroy();
      config.chartInstance = null;
    }

    // Preparar datos
    const labels = data.map(d => {
      const date = new Date(d.date + 'T00:00:00');
      return date.toLocaleDateString('es-MX', { day: '2-digit', month: 'short' });
    });
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
          salesCount: salesCounts,
          backgroundColor: 'rgba(34, 197, 94, 0.8)', // green-500
          borderColor: 'rgba(34, 197, 94, 1)',
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
  window.productChartSalesDaily = {
    render,
    destroy
  };

})();
