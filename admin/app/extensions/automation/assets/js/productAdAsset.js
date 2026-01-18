class productAdAsset {
  static apis = { productAdAsset: '/api/productAdAsset' };
  static currentId = null;

  static openNew(formId) {
    this.currentId = null;
    const formEl = document.getElementById(formId);
    const realId = formEl?.getAttribute('data-real-id') || formId;
    ogModule('form').clearAllErrors(realId);
  }

  static async openEdit(formId, id) {
    this.currentId = id;
    const formEl = document.getElementById(formId);
    const realId = formEl?.getAttribute('data-real-id') || formId;

    ogModule('form').clearAllErrors(realId);
    const data = await this.get(id);
    if (!data) return;

    this.fillForm(formId, data);
  }

  static fillForm(formId, data) {
    const fillData = {
      product_id: data.product_id,
      ad_platform: data.ad_platform,
      ad_asset_type: data.ad_asset_type,
      ad_asset_id: data.ad_asset_id,
      ad_asset_name: data.ad_asset_name || '',
      is_active: data.is_active == 1,
      notes: data.notes || ''
    };

    ogModule('form').fill(formId, fillData);
  }

  static async save(formId) {
    const validation = ogModule('form').validate(formId);
    if (!validation.success) return ogComponent('toast').error(validation.message);

    const body = this.buildBody(validation.data);
    if (!body) return;

    const result = this.currentId ? await this.update(this.currentId, body) : await this.create(body);

    if (result) {
      ogModule('form').clearSelectCache('/api/productAdAsset');
      ogComponent('toast').success(this.currentId ? __('automation.product_ad_assets.success.updated') : __('automation.product_ad_assets.success.created'));
      setTimeout(() => { ogModule('modal').closeAll(); this.refresh(); }, 100);
    }
  }

  static buildBody(formData) {
    return {
      product_id: formData.product_id,
      ad_platform: formData.ad_platform,
      ad_asset_type: formData.ad_asset_type,
      ad_asset_id: formData.ad_asset_id,
      ad_asset_name: formData.ad_asset_name || '',
      is_active: formData.is_active ? 1 : 0,
      notes: formData.notes || ''
    };
  }

  static async create(data) {
    if (!data) return null;
    try {
      const res = await ogModule('api').post(this.apis.productAdAsset, data);
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      ogLogger.error('ext:automation:productAdAsset', error);
      ogComponent('toast').error(__('automation.product_ad_assets.error.create_failed'));
      return null;
    }
  }

  static async get(id) {
    try {
      const res = await ogModule('api').get(`${this.apis.productAdAsset}/${id}`);
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      ogLogger.error('ext:automation:productAdAsset', error);
      ogComponent('toast').error(__('automation.product_ad_assets.error.load_failed'));
      return null;
    }
  }

  static async update(id, data) {
    if (!data) return null;
    try {
      const res = await ogModule('api').put(`${this.apis.productAdAsset}/${id}`, {...data, id});
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      ogLogger.error('ext:automation:productAdAsset', error);
      ogComponent('toast').error(__('automation.product_ad_assets.error.update_failed'));
      return null;
    }
  }

  static async delete(id) {
    try {
      const res = await ogModule('api').delete(`${this.apis.productAdAsset}/${id}`);
      if (res.success === false) {
        ogComponent('toast').error(__('automation.product_ad_assets.error.delete_failed'));
        return null;
      }
      ogComponent('toast').success(__('automation.product_ad_assets.success.deleted'));
      this.refresh();
      return res.data || res;
    } catch (error) {
      ogLogger.error('ext:automation:productAdAsset', error);
      ogComponent('toast').error(__('automation.product_ad_assets.error.delete_failed'));
      return null;
    }
  }

  static async list() {
    try {
      const res = await ogModule('api').get(this.apis.productAdAsset);
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      ogLogger.error('ext:automation:productAdAsset', error);
      return [];
    }
  }

  static refresh() {
    ogComponent('datatable')?.refreshFirst();
  }
}

window.productAdAsset = productAdAsset;