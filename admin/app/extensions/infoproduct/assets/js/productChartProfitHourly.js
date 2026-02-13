/**
 * productChartProfitHourly.js
 * Componente de Chart.js para visualizar profit por hora
 * Muestra barras para profit y líneas para ingreso y gasto
 */

(function() {
  'use strict';

  let chartInstance = null;

  /**
   * Renderizar gráfica de profit por hora
   * @param {Array} data - Array de objetos con: hour, profit, revenue, spend
   */
  function render(data) {
    const canvas = document.getElementById('chartProfitHourly');
    
    if (!canvas) {
      ogLogger.error('ext:infoproduct', 'Canvas chartProfitHourly no encontrado');
      return;
    }

    // Destruir gráfica anterior si existe
    if (chartInstance) {
      chartInstance.destroy();
      chartInstance = null;
    }

    // Validar datos
    if (!data || !Array.isArray(data) || data.length === 0) {
      ogLogger.warning('ext:infoproduct', 'No hay datos para gráfica de profit por hora');
      
      const ctx = canvas.getContext('2d');
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      ctx.font = '14px Arial';
      ctx.fillStyle = '#666';
      ctx.textAlign = 'center';
      ctx.fillText('No hay datos disponibles', canvas.width / 2, canvas.height / 2);
      return;
    }

    // Preparar datos
    const labels = data.map(item => {
      const hour = parseInt(item.hour);
      return `${hour.toString().padStart(2, '0')}:00`;
    });

    const profitData = data.map(item => parseFloat(item.profit || 0));
    const revenueData = data.map(item => parseFloat(item.revenue || 0));
    const spendData = data.map(item => parseFloat(item.spend || 0));

    // Configuración de la gráfica
    const config = {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [
          {
            type: 'bar',
            label: 'Profit',
            data: profitData,
            backgroundColor: 'rgba(34, 197, 94, 0.7)', // green-500
            borderColor: 'rgb(34, 197, 94)',
            borderWidth: 2,
            yAxisID: 'y'
          },
          {
            type: 'line',
            label: 'Ingreso',
            data: revenueData,
            borderColor: 'rgb(59, 130, 246)', // blue-500
            backgroundColor: 'rgba(59, 130, 246, 0.1)',
            borderWidth: 3,
            tension: 0.4,
            fill: false,
            pointRadius: 4,
            pointHoverRadius: 6,
            yAxisID: 'y'
          },
          {
            type: 'line',
            label: 'Gasto',
            data: spendData,
            borderColor: 'rgb(239, 68, 68)', // red-500
            backgroundColor: 'rgba(239, 68, 68, 0.1)',
            borderWidth: 3,
            tension: 0.4,
            fill: false,
            pointRadius: 4,
            pointHoverRadius: 6,
            yAxisID: 'y'
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: {
          mode: 'index',
          intersect: false
        },
        plugins: {
          title: {
            display: false
          },
          legend: {
            display: true,
            position: 'top',
            labels: {
              usePointStyle: true,
              padding: 15,
              font: {
                size: 12,
                weight: '500'
              }
            }
          },
          tooltip: {
            enabled: true,
            backgroundColor: 'rgba(0, 0, 0, 0.8)',
            padding: 12,
            titleFont: {
              size: 13,
              weight: 'bold'
            },
            bodyFont: {
              size: 12
            },
            callbacks: {
              label: function(context) {
                const label = context.dataset.label || '';
                const value = context.parsed.y;
                return `${label}: $${value.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
              },
              afterBody: function(context) {
                if (context.length > 0) {
                  const dataIndex = context[0].dataIndex;
                  const dataPoint = data[dataIndex];
                  if (dataPoint && dataPoint.roas !== undefined) {
                    return `ROAS: ${dataPoint.roas}x`;
                  }
                }
                return '';
              }
            }
          }
        },
        scales: {
          x: {
            grid: {
              display: false
            },
            ticks: {
              font: {
                size: 11
              }
            }
          },
          y: {
            type: 'linear',
            display: true,
            position: 'left',
            title: {
              display: true,
              text: 'USD',
              font: {
                size: 12,
                weight: 'bold'
              }
            },
            grid: {
              color: 'rgba(0, 0, 0, 0.05)'
            },
            ticks: {
              callback: function(value) {
                return '$' + value.toLocaleString('es-MX');
              },
              font: {
                size: 11
              }
            }
          }
        }
      }
    };

    // Crear nueva gráfica
    const ctx = canvas.getContext('2d');
    chartInstance = new Chart(ctx, config);
    
    ogLogger.info('ext:infoproduct', 'Gráfica de profit por hora renderizada', {
      dataPoints: data.length
    });
  }

  /**
   * Destruir gráfica
   */
  function destroy() {
    if (chartInstance) {
      chartInstance.destroy();
      chartInstance = null;
      ogLogger.info('ext:infoproduct', 'Gráfica de profit por hora destruida');
    }
  }

  // Exponer API pública
  window.productChartProfitHourly = {
    render: render,
    destroy: destroy
  };

})();
