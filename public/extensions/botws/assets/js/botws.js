class botws {
  // APIs de la extensión
  static apis = {
    bot: '/api/bot'
  };

  static currentId = null;

  // ============================================
  // FORMULARIOS
  // ============================================

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
    console.log(`realId:`, realId);

    form.clearAllErrors(realId);
    const data = await this.get(id);
    console.log(`data:`, data);
    if (!data) return;

    this.fillForm(formId, data);
  }

  // Llenar formulario
  static fillForm(formId, data) {
    form.fill(formId, {
      name: data.name,
      personality: data.personality || ''
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
    return {
      name: formData.name,
      personality: formData.personality || null,
      config: {}
    };
  }

  // ============================================
  // CRUD
  // ============================================

  static async create(data) {
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

  // ============================================
  // UTILIDADES
  // ============================================

  // Refrescar datatable
  static refresh() {
    if (window.datatable) datatable.refreshFirst();
  }
}

window.botws = botws;
