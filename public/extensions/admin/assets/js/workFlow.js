class workFlow {
  static apis = {
    workFlow: '/api/workFlow'
  };

  static currentId = null;

  static openNew(formId) {
    this.currentId = null;
    const formEl = document.getElementById(formId);
    const realId = formEl?.getAttribute('data-real-id') || formId;
    form.clearAllErrors(realId);
  }

  static async openEdit(formId, id) {
    this.currentId = id;
    const formEl = document.getElementById(formId);
    const realId = formEl?.getAttribute('data-real-id') || formId;
    
    form.clearAllErrors(realId);
    const data = await this.get(id);
    if (!data) return;
    
    this.fillForm(formId, data);
  }

  static fillForm(formId, data) {
    form.fill(formId, {
      name: data.name,
      description: data.description || '',
      file_path: data.file_path || ''
    });
  }

  static async save(formId) {
    const validation = form.validate(formId);
    if (!validation.success) return toast.error(validation.message);

    const body = this.buildBody(validation.data);
    if (!body) return;

    const result = this.currentId 
      ? await this.update(this.currentId, body) 
      : await this.create(body);

    if (result) {
      toast.success(this.currentId 
        ? __('admin.workflows.success.updated') 
        : __('admin.workflows.success.created')
      );
      setTimeout(() => {
        modal.closeAll();
        this.refresh();
      }, 100);
    }
  }

  static buildBody(formData) {
    const userId = auth.user?.id;
    
    if (!userId) {
      logger.error('ext:admin', 'No se pudo obtener el user_id');
      toast.error(__('admin.workflows.error.user_not_found'));
      return null;
    }

    return {
      user_id: userId,
      name: formData.name,
      description: formData.description || null,
      file_path: formData.file_path || null
    };
  }

  static async create(data) {
    if (!data) return null;

    try {
      const res = await api.post(this.apis.workFlow, data);
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      logger.error('ext:admin', error);
      toast.error(__('admin.workflows.error.create_failed'));
      return null;
    }
  }

  static async get(id) {
    try {
      const res = await api.get(`${this.apis.workFlow}/${id}`);
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      logger.error('ext:admin', error);
      toast.error(__('admin.workflows.error.load_failed'));
      return null;
    }
  }

  static async update(id, data) {
    if (!data) return null;

    try {
      const res = await api.put(`${this.apis.workFlow}/${id}`, {...data, id});
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      logger.error('ext:admin', error);
      toast.error(__('admin.workflows.error.update_failed'));
      return null;
    }
  }

  static async delete(id) {
    try {
      const res = await api.delete(`${this.apis.workFlow}/${id}`);
      if (res.success === false) {
        toast.error(__('admin.workflows.error.delete_failed'));
        return null;
      }
      toast.success(__('admin.workflows.success.deleted'));
      this.refresh();
      return res.data || res;
    } catch (error) {
      logger.error('ext:admin', error);
      toast.error(__('admin.workflows.error.delete_failed'));
      return null;
    }
  }

  static async list() {
    try {
      const res = await api.get(this.apis.workFlow);
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      logger.error('ext:admin', error);
      return [];
    }
  }

  static refresh() {
    if (window.datatable) datatable.refreshFirst();
  }
}

window.workFlow = workFlow;
