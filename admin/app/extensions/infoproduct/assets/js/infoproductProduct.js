class infoproductProduct {
  static apis = {
    product: '/api/product'
  };

  static currentId = null;
  static context = 'infoproductws';

  // Inicializar formatters personalizados
  static initFormatters() {
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
      
      return `<div><strong>${value}</strong> <span style="color: #6b7280; font-size: 0.875rem;">(${price})</span></div>`;
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
      context: data.context || this.context
    });
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
}

// Inicializar formatters al cargar el módulo
infoproductProduct.initFormatters();

window.infoproductProduct = infoproductProduct;