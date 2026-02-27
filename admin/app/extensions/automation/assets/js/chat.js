class chat {

  static apis = { client: '/api/client' };

  // Variable configurable: cuántos contactos cargar por página
  static perPage = 30;

  static _page          = 1;
  static _total         = 0;
  static _activeNum     = null;
  static _activeId      = null;
  static _activeBotNum  = null;  // Se obtiene del primer mensaje al cargar

  // ─── Inicialización ────────────────────────────────────────────────────────

  static init() {
    this._page         = 1;
    this._total        = 0;
    this._activeNum    = null;
    this._activeId     = null;
    this._activeBotNum = null;
    this.loadClients(true);
  }

  // ─── Carga de clientes ─────────────────────────────────────────────────────

  static async loadClients(reset = false) {
    const list = document.getElementById('bsChatList');
    if (!list) return;

    if (reset) {
      this._page = 1;
      // Preservar el botón, limpiar el resto
      const btnRef = document.getElementById('bsChatLoadMore');
      list.innerHTML = '<div class="bs-chat-loading" id="bsChatLoading">Cargando...</div>';
      if (btnRef) list.appendChild(btnRef);
    }

    const url = `${this.apis.client}?sort=last_message_at&order=DESC&per_page=${this.perPage}&page=${this._page}`;

    try {
      const json = await ogModule('api').get(url);
      if (!json.success) throw new Error('not success');

      const items  = json.data?.data ?? [];
      // Solo actualizar el total en la primera página para no sobreescribirlo
      if (this._page === 1) {
        this._total = json.data?.total ?? items.length;
      }

      // Quitar loading
      document.getElementById('bsChatLoading')?.remove();

      // Obtener referencia actualizada del botón (puede haberse movido en el DOM)
      const btnMore = document.getElementById('bsChatLoadMore');

      if (items.length === 0 && reset) {
        list.insertAdjacentHTML('afterbegin', '<div class="bs-chat-empty-msg">Sin contactos registrados</div>');
        if (btnMore) btnMore.style.display = 'none';
        return;
      }

      // Insertar items ANTES del botón para que siempre quede al final
      items.forEach(c => {
        if (btnMore) list.insertBefore(this._itemNode(c), btnMore);
        else         list.insertAdjacentHTML('beforeend', this._itemHtml(c));
      });

      const totalLoaded = this._page * this.perPage;
      if (btnMore) btnMore.style.display = totalLoaded < this._total ? 'block' : 'none';

    } catch (e) {
      document.getElementById('bsChatLoading')?.remove();
      if (reset) list.insertAdjacentHTML('afterbegin', '<div class="bs-chat-empty-msg" style="color:var(--og-red-500)">Error al cargar contactos</div>');
    }
  }

  static loadMore() {
    this._page++;
    this.loadClients(false);
  }

  static _itemNode(c) {
    const div = document.createElement('div');
    div.innerHTML = this._itemHtml(c);
    return div.firstElementChild;
  }

  // ─── Seleccionar contacto ──────────────────────────────────────────────────

  static select(number, clientId) {
    document.querySelectorAll('.bs-chat-item').forEach(el => el.classList.remove('active'));

    const el = document.querySelector(`.bs-chat-item[data-number="${number}"]`);
    const name = el ? el.querySelector('.bs-chat-item-name')?.textContent?.trim() : '';
    if (el) {
      el.classList.add('active');
      el.classList.remove('unread');
      const badge = el.querySelector('.bs-chat-unread-badge');
      if (badge) badge.remove();
    }

    this._activeNum    = number;
    this._activeId     = clientId;
    this._activeBotNum = null; // se actualiza al cargar mensajes

    // Marcar como leído en el servidor (sin bloquear UI)
    ogModule('api').post(`/api/client/${clientId}/read`, {}).catch(() => {});

    this._showHeaderLoading(number, name);
    this.loadMessages(clientId);
  }

  static _showHeaderLoading(number, name) {
    const h = document.getElementById('bsChatHeader');
    if (!h) return;
    h.className = 'bs-chat-panel-header';
    h.innerHTML = `
      <span class="bs-chat-header-number">+${number}</span>
      ${name ? `<span class="bs-chat-header-name">${name}</span>` : ''}
      <span class="bs-chat-header-bot">Cargando...</span>
    `;
  }

  static _showHeader(number, name, botNumber) {
    const h = document.getElementById('bsChatHeader');
    if (!h) return;
    h.className = 'bs-chat-panel-header';
    const botInfo = botNumber ? `Bot: +${botNumber}` : 'Sin bot asociado';
    h.innerHTML = `
      <div class="bs-chat-header-row">
        <span class="bs-chat-header-number">+${number}</span>
        ${name ? `<span class="bs-chat-header-name">${name}</span>` : ''}
        <span class="bs-chat-header-bot">${botInfo}</span>
      </div>
      <div class="bs-chat-open-bar-wrap" id="bsChatOpenBarWrap" style="display:none">
        <div class="bs-chat-open-bar" id="bsChatOpenBar"></div>
      </div>
    `;
  }

  static async _loadOpenChatBar(clientId, botId) {
    if (!clientId || !botId) return;
    try {
      const json = await ogModule('api').get(`/api/client/${clientId}/open-chat/${botId}`);
      const expiry = json?.data?.expiry ?? null;
      const wrap   = document.getElementById('bsChatOpenBarWrap');
      const bar    = document.getElementById('bsChatOpenBar');
      if (!wrap || !bar || !expiry) return;

      const now        = Date.now();
      const expiryMs   = new Date(expiry.replace(' ', 'T')).getTime();
      const windowMs   = 72 * 3600 * 1000;                       // referencia: 72h
      const remaining  = Math.max(0, expiryMs - now);
      const pctLeft    = Math.min(100, Math.round(remaining / windowMs * 100));
      const pctConsumed = 100 - pctLeft;

      bar.style.width = pctConsumed + '%';
      wrap.style.display = 'block';
      wrap.title = `Ventana gratuita: ${pctLeft}% restante (expira ${expiry})`;
    } catch (_) { /* silencioso */ }
  }

  // ─── Mensajes ──────────────────────────────────────────────────────────────

  static async loadMessages(clientId) {
    const main = document.getElementById('bsChatMain');
    if (!main) return;

    main.innerHTML = '<div class="bs-msg-loading">Cargando mensajes...</div>';

    try {
      const url  = `/api/chat?client_id=${clientId}&sort=tc&order=ASC&per_page=500`;
      const json = await ogModule('api').get(url);

      if (!json.success) throw new Error('not success');

      const msgs = json.data ?? [];

      // Extraer bot_number del primer mensaje
      const botNumber = msgs.length > 0 ? (msgs[0].bot_number ?? null) : null;
      this._activeBotNum = botNumber;

      // Actualizar header con bot_number conocido
      const activeEl = document.querySelector('.bs-chat-item.active');
      const name = activeEl ? activeEl.querySelector('.bs-chat-item-name')?.textContent?.trim() : '';
      const botId = msgs.length > 0 ? (msgs[0].bot_id ?? null) : null;
      this._showHeader(this._activeNum, name, botNumber);
      this._loadOpenChatBar(clientId, botId);

      // Mostrar/ocultar compose
      const compose = document.getElementById('bsChatCompose');
      if (compose) compose.style.display = botNumber ? 'flex' : 'none';

      if (msgs.length === 0) {
        main.innerHTML = '<div class="bs-chat-empty-state"><span class="bs-chat-empty-icon">💬</span><p>Sin mensajes registrados</p></div>';
        return;
      }

      main.innerHTML = msgs.map(m => this._msgHtml(m)).join('');
      // Scroll al final
      main.scrollTop = main.scrollHeight;

    } catch (e) {
      main.innerHTML = '<div class="bs-chat-empty-state" style="color:var(--og-red-500)">Error al cargar mensajes</div>';
    }
  }

  static _msgHtml(m) {
    const type    = m.type ?? 'P';   // S, B, P
    const format  = m.format ?? 'text';
    const text    = m.message ?? '';
    const relTime = window.bsDate ? bsDate.relative(m.dc) : ((m.dc ?? '').substring(0, 10) + ' ' + (m.dc ?? '').substring(11, 16));

    let content = '';
    if (format === 'text') {
      // Prefijo especial para mensajes de sistema según action
      let displayText = text;
      if (type === 'S') {
        const meta   = m.metadata ?? {};
        const action = meta.action ?? '';
        if (action === 'followup_sent') displayText = `📨 Seguimiento: ${text}`;
      }
      const escaped = displayText.replace(/</g, '&lt;').replace(/>/g, '&gt;');
      const formatted = escaped
        .replace(/\*(.*?)\*/g, '<strong>$1</strong>')
        .replace(/\n/g, '<br>');
      content = `<span>${formatted}</span>`;
    } else if (format === 'image') {
      const meta = m.metadata ?? {};
      const imgUrl = meta.image_url ?? '';
      const caption = (meta.caption ?? '') || (typeof text === 'string' && text.startsWith('{') ? '' : text);
      content = imgUrl
        ? `<div class="bs-msg-media"><img src="${imgUrl}" alt="imagen" onerror="this.style.display='none'"></div>${caption ? `<span>${caption}</span>` : ''}`
        : `<span>📷 Imagen</span>`;
    } else if (format === 'audio') {
      content = `<span>🎵 Audio</span>`;
    } else if (format === 'video') {
      content = `<span>🎬 Video</span>`;
    } else if (format === 'document') {
      content = `<span>📄 Documento</span>`;
    } else {
      // Para sistema: message puede ser JSON u objeto
      let display = text;
      if (!display && m.metadata) {
        const meta = m.metadata ?? {};
        display = meta.action ?? JSON.stringify(meta).substring(0, 80);
      }
      content = `<span>${(display ?? '').toString().replace(/</g,'&lt;').replace(/>/g,'&gt;')}</span>`;
    }

    const typeClass = type === 'B' ? 'bs-msg-b' : (type === 'S' ? 'bs-msg-s' : 'bs-msg-p');

    return `<div class="bs-msg ${typeClass}">
      <div class="bs-msg-bubble">
        ${content}
        <span class="bs-msg-time">${relTime}</span>
      </div>
    </div>`;
  }
  // ─── Envío de mensajes ────────────────────────────────────────────────────────────────

  static onKeyDown(event) {
    // Enter sin shift = enviar; Shift+Enter = nueva línea
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault();
      this.sendMessage();
    }
  }

  static async sendMessage() {
    const input  = document.getElementById('bsChatInput');
    const btn    = document.querySelector('.bs-chat-send-btn');
    const message = (input?.value ?? '').trim();

    if (!message) return;
    if (!this._activeBotNum) {
      alert('No se encontró el número de bot para este contacto.');
      return;
    }

    // Deshabilitar mientras se envía
    input.disabled = true;
    if (btn) { btn.disabled = true; btn.textContent = 'Enviando...'; }

    try {
      const json = await ogModule('api').post('/api/chat/manual-send', {
        bot_number:    this._activeBotNum,
        client_number: this._activeNum,
        client_id:     this._activeId,
        message
      });

      if (!json.success) throw new Error(json.error ?? 'Error al enviar');

      input.value = '';
      // Recargar mensajes para mostrar el enviado
      await this.loadMessages(this._activeId);

    } catch (e) {
      alert('Error: ' + e.message);
    } finally {
      input.disabled = false;
      if (btn) { btn.disabled = false; btn.textContent = 'Enviar'; }
      input.focus();
    }
  }
  // ─── Helpers privados ──────────────────────────────────────────────────────

  static _itemHtml(c) {
    const number   = c.number ?? '';
    const clientId = c.id ?? '';
    const name     = (c.name ?? '').trim().replace(/'/g, '&#39;');
    const product  = (c.last_product_name ?? '').trim().replace(/</g, '&lt;');
    const unread   = parseInt(c.unread_count ?? 0);
    const dateStr  = c.last_message_at ?? c.dc ?? '';
    const date     = window.bsDate ? bsDate.relativeShort(dateStr) : dateStr.substring(0, 10);
    const unreadCls = unread > 0 ? ' unread' : '';
    const badge    = unread > 0 ? `<span class="bs-chat-unread-badge">${unread > 99 ? '99+' : unread}</span>` : '';
    return `
      <div class="bs-chat-item${unreadCls}" data-number="${number}" data-client-id="${clientId}" onclick="chat.select('${number}', ${clientId})">
        <div class="bs-chat-item-header">
          <span class="bs-chat-item-number">+${number}</span>
          <div class="bs-chat-item-right">
            ${date ? `<span class="bs-chat-item-date">${date}</span>` : ''}
            ${badge}
          </div>
        </div>
        <div class="bs-chat-item-meta">
          <span class="bs-chat-item-product">${product || '<span class="bs-chat-item-noproduct">sin venta</span>'}</span>
          ${name ? `<span class="bs-chat-item-name">${name}</span>` : ''}
        </div>
      </div>`;
  }
}

window.chat = chat;
