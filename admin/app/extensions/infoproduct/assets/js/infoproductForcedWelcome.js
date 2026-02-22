/**
 * infoproductForcedWelcome.js
 * Maneja el tab "Bienvenida Forzada" dentro de la sección Herramientas de infoproductos.
 * Permite seleccionar un bot, un producto activo de ese bot, ingresar un número de teléfono
 * y ejecutar el flujo de bienvenida forzado para ese contacto.
 */
class infoproductForcedWelcome {

  // Cache de datos de bots (id → objeto bot, incluyendo country_code)
  static botsCache = {};

  // country_code del bot actualmente seleccionado
  static currentCountryCode = null;

  // Mapa ISO 3166-1 alpha-2 → prefijo numérico (los más usados en LATAM)
  static COUNTRY_PREFIXES = {
    'EC': '593',
    'PE': '51',
    'CO': '57',
    'MX': '52',
    'AR': '54',
    'BR': '55',
    'CL': '56',
    'VE': '58',
    'BO': '591',
    'PY': '595',
    'UY': '598',
    'GT': '502',
    'HN': '504',
    'SV': '503',
    'NI': '505',
    'CR': '506',
    'PA': '507',
    'DO': '1',
    'CU': '53',
    'US': '1',
    'ES': '34',
  };

  /**
   * Se llama al cargar el tab.
   * Carga los datos de bots en caché y adjunta los event listeners.
   */
  static async init() {
    ogLogger.debug('ext:infoproduct:forcedWelcome', 'Inicializando Bienvenida Forzada');
    await this.loadBotsCache();
    this.attachListeners();
  }

  /**
   * Carga todos los bots activos en caché para tener acceso a country_code
   * sin necesidad de consultar la API cada vez que cambia el select.
   */
  static async loadBotsCache() {
    try {
      const response = await ogApi.get('/api/bot?status=1');
      const bots = Array.isArray(response) ? response : (response.data || []);

      this.botsCache = {};
      bots.forEach(bot => {
        this.botsCache[bot.id] = bot;
      });

      ogLogger.debug('ext:infoproduct:forcedWelcome', `${bots.length} bots cargados en caché`);
    } catch (error) {
      ogLogger.error('ext:infoproduct:forcedWelcome', 'Error cargando caché de bots', error);
    }
  }

  /**
   * Adjunta los event listeners al formulario.
   */
  static attachListeners() {
    // -- Select de bot --
    const botSelect = document.querySelector('[name="bot_id"]');
    if (botSelect) {
      botSelect.addEventListener('change', (e) => this.onBotChange(e.target.value));

      // Si ya hay un valor seleccionado al iniciar, cargamos productos
      if (botSelect.value) {
        this.onBotChange(botSelect.value);
      }
    }

    // -- Input de teléfono --
    const phoneInput = document.querySelector('[name="phone"]');
    if (phoneInput) {
      phoneInput.addEventListener('input', () => this.onPhoneInput(phoneInput.value));
      phoneInput.addEventListener('blur',  () => this.onPhoneInput(phoneInput.value));
    }
  }

  /**
   * Se dispara cuando cambia el bot seleccionado.
   * Actualiza el country_code actual y carga los productos activos del bot.
   *
   * @param {string|number} botId
   */
  static async onBotChange(botId) {
    // Actualizar country_code del bot seleccionado
    if (botId && this.botsCache[botId]) {
      this.currentCountryCode = this.botsCache[botId].country_code || null;
      ogLogger.debug(
        'ext:infoproduct:forcedWelcome',
        `Bot seleccionado: ${this.botsCache[botId].name} | country_code: ${this.currentCountryCode}`
      );
    } else {
      this.currentCountryCode = null;
    }

    // Re-validar el teléfono con el nuevo country_code
    const phoneInput = document.querySelector('[name="phone"]');
    if (phoneInput && phoneInput.value) {
      this.onPhoneInput(phoneInput.value);
    }

    // Cargar productos activos del bot
    await this.loadProducts(botId);
  }

  /**
   * Carga los productos activos del bot indicado y rellena el select #product_id.
   *
   * @param {string|number} botId
   */
  static async loadProducts(botId) {
    const productSelect = document.querySelector('[name="product_id"]');
    if (!productSelect) return;

    // Estado de carga
    productSelect.innerHTML = '<option value="">⏳ Cargando productos...</option>';
    productSelect.disabled = true;

    if (!botId) {
      productSelect.innerHTML = '<option value="">Primero selecciona un bot...</option>';
      productSelect.disabled = false;
      return;
    }

    try {
      const response = await ogApi.get(`/api/product?bot_id=${botId}&status=1&context=infoproductws`);
      const products = Array.isArray(response) ? response : (response.data || []);

      productSelect.innerHTML = '';

      if (products.length === 0) {
        productSelect.innerHTML = '<option value="">No hay productos activos para este bot</option>';
      } else {
        // Opción vacía inicial
        const emptyOpt = document.createElement('option');
        emptyOpt.value = '';
        emptyOpt.textContent = 'Selecciona un producto...';
        productSelect.appendChild(emptyOpt);

        products.forEach(product => {
          const opt = document.createElement('option');
          opt.value = product.id;
          opt.textContent = product.name + (product.price ? ` — $${parseFloat(product.price).toFixed(2)}` : '');
          productSelect.appendChild(opt);
        });
      }

      productSelect.disabled = false;
      ogLogger.debug('ext:infoproduct:forcedWelcome', `${products.length} productos cargados`);

    } catch (error) {
      ogLogger.error('ext:infoproduct:forcedWelcome', 'Error cargando productos', error);
      productSelect.innerHTML = '<option value="">❌ Error al cargar productos</option>';
      productSelect.disabled = false;
    }
  }

  /**
   * Se dispara en cada keystroke del input de teléfono.
   * Muestra una previsualización del número normalizado o un mensaje de error.
   *
   * @param {string} rawValue
   */
  static onPhoneInput(rawValue) {
    const previewEl   = document.getElementById('fw-phone-preview');
    const normalEl    = document.getElementById('fw-phone-normalized');
    const errorEl     = document.getElementById('fw-phone-error');
    const errorMsgEl  = document.getElementById('fw-phone-error-msg');

    if (!previewEl || !normalEl || !errorEl) return;

    // Ocultar ambos si el campo está vacío
    if (!rawValue || rawValue.trim() === '') {
      previewEl.style.display = 'none';
      errorEl.style.display   = 'none';
      return;
    }

    const result = this.normalizePhone(rawValue, this.currentCountryCode);

    if (result.valid) {
      normalEl.textContent    = result.normalized;
      previewEl.style.display = 'block';
      errorEl.style.display   = 'none';
    } else {
      if (errorMsgEl) errorMsgEl.textContent = result.error;
      previewEl.style.display = 'none';
      errorEl.style.display   = 'block';
    }
  }

  /**
   * Normaliza un número de teléfono al formato internacional sin símbolo "+".
   * Acepta cualquier formato que el usuario pueda escribir:
   *   - "+593 978745575"
   *   - "+593978745575"
   *   - "593 978745575"
   *   - "0978745575"
   *   - "978745575"
   *   - "+(593) 97 8745575"
   *
   * @param {string} raw            Número tal como lo escribió el usuario
   * @param {string|null} countryCode  Código ISO del país del bot (ej: "EC")
   * @returns {{ valid: boolean, normalized?: string, error?: string }}
   */
  static normalizePhone(raw, countryCode) {
    // 1. Obtener el prefijo numérico del país
    const prefix = countryCode
      ? (this.COUNTRY_PREFIXES[countryCode.toUpperCase()] || null)
      : null;

    // 2. Limpiar: quitar todo excepto dígitos
    const digits = raw.replace(/\D/g, '');

    if (digits.length === 0) {
      return { valid: false, error: 'El número no contiene dígitos válidos' };
    }

    // 3. Si tenemos el prefijo del país, normalizamos teniendo eso en cuenta
    if (prefix) {
      let normalized = digits;

      if (normalized.startsWith('00' + prefix)) {
        // "00593..." → "593..."
        normalized = normalized.slice(2);
      } else if (normalized.startsWith(prefix)) {
        // Ya tiene el prefijo → dejar como está
        normalized = normalized;
      } else if (normalized.startsWith('0')) {
        // "09..." → quitar el 0 inicial y agregar prefijo
        normalized = prefix + normalized.slice(1);
      } else {
        // Número local sin 0 inicial → agregar prefijo directamente
        normalized = prefix + normalized;
      }

      // Validación mínima de longitud (prefijo + al menos 7 dígitos locales)
      if (normalized.length < prefix.length + 7) {
        return { valid: false, error: `Número demasiado corto (mínimo ${prefix.length + 7} dígitos totales)` };
      }

      return { valid: true, normalized };
    }

    // 4. Sin prefijo de país conocido: solo validamos que tenga al menos 7 dígitos
    if (digits.length < 7) {
      return { valid: false, error: 'El número es demasiado corto (mínimo 7 dígitos)' };
    }

    // Si el número comienza con 00 (marcación internacional), quitamos los dos ceros
    let normalized = digits.startsWith('00') ? digits.slice(2) : digits;

    return { valid: true, normalized };
  }

  /**
   * Ejecuta la acción de bienvenida forzada.
   * Valida el formulario, normaliza el teléfono y llama al endpoint.
   *
   * @param {string} formId  ID del formulario inyectado por el sistema de acciones
   */
  static async execute(formId) {
    // ── Validaciones básicas ──────────────────────────────────────────
    const botSelect     = document.querySelector('[name="bot_id"]');
    const productSelect = document.querySelector('[name="product_id"]');
    const phoneInput    = document.querySelector('[name="phone"]');

    const botId     = botSelect     ? botSelect.value     : null;
    const productId = productSelect ? productSelect.value : null;
    const rawPhone  = phoneInput    ? phoneInput.value    : '';

    if (!botId) {
      ogComponent('toast').error('Debes seleccionar un bot');
      return;
    }

    if (!productId) {
      ogComponent('toast').error('Debes seleccionar un producto');
      return;
    }

    if (!rawPhone || rawPhone.trim() === '') {
      ogComponent('toast').error('Debes ingresar un número de teléfono');
      return;
    }

    const phoneResult = this.normalizePhone(rawPhone, this.currentCountryCode);

    if (!phoneResult.valid) {
      ogComponent('toast').error(phoneResult.error || 'Número de teléfono inválido');
      return;
    }

    // ── Confirmación ─────────────────────────────────────────────────
    const bot         = this.botsCache[botId] || { name: botId };
    const productName = productSelect.options[productSelect.selectedIndex]?.text || productId;

    const confirmMsg =
      `¿Confirmas ejecutar la bienvenida forzada?\n\n` +
      `Bot:      ${bot.name}\n` +
      `Producto: ${productName}\n` +
      `Teléfono: ${phoneResult.normalized}`;

    if (!confirm(confirmMsg)) return;

    // ── Deshabilitar botón y disparar petición sin esperar respuesta ──
    const executeBtn = document.querySelector('.og-statusbar [data-action*="execute"], .og-statusbar button');
    const originalText = executeBtn ? executeBtn.textContent : null;

    if (executeBtn) {
      executeBtn.disabled     = true;
      executeBtn.textContent  = '📨 Enviando...';
    }

    // Fire-and-forget: no esperamos la respuesta (puede tardar 20s+)
    ogApi.post('/api/product/force-welcome', {
      bot_id:     parseInt(botId),
      product_id: parseInt(productId),
      phone:      phoneResult.normalized,
    }).catch(error => {
      ogLogger.error('ext:infoproduct:forcedWelcome', 'Error en force-welcome', error);
    });

    // Limpiar campo de teléfono y previews para evitar reenvíos accidentales
    if (phoneInput) phoneInput.value = '';
    const previewEl = document.getElementById('fw-phone-preview');
    const errorEl   = document.getElementById('fw-phone-error');
    if (previewEl) previewEl.style.display = 'none';
    if (errorEl)   errorEl.style.display   = 'none';

    // Notificar al usuario inmediatamente y restaurar el botón
    ogComponent('toast').success(`📨 Bienvenida en camino a ${phoneResult.normalized}`);

    if (executeBtn) {
      setTimeout(() => {
        executeBtn.disabled    = false;
        executeBtn.textContent = originalText;
      }, 3000);
    }
  }
}

window.infoproductForcedWelcome = infoproductForcedWelcome;
