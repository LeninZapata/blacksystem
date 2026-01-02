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
    ogModule('form').clearAllErrors(realId);
  }

  // Abrir form con datos
  static async openEdit(formId, id) {
    this.currentId = id;
    const formEl = document.getElementById(formId);
    const realId = formEl?.getAttribute('data-real-id') || formId;
    ogLogger.debug('ext:botws', 'openEdit - realId:', realId);

    ogModule('form').clearAllErrors(realId);
    const data = await this.get(id);
    ogLogger.debug('ext:botws', 'openEdit - data:', data);
    if (!data) return;

    this.fillForm(formId, data);
  }

  // Llenar formulario
  static fillForm(formId, data) {
    const configData = typeof data.config === 'string' ? JSON.parse(data.config) : (data.config || {});

    // Convertir desde bot.apis.ai[task] al formato de repeatable para agent
    const aiData = configData.apis?.ai || {};
    const agentArray = [];

    ['conversation', 'image', 'audio'].forEach(task => {
      if (Array.isArray(aiData[task])) {
        aiData[task].forEach(credId => {
          agentArray.push({ credential_id: String(credId), task: task });
        });
      }
    });

    // Chat se mantiene como array simple de IDs
    const chatArray = Array.isArray(configData.apis?.chat)
      ? configData.apis.chat.map(id => ({ credential_id: String(id) }))
      : [];

    // Llenar todos los campos (ogModule('form').fill maneja automáticamente los selects asíncronos)
    ogModule('form').fill(formId, {
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
    const validation = ogModule('form').validate(formId);
    if (!validation.success) return ogComponent('toast').error(validation.message);

    const formEl = document.getElementById(formId);
    const realId = formEl?.getAttribute('data-real-id') || formId;

    const body = this.buildBody(validation.data);

    const result = this.currentId
      ? await this.update(this.currentId, body)
      : await this.create(body);

    if (result) {
      // Limpiar cache del select de bots
      ogModule('form').clearSelectCache('/api/bot');

      ogComponent('toast').success(this.currentId
        ? __('botws.bot.success.updated')
        : __('botws.bot.success.created')
      );
      setTimeout(() => {
        ogComponent('modal').closeAll();
        this.refresh();
      }, 100);
    }
  }

  // Construir body para API
  static buildBody(formData) {
    // Extraer arrays desde los repetibles
    let agentArray = [];
    let chatArray = [];

    // Buscar en formData con diferentes formatos posibles
    if (formData.config && typeof formData.config === 'object') {
      agentArray = formData.config.apis?.agent || [];
      chatArray = formData.config.apis?.chat || [];
    } else {
      agentArray = formData['config.apis.agent'] || formData['config[apis][agent]'] || [];
      chatArray = formData['config.apis.chat'] || formData['config[apis][chat]'] || [];
    }

    // Agrupar credenciales de AGENT por tarea
    const aiTasks = { conversation: [], image: [], audio: [] };

    if (Array.isArray(agentArray)) {
      agentArray.forEach(item => {
        const credId = parseInt(item.credential_id || item);
        const task = item.task || 'conversation';
        if (!isNaN(credId) && aiTasks[task]) {
          aiTasks[task].push(credId);
        }
      });
    }

    // Chat se mantiene como array simple de IDs
    const chatCredentials = Array.isArray(chatArray)
      ? chatArray.map(item => parseInt(item.credential_id || item)).filter(id => !isNaN(id))
      : [];

    // ✅ Validación: Al menos 1 agent y 1 chat requerido
    const totalAgents = aiTasks.conversation.length + aiTasks.image.length + aiTasks.audio.length;
    if (totalAgents === 0) {
      ogComponent('toast').error(__('botws.bot.error.agent_required'));
      return null;
    }

    if (chatCredentials.length === 0) {
      ogComponent('toast').error(__('botws.bot.error.chat_required'));
      return null;
    }

    const workflowId = formData.config?.workflow_id || formData['config.workflow_id'] || null;

    const config = {
      workflow_id: workflowId,
      apis: {
        ai: aiTasks,
        chat: chatCredentials
      }
    };

    ogLogger.debug('ext:botws', 'buildBody - formData:', formData);
    ogLogger.debug('ext:botws', 'buildBody - config construido:', config);

    return {
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
      const res = await ogModule('api').post(this.apis.bot, data);
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      ogLogger.error('ext:botws', error);
      ogComponent('toast').error(__('botws.bot.error.create_failed'));
      return null;
    }
  }

  static async get(id) {
    try {
      const res = await ogModule('api').get(`${this.apis.bot}/${id}`);
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      ogLogger.error('ext:botws', error);
      ogComponent('toast').error(__('botws.bot.error.load_failed'));
      return null;
    }
  }

  static async update(id, data) {
    if (!data) return null;

    try {
      const res = await ogModule('api').put(`${this.apis.bot}/${id}`, {...data, id});
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      ogLogger.error('ext:botws', error);
      ogComponent('toast').error(__('botws.bot.error.update_failed'));
      return null;
    }
  }

  static async delete(id) {
    try {
      const res = await ogModule('api').delete(`${this.apis.bot}/${id}`);

      if (res.success === false) {
        ogComponent('toast').error(__('botws.bot.error.delete_failed'));
        return null;
      }

      ogComponent('toast').success(__('botws.bot.success.deleted'));
      setTimeout(() => {
        this.refresh();
      }, 100);
      return res.data || res;
    } catch (error) {
      ogLogger.error('ext:botws', error);
      ogComponent('toast').error(__('botws.bot.error.delete_failed'));
      return null;
    }
  }

  static async list() {
    try {
      const res = await ogModule('api').get(this.apis.bot);
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      ogLogger.error('ext:botws', error);
      return [];
    }
  }

  // UTILIDADES
  // Refrescar datatable
  static refresh() {
    ogComponent('datatable')?.refreshFirst();
  }
}

window.botws = botws;