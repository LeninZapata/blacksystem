class infoproductLinks {
  static currentBotId = null;
  static bots = [];

  static async init() {
    await this.loadBots();
  }

  static async loadBots() {
    try {
      const res = await ogApi.get('/api/bot?status=1');
      if (!res || !res.success) return;

      this.bots = res.data || [];

      const select = document.getElementById('links-filter-bot');
      if (!select) return;

      select.innerHTML = '<option value="">Seleccionar bot...</option>';
      this.bots.forEach(bot => {
        const opt = document.createElement('option');
        opt.value = bot.id;
        opt.textContent = `${bot.name} (${bot.number})`;
        select.appendChild(opt);
      });

      if (this.bots.length === 1) {
        select.value = this.bots[0].id;
        await this.onBotChange(this.bots[0].id);
      }
    } catch (e) {
      ogLogger?.error('ext:infoproduct', 'Error al cargar bots:', e);
    }
  }

  static async onBotChange(botId) {
    this.currentBotId = botId || null;
    const container = document.getElementById('links-products-container');
    if (!container) return;

    if (!botId) {
      container.innerHTML = `<p class='og-text-center og-text-gray-500 og-p-4'>Selecciona un bot para ver los links</p>`;
      return;
    }

    container.innerHTML = `<p class='og-text-center og-text-gray-500 og-p-4'>⏳ Cargando...</p>`;

    try {
      const res = await ogApi.get(`/api/product/links?bot_id=${botId}`);
      if (!res || !res.success) {
        container.innerHTML = `<p class='og-text-center og-text-gray-500 og-p-4'>Error al cargar los productos</p>`;
        return;
      }

      const products = res.data || [];

      if (!products.length) {
        container.innerHTML = `<p class='og-text-center og-text-gray-500 og-p-4'>No hay productos con links configurados para este bot</p>`;
        return;
      }

      container.innerHTML = products.map(p => this.renderProduct(p)).join('');
    } catch (e) {
      ogLogger?.error('ext:infoproduct', 'Error al cargar links:', e);
      container.innerHTML = `<p class='og-text-center og-text-gray-500 og-p-4'>Error al cargar los datos</p>`;
    }
  }

  static renderProduct(product) {
    const links = product.fb_source_ids || [];

    const linksHtml = links.length
      ? links.map((item, i) => {
          const fbLink  = item.link     ? `<a href="${item.link}" target="_blank" style="color:#1877f2;font-weight:500;word-break:break-all;">🔵 Facebook</a>` : '';
          const igLink  = item.link_ig  ? `<a href="${item.link_ig}" target="_blank" style="color:#e1306c;font-weight:500;word-break:break-all;">📸 Instagram</a>` : '';
          const srcId   = item.source_id ? `<span style="font-size:0.75rem;color:#6b7280;display:block;margin-top:2px;">ID: ${item.source_id}</span>` : '';
          return `
            <div style="padding:8px 10px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:6px;">
              <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;">
                ${fbLink}
                ${igLink}
              </div>
              ${srcId}
            </div>`;
        }).join('')
      : `<p style="color:#9ca3af;font-size:0.85rem;margin:4px 0;">Sin links configurados</p>`;

    return `
      <div style="border:1px solid #e5e7eb;border-radius:8px;padding:12px 14px;margin-bottom:10px;background:#fff;">
        <div style="font-weight:600;font-size:0.95rem;margin-bottom:8px;color:#111827;">📦 ${product.name}</div>
        ${linksHtml}
      </div>`;
  }
}

window.infoproductLinks = infoproductLinks;
