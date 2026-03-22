class chat {

  static apis = { clientChat: '/api/client/list-chat', bot: '/api/bot', receipt: '/api/chat/receipt' };

  // ─── Config pública (editable) ─────────────────────────────────────────────
  static perPage                = 30;   // contactos por página
  static heartbeatInterval      = 20;   // segundos entre polls de mensajes del chat activo
  static heartbeatMaxPolls      = 20;   // máximo de polls por sesión de chat abierto
  static clientHeartbeatInterval = 20;  // segundos entre polls de la lista de contactos
  static clientHeartbeatMaxPolls = 50;  // máximo de polls de la lista de contactos

  // ─── Estado interno ────────────────────────────────────────────────────────
  static _page          = 1;
  static _total         = 0;
  static _activeNum     = null;
  static _activeId      = null;
  static _activeBotNum  = null;  // Se obtiene del primer mensaje al cargar
  static _activeBotId        = null;  // Filtro de bot seleccionado (opcional)
  static _activeProductId    = null;  // Filtro de producto seleccionado (opcional)
  static _botsById           = {};    // Mapa botId → bot object (cargado en _initBotFilter)
  static _clientCache        = new Map(); // clientId → client data (para el header del panel)
  static _filterNumber       = '';    // Filtro de número de cliente
  static _onlyConfirmed       = false; // Filtro "solo ventas confirmadas"
  static _onlyToday           = false; // Filtro "solo hoy"

  static _heartbeatTimer  = null;
  static _heartbeatCount  = 0;
  static _lastMsgId       = null; // ID del último mensaje conocido en el chat activo
  static _activeExpiry    = null; // Expiry de ventana open_chat del chat activo (ms epoch)

  static _clientHeartbeatTimer = null;
  static _clientHeartbeatCount = 0;
  static _clientsKnown         = new Map(); // clientId → { last_message_at, unread_count }

  // ─── Inicialización ────────────────────────────────────────────────────────

  static init() {
    this._stopHeartbeat();
    this._stopClientHeartbeat();
    this._page            = 1;
    this._total           = 0;
    this._activeNum       = null;
    this._activeId        = null;
    this._activeBotNum    = null;
    this._activeBotId     = null;
    this._activeProductId = null;
    this._filterNumber    = '';
    this._clientCache     = new Map();
    this._onlyConfirmed   = false;
    this._onlyToday       = false;
    this._lastMsgId       = null;
    this._activeExpiry    = null;
    this._clientsKnown    = new Map();

    // Configurar timezone del usuario en bsDate para mostrar fechas correctas.
    // Solo setear si viene en userPreferences (sesión actual con timezone guardado).
    // Si no viene (sesión antigua sin timezone), queda null y _initBotFilter lo completa.
    if (window.bsDate) {
      const auth = ogModule('auth');
      bsDate.timezone = auth?.userPreferences?.timezone || null;
    }

    this._initBotFilter();
  }

  // Genera el HTML de ventas reutilizable (item + panel header)
  static _salesHtml(salesList) {
    if (!salesList || salesList.length === 0) return '';
    return salesList.map(s => {
      const ok   = s.process_status === 'sale_confirmed';
      const icon = ok ? '✓' : '⊙';
      const nm   = (s.product_name ?? '').replace(/</g, '&lt;');
      const amt  = ok && s.amount != null
        ? ` <span class="bs-sale-amt">$${parseFloat(s.amount) % 1 === 0 ? parseFloat(s.amount) : parseFloat(s.amount).toFixed(2)}</span>`
        : '';
      return `<span class="bs-sale-item ${ok ? 'bs-sale-ok' : 'bs-sale-pending'}"><span class="bs-sale-icon">${icon}</span> ${nm}${amt}</span>`;
    }).join('');
  }

  // Convierte un country_code (ej: "EC") en emoji de bandera
  static _countryFlag(countryCode) {
    if (!countryCode) return '🌐';
    return countryCode.toUpperCase().replace(/./g, c => String.fromCodePoint(0x1F1E6 - 65 + c.charCodeAt(0)));
  }

  // Carga bots del usuario e inserta el select como filtro opcional en el sidebar
  static async _initBotFilter() {
    const header = document.querySelector('.bs-chat-sidebar-header');
    if (!header) { this.loadClients(true); this._startClientHeartbeat(); return; }

    try {
      const json = await ogModule('api').get(`${this.apis.bot}?per_page=50`);
      const bots = json.data?.data ?? json.data ?? [];
      bots.forEach(b => { this._botsById[String(b.id)] = b; });

      // Fallback: si userPreferences.timezone no vino en la sesión (sesión antigua),
      // usar el timezone del bot como fuente confiable desde DB.
      if (bots.length > 0 && window.bsDate && !bsDate.timezone) {
        const botTz = bots[0].config?.timezone;
        if (botTz) bsDate.timezone = botTz;
      }

      const panel = document.getElementById('bsChatFilterPanel');
      if (panel && bots.length > 1) {
        // Más de 1 bot: mostrar select de bot + select de producto (oculto hasta elegir bot)
        panel.insertAdjacentHTML('beforeend',
          `<select id="bsChatBotSelect" onchange="chat.onBotFilter(this.value)">
            <option value="">Todos los bots</option>
            ${bots.map(b => `<option value="${b.id}">${this._countryFlag(b.country_code)} ${b.name} · +${b.number}</option>`).join('')}
          </select>
          <div id="bsChatProductWrap" class="og-mt-1" style="display:none">
            <select id="bsChatProductSelect" onchange="chat.onProductFilter(this.value)">
              <option value="">Selecciona un bot primero</option>
            </select>
          </div>`
        );
      } else if (panel && bots.length === 1) {
        // Solo 1 bot: auto-seleccionarlo y mostrar directamente el select de productos
        this._activeBotId = bots[0].id;
        panel.insertAdjacentHTML('beforeend',
          `<div id="bsChatProductWrap" class="og-mt-1">
            <select id="bsChatProductSelect" onchange="chat.onProductFilter(this.value)">
              <option value="">Cargando productos...</option>
            </select>
          </div>`
        );
        this._loadProductSelect(this._activeBotId);
      }
    } catch (_) { /* sin bots disponibles, igual continúa */ }

    this.loadClients(true);
    this._startClientHeartbeat();
  }

  // Cambia el filtro de bot y recarga la lista
  static onBotFilter(botId) {
    this._activeBotId     = botId ? parseInt(botId) : null;
    this._activeProductId = null;
    this._clientsKnown    = new Map();
    this._loadProductSelect(this._activeBotId);
    this.loadClients(true);
  }

  // Carga el select de productos según el bot seleccionado
  static async _loadProductSelect(botId) {
    const wrap   = document.getElementById('bsChatProductWrap');
    const select = document.getElementById('bsChatProductSelect');
    if (!wrap || !select) return;

    if (!botId) {
      select.innerHTML = `<option value="">Selecciona un bot primero</option>`;
      wrap.style.display = 'none';
      return;
    }

    wrap.style.display = 'block';
    select.innerHTML = `<option value="">Cargando productos...</option>`;

    try {
      const json     = await ogModule('api').get(`/api/product?bot_id=${botId}&status=1&sort=sale_type_mode&order=ASC&per_page=100`);
      const products = json.data?.data ?? json.data ?? [];

      if (products.length === 0) {
        select.innerHTML = `<option value="">Sin productos activos</option>`;
        return;
      }

      // Agrupar por sale_type_mode; dentro de cada grupo: producción antes que testeo
      const modeLabels = { 1: 'Principal', 2: 'Solo upsell', 3: 'Principal & Upsell' };
      const groups     = { 1: [], 2: [], 3: [] };

      for (const p of products) {
        const mode = parseInt(p.sale_type_mode) || 3;
        const label = p.env === 'T' ? `[testeo] ${p.name}` : p.name;
        groups[mode]?.push({ id: p.id, label, isTest: p.env === 'T' });
      }

      // Dentro de cada grupo: producción primero, testeo al final
      for (const mode of [1, 2, 3]) {
        groups[mode].sort((a, b) => a.isTest - b.isTest);
      }

      let html = `<option value="">Todos los productos</option>`;
      for (const mode of [1, 2, 3]) {
        if (groups[mode].length === 0) continue;
        html += `<optgroup label="${modeLabels[mode]}">`;
        html += groups[mode].map(p => `<option value="${p.id}">${p.label}</option>`).join('');
        html += `</optgroup>`;
      }
      select.innerHTML = html;
    } catch (_) {
      select.innerHTML = `<option value="">Error cargando productos</option>`;
    }
  }

  // Cambia el filtro de producto y recarga la lista
  static onProductFilter(productId) {
    this._activeProductId = productId ? parseInt(productId) : null;
    this._clientsKnown    = new Map();
    this.loadClients(true);
  }

  // Abre/cierra el panel de filtros (bot + solo ventas)
  static toggleFilterPanel() {
    const panel = document.getElementById('bsChatFilterPanel');
    const btn   = document.getElementById('bsChatFilterBtn');
    if (!panel) return;
    const isOpen = panel.style.display !== 'none';
    panel.style.display = isOpen ? 'none' : 'block';
    btn?.classList.toggle('active', !isOpen);
  }

  // Cambia el filtro de solo ventas confirmadas
  static onConfirmedFilter(checked) {
    this._onlyConfirmed = checked;
    this._clientsKnown  = new Map();
    this.loadClients(true);
  }

  // Ejecuta búsqueda por número al presionar Enter
  static onNumberKeyDown(event) {
    if (event.key === 'Enter') {
      event.preventDefault();
      this.onNumberFilter();
    }
  }

  // Aplica el filtro de número de cliente
  static onNumberFilter() {
    const input = document.getElementById('bsChatNumberInput');
    this._filterNumber = input?.value?.trim() ?? '';
    this._clientsKnown = new Map();
    this.loadClients(true);
  }

  // Cambia el filtro de solo chats del día de hoy
  static onTodayFilter(checked) {
    this._onlyToday    = checked;
    this._clientsKnown = new Map();
    this.loadClients(true);
  }

  // ─── Heartbeat de lista de contactos ───────────────────────────────────────

  static _startClientHeartbeat() {
    this._stopClientHeartbeat();
    this._clientHeartbeatCount = 0;
    this._clientHeartbeatTimer = setInterval(async () => {
      if (this._clientHeartbeatCount >= this.clientHeartbeatMaxPolls) { this._stopClientHeartbeat(); return; }
      this._clientHeartbeatCount++;
      await this._syncClients();
    }, this.clientHeartbeatInterval * 1000);
  }

  static _stopClientHeartbeat() {
    if (this._clientHeartbeatTimer) {
      clearInterval(this._clientHeartbeatTimer);
      this._clientHeartbeatTimer = null;
    }
  }

  // Fetch silencioso: detecta nuevos mensajes / nuevos contactos e inyecta en DOM
  static async _syncClients() {
    try {
      const botParam       = this._activeBotId     ? `&bot_id=${this._activeBotId}`                              : '';
      const productParam   = this._activeProductId ? `&product_id=${this._activeProductId}`                      : '';
      const numberParam    = this._filterNumber     ? `&number_search=${encodeURIComponent(this._filterNumber)}` : '';
      const confirmedParam  = this._onlyConfirmed  ? `&confirmed_only=1`                                         : '';
      const todayParam      = this._onlyToday      ? `&today_only=1`                                             : '';
      const url  = `${this.apis.clientChat}?per_page=${this.perPage}&page=1${botParam}${productParam}${numberParam}${confirmedParam}${todayParam}`;
      const json = await ogModule('api').get(url);
      if (!json.success) return;

      const items = json.data?.data ?? [];
      const list  = document.getElementById('bsChatList');
      if (!list) return;

      items.forEach(async c => {
        const cId    = String(c.id ?? '');
        const known  = this._clientsKnown.get(cId);
        const el     = list.querySelector(`.bs-chat-item[data-client-id="${cId}"]`);

        if (!known) {
          // Registrar en mapa siempre; insertar en DOM solo si realmente no existe
          this._clientsKnown.set(cId, { last_message_at: c.last_message_at, unread_count: c.unread_count });
          if (!el) list.insertBefore(this._itemNode(c), list.firstChild);
          return;
        }

        const dateChanged   = known.last_message_at !== c.last_message_at;
        const unreadChanged = String(known.unread_count) !== String(c.unread_count);

        if (!dateChanged && !unreadChanged) return;

        // Actualizar estado conocido
        this._clientsKnown.set(cId, { last_message_at: c.last_message_at, unread_count: c.unread_count });

        // Si el chat está activo y hay nuevos mensajes o unread, marcar como leído automáticamente
        if (String(this._activeId) === cId && (dateChanged || unreadChanged) && parseInt(c.unread_count ?? 0) > 0) {
          // Llamar al endpoint para marcar como leído
          await ogModule('api').post(`/api/client/${cId}/read`, { bot_id: c.chat_bot_id ?? this._activeBotId });
          // Quitar badge y clase unread del DOM
          if (el) {
            el.classList.remove('unread');
            const oldBadge = el.querySelector('.bs-chat-unread-badge');
            oldBadge?.remove();
          }
          // También actualizar el objeto conocido
          this._clientsKnown.set(cId, { last_message_at: c.last_message_at, unread_count: 0 });
          return;
        }

        if (dateChanged && el) {
          // Nuevo mensaje: reemplazar elemento y moverlo al tope
          const isActive = el.classList.contains('active');
          const newNode  = this._itemNode(c);
          if (isActive) newNode.classList.add('active');
          el.remove();
          list.insertBefore(newNode, list.firstChild);
          return;
        }

        // Solo cambió unread (sin nueva fecha): actualizar badge in-place
        if (unreadChanged && el) {
          const unread   = parseInt(c.unread_count ?? 0);
          const right    = el.querySelector('.bs-chat-item-right');
          const oldBadge = el.querySelector('.bs-chat-unread-badge');
          oldBadge?.remove();
          if (unread > 0) {
            right?.insertAdjacentHTML('beforeend', `<span class="bs-chat-unread-badge">${unread > 99 ? '99+' : unread}</span>`);
            el.classList.add('unread');
          } else {
            el.classList.remove('unread');
          }
        }
      });
    } catch (_) { /* silencioso */ }
  }

  // Botón manual de actualizar: recarga toda la lista como al inicio
  static refreshClients() {
    const btn = document.querySelector('.bs-chat-refresh-btn');
    if (btn) btn.classList.add('spinning');
    this._clientsKnown = new Map();
    this.loadClients(true).finally(() => {
      if (btn) btn.classList.remove('spinning');
    });
  }

  // ─── Heartbeat ─────────────────────────────────────────────────────────────

  static _startHeartbeat(clientId) {
    this._stopHeartbeat();
    this._heartbeatCount = 0;
    this._heartbeatTimer = setInterval(async () => {
      if (clientId !== this._activeId)         { this._stopHeartbeat(); return; }
      if (this._heartbeatCount >= this.heartbeatMaxPolls) { this._stopHeartbeat(); return; }
      this._heartbeatCount++;
      await this._syncMessages(clientId);
    }, this.heartbeatInterval * 1000);
  }

  static _stopHeartbeat() {
    if (this._heartbeatTimer) {
      clearInterval(this._heartbeatTimer);
      this._heartbeatTimer = null;
    }
  }

  // Fetch silencioso: obtiene todos los mensajes, detecta nuevos (id > _lastMsgId),
  // actualiza cache, quita burbujas optimistas y hace append de los nuevos.
  static async _syncMessages(clientId) {
    if (!clientId) return;
    try {
      const url     = `/api/chat?client_id=${clientId}&sort=tc&order=ASC&per_page=500`;
      const json    = await ogModule('api').get(url);
      if (!json.success || clientId !== this._activeId) return;

      const allMsgs = json.data ?? [];
      const lastId  = this._lastMsgId;
      const newMsgs = lastId !== null
        ? allMsgs.filter(m => (m.id ?? 0) > lastId)
        : [];

      // Actualizar cache siempre
      ogCache.setMemory(this._cacheMsgs(clientId), allMsgs, 10 * 60 * 1000);

      // Avanzar el puntero
      if (allMsgs.length > 0) {
        this._lastMsgId = allMsgs[allMsgs.length - 1].id ?? lastId;
      }

      if (newMsgs.length === 0) return;

      // Quitar burbuja(s) optimistas (si existen)
      document.querySelectorAll('.bs-msg-optimistic').forEach(el => el.remove());

      // Append nuevos mensajes sin recargar el chat
      const main = document.getElementById('bsChatMain');
      if (main) {
        newMsgs.forEach(m => main.insertAdjacentHTML('beforeend', this._msgHtml(m)));
        main.scrollTop = main.scrollHeight;
        this._loadReceiptImages(main);
        this._bindImageLightbox(main);
      }

      // Si llegó mensaje del cliente (type P), invalidar cache de ventana open_chat
      if (newMsgs.some(m => m.type === 'P')) {
        ogCache.delete(this._cacheOpen(clientId));
        const botId = allMsgs.find(m => m.bot_id)?.bot_id ?? null;
        if (botId) this._loadOpenChatBar(clientId, botId);
      }
    } catch (_) { /* silencioso */ }
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

    const botParam       = this._activeBotId     ? `&bot_id=${this._activeBotId}`                              : '';
    const productParam   = this._activeProductId ? `&product_id=${this._activeProductId}`                      : '';
    const numberParam    = this._filterNumber     ? `&number_search=${encodeURIComponent(this._filterNumber)}` : '';
    const confirmedParam  = this._onlyConfirmed  ? `&confirmed_only=1`                                         : '';
    const todayParam      = this._onlyToday      ? `&today_only=1`                                             : '';
    const url = `${this.apis.clientChat}?per_page=${this.perPage}&page=${this._page}${botParam}${productParam}${numberParam}${confirmedParam}${todayParam}`;

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
        const cId = String(c.id ?? '');
        // Registrar en el mapa de conocidos
        this._clientsKnown.set(cId, { last_message_at: c.last_message_at, unread_count: c.unread_count });
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

  // ─── Caché ─────────────────────────────────────────────────────────────────
  // Claves: chat_msgs_{clientId} · chat_open_{clientId}
  // TTL  : mensajes 10 min, ventana 5 min — solo memoryCache (se limpia al refrescar)

  static _cacheMsgs(clientId)  { return `chat_msgs_${clientId}`; }
  static _cacheOpen(clientId)  { return `chat_open_${clientId}`; }

  static _invalidateClient(clientId) {
    ogCache.delete(this._cacheMsgs(clientId));
    ogCache.delete(this._cacheOpen(clientId));
  }

  // ─── Seleccionar contacto ──────────────────────────────────────────────────

  static select(number, clientId) {
    document.querySelectorAll('.bs-chat-item').forEach(el => el.classList.remove('active'));

    const el = document.querySelector(`.bs-chat-item[data-number="${number}"]`);
    const name = el ? (el.dataset.name ?? '') : '';

    // Comprobar ANTES de quitar la clase si hay mensajes sin leer
    const hasUnread = el?.classList.contains('unread') ?? false;

    if (el) {
      el.classList.add('active');
      el.classList.remove('unread');
      const badge = el.querySelector('.bs-chat-unread-badge');
      if (badge) badge.remove();
    }

    this._activeNum    = number;
    this._activeId     = clientId;
    this._activeBotNum  = null; // se actualiza al cargar mensajes
    this._activeExpiry  = null; // se actualiza al cargar barra open_chat

    // Si había mensajes sin leer significa que llegaron nuevos → invalidar cache
    if (hasUnread) this._invalidateClient(clientId);

    // Marcar como leído en el servidor — bot_id viene del item del sidebar
    const itemEl = document.querySelector(`.bs-chat-item[data-client-id="${clientId}"]`);
    const botIdForRead = itemEl?.dataset.botId ?? this._activeBotId;
    ogModule('api').post(`/api/client/${clientId}/read`, { bot_id: botIdForRead }).catch(() => {});

    // Mobile: mostrar panel de mensajes
    document.querySelector('.bs-chat')?.classList.add('mobile-chat-open');

    this._showHeaderLoading(number, name);
    this.loadMessages(clientId);
    this._startHeartbeat(clientId); // inicia polls cada N segundos (máx heartbeatMaxPolls veces)
  }

  // Mobile: volver al listado de contactos
  static backToList() {
    document.querySelector('.bs-chat')?.classList.remove('mobile-chat-open');
  }

  static _showHeaderLoading(number, name) {
    const h = document.getElementById('bsChatHeader');
    if (!h) return;
    const clientData = this._clientCache.get(String(this._activeId)) ?? {};
    const clientFlag = this._countryFlag(clientData.country_code ?? '');
    h.className = 'bs-chat-panel-header';
    h.innerHTML = `
      <div class="bs-chat-header-row">
        <button class="bs-chat-back-btn" onclick="chat.backToList()" title="Volver">&#8592;</button>
        <div class="bs-chat-header-main">
          ${name ? `<span class="bs-chat-header-name">${name}</span>` : ''}
          <span class="bs-chat-header-bot"><span class="bs-chat-header-flag">${clientFlag}</span> +${number}</span>
        </div>
        <div class="bs-chat-header-sub">
          <span class="bs-chat-header-bot">Cargando...</span>
          <div class="bs-chat-open-bar-wrap" id="bsChatOpenBarWrap" style="display:none">
            <div class="bs-chat-open-bar-track"><div class="bs-chat-open-bar" id="bsChatOpenBar"></div></div>
            <span class="bs-chat-open-bar-label"></span>
          </div>
        </div>
      </div>
    `;
  }

  static _showHeader(number, name, botNumber, botId = null) {
    const h = document.getElementById('bsChatHeader');
    if (!h) return;
    h.className = 'bs-chat-panel-header';

    const clientData = this._clientCache.get(String(this._activeId)) ?? {};
    const clientFlag = this._countryFlag(clientData.country_code ?? '');
    const botData    = botId ? (this._botsById[String(botId)] ?? {}) : {};
    const botFlag    = this._countryFlag(botData.country_code ?? '');
    const botInfo    = botNumber ? `Bot: +${botNumber}` : 'Sin bot asociado';
    const salesHtml  = this._salesHtml(clientData.sales ?? []);

    h.innerHTML = `
      <div class="bs-chat-header-row">
        <button class="bs-chat-back-btn" onclick="chat.backToList()" title="Volver">&#8592;</button>
        <div class="bs-chat-header-main">
          ${name ? `<span class="bs-chat-header-name">${name}</span>` : ''}
          <span class="bs-chat-header-bot"><span class="bs-chat-header-flag">${clientFlag}</span> +${number}</span>
        </div>
        <div class="bs-chat-header-sub">
          <span class="bs-chat-header-bot"><span class="bs-chat-header-flag">${botFlag}</span> ${botInfo}</span>
          <div class="bs-chat-open-bar-wrap" id="bsChatOpenBarWrap" style="display:none">
            <div class="bs-chat-open-bar-track"><div class="bs-chat-open-bar" id="bsChatOpenBar"></div></div>
            <span class="bs-chat-open-bar-label"></span>
          </div>
        </div>
      </div>
      ${salesHtml ? `<div class="bs-chat-header-sales">${salesHtml}</div>` : ''}
    `;
  }

  static async _loadOpenChatBar(clientId, botId) {
    if (!clientId || !botId) return;
    try {
      // Intentar desde caché primero (5 min, solo memoria)
      let cached = ogCache.getMemory(this._cacheOpen(clientId));

      if (!cached) {
        const json   = await ogModule('api').get(`/api/client/${clientId}/open-chat/${botId}`);
        const expiry = json?.data?.expiry    ?? null;
        const tc     = json?.data?.tc        ?? null;
        const srvNow = json?.data?.server_now ?? null;
        if (expiry && tc) {
          cached = { expiry, tc, server_now: srvNow };
          ogCache.setMemory(this._cacheOpen(clientId), cached, 5 * 60 * 1000);
        }
      }

      const wrap = document.getElementById('bsChatOpenBarWrap');
      const bar  = document.getElementById('bsChatOpenBar');
      if (!wrap || !bar || !cached?.expiry || !cached?.tc) return;

      const { expiry, tc } = cached;

      // Calcular progreso real usando inicio (tc), actual (hora local), final (expiry)
      const toMs = s => typeof s === 'number' ? s * 1000 : new Date(s.replace(' ', 'T')).getTime();
      const startMs  = toMs(tc); // tc es timestamp (segundos)
      const expiryMs = toMs(expiry); // expiry es string fecha
      const nowMs    = Date.now(); // Hora local del navegador

      const totalMs = expiryMs - startMs;
      const elapsedMs = Math.max(0, nowMs - startMs);
      const pctConsumed = totalMs > 0 ? Math.min(100, Math.round(elapsedMs / totalMs * 100)) : 0;
      const pctLeft = 100 - pctConsumed;
      const label = window.bsDate ? bsDate.timeRemaining(expiry) : '';

      // Guardar el expiry en ms para validar al enviar (usando offset con reloj local)
      this._activeExpiry = { expiryMs, serverOffset: 0 };

      bar.style.width = pctConsumed + '%';
      wrap.style.display = 'flex';
      const labelEl = wrap.querySelector('.bs-chat-open-bar-label');
      if (labelEl) labelEl.textContent = label;
      wrap.title = `Ventana gratuita: ${pctLeft}% restante (expira ${expiry})`;
    } catch (_) { /* silencioso */ }
  }

  // ─── Mensajes ──────────────────────────────────────────────────────────────

  static _renderMsgs(main, msgs, clientId) {
    const botNumber = msgs.length > 0 ? (msgs[0].bot_number ?? null) : null;
    const botId     = msgs.length > 0 ? (msgs[0].bot_id     ?? null) : null;
    this._activeBotNum = botNumber;

    // Actualizar puntero de último mensaje conocido
    this._lastMsgId = msgs.length > 0 ? (msgs[msgs.length - 1].id ?? null) : null;

    const activeEl = document.querySelector('.bs-chat-item.active');
    const name = activeEl ? (activeEl.dataset.name ?? '') : '';
    this._showHeader(this._activeNum, name, botNumber, botId);
    this._loadOpenChatBar(clientId, botId);

    const compose = document.getElementById('bsChatCompose');
    if (compose) compose.style.display = botNumber ? 'flex' : 'none';

    if (msgs.length === 0) {
      main.innerHTML = '<div class="bs-chat-empty-state"><span class="bs-chat-empty-icon">💬</span><p>Sin mensajes registrados</p></div>';
      return;
    }

    main.innerHTML = msgs.map(m => this._msgHtml(m)).join('');
    main.scrollTop = main.scrollHeight;
    this._loadReceiptImages(main);
    this._bindImageLightbox(main);
  }

  // Carga imágenes de recibos autenticadas como blob para evitar exponer el endpoint
  static _receiptCache = new Map(); // filename → objectURL (cache en memoria por sesión)

  static async _loadReceiptImages(container) {
    const imgs = container.querySelectorAll('img[data-receipt]');
    if (!imgs.length) return;

    const token = ogModule('auth')?.getToken?.() ?? null;
    const headers = token ? { Authorization: `Bearer ${token}` } : {};

    imgs.forEach(async img => {
      const filename = img.dataset.receipt;

      // Usar caché si ya fue cargado antes
      if (this._receiptCache.has(filename)) {
        img.src = this._receiptCache.get(filename);
        return;
      }

      try {
        const res = await fetch(`${this.apis.receipt}/${filename}`, { headers });
        if (!res.ok) throw new Error('not ok');
        const blob = await res.blob();
        const url  = URL.createObjectURL(blob);
        this._receiptCache.set(filename, url);
        img.src = url;
      } catch {
        const resume = img.closest('[data-resume]')?.dataset?.resume ?? '';
        img.parentElement.innerHTML = `<span class="bs-msg-expired-media">📷 ${resume || 'Imagen no disponible'}</span>`;
      }
    });
  }

  static _bindImageLightbox(container) {
    container.querySelectorAll('img[data-receipt]').forEach(img => {
      img.addEventListener('click', () => this._openLightbox(img.src));
    });
  }

  static _openLightbox(src) {
    const box = document.createElement('div');
    box.className = 'bs-img-lightbox';
    box.innerHTML = `<button class="bs-img-lightbox-close">✕</button><img src="${src}">`;

    const close = () => box.remove();
    box.querySelector('.bs-img-lightbox-close').onclick = close;
    box.addEventListener('click', e => { if (e.target === box) close(); });
    document.addEventListener('keydown', function esc(e) {
      if (e.key === 'Escape') { close(); document.removeEventListener('keydown', esc); }
    });

    document.body.appendChild(box);
  }

  static async loadMessages(clientId) {
    const main = document.getElementById('bsChatMain');
    if (!main) return;

    // Intentar desde caché primero
    const cached = ogCache.getMemory(this._cacheMsgs(clientId));
    if (cached) {
      this._renderMsgs(main, cached, clientId);
      return;
    }

    main.innerHTML = '<div class="bs-msg-loading">Cargando mensajes...</div>';

    try {
      const url  = `/api/chat?client_id=${clientId}&sort=tc&order=ASC&per_page=500`;
      const json = await ogModule('api').get(url);

      if (!json.success) throw new Error('not success');

      const msgs = json.data ?? [];

      // Guardar en caché (10 min, solo memoria)
      ogCache.setMemory(this._cacheMsgs(clientId), msgs, 10 * 60 * 1000);

      this._renderMsgs(main, msgs, clientId);

    } catch (e) {
      main.innerHTML = '<div class="bs-chat-empty-state" style="color:var(--og-red-500)">Error al cargar mensajes</div>';
    }
  }

  static _msgHtml(m, opts = {}) {
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
        if (action === 'start_sale' && meta.msgs_total != null) {
          const sent   = meta.msgs_sent   ?? 0;
          const total  = meta.msgs_total  ?? 0;
          const ok     = sent === total;
          const badge  = ok
            ? `✅ ${sent}/${total} msgs`
            : `⚠️ ${sent}/${total} msgs`;
          displayText = `${text}\n${badge}`;
        }
      }
      const escaped = displayText.replace(/</g, '&lt;').replace(/>/g, '&gt;');
      const formatted = escaped
        .replace(/\*(.*?)\*/g, '<strong>$1</strong>')
        .replace(/_(.*?)_/g, '<em>$1</em>')
        .replace(/~(.*?)~/g, '<s>$1</s>')
        .replace(/`(.*?)`/g, '<code>$1</code>')
        .replace(/\n/g, '<br>');
      content = `<span>${formatted}</span>`;
    } else if (format === 'image') {
      const meta = m.metadata ?? {};
      const receiptFile = meta.receipt_file ?? null;
      const resume = meta.description?.resume ?? meta.resume ?? '';
      const caption = (meta.caption ?? '') || (typeof text === 'string' && text.startsWith('{') ? '' : text);
      if (receiptFile) {
        content = `<div class="bs-msg-media" data-resume="${resume}"><img data-receipt="${receiptFile}" alt="imagen"></div>${caption ? `<span>${caption}</span>` : ''}`;
      } else {
        const imgUrl = meta.image_url ?? '';
        content = imgUrl
          ? `<div class="bs-msg-media"><img src="${imgUrl}" alt="imagen" onerror="this.parentElement.innerHTML='<span class=\\'bs-msg-expired-media\\'>📷 ${resume || 'Imagen no disponible'}</span>'"></div>${caption ? `<span>${caption}</span>` : ''}`
          : `<span>📷 ${resume || 'Imagen'}</span>`;
      }
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

    const typeClass    = type === 'B' ? 'bs-msg-b' : (type === 'S' ? 'bs-msg-s' : 'bs-msg-p');
    const optimistic   = opts?.optimistic ? ' bs-msg-optimistic' : '';
    const msgIdAttr    = m.id ? ` data-msg-id="${m.id}"` : '';

    return `<div class="bs-msg ${typeClass}${optimistic}"${msgIdAttr}>
      <div class="bs-msg-bubble">
        ${content}
        <span class="bs-msg-time">${relTime}</span>
      </div>
    </div>`;
  }
  // ─── Envío de mensajes ────────────────────────────────────────────────────────────────

  static openOptions() {
    ogModal.open('automation|forms/chat-options-form', {
      title: '⚙️ Opciones del chat',
      width: '90%',
      maxWidth: '580px',
      showFooter: false,
      afterRender: () => chat._renderOptionsModal()
    });
  }

  static async _renderOptionsModal() {
    const container = document.getElementById('chat-options-sales');
    if (!container) return;

    const clientData = this._clientCache.get(String(this._activeId)) ?? {};
    const botId      = clientData.chat_bot_id ?? null;

    if (!this._activeId) {
      container.innerHTML = '<p style="color:#9ca3af;text-align:center;padding:1rem">No hay cliente activo.</p>';
      return;
    }

    try {
      const json  = await ogModule('api').get(`/api/sale?client_id=${this._activeId}&bot_id=${botId}&status=1`);
      const sales = json?.data ?? [];

      if (!sales.length) {
        container.innerHTML = '<p style="color:#9ca3af;text-align:center;padding:1rem">Sin ventas registradas.</p>';
        return;
      }

      const statusOpts = (current) => [
        { v: 'initiated',      l: 'En proceso' },
        { v: 'sale_confirmed', l: 'Venta confirmada' },
        { v: 'cancelled',      l: 'Cancelado' }
      ].map(o => `<option value="${o.v}" ${o.v === current ? 'selected' : ''}>${o.l}</option>`).join('');

      const rows = sales.map(s => {
        const confirmed = s.process_status === 'sale_confirmed';
        const amtVal    = s.local_billed_amount != null ? parseFloat(s.local_billed_amount).toFixed(2) : '';
        const name      = (s.product_name ?? '').replace(/</g, '&lt;');
        return `
          <tr data-sale-id="${s.id}">
            <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${name}">${name}</td>
            <td style="min-width:90px">
              <input type="number" class="chat-sale-amount" value="${amtVal}" step="0.01" placeholder="—"
                style="width:100%;border:1px solid #e5e7eb;border-radius:4px;padding:3px 6px;font-size:0.8125rem;background:${confirmed ? '#fff' : '#f9fafb'};color:${confirmed ? '#111827' : '#9ca3af'}"
                ${confirmed ? '' : 'disabled'}>
            </td>
            <td style="min-width:130px">
              <select class="chat-sale-status" onchange="chat.onSaleStatusChange(this)"
                style="width:100%;border:1px solid #e5e7eb;border-radius:4px;padding:3px 6px;font-size:0.8125rem;background:#fff">
                ${statusOpts(s.process_status)}
              </select>
            </td>
            <td style="text-align:center;width:36px">
              <button class="btn btn-success btn-sm chat-sale-save-btn" onclick="chat.updateSale(${s.id}, this)" title="Guardar"
                style="padding:3px 7px;font-size:0.75rem">
                <svg width="12" height="12" viewBox="0 0 16 16" fill="currentColor">
                  <path d="M13.854 3.646a.5.5 0 0 1 0 .708l-7 7a.5.5 0 0 1-.708 0l-3.5-3.5a.5.5 0 1 1 .708-.708L6.5 10.293l6.646-6.647a.5.5 0 0 1 .708 0z"/>
                </svg>
              </button>
            </td>
          </tr>`;
      }).join('');

      container.innerHTML = `
        <h6 style="font-size:0.8125rem;font-weight:600;margin:0 0 0.5rem;color:#374151">Ventas actuales</h6>
        <div class="og-table-responsive">
          <table class="og-table og-table-compact">
            <thead>
              <tr>
                <th>Producto</th>
                <th>Valor local</th>
                <th>Estado</th>
                <th></th>
              </tr>
            </thead>
            <tbody>${rows}</tbody>
          </table>
        </div>`;

    } catch (_) {
      container.innerHTML = '<p style="color:#ef4444;text-align:center;padding:1rem">Error al cargar ventas.</p>';
    }
  }

  static onSaleStatusChange(select) {
    const row      = select.closest('tr');
    const amtInput = row?.querySelector('.chat-sale-amount');
    if (!amtInput) return;
    const confirmed = select.value === 'sale_confirmed';
    amtInput.disabled = !confirmed;
    amtInput.style.background = confirmed ? '#fff' : '#f9fafb';
    amtInput.style.color      = confirmed ? '#111827' : '#9ca3af';
  }

  static async updateSale(saleId, btn) {
    const row     = btn.closest('tr');
    const status  = row.querySelector('.chat-sale-status')?.value;
    const amtRaw  = row.querySelector('.chat-sale-amount')?.value;

    const payload = { process_status: status };
    if (status === 'sale_confirmed' && amtRaw !== '') {
      payload.local_billed_amount = parseFloat(amtRaw);
    }

    btn.disabled = true;
    try {
      await ogModule('api').put(`/api/sale/${saleId}`, payload);

      // Reconstruir el JSON del chat para que el bot tenga el contexto actualizado
      const clientData = this._clientCache.get(String(this._activeId)) ?? {};
      const botId      = clientData.chat_bot_id ?? null;
      if (this._activeNum && botId) {
        ogModule('api').get(`/api/chat/rebuild/${this._activeNum}/${botId}`).catch(() => {});
      }

      btn.classList.add('btn-success-temp');
      setTimeout(() => { btn.disabled = false; btn.classList.remove('btn-success-temp'); }, 1500);
    } catch (_) {
      btn.disabled = false;
      if (window.ogToast) ogToast.error('Error al actualizar la venta.');
    }
  }

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

    // Validar ventana open_chat antes de enviar
    if (this._activeExpiry) {
      const { expiryMs, serverOffset } = this._activeExpiry;
      const serverNow = Date.now() + serverOffset;
      if (serverNow >= expiryMs) {
        alert('⚠️ Ventana de conversación expirada.\nNo es posible enviar mensajes libres.\nEl cliente debe escribir primero para reabrir la ventana.');
        return;
      }
    }

    // Deshabilitar mientras se envía
    input.disabled = true;
    if (btn) { btn.disabled = true; btn.textContent = 'Enviando...'; }

    const clientIdAtSend = this._activeId;

    // ── Burbuja optimista: aparece al instante sin esperar la red ──
    const nowStr  = new Date().toISOString().replace('T', ' ').substring(0, 19);
    const fakeMsg = { type: 'B', format: 'text', message, dc: nowStr };
    const main    = document.getElementById('bsChatMain');
    if (main) {
      main.insertAdjacentHTML('beforeend', this._msgHtml(fakeMsg, { optimistic: true }));
      main.scrollTop = main.scrollHeight;
    }

    try {
      const json = await ogModule('api').post('/api/chat/manual-send', {
        bot_number:    this._activeBotNum,
        client_number: this._activeNum,
        client_id:     clientIdAtSend,
        message
      });

      if (!json.success) throw new Error(json.error ?? 'Error al enviar');

      input.value = '';
      // Fetch silencioso: reemplaza la burbuja optimista con el mensaje real de la BD
      await this._syncMessages(clientIdAtSend);

    } catch (e) {
      // Quitar la burbuja optimista si el envío falló
      document.querySelector('.bs-msg-optimistic')?.remove();
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
    const botId    = c.chat_bot_id ?? '';
    const name      = (c.name ?? '').trim().replace(/'/g, '&#39;');
    const flag      = this._countryFlag(c.country_code ?? '');
    const salesList = c.sales ?? [];
    this._clientCache.set(String(clientId), c);

    // Icono de advertencia: último mensaje fue del cliente (P), formato relevante y > 1 min sin respuesta
    const relevantFormats   = ['text', 'image', 'emoji', 'audio'];
    const lastMsgType       = c.last_msg_type ?? '';              // tipo del último mensaje (P/B/S)
    const lastClientMsgAt   = c.last_client_message_at  ?? '';   // dc del último mensaje P
    const lastClientFormat  = c.last_client_message_format ?? '';
    const lastClientMs      = lastClientMsgAt ? new Date(lastClientMsgAt.replace(' ', 'T') + 'Z').getTime() : 0;
    const elapsedMin        = lastClientMs ? (Date.now() - lastClientMs) / 60000 : 0;
    const lastIsFromClient  = lastMsgType === 'P';
    const isRelevantFormat  = relevantFormats.includes(lastClientFormat);
    const showWarning       = lastIsFromClient && isRelevantFormat && elapsedMin > 1;
    const warnClass         = showWarning ? ' bs-chat-item--no-reply' : '';
    const warningIcon       = showWarning
      ? `<span class="bs-chat-warn-icon" title="Sin respuesta del bot (${Math.floor(elapsedMin)} min)">⚠️</span>`
      : '';
    const unread      = parseInt(c.unread_count ?? 0);
    const dateStr     = c.last_message_at ?? c.dc ?? '';
    const date        = window.bsDate ? bsDate.relativeShort(dateStr) : dateStr.substring(0, 10);
    const lastSysMsgAction = c.last_sys_msg_action ?? '';
    const isFollowup      = unread > 0 && lastMsgType === 'S' && lastSysMsgAction === 'followup_sent';
    const isLastFollowup  = lastMsgType === 'S' && lastSysMsgAction === 'followup_sent';
    const unreadCls       = unread > 0 ? (isFollowup ? ' unread-followup' : ' unread') : '';
    const badge       = unread > 0
      ? `<span class="bs-chat-unread-badge${isFollowup ? ' bs-chat-unread-badge--followup' : ''}">${unread > 99 ? '99+' : unread}</span>`
      : '';
    const todayAmt = c.today_amount ? parseFloat(c.today_amount) : null;
    const prevAmt  = c.prev_amount  ? parseFloat(c.prev_amount)  : null;
    const fmtAmt   = v => `+$${v % 1 === 0 ? v : v.toFixed(2)}`;
    const saleBadge = [
      prevAmt  ? `<span class="bs-chat-sale-badge bs-chat-sale-badge--old">${fmtAmt(prevAmt)}</span>`  : '',
      todayAmt ? `<span class="bs-chat-sale-badge">${fmtAmt(todayAmt)}</span>` : '',
    ].join('');
    return `
      <div class="bs-chat-item${unreadCls}${warnClass}" data-number="${number}" data-client-id="${clientId}" data-bot-id="${botId}" data-name="${name}" onclick="chat.select('${number}', ${clientId})">
        <div class="bs-chat-item-header">
          <div class="bs-chat-item-left">
            ${warningIcon}
            ${name ? `<span class="bs-chat-item-number">${name}</span>` : `<span class="bs-chat-item-number">${flag} +${number}</span>`}${isLastFollowup ? `<span class="bs-chat-followup-icon" title="Último mensaje: seguimiento">📨</span>` : ''}
            ${saleBadge}
          </div>
          <div class="bs-chat-item-right">
            ${date ? `<span class="bs-chat-item-date">${date}</span>` : ''}
            ${badge}
          </div>
        </div>
        <div class="bs-chat-item-meta">
          <div class="bs-chat-item-sales">
            ${salesList.length > 0 ? this._salesHtml(salesList) : '<span class="bs-chat-item-noproduct">sin venta</span>'}
          </div>
          ${name ? `<span class="bs-chat-item-name">${flag} +${number}</span>` : ''}
        </div>
      </div>`;
  }
}

window.chat = chat;