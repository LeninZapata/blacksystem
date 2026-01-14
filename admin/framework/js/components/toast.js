// toast.js - Con prefijo og-
class ogToast {
  static containers = {};
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
    const translatedMessage = this.translateMessage(message);

    const toastEl = document.createElement('div');
    toastEl.className = `og-toast og-toast-${config.type}`;
    toastEl.innerHTML = `
      <span class="og-toast-icon">${this.getIcon(config.type)}</span>
      <span class="og-toast-message">${translatedMessage}</span>
      <button class="og-toast-close" onclick="ogToast.remove(this.parentElement)">×</button>
    `;

    container.appendChild(toastEl);
    this.active.push(toastEl);

    setTimeout(() => toastEl.classList.add('og-toast-show'), 10);
    setTimeout(() => this.remove(toastEl), config.duration);
  }

  static translateMessage(message) {
    if (!message) return message;

    const i18n = window.ogFramework?.i18n || window.ogModule?.('i18n');
    if (!i18n) return message;

    // Si empieza con "i18n:", quitar el prefijo y traducir
    if (message.startsWith('i18n:')) {
      const key = message.substring(5);
      return i18n.t ? i18n.t(key) : message;
    }

    // Si es una key de traducción (contiene puntos pero no espacios)
    if (!message.includes(' ') && message.includes('.')) {
      const translated = i18n.t ? i18n.t(message) : message;
      return translated !== message ? translated : message;
    }

    return message;
  }

  static remove(toastEl) {
    if (!toastEl || !toastEl.classList.contains('og-toast-show')) return;

    toastEl.classList.remove('og-toast-show');

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
    if (this.containers[position]) {
      return this.containers[position];
    }

    const container = document.createElement('div');
    container.className = `og-toast-container og-toast-${position}`;
    container.dataset.position = position;
    document.body.appendChild(container);

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

  // Limpiar todos los toasts
  static clearAll() {
    this.active.forEach(toast => this.remove(toast));
    this.queue = [];
  }
}

// Exponer globalmente
window.ogToast = ogToast;

// Registrar en ogFramework si existe
if (window.ogFramework) {
  window.ogFramework.components = window.ogFramework.components || {};
  window.ogFramework.components.toast = ogToast;
}