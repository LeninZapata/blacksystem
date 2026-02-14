// productChartPerformanceHourly.js - Gráfica de rendimiento por hora (Chart.js)
(function() {
  'use strict';

  // Configuración
  const config = {
    canvasId: 'chartPerformanceHourly',
    chartInstance: null,
    defaultOptions: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: {
        mode: 'index',
        intersect: false,
      },
      scales: {
        y: {
          type: 'linear',
          display: true,
          position: 'left',
          beginAtZero: true,
          ticks: {
            precision: 0
          },
          title: {
            display: true,
            text: 'Chats / Clics'
          }
        },
        y1: {
          type: 'linear',
          display: true,
          position: 'right',
          beginAtZero: true,
          ticks: {
            precision: 0
          },
          grid: {
            drawOnChartArea: false,
          },
          title: {
            display: true,
            text: 'Alcance'
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
          display: true,
          position: 'top',
        },
        tooltip: {
          callbacks: {
            label: function(context) {
              let label = context.dataset.label || '';
              if (label) {
                label += ': ';
              }
              label += context.parsed.y.toLocaleString();
              return label;
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
      console.error('Canvas #chartPerformanceHourly no encontrado');
      return;
    }

    // Destruir instancia previa
    if (config.chartInstance) {
      config.chartInstance.destroy();
      config.chartInstance = null;
    }

    // Preparar datos
    const labels = data.map(d => `${String(d.hour).padStart(2, '0')}:00`);
    const chatsInitiated = data.map(d => d.chats_initiated);
    const whatsappClicks = data.map(d => d.whatsapp_clicks);
    const reach = data.map(d => d.reach);

    // Crear gráfica
    const ctx = canvas.getContext('2d');
    config.chartInstance = new Chart(ctx, {
      type: 'line',
      data: {
        labels: labels,
        datasets: [
          {
            label: 'Alcance',
            data: reach,
            borderColor: 'rgba(168, 85, 247, 0.8)', // purple-500
            backgroundColor: 'rgba(168, 85, 247, 0.15)',
            borderWidth: 2,
            fill: true,
            yAxisID: 'y1',
            order: 3,
            tension: 0.4
          },
          {
            label: 'Chats Iniciados',
            data: chatsInitiated,
            borderColor: 'rgba(34, 197, 94, 1)', // green-500
            backgroundColor: 'transparent',
            borderWidth: 2.5,
            fill: false,
            yAxisID: 'y',
            order: 1,
            tension: 0
          },
          {
            label: 'Clics en WhatsApp',
            data: whatsappClicks,
            borderColor: 'rgba(59, 130, 246, 1)', // blue-500
            backgroundColor: 'transparent',
            borderWidth: 2.5,
            fill: false,
            yAxisID: 'y',
            order: 2,
            tension: 0
          }
        ]
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
  window.productChartPerformanceHourly = {
    render,
    destroy
  };

})();
