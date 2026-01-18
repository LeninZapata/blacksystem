class scaleRule {
  static apis = {
    scaleRule: '/api/ad-auto-scale'
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
      ad_assets_id: data.ad_assets_id,
      status: data.status == 1
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
      ad_assets_id: parseInt(formData.ad_assets_id),
      status: formData.status ? 1 : 0,
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
}

window.scaleRule = scaleRule;