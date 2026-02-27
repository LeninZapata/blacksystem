class chat {

  static apis = { client: '/api/client' };

  // Variable configurable: cuántos contactos cargar por página
  static perPage = 30;

  static _page       = 1;
  static _total      = 0;
  static _activeNum  = null;

  // ─── Inicialización ────────────────────────────────────────────────────────

  static init() {
    this._page      = 1;
    this._total     = 0;
    this._activeNum = null;
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

    const url = `${this.apis.client}?sort=dc&order=DESC&per_page=${this.perPage}&page=${this._page}`;

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
    if (el) el.classList.add('active');

    this._activeNum = number;
    this.loadMessages(clientId);
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

  // ─── Helpers privados ──────────────────────────────────────────────────────

  static _itemHtml(c) {
    const number   = c.number ?? '';
    const clientId = c.id ?? '';
    const name     = (c.name ?? '').trim().replace(/'/g, '&#39;');
    const date     = window.bsDate ? bsDate.relativeShort(c.dc) : (c.dc ?? '').substring(0, 10);
    return `
      <div class="bs-chat-item" data-number="${number}" data-client-id="${clientId}" onclick="chat.select('${number}', ${clientId})">
        <div class="bs-chat-item-header">
          <span class="bs-chat-item-number">+${number}</span>
          ${date ? `<span class="bs-chat-item-date">${date}</span>` : ''}
        </div>
        ${name ? `<div class="bs-chat-item-name">${name}</div>` : ''}
      </div>`;
  }
}

window.chat = chat;
