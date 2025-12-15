class infoproductProduct {
  static apis = {
    product: '/api/product'
  };

  static currentId = null;
  static context = 'infoproductws';

  // Abrir form nuevo
  static openNew(formId) {
    this.currentId = null;
    const formEl = document.getElementById(formId);
    const realId = formEl?.getAttribute('data-real-id') || formId;
    form.clearAllErrors(realId);
  }

  // Abrir form con datos
  static async openEdit(formId, id) {
    this.currentId = id;
    const formEl = document.getElementById(formId);
    const realId = formEl?.getAttribute('data-real-id') || formId;
    
    form.clearAllErrors(realId);
    const data = await this.get(id);
    if (!data) return;
    
    this.fillForm(formId, data);
  }

  // Llenar formulario
  static fillForm(formId, data) {
    const configData = typeof data.config === 'string' ? JSON.parse(data.config) : (data.config || {});
    const messagesData = configData.messages || {};
    
    form.fill(formId, {
      name: data.name,
      bot_id: data.bot_id ? String(data.bot_id) : '',
      price: data.price || '',
      description: data.description || '',
      'config.welcome_triggers': configData.welcome_triggers || '',
      'config.prompt': configData.prompt || '',
      'config.welcome_messages': messagesData.welcome_messages || [],
      'config.welcome_messages_upsell': messagesData.welcome_messages_upsell || [],
      'config.tracking_messages': messagesData.tracking_messages || [],
      'config.tracking_messages_upsell': messagesData.tracking_messages_upsell || [],
      'config.templates': messagesData.templates || [],
      context: data.context || this.context
    });
  }

  static async save(formId) {
    const validation = form.validate(formId);
    if (!validation.success) return toast.error(validation.message);

    const body = this.buildBody(validation.data);
    const result = this.currentId 
      ? await this.update(this.currentId, body) 
      : await this.create(body);

    if (result) {
      toast.success(this.currentId 
        ? __('infoproduct.products.success.updated') 
        : __('infoproduct.products.success.created')
      );
      setTimeout(() => {
        modal.closeAll();
        this.refresh();
      }, 100);
    }
  }

  // Construir body para API
  static buildBody(formData) {
    const userId = auth.user?.id;
    
    if (!userId) {
      logger.error('ext:infoproduct', 'No se pudo obtener el user_id');
      toast.error(__('infoproduct.products.error.user_not_found'));
      return null;
    }

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
    
    if (formData.config?.templates && Array.isArray(formData.config.templates)) {
      messages.templates = formData.config.templates;
    }

    // Solo agregar messages si tiene contenido
    if (Object.keys(messages).length > 0) {
      config.messages = messages;
    }
    console.log(`config:`, config);
    return {
      user_id: userId,
      context: formData.context || this.context,
      bot_id: parseInt(formData.bot_id),
      price: parseFloat(formData.price),
      name: formData.name,
      description: formData.description || null,
      config: Object.keys(config).length > 0 ? config : null
    };
  }

  static async create(data) {
    if (!data) return null;

    try {
      const res = await api.post(this.apis.product, data);
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      logger.error('ext:infoproduct', error);
      toast.error(__('infoproduct.products.error.create_failed'));
      return null;
    }
  }

  static async get(id) {
    try {
      const res = await api.get(`${this.apis.product}/${id}`);
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      logger.error('ext:infoproduct', error);
      toast.error(__('infoproduct.products.error.load_failed'));
      return null;
    }
  }

  static async update(id, data) {
    if (!data) return null;

    try {
      const res = await api.put(`${this.apis.product}/${id}`, {...data, id});
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      logger.error('ext:infoproduct', error);
      toast.error(__('infoproduct.products.error.update_failed'));
      return null;
    }
  }

  static async delete(id) {
    try {
      const res = await api.delete(`${this.apis.product}/${id}`);
      if (res.success === false) {
        toast.error(__('infoproduct.products.error.delete_failed'));
        return null;
      }
      toast.success(__('infoproduct.products.success.deleted'));
      this.refresh();
      return res.data || res;
    } catch (error) {
      logger.error('ext:infoproduct', error);
      toast.error(__('infoproduct.products.error.delete_failed'));
      return null;
    }
  }

  static async list() {
    try {
      const res = await api.get(this.apis.product);
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      logger.error('ext:infoproduct', error);
      return [];
    }
  }

  // Refrescar datatable
  static refresh() {
    if (window.datatable) datatable.refreshFirst();
  }
}

window.infoproductProduct = infoproductProduct;
