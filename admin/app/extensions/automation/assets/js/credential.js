class credential {
  static apis = {
    credential: '/api/credential'
  };

  static currentId = null;

  static openNew(formId) {
    this.currentId = null;
    const formEl = document.getElementById(formId);
    const realId = formEl?.getAttribute('data-real-id') || formId;
    ogModule('form').clearAllErrors(realId);
    
    // ✅ FIX: Reinicializar condiciones después de limpiar
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
    
    // ✅ FIX: Reinicializar condiciones después de llenar el formulario
    setTimeout(() => {
      ogModule('conditions')?.init(formId);
    }, 100);
  }

  static fillForm(formId, data) {
    const fillData = { name: data.name, type: data.type, status: data.status == 1 };

    // Si hay config, extraer los campos específicos
    if (data.config && typeof data.config === 'object') {
      if (data.config.type_value) {
        if (data.type === 'ai-agent') {
          fillData.ai_agent = data.config.type_value;
          if (data.config.credential_value) fillData.api_token = data.config.credential_value;
        } else if (data.type === 'chat') {
          fillData.chat_api = data.config.type_value;
          
          // Evolution API
          if (data.config.type_value === 'evolutionapi') {
            if (data.config.base_url) fillData.base_url = data.config.base_url;
            if (data.config.instance) fillData.instance = data.config.instance;
            if (data.config.credential_value) fillData.credential_value = data.config.credential_value;
          }
          
          // WhatsApp Cloud API (Facebook)
          if (data.config.type_value === 'whatsapp-cloud-api') {
            if (data.config.access_token) fillData.access_token = data.config.access_token;
            if (data.config.phone_number_id) fillData.phone_number_id = data.config.phone_number_id;
            if (data.config.business_account_id) fillData.business_account_id = data.config.business_account_id;
          }
        } else if (data.type === 'ad') {
          fillData.ad_type = data.config.type_value;
          if (data.config.credential_value) fillData.api_token = data.config.credential_value;
        }
      }
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
        ? __('automation.credentials.success.updated') 
        : __('automation.credentials.success.created')
      );
      setTimeout(() => {
        ogModule('modal').closeAll();
        this.refresh();
      }, 100);
    }
  }

  static buildBody(formData) {
    // Construir config JSON automáticamente
    let config = { type: formData.type, type_value: '', credential_value: '' };

    if (formData.type === 'ai-agent') {
      config.type_value = formData.ai_agent || '';
      config.credential_value = formData.api_token || '';
    } else if (formData.type === 'chat') {
      config.type_value = formData.chat_api || '';
      
      // Evolution API
      if (formData.chat_api === 'evolutionapi') {
        config.base_url = formData.base_url || '';
        config.instance = formData.instance || '';
        config.credential_value = formData.credential_value || '';
      }
      
      // WhatsApp Cloud API (Facebook)
      if (formData.chat_api === 'whatsapp-cloud-api') {
        config.access_token = formData.access_token || '';
        config.phone_number_id = formData.phone_number_id || '';
        config.business_account_id = formData.business_account_id || '';
        // credential_value se deja vacío o igual al access_token
        config.credential_value = formData.access_token || '';
      }
    } else if (formData.type === 'ad') {
      config.type_value = formData.ad_type || '';
      config.credential_value = formData.api_token || '';
    }

    return { name: formData.name, type: formData.type, status: formData.status ? 1 : 0, config: config };
  }

  static async create(data) {
    if (!data) return null;

    try {
      const res = await ogModule('api').post(this.apis.credential, data);
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      ogLogger.error('ext:automation', error);
      ogComponent('toast').error(__('automation.credentials.error.create_failed'));
      return null;
    }
  }

  static async get(id) {
    try {
      const res = await ogModule('api').get(`${this.apis.credential}/${id}`);
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      ogLogger.error('ext:automation', error);
      ogComponent('toast').error(__('automation.credentials.error.load_failed'));
      return null;
    }
  }

  static async update(id, data) {
    if (!data) return null;

    try {
      const res = await ogModule('api').put(`${this.apis.credential}/${id}`, {...data, id});
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      ogLogger.error('ext:automation:credential: ' + __('automation.credentials.error.update_failed'), error);
      //ogComponent('toast').error(__('automation.credentials.error.update_failed'));
      return null;
    }
  }

  static async delete(id) {
    try {
      const res = await ogModule('api').delete(`${this.apis.credential}/${id}`);
      if (res.success === false) {
        ogComponent('toast').error(__('automation.credentials.error.delete_failed'));
        return null;
      }
      ogComponent('toast').success(__('automation.credentials.success.deleted'));
      this.refresh();
      return res.data || res;
    } catch (error) {
      ogLogger.error('ext:automation', error);
      ogComponent('toast').error(__('automation.credentials.error.delete_failed'));
      return null;
    }
  }

  static async list() {
    try {
      const res = await ogModule('api').get(this.apis.credential);
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      ogLogger.error('ext:automation', error);
      return [];
    }
  }

  static refresh() {
    ogComponent('datatable')?.refreshFirst();
  }
}

window.credential = credential;