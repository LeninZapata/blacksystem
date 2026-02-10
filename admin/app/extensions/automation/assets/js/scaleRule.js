class scaleRule {
  static apis = {
    scaleRule: '/api/adAutoScale'
  };

  static currentId = null;

  static openNew(formId) {
    this.currentId = null;
    const formEl = document.getElementById(formId);
    const realId = formEl?.getAttribute('data-real-id') || formId;
    ogModule('form').clearAllErrors(realId);

    setTimeout(() => {
      ogModule('conditions')?.init(formId);
    }, 100);
  }

  static async openEdit(formId, id) {
    this.currentId = id;
    const formEl = document.getElementById(formId);
    const realId = formEl?.getAttribute('data-real-id') || formId;

    ogModule('form').clearAllErrors(realId);
    const data = await this.get(id);
    if (!data) return;

    this.fillForm(formId, data);

    setTimeout(() => {
      ogModule('conditions')?.init(formId);
    }, 100);
  }

  static fillForm(formId, data) {
    const config = typeof data.config === 'string' ? JSON.parse(data.config) : (data.config || {});

    const fillData = {
      name: data.name,
      product_ad_asset_id: data.ad_assets_id,
      is_active: data.is_active == 1  // ← Cambiar de status a is_active
    };

    if (config.conditions_logic) {
      const logicSelect = document.getElementById('conditions_logic');
      if (logicSelect) logicSelect.value = config.conditions_logic;
    }

    if (config.actions && Array.isArray(config.actions)) {
      fillData.actions = config.actions;
    }

    if (config.condition_groups && Array.isArray(config.condition_groups)) {
      fillData.condition_groups = config.condition_groups;
    }

    ogModule('form').fill(formId, fillData);
  }

  static async save(formId) {
    const validation = ogModule('form').validate(formId);
    if (!validation.success) return ogComponent('toast').error(validation.message);

    const body = this.buildBody(validation.data);
    if (!body) return;

    const result = this.currentId
      ? await this.update(this.currentId, body)
      : await this.create(body);

    if (result) {
      ogComponent('toast').success(this.currentId
        ? __('automation.scale_rules.success.updated')
        : __('automation.scale_rules.success.created')
      );
      setTimeout(() => {
        ogModule('modal').closeAll();
        this.refresh();
      }, 100);
    }
  }

  static buildBody(formData) {
    const logicSelect = document.getElementById('conditions_logic');
    const conditionsLogic = logicSelect ? logicSelect.value : 'and_or_and';

    const config = {
      conditions_logic: conditionsLogic,
      actions: formData.actions || [],
      condition_groups: formData.condition_groups || []
    };

    return {
      name: formData.name,
      ad_assets_id: parseInt(formData.product_ad_asset_id || formData.ad_assets_id),
      is_active: formData.is_active ? 1 : 0,  // ← Del checkbox
      status: 1,  // ← Siempre 1 (interno)
      config: config
    };
  }

  static async create(data) {
    if (!data) return null;

    try {
      const res = await ogModule('api').post(this.apis.scaleRule, data);
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      ogLogger.error('ext:automation:scaleRule', error);
      ogComponent('toast').error(__('automation.scale_rules.error.create_failed'));
      return null;
    }
  }

  static async get(id) {
    try {
      const res = await ogModule('api').get(`${this.apis.scaleRule}/${id}`);
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      ogLogger.error('ext:automation:scaleRule', error);
      ogComponent('toast').error(__('automation.scale_rules.error.load_failed'));
      return null;
    }
  }

  static async update(id, data) {
    if (!data) return null;

    try {
      const res = await ogModule('api').put(`${this.apis.scaleRule}/${id}`, {...data, id});
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      ogLogger.error('ext:automation:scaleRule', error);
      ogComponent('toast').error(__('automation.scale_rules.error.update_failed'));
      return null;
    }
  }

  static async delete(id) {
    try {
      const res = await ogModule('api').delete(`${this.apis.scaleRule}/${id}`);
      if (res.success === false) {
        ogComponent('toast').error(__('automation.scale_rules.error.delete_failed'));
        return null;
      }
      ogComponent('toast').success(__('automation.scale_rules.success.deleted'));
      this.refresh();
      return res.data || res;
    } catch (error) {
      ogLogger.error('ext:automation:scaleRule', error);
      ogComponent('toast').error(__('automation.scale_rules.error.delete_failed'));
      return null;
    }
  }

  static async list() {
    try {
      const res = await ogModule('api').get(this.apis.scaleRule);
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      ogLogger.error('ext:automation:scaleRule', error);
      return [];
    }
  }

  static refresh() {
    ogComponent('datatable')?.refreshFirst();
  }

  static initFormatters() {
    const dataTable = ogComponent('datatable');
    if (!dataTable) return;

    // Formatter para el estado de la regla (is_active)
    dataTable.registerFormatter('scale-rule-status', (value, row) => {
      const isActive = value == 1 || value === true;
      const statusText = isActive ? __('core.status.active') : __('core.status.inactive');
      const statusColor = isActive ? '#16a34a' : '#dc2626';
      const statusBg = isActive ? '#dcfce7' : '#fee2e2';
      return `<span style="display: inline-block; padding: 0.25rem 0.75rem; border-radius: 0.375rem; font-size: 0.875rem; font-weight: 500; color: ${statusColor}; background-color: ${statusBg};">${statusText}</span>`;
    });

    // Formatter para nombre con activo publicitario
    dataTable.registerFormatter('scale-rule-name-with-asset', (value, row) => {
      const name = value ?? '';
      const assetId = row.ad_assets_id ?? '';
      const assetName = row.product_ad_asset_name || row.ad_asset_name || '';
      
      const assetText = assetName ? assetName : `Activo #${assetId}`;
      
      return `
        <div>
          <div style="font-weight: 500;">${name}</div>
          <div style="font-size: 0.85em; color: var(--og-gray-600); margin-top: 2px;">${assetText}</div>
        </div>
      `;
    });
  }
}

window.scaleRule = scaleRule;

// Registrar formatters al cargar el módulo
scaleRule.initFormatters();