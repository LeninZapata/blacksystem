class credential {
  static apis = {
    credential: '/api/credential'
  };

  static currentId = null;

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
    const fillData = {
      name: data.name,
      type: data.type
    };

    // Si hay config, extraer los campos específicos
    if (data.config && typeof data.config === 'object') {
      if (data.config.type_value) {
        if (data.type === 'ai-agent') {
          fillData.ai_agent = data.config.type_value;
          if (data.config.credential_value) {
            fillData.api_token = data.config.credential_value;
          }
        } else if (data.type === 'chat') {
          fillData.chat_api = data.config.type_value;
          if (data.config.base_url) fillData.base_url = data.config.base_url;
          if (data.config.instance) fillData.instance = data.config.instance;
          if (data.config.credential_value) fillData.credential_value = data.config.credential_value;
        }
      }
    }

    ogForm.fill(formId, fillData);
  }

  static async save(formId) {
    const validation = ogForm.validate(formId);
    if (!validation.success) return ogToast.error(validation.message);

    const body = this.buildBody(validation.data);
    if (!body) return;

    const result = this.currentId 
      ? await this.update(this.currentId, body) 
      : await this.create(body);

    if (result) {
      ogToast.success(this.currentId 
        ? __('automation.credentials.success.updated') 
        : __('automation.credentials.success.created')
      );
      setTimeout(() => {
        ogForm.closeAll();
        this.refresh();
      }, 100);
    }
  }

  static buildBody(formData) {
    // Construir config JSON automáticamente
    let config = {
      type: formData.type,
      type_value: '',
      credential_value: ''
    };

    if (formData.type === 'ai-agent') {
      config.type_value = formData.ai_agent || '';
      config.credential_value = formData.api_token || '';
    } else if (formData.type === 'chat') {
      config.type_value = formData.chat_api || '';
      config.base_url = formData.base_url || '';
      config.instance = formData.instance || '';
      config.credential_value = formData.credential_value || '';
    }

    return {
      name: formData.name,
      type: formData.type,
      config: config
    };
  }

  static async create(data) {
    if (!data) return null;

    try {
      const res = await api.post(this.apis.credential, data);
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      logger.error('ext:automation', error);
      ogToast.error(__('automation.credentials.error.create_failed'));
      return null;
    }
  }

  static async get(id) {
    try {
      const res = await api.get(`${this.apis.credential}/${id}`);
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      logger.error('ext:automation', error);
      ogToast.error(__('automation.credentials.error.load_failed'));
      return null;
    }
  }

  static async update(id, data) {
    if (!data) return null;

    try {
      const res = await api.put(`${this.apis.credential}/${id}`, {...data, id});
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      logger.error('ext:automation', error);
      ogToast.error(__('automation.credentials.error.update_failed'));
      return null;
    }
  }

  static async delete(id) {
    try {
      const res = await api.delete(`${this.apis.credential}/${id}`);
      if (res.success === false) {
        ogToast.error(__('automation.credentials.error.delete_failed'));
        return null;
      }
      ogToast.success(__('automation.credentials.success.deleted'));
      this.refresh();
      return res.data || res;
    } catch (error) {
      logger.error('ext:automation', error);
      ogToast.error(__('automation.credentials.error.delete_failed'));
      return null;
    }
  }

  static async list() {
    try {
      const res = await api.get(this.apis.credential);
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      logger.error('ext:automation', error);
      return [];
    }
  }

  static refresh() {
    if (window.datatable) datatable.refreshFirst();
  }
}

window.credential = credential;
