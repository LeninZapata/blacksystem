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
    form.fill(formId, {
      name: data.name,
      bot_id: data.bot_id ? String(data.bot_id) : '',
      price: data.price || '',
      description: data.description || '',
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

    return {
      user_id: userId,
      context: formData.context || this.context,
      bot_id: parseInt(formData.bot_id),
      price: parseFloat(formData.price),
      name: formData.name,
      description: formData.description || null,
      config: null
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
