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
      notes: data.notes || '',
      credential_id: data.credential_id || '',
      timezone: data.timezone || 'America/Guayaquil',
      base_daily_budget: data.base_daily_budget || '',
      reset_time: data.reset_time || '00:00',
      auto_reset_budget: data.auto_reset_budget == 1
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
      notes: formData.notes || '',
      credential_id: formData.credential_id || null,
      timezone: formData.timezone || 'America/Guayaquil',
      base_daily_budget: formData.base_daily_budget || null,
      reset_time: formData.reset_time || '00:00:00',
      auto_reset_budget: formData.auto_reset_budget ? 1 : 0
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

  static initFormatters() {
    const dataTable = ogComponent('datatable');
    if (!dataTable) return;

    // Formatter para el estado del activo publicitario
    dataTable.registerFormatter('product-ad-asset-status', (value, row) => {
      const isActive = value == 1 || value === true;
      const statusText = isActive ? __('core.status.active') : __('core.status.inactive');
      const statusColor = isActive ? '#16a34a' : '#dc2626';
      const statusBg = isActive ? '#dcfce7' : '#fee2e2';
      return `<span style="display: inline-block; padding: 0.25rem 0.75rem; border-radius: 0.375rem; font-size: 0.875rem; font-weight: 500; color: ${statusColor}; background-color: ${statusBg};">${statusText}</span>`;
    });

    // Formatter para nombre con plataforma/tipo y ID del activo
    dataTable.registerFormatter('product-ad-asset-name-with-details', (value, row) => {
      const name = value ?? '';
      const platform = row.ad_platform ?? '';
      const assetType = row.ad_asset_type ?? '';
      const assetId = row.ad_asset_id ?? '';
      
      // Mapeo de plataformas y tipos
      const platformLabels = {
        'facebook': 'Facebook',
        'google': 'Google',
        'tiktok': 'TikTok',
        'instagram': 'Instagram',
        'other': 'Otra'
      };
      
      const typeLabels = {
        'campaign': __('automation.product_ad_assets.asset_type.campaign'),
        'adset': __('automation.product_ad_assets.asset_type.adset'),
        'ad': __('automation.product_ad_assets.asset_type.ad')
      };
      
      const platformText = platformLabels[platform] || platform;
      const typeText = typeLabels[assetType] || assetType;
      
      return `
        <div>
          <div style="font-weight: 500;">${name}</div>
          <div style="font-size: 0.85em; color: var(--og-gray-600); margin-top: 2px;">${platformText} / ${typeText}</div>
          <div style="font-size: 0.8em; color: var(--og-gray-500); margin-top: 2px; font-family: monospace;">${assetId}</div>
        </div>
      `;
    });
  }
}

window.productAdAsset = productAdAsset;

// Registrar formatters al cargar el módulo
productAdAsset.initFormatters();