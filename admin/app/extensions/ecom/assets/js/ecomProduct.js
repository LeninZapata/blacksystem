class ecomProduct {
  static apis = {
    product: '/api/product'
  };

  static currentId = null;
  static context = 'ecom';

  static openNew(formId) {
    this.currentId = null;
    const formEl = document.getElementById(formId);
    const realId = formEl?.getAttribute('data-real-id') || formId;
    ogForm.clearAllErrors(realId);
  }

  static async openEdit(formId, id) {
    this.currentId = id;
    const formEl = document.getElementById(formId);
    const realId = formEl?.getAttribute('data-real-id') || formId;

    ogForm.clearAllErrors(realId);
    const data = await this.get(id);
    if (!data) return;

    this.fillForm(formId, data);
  }

  static fillForm(formId, data) {
    const configData = typeof data.config === 'string' ? JSON.parse(data.config) : (data.config || {});

    window.ogForm.fill(formId, {
      name: data.name,
      slug: data.slug || '',
      status: data.status == 1,
      'config.gifts': configData.gifts || [],
      context: data.context || this.context
    });
  }

  static async save(formId) {
    const validation = window.ogForm.validate(formId);
    if (!validation.success) return ogToast.error(validation.message);

    // Validar que no se agregue el mismo producto como regalo
    if (this.currentId && validation.data.config?.gifts) {
      const hasSameProduct = validation.data.config.gifts.some(gift => parseInt(gift.product_id) === parseInt(this.currentId));
      if (hasSameProduct) return ogToast.error(__('ecom.error.same_product_gift'));
    }

    // Validar que no haya productos duplicados en gifts
    if (validation.data.config?.gifts) {
      const productIds = validation.data.config.gifts.map(g => g.product_id).filter(id => id);
      const hasDuplicates = productIds.length !== new Set(productIds).size;
      if (hasDuplicates) return ogToast.error(__('ecom.error.duplicate_gifts'));
    }

    const body = this.buildBody(validation.data);
    const result = this.currentId
      ? await this.update(this.currentId, body)
      : await this.create(body);

    if (result) {
      ogModule('form').clearSelectCache('api/product?context=ecom');

      ogToast.success(this.currentId
        ? __('ecom.success.updated')
        : __('ecom.success.created')
      );
      setTimeout(() => {
        window.ogModal.closeAll();
        this.refresh();
      }, 100);
    }
  }

  static buildBody(formData) {
    const config = {};

    if (formData.config?.gifts && Array.isArray(formData.config.gifts)) {
      config.gifts = formData.config.gifts;
    }

    return {
      context: formData.context || this.context,
      name: formData.name,
      slug: formData.slug || null,
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
      ogLogger.error('ext:ecom', error);
      ogToast.error(__('ecom.error.create_failed'));
      return null;
    }
  }

  static async get(id) {
    try {
      const res = await ogApi.get(`${this.apis.product}/${id}`);
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      ogLogger.error('ext:ecom', error);
      ogToast.error(__('ecom.error.load_failed'));
      return null;
    }
  }

  static async update(id, data) {
    if (!data) return null;

    try {
      const res = await ogApi.put(`${this.apis.product}/${id}`, {...data, id});
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      ogLogger.error('ext:ecom', error);
      ogToast.error(__('ecom.error.update_failed'));
      return null;
    }
  }

  static async delete(id) {
    try {
      const res = await ogApi.delete(`${this.apis.product}/${id}`);
      if (res.success === false) {
        ogToast.error(__('ecom.error.delete_failed'));
        return null;
      }
      ogToast.success(__('ecom.success.deleted'));
      ogModule('form').clearSelectCache('api/product?context=ecom');
      this.refresh();
      return res.data || res;
    } catch (error) {
      ogLogger.error('ext:ecom', error);
      ogToast.error(__('ecom.error.delete_failed'));
      return null;
    }
  }

  static async list() {
    try {
      const res = await ogApi.get(this.apis.product + '?context=ecom');
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      ogLogger.error('ext:ecom', error);
      return [];
    }
  }

  static refresh() {
    if (window.ogDatatable) ogDatatable.refreshFirst();
  }
}

window.ecomProduct = ecomProduct;