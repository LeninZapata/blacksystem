class infoproductProduct {
  static apis = {
    product: '/api/product',
    clone: '/api/product/clone'
  };

  static currentId = null;
  static currentFormId = null;
  static currentProductName = null;
  static context = 'infoproductws';

  // Inicializar formatters personalizados
  static initFormatters() {
    // Formatter para sale_type_mode con badge gris
    ogDatatable.registerFormatter('sale-type-mode', (value) => {
      const map = { '1': 'Principal', '2': 'Upsell', '3': 'P & U' };
      const label = map[String(value)] ?? 'P & U';
      return `<span style="display:inline-block;padding:0.15rem 0.5rem;border-radius:0.3rem;font-size:0.75rem;font-weight:500;background:#e5e7eb;color:#374151;">${label}</span>`;
    });

    // Formatter para estado con badge de color
    ogDatatable.registerFormatter('product-status', (value, row) => {
      const isActive = value == 1 || value === true;
      const statusText = isActive ? __('core.status.active') : __('core.status.inactive');
      const statusColor = isActive ? '#16a34a' : '#dc2626';
      const statusBg = isActive ? '#dcfce7' : '#fee2e2';
      return `<span style="display: inline-block; padding: 0.25rem 0.75rem; border-radius: 0.375rem; font-size: 0.875rem; font-weight: 500; color: ${statusColor}; background-color: ${statusBg};">${statusText}</span>`;
    });

    // Formatter para nombre con precio incluido
    ogDatatable.registerFormatter('product-name-with-price', (value, row) => {
      const price = row.price ? new Intl.NumberFormat('es-EC', {
        style: 'currency',
        currency: 'USD'
      }).format(row.price) : 'Gratis';

      let botInfo = '';
      if (row.bot_name) {
        const flag = row.country_code
          ? row.country_code.toUpperCase().replace(/./g, c => String.fromCodePoint(0x1F1E6 - 65 + c.charCodeAt(0)))
          : '🌐';
        botInfo = `<div style="font-size: 0.78rem; color: #6b7280; margin-top: 3px;">${flag} ${row.bot_name}</div>`;
      }

      return `<div><strong>${value}</strong> <span style="color: #6b7280; font-size: 0.875rem;">(${price})</span>${botInfo}</div>`;
    });

    // Formatter para precio con badge de color
    ogDatatable.registerFormatter('product-price', (value, row) => {
      if (!value || value === 0) {
        return '<span class="og-bg-gray-200 og-text-gray-700 og-p-1 og-rounded" style="font-size: 0.875rem; display: inline-block; padding: 0.25rem 0.75rem;">Gratis</span>';
      }
      
      const formatted = new Intl.NumberFormat('es-EC', {
        style: 'currency',
        currency: 'USD'
      }).format(value);
      
      return `<span class="og-bg-green-100 og-text-green-700 og-p-1 og-rounded" style="font-size: 0.875rem; font-weight: 600; display: inline-block; padding: 0.25rem 0.75rem;">${formatted}</span>`;
    });

    // Formatter para nombre con bot asociado
    ogDatatable.registerFormatter('product-name-detailed', (value, row) => {
      const botInfo = row.bot_id ? `<small class="og-text-gray-500" style="display: block; font-size: 0.75rem;">Bot #${row.bot_id}</small>` : '';
      return `<div><strong>${value}</strong>${botInfo}</div>`;
    });

    // Formatter para entorno (env)
    ogDatatable.registerFormatter('product-env', (value, row) => {
      const isInactive = row.status == 0 || row.status === false;
      const isTest = value === 'T';
      const emoji = isTest ? '🧪' : '🟢';
      const text = isTest ? 'Testeo' : 'Prod';
      const color = isInactive ? '#6b7280' : (isTest ? '#92400e' : '#14532d');
      const bg    = isInactive ? '#f3f4f6' : (isTest ? '#fef3c7' : '#dcfce7');
      const emojiStyle = isInactive ? 'filter:grayscale(1);opacity:0.6;' : '';
      return `<span style="display:inline-block;padding:0.15rem 0.5rem;border-radius:0.3rem;font-size:0.75rem;font-weight:500;background:${bg};color:${color};"><span style="${emojiStyle}">${emoji}</span> ${text}</span>`;
    });
  }

  // Abrir form nuevo
  static openNew(formId) {
    this.currentId = null;
    const formEl = document.getElementById(formId);
    const realId = formEl?.getAttribute('data-real-id') || formId;
    ogForm.clearAllErrors(realId);
  }

  // Abrir form con datos
  static async openEdit(formId, id) {
    this.currentId = id;
    this.currentFormId = formId;
    const formEl = document.getElementById(formId);
    const realId = formEl?.getAttribute('data-real-id') || formId;

    ogForm.clearAllErrors(realId);
    const data = await this.get(id);
    if (!data) return;

    this.fillForm(formId, data);
  }

  // Llenar formulario
  static fillForm(formId, data) {
    const configData = typeof data.config === 'string' ? JSON.parse(data.config) : (data.config || {});
    const messagesData = configData.messages || {};

    window.ogForm.fill(formId, {
      name: data.name,
      bot_id: data.bot_id ? String(data.bot_id) : '',
      price: data.price || '',
      sale_type_mode: data.sale_type_mode ? String(data.sale_type_mode) : '3',
      env: data.env || 'P',
      description: data.description || '',
      status: data.status == 1,
      'config.welcome_triggers': configData.welcome_triggers || '',
      'config.prompt': configData.prompt || '',
      'config.welcome_messages': messagesData.welcome_messages || [],
      'config.welcome_messages_upsell': messagesData.welcome_messages_upsell || [],
      'config.tracking_messages': messagesData.tracking_messages || [],
      'config.tracking_messages_upsell': messagesData.tracking_messages_upsell || [],
      'config.upsell_products': messagesData.upsell_products || [],
      'config.templates': messagesData.templates || [],
      'config.fb_ad_copy': configData.fb_ad_copy || '',
      'config.fb_welcome_text': configData.fb_welcome_text || '',
      'config.fb_source_ids': configData.fb_source_ids || [],
      'config.hotmart_product_id': configData.hotmart_product_id ? String(configData.hotmart_product_id) : '',
      context: data.context || this.context
    });
  }

  // Abrir formulario de clonación
  static openClone(formId, productId, productName) {
    if (!productId) {
      ogToast.error(__('infoproduct.products.error.no_id'));
      return;
    }

    this.currentId = productId;
    this.currentProductName = productName || 'Infoproducto';

    const formEl = document.getElementById(formId);
    const realId = formEl?.getAttribute('data-real-id') || formId;
    ogForm.clearAllErrors(realId);

    // Actualizar el nombre en el header del modal
    setTimeout(() => {
      const nameElement = document.getElementById('infoproduct-clone-name');
      if (nameElement) {
        nameElement.textContent = `"${this.currentProductName}"`;
      }
    }, 100);

    ogLogger?.info('ext:infoproduct', `Preparando clonación de infoproducto ${productId}`);
  }

  // Clonar infoproducto
  static async clone(formId, formData) {
    if (!this.currentId) {
      ogToast.error(__('infoproduct.products.error.no_product_selected'));
      return false;
    }

    const validation = ogForm.validate(formId);
    if (!validation.success) {
      return ogToast.error(validation.message || __('infoproduct.products.error.validation_failed'));
    }

    if (!validation.data.target_user_id) {
      ogToast.error(__('infoproduct.products.error.no_user_selected'));
      return false;
    }

    ogLogger?.info('ext:infoproduct', `Clonando infoproducto ${this.currentId} para usuario ${validation.data.target_user_id}`);

    try {
      const res = await ogApi.post(this.apis.clone, {
        product_id: this.currentId,
        target_user_id: parseInt(validation.data.target_user_id)
      });

      if (res.success === false) {
        ogToast.error(res.error || __('infoproduct.products.error.clone_failed'));
        return null;
      }

      ogToast.success(__('infoproduct.products.success.cloned', {name: this.currentProductName}));
      setTimeout(() => {
        ogModal.closeAll();
        this.refresh();
        
        // Limpiar variables
        this.currentId = null;
        this.currentProductName = null;
      }, 100);

      return res.data || res;
    } catch (error) {
      ogLogger?.error('ext:infoproduct', 'Error al clonar infoproducto:', error);
      ogToast.error(__('infoproduct.products.error.clone_failed'));
      return null;
    }
  }

  static async save(formId) {
    const validation = window.ogForm.validate(formId);
    console.log(`validation:`, validation);
    if (!validation.success) return ogToast.error(validation.message);

    // Validar que no se seleccione el mismo producto como upsell
    if (this.currentId && validation.data.config?.upsell_products) {
      const hasSameProduct = validation.data.config.upsell_products.some(up => parseInt(up.product_id) === parseInt(this.currentId));
      if (hasSameProduct) return ogToast.error(__('infoproduct.products.error.same_product_upsell'));
    }

    // Validar que no haya productos duplicados en upsell_products
    if (validation.data.config?.upsell_products) {
      const productIds = validation.data.config.upsell_products.map(up => up.product_id).filter(id => id);
      const hasDuplicates = productIds.length !== new Set(productIds).size;
      if (hasDuplicates) return ogToast.error(__('infoproduct.products.error.duplicate_upsell_products'));
    }

    const body = this.buildBody(validation.data);
    const result = this.currentId
      ? await this.update(this.currentId, body)
      : await this.create(body);

    if (result) {
      // Limpiar cache del select de productos para que se recargue con los datos actualizados
      ogModule('form').clearSelectCache('/api/product');

      ogToast.success(this.currentId
        ? __('infoproduct.products.success.updated')
        : __('infoproduct.products.success.created')
      );
      setTimeout(() => {
        window.ogModal.closeAll();
        this.refresh();
      }, 100);
    }
  }

  // Construir body para API
  static buildBody(formData) {
    // Construir config con welcome_triggers, prompt y messages
    const config = {};

    if (formData.config?.welcome_triggers) {
      config.welcome_triggers = formData.config.welcome_triggers;
    }

    if (formData.config?.prompt) {
      config.prompt = formData.config.prompt;
    }

    if (formData.config?.fb_ad_copy) {
      config.fb_ad_copy = formData.config.fb_ad_copy;
    }

    if (formData.config?.fb_welcome_text) {
      config.fb_welcome_text = formData.config.fb_welcome_text;
    }

    if (formData.config?.fb_source_ids && Array.isArray(formData.config.fb_source_ids)) {
      config.fb_source_ids = formData.config.fb_source_ids;
    }

    if (formData.config?.hotmart_product_id) {
      config.hotmart_product_id = parseInt(formData.config.hotmart_product_id) || formData.config.hotmart_product_id;
    }

    // Agrupar todos los repeatables del grouper en messages
    const messages = {};

    if (formData.config?.welcome_messages && Array.isArray(formData.config.welcome_messages)) {
      messages.welcome_messages = formData.config.welcome_messages;
    }

    if (formData.config?.welcome_messages_upsell && Array.isArray(formData.config.welcome_messages_upsell)) {
      messages.welcome_messages_upsell = formData.config.welcome_messages_upsell;
    }

    if (formData.config?.tracking_messages && Array.isArray(formData.config.tracking_messages)) {
      messages.tracking_messages = formData.config.tracking_messages;
    }

    if (formData.config?.tracking_messages_upsell && Array.isArray(formData.config.tracking_messages_upsell)) {
      messages.tracking_messages_upsell = formData.config.tracking_messages_upsell;
    }

    if (formData.config?.upsell_products && Array.isArray(formData.config.upsell_products)) {
      messages.upsell_products = formData.config.upsell_products;
    }

    if (formData.config?.templates && Array.isArray(formData.config.templates)) {
      messages.templates = formData.config.templates;
    }

    // Solo agregar messages si tiene contenido
    if (Object.keys(messages).length > 0) {
      config.messages = messages;
    }

    return {
      context: formData.context || this.context,
      bot_id: parseInt(formData.bot_id),
      price: parseFloat(formData.price),
      sale_type_mode: parseInt(formData.sale_type_mode) || 3,
      env: formData.env || 'P',
      name: formData.name,
      description: formData.description || null,
      status: formData.status ? 1 : 0,
      config: Object.keys(config).length > 0 ? config : null
    };
  }

  static async create(data) {
    if (!data) return null;

    try {
      const res = await ogApi.post(this.apis.product, data);
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      logger.error('ext:infoproduct', error);
      ogToast.error(__('infoproduct.products.error.create_failed'));
      return null;
    }
  }

  static async get(id) {
    try {
      const res = await ogApi.get(`${this.apis.product}/${id}`);
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      logger.error('ext:infoproduct', error);
      ogToast.error(__('infoproduct.products.error.load_failed'));
      return null;
    }
  }

  static async update(id, data) {
    if (!data) return null;

    try {
      const res = await ogApi.put(`${this.apis.product}/${id}`, {...data, id});
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      ogLogger.error('ext:infoproduct', error);
      ogToast.error(__('infoproduct.products.error.update_failed'));
      return null;
    }
  }

  static async delete(id) {
    try {
      const res = await ogApi.delete(`${this.apis.product}/${id}`);
      if (res.success === false) {
        ogToast.error(__('infoproduct.products.error.delete_failed'));
        return null;
      }
      ogToast.success(__('infoproduct.products.success.deleted'));
      this.refresh();
      return res.data || res;
    } catch (error) {
      ogLogger.error('ext:infoproduct', error);
      ogToast.error(__('infoproduct.products.error.delete_failed'));
      return null;
    }
  }

  static async list() {
    try {
      const res = await ogApi.get(this.apis.product);
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      ogLogger.error('ext:infoproduct', error);
      return [];
    }
  }

  // Refrescar datatable
  static refresh() {
    if (window.ogDatatable) ogDatatable.refreshFirst();
  }

  // ── Validar Bienvenida ────────────────────────────────────────────────────
  static async validateWelcome() {
    const formId = this.currentFormId;
    const form = formId ? document.getElementById(formId) : null;
    const resultEl = form ? form.querySelector('#validate-welcome-result') : null;

    if (!form) return;

    const botIdEl      = form.querySelector('[name="bot_id"]');
    const fbAdCopyEl   = form.querySelector('[name="config.fb_ad_copy"]');
    const fbWelcomeEl  = form.querySelector('[name="config.fb_welcome_text"]');

    const botId        = botIdEl?.  value;
    const fbAdCopy     = fbAdCopyEl?.value?.trim()   || '';
    const fbWelcomeText = fbWelcomeEl?.value?.trim() || '';
    const productId    = this.currentId;

    if (resultEl) resultEl.innerHTML = '';

    if (!botId) {
      ogToast.error('Debes seleccionar un bot primero');
      return;
    }

    if (!productId) {
      ogToast.error('Guarda el producto antes de validar');
      return;
    }

    if (!fbAdCopy && !fbWelcomeText) {
      ogToast.error('Ingresa el texto del anuncio o el texto de bienvenida');
      return;
    }

    try {
      const res = await ogApi.post('/api/product/validate-welcome', {
        product_id: parseInt(productId),
        bot_id: parseInt(botId),
        fb_ad_copy: fbAdCopy,
        fb_welcome_text: fbWelcomeText,
      });

      if (res.success === false) {
        ogToast.error(res.error || 'Error al validar');
        return;
      }

      const data = res.data ?? res;

      if (!resultEl) return;

      if (data.is_valid) {
        resultEl.innerHTML = `
          <div style="padding:10px 14px;background:#dcfce7;border:1px solid #86efac;border-radius:6px;margin-top:8px;color:#15803d;font-size:0.88rem;">
            ✅ Sin conflictos detectados. Los activadores no chocan con otros productos del bot.
          </div>`;
      } else {
        const rows = data.conflicts.map(c => {
          const campo = c.matched_in === 'fb_ad_copy' ? 'Texto del anuncio' : 'Texto de bienvenida';
          const excerpt = c.matched_text?.length > 120 ? c.matched_text.substring(0, 120) + '…' : c.matched_text;
          const wordsHtml = (c.matched_words || []).map(w =>
            `<span style="display:inline-block;padding:1px 6px;background:#fecaca;border-radius:3px;font-weight:600;font-size:0.8rem;margin:2px 2px 0 0;">${w}</span>`
          ).join('');
          const wordsRow = wordsHtml ? `<div style="margin-top:4px;">Palabras que coinciden: ${wordsHtml}</div>` : '';
          return `<li style="margin-bottom:8px;">
            El activador <strong>"${c.trigger}"</strong> coincide con el <strong>${campo}</strong> del producto <strong>"${c.product_name}"</strong>
            <div style="margin-top:3px;padding:4px 8px;background:#fff1f2;border-radius:4px;font-size:0.82rem;color:#9f1239;word-break:break-word;">${excerpt}</div>
            ${wordsRow}
          </li>`;
        }).join('');

        resultEl.innerHTML = `
          <div style="padding:10px 14px;background:#fee2e2;border:1px solid #fca5a5;border-radius:6px;margin-top:8px;">
            <strong style="color:#dc2626;font-size:0.88rem;">⚠️ Se encontraron ${data.conflicts.length} conflicto(s)</strong>
            <ul style="margin:8px 0 0;padding-left:18px;font-size:0.85rem;color:#374151;">${rows}</ul>
          </div>`;
      }
    } catch (err) {
      ogToast.error('Error al conectar con el servidor');
    }
  }
}

// Inicializar formatters al cargar el módulo
infoproductProduct.initFormatters();

window.infoproductProduct = infoproductProduct;