class product {
  static apis = {
    product: '/api/product'
  };

  static currentId = null;

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
    ogForm.fill(formId, {
      name: data.name,
      description: data.description || ''
    });
  }

  static async save(formId) {
    const validation = ogForm.validate(formId);
    if (!validation.success) return ogToast.error(validation.message);

    const body = this.buildBody(validation.data);
    const result = this.currentId 
      ? await this.update(this.currentId, body) 
      : await this.create(body);

    if (result) {
      ogToast.success(this.currentId 
        ? __('product.success.updated') 
        : __('product.success.created')
      );
      setTimeout(() => {
        ogForm.closeAll();
        this.refresh();
      }, 100);
    }
  }

  // Construir body para API
  static buildBody(formData) {
    const userId = auth.user?.id;
    
    if (!userId) {
      logger.error('ext:product', 'No se pudo obtener el user_id');
      ogToast.error(__('product.error.user_not_found'));
      return null;
    }

    return {
      user_id: userId,
      name: formData.name,
      description: formData.description || null
    };
  }

  static async create(data) {
    if (!data) return null;

    try {
      const res = await api.post(this.apis.product, data);
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      logger.error('ext:product', error);
      ogToast.error(__('product.error.create_failed'));
      return null;
    }
  }

  static async get(id) {
    try {
      const res = await api.get(`${this.apis.product}/${id}`);
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      logger.error('ext:product', error);
      ogToast.error(__('product.error.load_failed'));
      return null;
    }
  }

  static async update(id, data) {
    if (!data) return null;

    try {
      const res = await api.put(`${this.apis.product}/${id}`, {...data, id});
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      logger.error('ext:product', error);
      ogToast.error(__('product.error.update_failed'));
      return null;
    }
  }

  static async delete(id) {
    try {
      const res = await api.delete(`${this.apis.product}/${id}`);
      if (res.success === false) {
        ogToast.error(__('product.error.delete_failed'));
        return null;
      }
      ogToast.success(__('product.success.deleted'));
      this.refresh();
      return res.data || res;
    } catch (error) {
      logger.error('ext:product', error);
      ogToast.error(__('product.error.delete_failed'));
      return null;
    }
  }

  static async list() {
    try {
      const res = await api.get(this.apis.product);
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      logger.error('ext:product', error);
      return [];
    }
  }

  // Refrescar datatable
  static refresh() {
    if (window.datatable) datatable.refreshFirst();
  }
}

window.product = product;