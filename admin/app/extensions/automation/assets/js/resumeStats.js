// resumeStats.js - Resumen de ventas por bot y fecha
(function() {
  'use strict';

  const state = {
    botId: '',
    dateRange: 'today',
    customDate: '',
    products: [],
  };

  // ── Inicializar ──────────────────────────────────────────────────────────
  async function init() {
    await loadBots();
    bindEvents();
    await loadProducts();
  }

  // ── Cargar bots en el select ─────────────────────────────────────────────
  async function loadBots() {
    const select = document.getElementById('resume-filter-bot');
    if (!select) return;

    try {
      const res = await ogApi.get('/api/bot?status=1');
      const bots = res.data || res || [];

      select.innerHTML = '<option value="">Selecciona un bot</option>';
      bots.forEach(bot => {
        const opt = document.createElement('option');
        opt.value = bot.id;
        opt.textContent = bot.name;
        select.appendChild(opt);
      });
    } catch (err) {
      console.error('resumeStats: error cargando bots', err);
    }
  }

  // ── Cargar lista de productos (solo al cambiar bot) ──────────────────────
  async function loadProducts() {
    const container = document.getElementById('resume-products-container');
    if (!container) return;

    container.innerHTML = `<p class='og-text-center og-text-gray-400 og-p-2' style='font-size:0.85rem;'>⏳ Cargando productos...</p>`;

    try {
      let url = '/api/product?status=1&context=infoproductws&sale_type_mode_not=2';
      if (state.botId) url += `&bot_id=${state.botId}`;

      const res = await ogApi.get(url);
      state.products = Array.isArray(res) ? res : (res.data || []);

      renderProductsLayout(container);

      if (state.botId && state.products.length > 0) {
        await loadStats();
      }
    } catch (err) {
      console.error('resumeStats: error cargando productos', err);
      container.innerHTML = `<div class='alert alert-danger og-mt-2'>❌ Error al cargar productos</div>`;
    }
  }

  // ── Cargar stats del endpoint (al cambiar filtros de fecha o bot) ────────
  async function loadStats() {
    if (!state.botId || state.products.length === 0) return;

    // Mostrar ⏳ en los valores mientras carga
    document.querySelectorAll('#resume-products-container .resume-stat-value').forEach(el => {
      el.textContent = '⏳';
    });
    ['resume-balance-ingresos', 'resume-balance-gastos', 'resume-balance-profit', 'resume-balance-chats'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.textContent = '⏳';
    });

    try {
      let url = `/api/profit/summary-by-product?bot_id=${state.botId}&range=${state.dateRange}`;
      if (state.dateRange === 'custom_date' && state.customDate) {
        url += `&date=${state.customDate}`;
      }

      const res = await ogApi.get(url);
      if (!res || !res.success) throw new Error(res?.error || 'Error desconocido');

      // Mapear respuesta por product_id
      const statsMap = {};
      (res.data || []).forEach(item => { statsMap[item.id] = item; });

      const fmt = v => `$${parseFloat(v || 0).toLocaleString('es-MX', { minimumFractionDigits: 2 })}`;

      let totalRevenue = 0, totalSpend = 0, totalChats = 0, totalSalesCount = 0;

      state.products.forEach(product => {
        const row = document.querySelector(`#resume-products-container [data-product-id="${product.id}"]`);
        if (!row) return;

        const s           = statsMap[product.id] || { chats: 0, revenue: 0, sales_count: 0, upsell_revenue: 0, upsell_count: 0, spend: 0 };
        const upsellRev   = parseFloat(s.upsell_revenue || 0);
        const totalRev    = parseFloat(s.revenue || 0) + upsellRev;
        const profit      = totalRev - parseFloat(s.spend || 0);
        const profitColor = profit >= 0 ? 'var(--og-green-600)' : 'var(--og-gray-900)';

        totalRevenue    += totalRev;
        totalSpend      += parseFloat(s.spend || 0);
        totalChats      += parseInt(s.chats || 0);
        totalSalesCount += parseInt(s.sales_count || 0);

        const set = (key, text, color) => {
          const el = row.querySelector(`[data-key="${key}"]`);
          if (!el) return;
          el.textContent = text;
          if (color) el.style.color = color;
        };

        set('ingresos', fmt(s.revenue));
        set('gastos',   fmt(s.spend));
        set('profit',   fmt(profit), profitColor);

        // Chats con formato svg igual que balance header
        const chatEl = row.querySelector('[data-key="chats"]');
        if (chatEl) {
          const conv     = parseInt(s.chats) > 0 ? ((parseInt(s.sales_count) / parseInt(s.chats)) * 100).toFixed(1) : '0.0';
          const chatSvg  = `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;vertical-align:middle;"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>`;
          const checkSvg = `<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="flex-shrink:0;vertical-align:middle;"><polyline points="20 6 9 17 4 12"/></svg>`;
          chatEl.innerHTML = `${chatSvg} ${s.chats} (${s.sales_count} ${checkSvg} / ${conv}%)`;
        }

        // Fila upsell: mostrar solo si hay ingresos por upsell
        const upsellRow = row.querySelector('[data-upsell-row]');
        if (upsellRow) {
          if (upsellRev > 0) {
            upsellRow.style.display = '';
            set('upsell', `${fmt(upsellRev)}  (${s.upsell_count} ✓)`);
          } else {
            upsellRow.style.display = 'none';
          }
        }
      });

      // Balance general
      const totalProfit = totalRevenue - totalSpend;
      const fmtB = v => `$${parseFloat(v).toLocaleString('es-MX', { minimumFractionDigits: 2 })}`;

      const elI = document.getElementById('resume-balance-ingresos');
      const elG = document.getElementById('resume-balance-gastos');
      const elP = document.getElementById('resume-balance-profit');
      const elC = document.getElementById('resume-balance-chats');

      if (elI) elI.textContent = fmtB(totalRevenue);
      if (elG) elG.textContent = fmtB(totalSpend);
      if (elP) {
        elP.textContent  = fmtB(totalProfit);
        elP.style.color  = totalProfit >= 0 ? 'var(--og-green-600)' : 'var(--og-red-600)';
      }
      if (elC) {
        const conversion = totalChats > 0 ? ((totalSalesCount / totalChats) * 100).toFixed(1) : '0.0';
        const chatSvg  = `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>`;
        const checkSvg = `<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="flex-shrink:0;"><polyline points="20 6 9 17 4 12"/></svg>`;
        elC.innerHTML = `${chatSvg} ${totalChats} (${totalSalesCount} ${checkSvg} / ${conversion}%)`;
      }

    } catch (err) {
      console.error('resumeStats: error en loadStats', err);
      document.querySelectorAll('#resume-products-container .resume-stat-value').forEach(el => {
        el.textContent = '—';
      });
    }
  }

  // ── Renderizar layout de secciones por env ───────────────────────────────
  function renderProductsLayout(container) {
    const produccion = state.products.filter(p => p.env === 'P');
    const testeo     = state.products.filter(p => p.env === 'T');
    const sinEnv     = state.products.filter(p => !p.env || (p.env !== 'P' && p.env !== 'T'));

    let html = '';

    if (produccion.length > 0) html += renderEnvSection('🟢 Productos en Producción', produccion, 'produccion');
    if (testeo.length     > 0) html += renderEnvSection('🧪 Productos en Testeo',      testeo,     'testeo');
    if (sinEnv.length     > 0) html += renderEnvSection('📦 Sin clasificar',           sinEnv,     'sin-env');

    if (!html) {
      container.innerHTML = `<p class='og-text-center og-text-gray-400 og-p-2' style='font-size:0.85rem;'>Sin productos activos${state.botId ? ' para este bot' : ''}.</p>`;
      return;
    }

    container.innerHTML = renderBalanceGeneral() + html;
  }

  // ── Sección por entorno ──────────────────────────────────────────────────
  function renderEnvSection(title, products, sectionId) {
    const rows = products.map(p => renderProductRow(p)).join('');
    return `
      <div class="resume-env-section og-mb-3" id="resume-section-${sectionId}">
        <h4 style="margin:0.75rem 0 0.4rem; font-size:1.075rem; font-weight:600; color:#374151;">${title}</h4>
        <div class="resume-product-list">
          ${rows}
        </div>
      </div>
    `;
  }

  // ── Fila de producto ────────────────────────────────────────────────────
  function renderProductRow(product) {
    // Cada stat: label a la izquierda, valor a la derecha (og-flex og-between)
    // 4 stats en cuadrícula 2×2 (og-grid og-cols-2)
    const stats = [
      { icon: '💬', label: 'Chats',    key: 'chats',    color: 'var(--og-gray-800)', bold: false },
      { icon: '💰', label: 'Ingresos', key: 'ingresos', color: 'var(--og-blue-600)',  bold: false },
      { icon: '📢', label: 'Gastos P.',key: 'gastos',   color: 'var(--og-red-600)',   bold: false },
      { icon: '📈', label: 'Profit',   key: 'profit',   color: '',                    bold: true  },
    ];

    const statCells = stats.map(s => `
      <div class="og-flex og-between og-items-center og-bg-gray-50 og-rounded" style="padding:0.22rem 0.45rem;">
        <span style="font-size:0.845rem; color:var(--og-gray-500); white-space:nowrap;">${s.icon} ${s.label}</span>
        <span class="resume-stat-value" data-key="${s.key}"
              style="font-weight:${s.bold ? '700' : '400'}; font-size:0.925rem; color:${s.color}; margin-left:0.5rem; white-space:nowrap;">—</span>
      </div>`);

    // Fila upsell: ocupa ancho completo (col-span-2), oculta por defecto
    const upsellCell = `
      <div data-upsell-row style="display:none; grid-column:span 2; background:rgba(250,245,255,0.8); border-radius:5px; padding:0.22rem 0.45rem;"
           class="og-flex og-between og-items-center og-rounded">
        <span style="font-size:0.845rem; color:var(--og-gray-500); white-space:nowrap;">⬆️ Upsell</span>
        <span class="resume-stat-value" data-key="upsell"
              style="font-weight:700; font-size:0.925rem; color:var(--og-green-600); margin-left:0.5rem; white-space:nowrap;">—</span>
      </div>`;

    return `
      <div class="resume-product-row" data-product-id="${product.id}"
           style="background:#fff; border:1px solid var(--og-gray-200); border-radius:8px; padding:0.55rem 0.7rem; margin-bottom:0.4rem;">
        <div class="og-flex og-between og-items-center" style="margin-bottom:0.35rem; padding-bottom:0.3rem; border-bottom:1px solid var(--og-gray-100);">
          <span style="font-weight:600; font-size:0.975rem; color:var(--og-gray-900);">📦 ${escHtml(product.name)}</span>
        </div>
        <div class="og-grid og-cols-2 og-gap-xs">
          ${statCells[0]}
          ${statCells[1]}
          ${upsellCell}
          ${statCells[2]}
          ${statCells[3]}
        </div>
      </div>`;
  }

  // ── Balance general ──────────────────────────────────────────────────────
  function renderBalanceGeneral() {
    const balanceStats = [
      { id: 'resume-balance-ingresos', icon: '💰', label: 'Total Ingresos', color: 'var(--og-blue-600)' },
      { id: 'resume-balance-gastos',   icon: '📢', label: 'Total Gastos',   color: 'var(--og-red-600)'  },
      { id: 'resume-balance-profit',   icon: '📈', label: 'Profit',         color: 'var(--og-blue-600)' },
    ];

    const cells = balanceStats.map(b => `
      <div class="og-flex og-between og-items-center" style="padding:0.3rem 0.5rem; background:rgba(255,255,255,0.7); border-radius:6px;">
        <span style="font-size:var(--og-font-sm); color:var(--og-gray-500);">${b.icon} ${b.label}</span>
        <span id="${b.id}" style="font-weight:700; font-size:1.045rem; color:${b.color};">$—</span>
      </div>`).join('');

    return `
      <div id="resume-balance-general" class="og-bg-gray-100 og-rounded-lg" style="padding:0.6rem 0.7rem; margin-top:0.6rem; border:1px solid var(--og-gray-200);">
        <div class="og-flex og-between og-items-center" style="margin-bottom:0.4rem;">
          <span style="font-weight:700; font-size:0.975rem; color:var(--og-gray-800);">📊 Balance General</span>
          <span id="resume-balance-chats" style="font-size:0.72rem; color:var(--og-gray-400); display:inline-flex; align-items:center; gap:0.2rem;">—</span>
        </div>
        <div style="display:flex; flex-direction:column; gap:0.2rem;">
          ${cells}
        </div>
      </div>`;
  }

  // ── Utilidad: escapar HTML ───────────────────────────────────────────────
  function escHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  // ── Bindings de eventos ──────────────────────────────────────────────────
  function bindEvents() {
    // Select de bot → recarga productos Y stats
    const botSelect = document.getElementById('resume-filter-bot');
    if (botSelect) {
      botSelect.addEventListener('change', async () => {
        state.botId = botSelect.value;
        await loadProducts(); // internamente llama loadStats() al terminar
      });
    }

    // Radios de rango de fechas → solo recarga stats (no productos)
    document.querySelectorAll('[name="resume_date_range"]').forEach(radio => {
      radio.addEventListener('change', async () => {
        state.dateRange = radio.value;
        const customContainer = document.getElementById('resume-custom-date-container');
        if (customContainer) {
          customContainer.style.display = radio.value === 'custom_date' ? 'block' : 'none';
        }
        if (radio.value !== 'custom_date') {
          state.customDate = '';
          await loadStats();
        }
      });
    });

    // Input fecha específica → solo recarga stats
    const customInput = document.getElementById('resume-custom-date-input');
    if (customInput) {
      customInput.addEventListener('change', async () => {
        state.customDate = customInput.value;
        if (state.customDate) await loadStats();
      });
    }
  }

  // ── API pública ──────────────────────────────────────────────────────────
  window.resumeStats = { init };

})();
 