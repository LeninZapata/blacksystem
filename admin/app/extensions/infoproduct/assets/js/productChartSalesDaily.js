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
      interaction: {
        mode: 'index',
        intersect: false
      },
      scales: {
        y: {
          beginAtZero: true,
          stacked: true, // Apilar barras
          position: 'left',
          title: {
            display: true,
            text: 'Ingresos ($)'
          },
          ticks: {
            callback: function(value) {
              return '$' + value.toLocaleString('es-MX', { minimumFractionDigits: 0 });
            }
          }
        },
        y1: {
          beginAtZero: true,
          position: 'right',
          grid: {
            drawOnChartArea: false
          },
          title: {
            display: true,
            text: 'Cantidad'
          },
          ticks: {
            precision: 0
          }
        },
        x: {
          stacked: true, // Apilar barras
          grid: {
            display: false
          }
        }
      },
      plugins: {
        legend: {
          display: true,
          position: 'bottom',
          labels: {
            boxWidth: 12,
            padding: 10,
            font: {
              size: 11
            }
          }
        },
        tooltip: {
          callbacks: {
            title: function(context) {
              return context[0].label; // Fecha
            },
            label: function(context) {
              const value = context.parsed.y;
              const label = context.dataset.label;
              const dataIndex = context.dataIndex;
              
              // Si es Cantidad, mostrar sin formato dólar
              if (label === 'Cantidad') {
                return `${label}: ${Math.round(value)}`;
              }
              
              // Para ventas, mostrar valor + conteo entre paréntesis
              const count = context.dataset.counts ? context.dataset.counts[dataIndex] : 0;
              return `${label}: $${value.toLocaleString('es-MX', { minimumFractionDigits: 2 })} (${count})`;
            },
            footer: function(context) {
              // Calcular total sumando solo los datasets de ingresos (excluir Cantidad)
              const index = context[0].dataIndex;
              let total = 0;
              context[0].chart.data.datasets.forEach(dataset => {
                // Solo sumar si NO es el dataset de Cantidad
                if (dataset.label !== 'Cantidad') {
                  total += dataset.data[index] || 0;
                }
              });
              return `Total Ingresos: $${total.toLocaleString('es-MX', { minimumFractionDigits: 2 })}`;
            }
          },
          backgroundColor: 'rgba(0, 0, 0, 0.8)',
          titleColor: '#fff',
          bodyColor: '#fff',
          footerColor: '#86efac', // green-300
          padding: 12,
          displayColors: true
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
    
    const directRevenues = data.map(d => parseFloat(d.direct_revenue || 0));
    const remarketingRevenues = data.map(d => parseFloat(d.remarketing_revenue || 0));
    const upsellRevenues = data.map(d => parseFloat(d.upsell_revenue || 0));
    const directCounts = data.map(d => parseInt(d.direct_count || 0));
    const remarketingCounts = data.map(d => parseInt(d.remarketing_count || 0));
    const upsellCounts = data.map(d => parseInt(d.upsell_count || 0));
    const totalCounts = data.map(d => 
      parseInt(d.direct_count || 0) + 
      parseInt(d.remarketing_count || 0) + 
      parseInt(d.upsell_count || 0)
    );

    // Crear gráfica con barras apiladas + línea de cantidad
    const ctx = canvas.getContext('2d');
    config.chartInstance = new Chart(ctx, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [
          {
            label: 'Ventas Directas',
            data: directRevenues,
            counts: directCounts,
            backgroundColor: 'rgba(37, 99, 235, 0.8)', // blue-600
            borderColor: 'rgba(37, 99, 235, 1)',
            borderWidth: 1,
            borderRadius: 4,
            yAxisID: 'y'
          },
          {
            label: 'Remarketing',
            data: remarketingRevenues,
            counts: remarketingCounts,
            backgroundColor: 'rgba(147, 51, 234, 0.8)', // purple-600
            borderColor: 'rgba(147, 51, 234, 1)',
            borderWidth: 1,
            borderRadius: 4,
            yAxisID: 'y'
          },
          {
            label: 'Upsell',
            data: upsellRevenues,
            counts: upsellCounts,
            backgroundColor: 'rgba(79, 70, 229, 0.8)', // indigo-600
            borderColor: 'rgba(79, 70, 229, 1)',
            borderWidth: 1,
            borderRadius: 4,
            yAxisID: 'y'
          },
          {
            label: 'Cantidad',
            data: totalCounts,
            type: 'line',
            borderColor: 'rgba(107, 114, 128, 1)', // gray-500
            backgroundColor: 'transparent',
            borderWidth: 2.5,
            pointRadius: 3,
            pointBackgroundColor: 'rgba(107, 114, 128, 1)',
            tension: 0,
            yAxisID: 'y1',
            order: 0
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
  window.productChartSalesDaily = {
    render,
    destroy
  };

})();
