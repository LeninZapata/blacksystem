class botws {
  // APIs de la extensión
  static apis = {
    bot: '/api/bot'
  };

  static currentId = null;
  // FORMULARIOS
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
    logger.debug('ext:botws', 'openEdit - realId:', realId);

    form.clearAllErrors(realId);
    const data = await this.get(id);
    logger.debug('ext:botws', 'openEdit - data:', data);
    if (!data) return;

    this.fillForm(formId, data);
  }

  // Llenar formulario
  static fillForm(formId, data) {
    const configData = typeof data.config === 'string' ? JSON.parse(data.config) : (data.config || {});

    logger.debug('ext:botws', 'fillForm - data:', data);
    logger.debug('ext:botws', 'fillForm - configData:', configData);

    // Convertir arrays de IDs a arrays de objetos con credential_id
    const agentArray = Array.isArray(configData.apis?.agent)
      ? configData.apis.agent.map(id => ({ credential_id: String(id) }))
      : [];

    const chatArray = Array.isArray(configData.apis?.chat)
      ? configData.apis.chat.map(id => ({ credential_id: String(id) }))
      : [];

    logger.debug('ext:botws', 'fillForm - agentArray:', agentArray);
    logger.debug('ext:botws', 'fillForm - chatArray:', chatArray);

    // Llenar todos los campos (form.fill maneja automáticamente los selects asíncronos)
    form.fill(formId, {
      name: data.name,
      number: data.number || '',
      country_code: data.country_code || '',
      personality: data.personality || '',
      type: data.type || '',
      mode: data.mode || '',
      'config.workflow_id': configData.workflow_id ? String(configData.workflow_id) : '',
      'config.apis.agent': agentArray,
      'config.apis.chat': chatArray
    });
  }

  // ============================================
  // GUARDAR
  // ============================================

  static async save(formId) {
    const validation = form.validate(formId);
    if (!validation.success) return toast.error(validation.message);

    const formEl = document.getElementById(formId);
    const realId = formEl?.getAttribute('data-real-id') || formId;

    const body = this.buildBody(validation.data);

    const result = this.currentId
      ? await this.update(this.currentId, body)
      : await this.create(body);

    if (result) {
      toast.success(this.currentId
        ? __('botws.bot.success.updated')
        : __('botws.bot.success.created')
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
      logger.error('ext:botws', 'No se pudo obtener el user_id');
      toast.error(__('botws.bot.error.user_not_found'));
      return null;
    }

    // Extraer arrays de credenciales desde los repetibles
    let agentCredentials = [];
    let chatCredentials = [];

    // Buscar en formData con diferentes formatos posibles
    if (formData.config && typeof formData.config === 'object') {
      // config.apis.agent es un array de objetos [{credential_id: 1}, {credential_id: 2}]
      const agentArray = formData.config.apis?.agent || [];
      const chatArray = formData.config.apis?.chat || [];

      agentCredentials = Array.isArray(agentArray)
        ? agentArray.map(item => parseInt(item.credential_id)).filter(id => !isNaN(id))
        : [];

      chatCredentials = Array.isArray(chatArray)
        ? chatArray.map(item => parseInt(item.credential_id)).filter(id => !isNaN(id))
        : [];
    } else {
      // Buscar por keys directas (form.getData puede estructurar así)
      const agentData = formData['config.apis.agent'] || formData['config[apis][agent]'] || [];
      const chatData = formData['config.apis.chat'] || formData['config[apis][chat]'] || [];

      agentCredentials = Array.isArray(agentData)
        ? agentData.map(item => parseInt(item.credential_id || item)).filter(id => !isNaN(id))
        : [];

      chatCredentials = Array.isArray(chatData)
        ? chatData.map(item => parseInt(item.credential_id || item)).filter(id => !isNaN(id))
        : [];
    }

    // ✅ Validación: Al menos 1 agent y 1 chat requerido
    if (agentCredentials.length === 0) {
      toast.error(__('botws.bot.error.agent_required'));
      return null;
    }

    if (chatCredentials.length === 0) {
      toast.error(__('botws.bot.error.chat_required'));
      return null;
    }

    const workflowId = formData.config?.workflow_id || formData['config.workflow_id'] || null;

    const config = {
      workflow_id: workflowId,
      apis: {
        agent: agentCredentials,
        chat: chatCredentials
      }
    };

    logger.debug('ext:botws', 'buildBody - formData:', formData);
    logger.debug('ext:botws', 'buildBody - config construido:', config);

    return {
      user_id: userId,
      name: formData.name,
      number: formData.number,
      country_code: formData.country_code,
      personality: formData.personality || null,
      type: formData.type,
      mode: formData.mode,
      config: config
    };
  }

  // ============================================
  // CRUD
  // ============================================

  static async create(data) {
    if (!data) return null;

    try {
      const res = await api.post(this.apis.bot, data);
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      logger.error('ext:botws', error);
      toast.error(__('botws.bot.error.create_failed'));
      return null;
    }
  }

  static async get(id) {
    try {
      const res = await api.get(`${this.apis.bot}/${id}`);
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      logger.error('ext:botws', error);
      toast.error(__('botws.bot.error.load_failed'));
      return null;
    }
  }

  static async update(id, data) {
    if (!data) return null;

    try {
      const res = await api.put(`${this.apis.bot}/${id}`, {...data, id});
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      logger.error('ext:botws', error);
      toast.error(__('botws.bot.error.update_failed'));
      return null;
    }
  }

  static async delete(id) {
    try {
      const res = await api.delete(`${this.apis.bot}/${id}`);

      if (res.success === false) {
        toast.error(__('botws.bot.error.delete_failed'));
        return null;
      }

      toast.success(__('botws.bot.success.deleted'));
      this.refresh();
      return res.data || res;
    } catch (error) {
      logger.error('ext:botws', error);
      toast.error(__('botws.bot.error.delete_failed'));
      return null;
    }
  }

  static async list() {
    try {
      const res = await api.get(this.apis.bot);
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      logger.error('ext:botws', error);
      return [];
    }
  }

  // UTILIDADES
  // Refrescar datatable
  static refresh() {
    if (window.datatable) datatable.refreshFirst();
  }
}

window.botws = botws;
