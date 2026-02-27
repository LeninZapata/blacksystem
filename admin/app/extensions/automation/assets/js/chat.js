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
    console.log('[chat] init() ejecutado');
    const list = document.getElementById('bsChatList');
    console.log('[chat] #bsChatList encontrado:', list);
    this.loadClients(true);
  }

  // ─── Carga de clientes ─────────────────────────────────────────────────────

  static async loadClients(reset = false) {
    const list    = document.getElementById('bsChatList');
    const btnMore = document.getElementById('bsChatLoadMore');
    console.log('[chat] loadClients() - list:', list, '| reset:', reset);
    if (!list) return;

    if (reset) {
      this._page = 1;
      list.innerHTML = '<div class="bs-chat-loading">Cargando...</div>';
    }

    const url = `${this.apis.client}?sort=dc&order=DESC&per_page=${this.perPage}&page=${this._page}`;
    console.log('[chat] fetch url:', url);

    try {
      const json = await ogModule('api').get(url);
      console.log('[chat] respuesta raw:', json);
      if (!json.success) throw new Error('not success');

      // La API devuelve {success, data: [...]} directamente (array plano)
      const items = Array.isArray(json.data) ? json.data : (json.data?.data ?? []);
      this._total = json.data?.total ?? (Array.isArray(json.data) ? json.data.length : 0);
      console.log('[chat] items:', items.length, '| total:', this._total);

      if (reset) list.innerHTML = '';

      if (items.length === 0 && reset) {
        list.innerHTML = '<div class="bs-chat-empty-msg">Sin contactos registrados</div>';
        if (btnMore) btnMore.style.display = 'none';
        return;
      }

      items.forEach(c => list.insertAdjacentHTML('beforeend', this._itemHtml(c)));
      console.log('[chat] items renderizados');

      // Mostrar "Cargar más" si aún hay registros
      const totalLoaded = this._page * this.perPage;
      if (btnMore) btnMore.style.display = totalLoaded < this._total ? 'block' : 'none';

    } catch (e) {
      console.error('[chat] error en loadClients:', e);
      if (reset) list.innerHTML = '<div class="bs-chat-empty-msg" style="color:var(--og-red-500)">Error al cargar contactos</div>';
    }
  }

  static loadMore() {
    this._page++;
    this.loadClients(false);
  }

  // ─── Seleccionar contacto ──────────────────────────────────────────────────

  static select(number) {
    document.querySelectorAll('.bs-chat-item').forEach(el => el.classList.remove('active'));

    const el = document.querySelector(`.bs-chat-item[data-number="${number}"]`);
    if (el) el.classList.add('active');

    this._activeNum = number;

    const main = document.getElementById('bsChatMain');
    if (main) {
      main.innerHTML = '<div class="bs-chat-empty-state"><span class="icon">🚧</span><p>En construcción...</p></div>';
    }
  }

  // ─── Helpers privados ──────────────────────────────────────────────────────

  static _itemHtml(c) {
    const number = c.number ?? '';
    const name   = (c.name ?? '').trim().replace(/'/g, '&#39;');
    return `
      <div class="bs-chat-item" data-number="${number}" onclick="chat.select('${number}')">
        <div class="bs-chat-item-number">+${number}</div>
        ${name ? `<div class="bs-chat-item-name">${name}</div>` : ''}
      </div>`;
  }
}
