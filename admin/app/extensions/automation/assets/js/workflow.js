class workflow {
  static apis = {
    workflow: '/api/workFlow'
  };

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
      name: data.name,
      description: data.description || '',
      file_path: data.file_path || ''
    };
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
        ? __('automation.workflow.success.updated')
        : __('automation.workflow.success.created')
      );
      setTimeout(() => { ogComponent('modal').closeAll(); this.refresh(); }, 100);
    }
  }

  static buildBody(formData) {
    return {
      name: formData.name,
      description: formData.description,
      file_path: formData.file_path
    };
  }

  static async create(data) {
    if (!data){ return null; }
    try {
      const res = await ogModule('api').post(this.apis.workflow, data);
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      ogLogger.error('ext:automation:workFlow: ' + __('automation.workflow.error.create_failed'), error);
      return null;
    }
  }

  static async get(id) {
    try {
      const res = await ogModule('api').get(`${this.apis.workflow}/${id}`);
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      ogLogger.error('ext:automation:workFlow', error);
      ogComponent('toast').error(__('automation.workflow.error.load_failed'));
      return null;
    }
  }

  static async update(id, data) {
    if (!data) return null;
    try {
      const res = await ogModule('api').put(`${this.apis.workflow}/${id}`, {...data, id});
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      ogLogger.error('ext:automation:workFlow: ' + __('automation.workflow.error.update_failed'), error);
      return null;
    }
  }

  static async delete(id) {
    try {
      const res = await ogModule('api').delete(`${this.apis.workflow}/${id}`);
      if (res.success === false) return null;
      ogComponent('toast').success(__('automation.workflow.success.deleted'));
      this.refresh();
      return res.data || res;
    } catch (error) {
      ogLogger.error('ext:automation:workFlow', error);
      ogComponent('toast').error(__('automation.workflow.error.delete_failed'));
      return null;
    }
  }

  static async list() {
    try {
      const res = await ogModule('api').get(this.apis.workflow);
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      ogLogger.error('ext:automation:workFlow', error);
      return [];
    }
  }

  static refresh() {
    //ogModule('datatable').refreshAll();
    ogComponent('datatable')?.refreshFirst();
  }
}

window.workflow = workflow;
