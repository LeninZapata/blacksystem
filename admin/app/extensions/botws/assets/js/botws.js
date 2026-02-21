class botws {
  // APIs de la extensión
  static apis = {
    bot: '/api/bot'
  };

  static currentId = null;

  // Inicializar formatters personalizados
  static initFormatters() {
    // Formatter para estado con badge de color
    ogDatatable.registerFormatter('bot-status', (value, row) => {
      const isActive = value == 1 || value === true;
      const statusText = isActive ? __('core.status.active') : __('core.status.inactive');
      const statusColor = isActive ? '#16a34a' : '#dc2626';
      const statusBg = isActive ? '#dcfce7' : '#fee2e2';
      return `<span style="display: inline-block; padding: 0.25rem 0.75rem; border-radius: 0.375rem; font-size: 0.875rem; font-weight: 500; color: ${statusColor}; background-color: ${statusBg};">${statusText}</span>`;
    });

    // Formatter para nombre con número de bot
    ogDatatable.registerFormatter('bot-name-with-number', (value, row) => {
      const number = row.number ? `+${row.country_code || ''} ${row.number}` : '';
      return `<div><strong>${value}</strong> <small style="color: #6b7280; font-size: 0.75rem; display: block; margin-top: 0.25rem;">${number}</small></div>`;
    });
  }

  // FORMULARIOS
  // Abrir form nuevo
  static openNew(formId) {
    this.currentId = null;
    const formEl = document.getElementById(formId);
    const realId = formEl?.getAttribute('data-real-id') || formId;
    window.ogForm.clearAllErrors(realId);
  }

  // Abrir form con datos
  static async openEdit(formId, id) {
    this.currentId = id;
    const formEl = document.getElementById(formId);
    const realId = formEl?.getAttribute('data-real-id') || formId;
    ogLogger.debug('ext:botws', 'openEdit - realId:', realId);

    window.ogForm.clearAllErrors(realId);
    const data = await this.get(id);
    ogLogger.debug('ext:botws', 'openEdit - data:', data);
    if (!data) return;

    this.fillForm(formId, data);
  }

  // Llenar formulario
  static fillForm(formId, data) {
    ogLogger.debug('ext:botws', 'fillForm - data completa:', data);
    ogLogger.debug('ext:botws', 'fillForm - status recibido:', data.status, 'tipo:', typeof data.status);
    
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

    // Llenar todos los campos
    window.ogForm.fill(formId, {
      name: data.name,
      number: data.number || '',
      country_code: data.country_code || '',
      personality: data.personality || '',
      type: data.type || '',
      mode: data.mode || '',
      status: data.status == 1,
      'config.workflow_id': configData.workflow_id ? String(configData.workflow_id) : '',
      'config.apis.agent': agentArray,
      'config.apis.chat': chatArray
    });
  }

  // ============================================
  // GUARDAR
  // ============================================

  static async save(formId) {
    const validation = window.ogForm.validate(formId);
    if (!validation.success) return ogToast.error(validation.message);

    const body = this.buildBody(validation.data);
    if (!body) return;

    const result = this.currentId
      ? await this.update(this.currentId, body)
      : await this.create(body);

    if (result) {
      ogToast.success(this.currentId
        ? __('botws.bot.success.updated')
        : __('botws.bot.success.created')
      );
      setTimeout(() => {
        window.ogModal.closeAll();
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
      ogToast.error(__('botws.bot.error.agent_required'));
      return null;
    }

    if (chatCredentials.length === 0) {
      ogToast.error(__('botws.bot.error.chat_required'));
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
      status: formData.status ? 1 : 0,
      config: config
    };
  }

  // ============================================
  // CRUD
  // ============================================

  static async create(data) {
    if (!data) return null;

    try {
      const res = await ogApi.post(this.apis.bot, data);
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      ogLogger.error('ext:botws', error);
      ogToast.error(__('botws.bot.error.create_failed'));
      return null;
    }
  }

  static async get(id) {
    try {
      const res = await ogApi.get(`${this.apis.bot}/${id}`);
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      ogLogger.error('ext:botws', error);
      ogToast.error(__('botws.bot.error.load_failed'));
      return null;
    }
  }

  static async update(id, data) {
    if (!data) return null;

    try {
      const res = await ogApi.put(`${this.apis.bot}/${id}`, {...data, id});
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      ogLogger.error('ext:botws', error);
      ogToast.error(__('botws.bot.error.update_failed'));
      return null;
    }
  }

  static async delete(id) {
    try {
      const res = await ogApi.delete(`${this.apis.bot}/${id}`);

      if (res.success === false) {
        ogToast.error(__('botws.bot.error.delete_failed'));
        return null;
      }

      ogToast.success(__('botws.bot.success.deleted'));
      setTimeout(() => {
        this.refresh();
      }, 100);
      return res.data || res;
    } catch (error) {
      ogLogger.error('ext:botws', error);
      ogToast.error(__('botws.bot.error.delete_failed'));
      return null;
    }
  }

  static async list() {
    try {
      const res = await ogApi.get(this.apis.bot);
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

// Inicializar formatters al cargar el módulo
botws.initFormatters();

window.botws = botws;