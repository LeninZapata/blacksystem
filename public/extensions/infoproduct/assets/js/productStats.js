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
      this.loadSalesDirectVsRemarketing(),
      this.loadChatsVsMessages(),
      this.loadNewClientsByDay(),
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
  // 1. PROSPECTOS NUEVOS POR DÍA (CON CONVERSIÓN)
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
      const newClients = data.map(item => parseInt(item.new_clients));
      const convertedClients = data.map(item => parseInt(item.converted_clients));
      const notConverted = newClients.map((total, idx) => total - convertedClients[idx]);

      const totalClients = newClients.reduce((sum, val) => sum + val, 0);
      const totalConverted = convertedClients.reduce((sum, val) => sum + val, 0);
      const conversionRate = totalClients > 0 ? ((totalConverted / totalClients) * 100) : 0;

      const html = `
        <div class="chart-card">
          <div class="chart-header">
            <h3 class="chart-title">👥 Prospectos Nuevos por Día (con Conversión)</h3>
          </div>
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
          </div>
          <div class="chart-canvas-wrapper">
            <canvas id="canvas-new-clients"></canvas>
          </div>
        </div>
      `;

      container.innerHTML = html;
      this.createProspectsStackedChart('canvas-new-clients', labels, notConverted, convertedClients);

    } catch (error) {
      ogLogger.error('ext:infoproduct', 'Error en prospectos nuevos:', error);
      container.innerHTML = '<div class="chart-error">❌ Error al cargar</div>';
    }
  }

  // ==========================================
  // 2. ACTIVIDAD DE MENSAJES (BARRA + 2 LÍNEAS)
  // ==========================================
  
  static async loadChatsVsMessages() {
    const container = document.getElementById('chart-chats-vs-messages');
    if (!container) return;

    container.innerHTML = '<div class="chart-loading"><div class="spinner"></div><p>Cargando...</p></div>';

    try {
      const response = await ogApi.get(`/api/chat/stats/messages-activity?range=${this.currentRange}`);
      
      if (!response.success || !response.data || response.data.length === 0) {
        container.innerHTML = '<div class="chart-empty">Sin datos para este período</div>';
        return;
      }

      const data = response.data;
      const labels = data.map(item => this.formatDate(new Date(item.date)));
      const totalMessages = data.map(item => parseInt(item.total_messages));
      const newChats = data.map(item => parseInt(item.new_chats));
      const followups = data.map(item => parseInt(item.followups_scheduled));

      const total = totalMessages.reduce((a, b) => a + b, 0);
      const totalChats = newChats.reduce((a, b) => a + b, 0);
      const totalFollowups = followups.reduce((a, b) => a + b, 0);

      const html = `
        <div class="chart-card">
          <div class="chart-header">
            <h3 class="chart-title">💬 Actividad de Mensajes</h3>
          </div>
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
              <div class="stat-value">${(total / data.length).toFixed(1)}</div>
              <div class="stat-label">Promedio/Día</div>
            </div>
          </div>
          <div class="chart-canvas-wrapper">
            <canvas id="canvas-messages-activity"></canvas>
          </div>
        </div>
      `;

      container.innerHTML = html;
      this.createMessagesActivityChart('canvas-messages-activity', labels, totalMessages, newChats, followups);

    } catch (error) {
      ogLogger.error('ext:infoproduct', 'Error en actividad de mensajes:', error);
      container.innerHTML = '<div class="chart-error">❌ Error al cargar</div>';
    }
  }

  // ==========================================
  // 3. VENTAS $ Y CONVERSIÓN %
  // ==========================================
  
  static async loadSalesRevenueAndConversion() {
    const container = document.getElementById('chart-sales-revenue-conversion');
    if (!container) return;

    container.innerHTML = '<div class="chart-loading"><div class="spinner"></div><p>Cargando...</p></div>';

    try {
      const response = await ogApi.get(`/api/sale/stats/revenue-conversion?range=${this.currentRange}`);
      
      if (!response.success || !response.data || response.data.length === 0) {
        container.innerHTML = '<div class="chart-empty">Sin datos para este período</div>';
        return;
      }

      const data = response.data;
      const labels = data.map(item => this.formatDate(new Date(item.date)));
      const revenue = data.map(item => parseFloat(item.revenue).toFixed(2));
      const salesCount = data.map(item => parseInt(item.sales_count));
      const conversionRate = data.map(item => parseFloat(item.conversion_rate));
      const totalSales = data.map(item => parseInt(item.total_sales || 0));

      const totalRevenue = revenue.reduce((a, b) => parseFloat(a) + parseFloat(b), 0);
      const totalConfirmed = salesCount.reduce((a, b) => a + b, 0);
      const totalProspects = totalSales.reduce((a, b) => a + b, 0);
      const avgConversion = conversionRate.reduce((a, b) => a + b, 0) / conversionRate.length;

      const html = `
        <div class="chart-card">
          <div class="chart-header">
            <h3 class="chart-title">💰 Ventas Confirmadas ($ y Conversión %)</h3>
          </div>
          <div class="chart-stats">
            <div class="stat-box">
              <div class="stat-value">$${totalRevenue.toFixed(2)}</div>
              <div class="stat-label">Ingresos Totales</div>
            </div>
            <div class="stat-box">
              <div class="stat-value">${totalProspects}</div>
              <div class="stat-label">Total Prospectos</div>
            </div>
            <div class="stat-box">
              <div class="stat-value">${totalConfirmed}</div>
              <div class="stat-label">Ventas Confirmadas</div>
            </div>
            <div class="stat-box">
              <div class="stat-value">${avgConversion.toFixed(1)}%</div>
              <div class="stat-label">Conversión Promedio</div>
            </div>
          </div>
          <div class="chart-canvas-wrapper">
            <canvas id="canvas-sales-revenue"></canvas>
          </div>
        </div>
      `;

      container.innerHTML = html;
      this.createMixedChart('canvas-sales-revenue', labels, revenue, salesCount, conversionRate, totalSales);

    } catch (error) {
      ogLogger.error('ext:infoproduct', 'Error en ventas y conversión:', error);
      container.innerHTML = '<div class="chart-error">❌ Error al cargar</div>';
    }
  }

  // ==========================================
  // 4. VENTAS DIRECTAS VS REMARKETING (BARRAS APILADAS)
  // ==========================================
  
  static async loadSalesDirectVsRemarketing() {
    const container = document.getElementById('chart-sales-direct-remarketing');
    if (!container) return;

    container.innerHTML = '<div class="chart-loading"><div class="spinner"></div><p>Cargando...</p></div>';

    try {
      const response = await ogApi.get(`/api/sale/stats/direct-vs-remarketing?range=${this.currentRange}`);
      
      if (!response.success || !response.data || response.data.length === 0) {
        container.innerHTML = '<div class="chart-empty">Sin datos para este período</div>';
        return;
      }

      const data = response.data;
      const labels = data.map(item => this.formatDate(new Date(item.date)));
      const directRevenue = data.map(item => parseFloat(item.direct_revenue));
      const remarketingRevenue = data.map(item => parseFloat(item.remarketing_revenue));
      const directCount = data.map(item => parseInt(item.direct_count));
      const remarketingCount = data.map(item => parseInt(item.remarketing_count));
      const totalSales = data.map(item => parseInt(item.total_sales || 0));
      const conversionRate = data.map(item => parseFloat(item.conversion_rate || 0));

      const totalDirect = directRevenue.reduce((a, b) => a + b, 0);
      const totalRemarketing = remarketingRevenue.reduce((a, b) => a + b, 0);
      const totalDirectCount = directCount.reduce((a, b) => a + b, 0);
      const totalRemarketingCount = remarketingCount.reduce((a, b) => a + b, 0);
      const totalRevenue = totalDirect + totalRemarketing;
      const totalConfirmed = totalDirectCount + totalRemarketingCount;
      const totalProspects = totalSales.reduce((a, b) => a + b, 0);
      const avgConversion = totalProspects > 0 ? ((totalConfirmed / totalProspects) * 100) : 0;

      const html = `
        <div class="chart-card">
          <div class="chart-header">
            <h3 class="chart-title">🎯 Ventas por Origen (Directas vs Remarketing)</h3>
          </div>
          <div class="chart-stats">
            <div class="stat-box">
              <div class="stat-value">$${totalRevenue.toFixed(2)}</div>
              <div class="stat-label">Total Ingresos</div>
            </div>
            <div class="stat-box">
              <div class="stat-value">$${totalDirect.toFixed(2)}</div>
              <div class="stat-label">Ventas Directas (${totalDirectCount}) [${((totalDirect/totalRevenue)*100).toFixed(1)}%]</div>
            </div>
            <div class="stat-box">
              <div class="stat-value">$${totalRemarketing.toFixed(2)}</div>
              <div class="stat-label">Remarketing (${totalRemarketingCount}) [${((totalRemarketing/totalRevenue)*100).toFixed(1)}%]</div>
            </div>
            <div class="stat-box">
              <div class="stat-value">${avgConversion.toFixed(1)}%</div>
              <div class="stat-label">Conversión (${totalConfirmed}/${totalProspects})</div>
            </div>
          </div>
          <div class="chart-canvas-wrapper">
            <canvas id="canvas-sales-origin"></canvas>
          </div>
        </div>
      `;

      container.innerHTML = html;
      this.createStackedBarWithLine('canvas-sales-origin', labels, directRevenue, remarketingRevenue, directCount, remarketingCount, totalSales, conversionRate);

    } catch (error) {
      ogLogger.error('ext:infoproduct', 'Error en ventas directas vs remarketing:', error);
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

  static createMixedChart(canvasId, labels, revenue, salesCount, conversionRate, totalSales) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return;

    if (this.charts[canvasId]) this.charts[canvasId].destroy();

    this.charts[canvasId] = new Chart(ctx, {
      type: 'bar',
      data: {
        labels,
        datasets: [
          {
            type: 'bar',
            label: 'Ingresos ($)',
            data: revenue,
            backgroundColor: 'rgba(52, 152, 219, 0.8)',
            borderColor: 'rgba(52, 152, 219, 1)',
            borderWidth: 1,
            yAxisID: 'y',
            order: 2
          },
          {
            type: 'line',
            label: 'Conversión (%)',
            data: conversionRate,
            borderColor: 'rgba(231, 76, 60, 1)',
            backgroundColor: 'rgba(231, 76, 60, 0.1)',
            borderWidth: 2,
            yAxisID: 'y1',
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
              afterLabel: function(context) {
                // Agregar info extra después del label principal
                const idx = context.dataIndex;
                const total = totalSales[idx];
                const confirmed = salesCount[idx];
                const conversion = conversionRate[idx];
                
                if (context.datasetIndex === 0) {
                  // En barras: mostrar prospectos totales
                  return `Prospectos: ${total} (${confirmed} confirmados)`;
                }
                
                return null;
              },
              label: function(context) {
                let label = context.dataset.label || '';
                if (label) label += ': ';
                if (context.parsed.y !== null) {
                  if (context.datasetIndex === 0) {
                    // Revenue: mostrar $
                    label += '$' + context.parsed.y;
                  } else {
                    // Conversion: mostrar %
                    label += context.parsed.y.toFixed(1) + '%';
                  }
                }
                return label;
              }
            }
          }
        },
        scales: {
          y: {
            type: 'linear',
            position: 'left',
            beginAtZero: true,
            title: { display: true, text: 'Ingresos ($)' },
            ticks: {
              callback: function(value) {
                return '$' + value.toFixed(0);
              }
            }
          },
          y1: {
            type: 'linear',
            position: 'right',
            beginAtZero: true,
            max: 100,
            title: { display: true, text: 'Conversión (%)' },
            grid: { drawOnChartArea: false },
            ticks: {
              callback: function(value) {
                return value + '%';
              }
            }
          },
          x: {
            ticks: { maxRotation: 45, minRotation: 45 }
          }
        }
      }
    });
  }

  static createStackedBarWithLine(canvasId, labels, directData, remarketingData, directCount, remarketingCount, totalSales, conversionRate) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return;

    if (this.charts[canvasId]) this.charts[canvasId].destroy();

    this.charts[canvasId] = new Chart(ctx, {
      type: 'bar',
      data: {
        labels,
        datasets: [
          {
            type: 'bar',
            label: 'Ventas Directas',
            data: directData,
            backgroundColor: 'rgba(52, 152, 219, 0.8)',
            borderColor: 'rgba(52, 152, 219, 1)',
            borderWidth: 1,
            stack: 'ventas',
            yAxisID: 'y',
            order: 2
          },
          {
            type: 'bar',
            label: 'Remarketing',
            data: remarketingData,
            backgroundColor: 'rgba(46, 204, 113, 0.8)',
            borderColor: 'rgba(46, 204, 113, 1)',
            borderWidth: 1,
            stack: 'ventas',
            yAxisID: 'y',
            order: 2
          },
          {
            type: 'line',
            label: 'Conversión (%)',
            data: conversionRate,
            borderColor: 'rgba(231, 76, 60, 1)',
            backgroundColor: 'rgba(231, 76, 60, 0.1)',
            borderWidth: 2,
            yAxisID: 'y1',
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
              title: function(tooltipItems) {
                return tooltipItems[0].label;
              },
              label: function(context) {
                const idx = context.dataIndex;
                const datasetIndex = context.datasetIndex;
                
                if (datasetIndex === 0) {
                  // Directas
                  const value = directData[idx];
                  const count = directCount[idx];
                  const total = directData[idx] + remarketingData[idx];
                  const percent = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                  return `Directas: $${value.toFixed(2)} (${count} ventas) [${percent}%]`;
                  
                } else if (datasetIndex === 1) {
                  // Remarketing
                  const value = remarketingData[idx];
                  const count = remarketingCount[idx];
                  const total = directData[idx] + remarketingData[idx];
                  const percent = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                  return `Remarketing: $${value.toFixed(2)} (${count} ventas) [${percent}%]`;
                  
                } else {
                  // Conversión
                  return `Conversión: ${conversionRate[idx].toFixed(1)}%`;
                }
              },
              afterBody: function(tooltipItems) {
                const idx = tooltipItems[0].dataIndex;
                const prospects = totalSales[idx];
                const confirmed = directCount[idx] + remarketingCount[idx];
                const total = directData[idx] + remarketingData[idx];
                const conversion = prospects > 0 ? ((confirmed / prospects) * 100).toFixed(1) : 0;
                
                return [
                  '',
                  `Total día: $${total.toFixed(2)}`,
                  `Prospectos: ${prospects}`,
                  `Confirmados: ${confirmed} (${conversion}%)`
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
            position: 'left',
            title: { display: true, text: 'Ingresos ($)' },
            ticks: {
              callback: function(value) {
                return '$' + value.toFixed(0);
              }
            }
          },
          y1: {
            type: 'linear',
            position: 'right',
            beginAtZero: true,
            max: 100,
            title: { display: true, text: 'Conversión (%)' },
            grid: { drawOnChartArea: false },
            ticks: {
              callback: function(value) {
                return value + '%';
              }
            }
          }
        }
      }
    });
  }

  static createProspectsStackedChart(canvasId, labels, notConverted, converted) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return;

    if (this.charts[canvasId]) this.charts[canvasId].destroy();

    this.charts[canvasId] = new Chart(ctx, {
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

  static createMessagesActivityChart(canvasId, labels, totalMessages, newChats, followups) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return;

    if (this.charts[canvasId]) this.charts[canvasId].destroy();

    this.charts[canvasId] = new Chart(ctx, {
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
                  // Total
                  return `${label}: ${value} mensajes`;
                } else if (context.datasetIndex === 1) {
                  // Chats nuevos
                  const percent = totalMessages[idx] > 0 ? ((value / totalMessages[idx]) * 100).toFixed(1) : 0;
                  return `${label}: ${value} (${percent}%)`;
                } else {
                  // Seguimientos
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

  static formatDate(date) {
    const days = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
    const months = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
    return `${days[date.getDay()]} ${date.getDate()} ${months[date.getMonth()]}`;
  }
}

window.productStats = productStats;