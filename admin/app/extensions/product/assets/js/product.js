/**
 * product.js
 * Gestión de productos genérica
 */

class product {
  static apis = {
    product: '/api/product',
    clone: '/api/product/clone'
  };

  static currentId = null;
  static currentProductName = null;

  // Abrir form nuevo
  static openNew(formId) {
    this.currentId = null;
    this.currentProductName = null;
    const formEl = document.getElementById(formId);
    const realId = formEl?.getAttribute('data-real-id') || formId;
    ogForm.clearAllErrors(realId);
    ogLogger?.info('ext:product', 'Abriendo formulario de nuevo producto');
  }

  // Abrir form con datos
  static async openEdit(formId, productId) {
    if (!productId) {
      ogToast.error(__('product.error.no_id'));
      return;
    }

    this.currentId = productId;
    const formEl = document.getElementById(formId);
    const realId = formEl?.getAttribute('data-real-id') || formId;

    ogForm.clearAllErrors(realId);
    ogLogger?.info('ext:product', `Cargando producto ${productId}`);

    const data = await this.get(productId);
    if (!data) return;

    ogForm.fill(formId, data);
    ogLogger?.success('ext:product', `Producto ${productId} cargado`);
  }

  // Abrir formulario de clonación
  static openClone(formId, productId, productName) {
    if (!productId) {
      ogToast.error(__('product.error.no_id'));
      return;
    }

    this.currentId = productId;
    this.currentProductName = productName || 'Producto';

    const formEl = document.getElementById(formId);
    const realId = formEl?.getAttribute('data-real-id') || formId;
    ogForm.clearAllErrors(realId);

    // Actualizar el nombre en el header del modal
    setTimeout(() => {
      const nameElement = document.getElementById('product-clone-name');
      if (nameElement) {
        nameElement.textContent = `"${this.currentProductName}"`;
      }
    }, 100);

    ogLogger?.info('ext:product', `Preparando clonación de producto ${productId}`);
  }

  // Guardar producto (nuevo o editar)
  static async save(formId, formData) {
    const validation = ogForm.validate(formId);
    if (!validation.success) {
      return ogToast.error(validation.message || __('product.error.validation_failed'));
    }

    const data = this.buildBody(validation.data);
    const result = this.currentId
      ? await this.update(this.currentId, data)
      : await this.create(data);

    if (result) {
      ogToast.success(this.currentId
        ? __('product.success.updated')
        : __('product.success.created')
      );
      setTimeout(() => {
        ogModal.closeAll();
        this.refresh();
      }, 100);
    }
  }

  // Clonar producto
  static async clone(formId, formData) {
    if (!this.currentId) {
      ogToast.error(__('product.error.no_product_selected'));
      return false;
    }

    const validation = ogForm.validate(formId);
    if (!validation.success) {
      return ogToast.error(validation.message || __('product.error.validation_failed'));
    }

    if (!validation.data.target_user_id) {
      ogToast.error(__('product.error.no_user_selected'));
      return false;
    }

    ogLogger?.info('ext:product', `Clonando producto ${this.currentId} para usuario ${validation.data.target_user_id}`);

    try {
      const res = await ogApi.post(this.apis.clone, {
        product_id: this.currentId,
        target_user_id: parseInt(validation.data.target_user_id)
      });

      if (res.success === false) {
        ogToast.error(res.error || __('product.error.clone_failed'));
        return null;
      }

      ogToast.success(__('product.success.cloned', {name: this.currentProductName}));
      setTimeout(() => {
        ogModal.closeAll();
        this.refresh();
        
        // Limpiar variables
        this.currentId = null;
        this.currentProductName = null;
      }, 100);

      return res.data || res;
    } catch (error) {
      ogLogger?.error('ext:product', 'Error al clonar producto:', error);
      ogToast.error(__('product.error.clone_failed'));
      return null;
    }
  }

  // Construir body para API
  static buildBody(formData) {
    return {
      name: formData.name,
      description: formData.description || null,
      config: formData.config || null,
      context: formData.context || null,
      bot_id: formData.bot_id ? parseInt(formData.bot_id) : null,
      price: formData.price ? parseFloat(formData.price) : null,
      status: formData.status ? 1 : 0
    };
  }

  // CRUD Methods
  static async create(data) {
    if (!data) return null;

    try {
      const res = await ogApi.post(this.apis.product, data);
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      ogLogger?.error('ext:product', error);
      ogToast.error(__('product.error.create_failed'));
      return null;
    }
  }

  static async get(id) {
    try {
      const res = await ogApi.get(`${this.apis.product}/${id}`);
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      ogLogger?.error('ext:product', error);
      ogToast.error(__('product.error.load_failed'));
      return null;
    }
  }

  static async update(id, data) {
    if (!data) return null;

    try {
      const res = await ogApi.put(`${this.apis.product}/${id}`, {...data, id});
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      ogLogger?.error('ext:product', error);
      ogToast.error(__('product.error.update_failed'));
      return null;
    }
  }

  static async delete(id) {
    if (!id) {
      ogToast.error(__('product.error.no_id'));
      return null;
    }

    try {
      const res = await ogApi.delete(`${this.apis.product}/${id}`);
      if (res.success === false) {
        ogToast.error(res.error || __('product.error.delete_failed'));
        return null;
      }
      ogToast.success(__('product.success.deleted'));
      this.refresh();
      return res.data || res;
    } catch (error) {
      ogLogger?.error('ext:product', error);
      ogToast.error(__('product.error.delete_failed'));
      return null;
    }
  }

  static async list() {
    try {
      const res = await ogApi.get(this.apis.product);
      return res.success === false ? null : (res.data || res);
    } catch (error) {
      ogLogger?.error('ext:product', error);
      return [];
    }
  }

  // Refrescar datatable
  static refresh() {
    if (window.ogDatatable) ogDatatable.refreshFirst();
    ogLogger?.info('ext:product', 'Tabla de productos actualizada');
  }
}

// Exponer globalmente
window.product = product;