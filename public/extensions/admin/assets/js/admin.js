class admin {
  // APIs de la extension
  static apis = {
    user: '/api/user',
    roles: '/api/roles'
  };

  static currentId = null;

  static getModules() {
    return {
      api: window.ogFramework?.core?.api,
      form: window.ogFramework?.core?.form,
      cache: window.ogFramework?.core?.cache,

      // Componentes
      modal: window.ogFramework?.components?.modal,
      toast: window.ogFramework?.components?.toast
    };
  }

  static getComponent(){
    return {
      datatable : window.ogFramework?.components?.datatable,
    }
  }


  // ============================================
  // FORMULARIOS
  // ============================================

  // Abrir form nuevo
  static openNew(formId) {
    const { form } = this.getModules();

    this.currentId = null;
    // Extraer el real-id del formulario
    const formEl = document.getElementById(formId);
    const realId = formEl?.getAttribute('data-real-id') || formId;

    form.clearAllErrors(realId);
    if (window.adminPermissions) adminPermissions.init(formId);
  }

  // Abrir form con datos
  static async openEdit(formId, id) {
    const { form } = this.getModules();
    this.currentId = id;
    // Extraer el real-id del formulario
    const formEl = document.getElementById(formId);
    const realId = formEl?.getAttribute('data-real-id') || formId;

    form.clearAllErrors(realId);
    const data = await this.get(id);
    if (!data) return;

    this.fillForm(formId, data);
    if (window.adminPermissions) adminPermissions.load(formId, data);
  }

  // Llenar formulario
  static fillForm(formId, data) {
    const { form } = this.getModules();
    const config = typeof data.config === 'string' ? JSON.parse(data.config) : (data.config || {});

    form.fill(formId, {
      username: data.user,
      email: data.email,
      role: data.role,
      preferences_theme: config.preferences?.theme || 'light',
      preferences_language: config.preferences?.language || 'es',
      preferences_notifications: config.preferences?.notifications ? 'on' : ''
    });
  }

  // ============================================
  // GUARDAR
  // ============================================

  static async save(formId) {
    const { api, form, toast, modal, cache } = this.getModules();
    const validation = form.validate(formId);
    if (!validation.success) return toast.error(validation.message);

    // Extraer el real-id del formulario
    const formEl = document.getElementById(formId);
    const realId = formEl?.getAttribute('data-real-id') || formId;

    const body = this.buildBody(validation.data, realId);
    if (!body) return; // buildBody retorna null si hay error de validación

    // En create, password es obligatorio
    if (!this.currentId && !body.pass) {
      form.setError(realId, 'password', __('admin.user.error.password_required'));
      return toast.error(__('admin.user.error.password_required'));
    }

    const result = this.currentId ? await this.update(this.currentId, body) : await this.create(body);

    if (result) {
      toast.success(this.currentId ? __('admin.user.success.updated') : __('admin.user.success.created'));
      setTimeout(() => {
        modal.closeAll();
        this.refresh();
      }, 100);
    }
  }

  // Construir body para API
  static buildBody(formData, formId) {
    const { toast, form } = this.getModules();
    // Validar password_confirm solo si se proporcionó password
    if (formData.password && formData.password !== formData.password_confirm) {
      toast.error(__('admin.user.error.passwords_not_match'));
      // Marcar campos con error usando data-real-id
      if (formId) {
        form.setError(formId, 'password', __('admin.user.error.passwords_not_match'));
        form.setError(formId, 'password_confirm', __('admin.user.error.passwords_not_match'));
      }
      return null;
    }

    const baseConfig = window.adminPermissions ? adminPermissions.getData() : { permissions: { extensions: {} }};

    const config = {
      ...baseConfig,
      preferences: {
        theme: formData.preferences_theme || 'light',
        language: formData.preferences_language || 'es',
        notifications: formData.preferences_notifications === 'on' || formData.preferences_notifications === true
      }
    };

    return {
      user: formData.username,
      email: formData.email,
      role: formData.role,
      pass: formData.password || undefined,
      config: JSON.stringify(config)
    };
  }

  // ============================================
  // CRUD
  // ============================================

  static async create(data) {
    const { api } = this.getModules();
    try {
      const res = await api.post(this.apis.user, data);
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      ogLogger.error('ext:admin', error);
      return null;
    }
  }

  static async get(id) {
    const { api } = this.getModules();
    try {
      const res = await api.get(`${this.apis.user}/${id}`);
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      ogLogger.error('ext:admin', error);
      return null;
    }
  }

  static async update(id, data) {
    const { api } = this.getModules();
    try {
      const res = await api.put(`${this.apis.user}/${id}`, {...data, id});
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      ogLogger.error('ext:admin', error);
      return null;
    }
  }

  static async delete(id) {
    const { api, toast, cache } = this.getModules();
    // Obtener usuarios del caché (quitar / inicial para que coincida con la clave)
    const source = this.apis.user.replace(/^\//, ''); // Quitar / del inicio
    const cacheKey = `datatable_${source.replace(/[^a-zA-Z0-9]/g, '_')}`;
    const cachedUsers = cache.get(cacheKey);

    // Validar si es admin
    if (cachedUsers && Array.isArray(cachedUsers)) {
      const user = cachedUsers.find(u => u.id == id);
      if (user && user.role === 'admin') {
        toast.error('admin.user.error.cannot_delete_admin');
        return null;
      }
    }

    try {
      const res = await api.delete(`${this.apis.user}/${id}`);

      if (res.success === false) {
        toast.error('admin.user.error.delete_failed');
        return null;
      }

      toast.success('admin.user.success.deleted');
      this.refresh();
      return res.data || res;
    } catch (error) {
      ogLogger.error('ext:admin', error);
      toast.error('admin.user.error.delete_failed');
      return null;
    }
  }

  static async list() {
    const { api } = this.getModules();
    try {
      const res = await api.get(this.apis.user);
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      ogLogger.error('ext:admin', error);
      return [];
    }
  }

  // ============================================
  // UTILIDADES
  // ============================================

  // Refrescar datatable
  static refresh() {
    const { datatable } = this.getComponent();
    datatable.refreshFirst();
  }

  // Cargar select options
  static async loadRoles() {
    const { api } = this.getModules();
    try {
      const res = await api.get(this.apis.roles);
      return res.success === false ? [] : (res.data || res);
    } catch (error) {
      ogLogger.error('ext:admin', error);
      return [];
    }
  }
}

window.admin = admin;