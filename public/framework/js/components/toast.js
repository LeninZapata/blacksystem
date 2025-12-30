class ogToast {
  static containers = {}; // Mapa de contenedores por posición
  static queue = [];
  static active = [];
  static maxVisible = 5;



  static show(message, options = {}) {
    const config = {
      type: options.type || 'info',
      duration: options.duration || 3000,
      position: options.position || 'top-right',
      ...options
    };

    if (this.active.length < this.maxVisible) {
      this.display(message, config);
    } else {
      this.queue.push({ message, config });
    }
  }

  static success(message, options = {}) {
    this.show(message, { ...options, type: 'success' });
  }

  static error(message, options = {}) {
    this.show(message, { ...options, type: 'error' });
  }

  static info(message, options = {}) {
    this.show(message, { ...options, type: 'info' });
  }

  static warning(message, options = {}) {
    this.show(message, { ...options, type: 'warning' });
  }

  static display(message, config) {
    const container = this.ensureContainer(config.position);

    // Traducir mensaje si empieza con "i18n:" o si es una key de traducción
    const translatedMessage = this.translateMessage(message);

    const toastEl = document.createElement('div');
    toastEl.className = `toast toast-${config.type}`;
    toastEl.innerHTML = `
      <span class="toast-icon">${this.getIcon(config.type)}</span>
      <span class="toast-message">${translatedMessage}</span>
      <button class="toast-close" onclick="ogToast.remove(this.parentElement)">×</button>
    `;

    container.appendChild(toastEl);
    this.active.push(toastEl);

    setTimeout(() => toastEl.classList.add('toast-show'), 10);

    setTimeout(() => this.remove(toastEl), config.duration);
  }

  // Traducir mensaje si es una key i18n
  static translateMessage(message) {
    if (!message) return message;

    // Si empieza con "i18n:", quitar el prefijo y traducir
    if (message.startsWith('i18n:')) {
      const key = message.substring(5); // Quitar "i18n:"
      return ogModule('i18n') && ogModule('i18n').__ ? ogModule('i18n').__(key) : (window.__ ? window.__(key) : message);
    }

    // Si NO contiene espacios y tiene puntos, probablemente es una key
    // Ejemplo: "admin.user.success.created"
    if (!message.includes(' ') && message.includes('.')) {
      const i18n = ogModule('i18n');
      const translated = i18n && i18n.__ ? i18n.__(message) : (window.__ ? window.__(message) : message);
      // Si la traducción es diferente a la key, usarla
      return translated !== message ? translated : message;
    }

    // Si no, retornar el mensaje tal cual
    return message;
  }

  static remove(toastEl) {
    if (!toastEl || !toastEl.classList.contains('toast-show')) return;

    toastEl.classList.remove('toast-show');

    setTimeout(() => {
      toastEl.remove();
      this.active = this.active.filter(t => t !== toastEl);

      if (this.queue.length > 0) {
        const { message, config } = this.queue.shift();
        this.display(message, config);
      }
    }, 300);
  }

  static ensureContainer(position) {
    // Si ya existe el contenedor para esta posición, retornarlo
    if (this.containers[position]) {
      return this.containers[position];
    }

    // Crear nuevo contenedor para esta posición
    const container = document.createElement('div');
    container.className = `toast-container toast-${position}`;
    container.dataset.position = position;
    document.body.appendChild(container);

    // Guardar referencia
    this.containers[position] = container;

    return container;
  }

  static getIcon(type) {
    const icons = {
      success: '✓',
      error: '✕',
      warning: '⚠',
      info: 'ℹ'
    };
    return icons[type] || icons.info;
  }
}

// ✅ Exponer GLOBALMENTE como ogToast (usado en onclick y en código JS)
window.ogToast = ogToast;

// Registrar en ogFramework
if (typeof window.ogFramework !== 'undefined') {
  window.ogFramework.components.toast = ogToast;
}