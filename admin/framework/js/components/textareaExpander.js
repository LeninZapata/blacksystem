/**
 * Textarea Expander - Componente minimalista
 * Abre textareas en modal para edición expandida
 */
class ogTextareaExpander {
  static activeTextarea = null;
  static currentModalId = null;

  static init() {
    this.bindEvents();
    ogLogger?.info('core:textareaExpander', 'Inicializado');
  }

  static bindEvents() {
    // Event delegation en document para capturar clicks en botones expander
    document.addEventListener('click', (e) => {
      const btn = e.target.closest('.textarea-expand-btn');
      if (!btn) return;

      e.preventDefault();
      e.stopPropagation();

      const textarea = btn.closest('.textarea-wrapper')?.querySelector('textarea');
      if (textarea) {
        this.expand(textarea);
      }
    });

    // Observar nuevos textareas con clase
    const observer = new MutationObserver((mutations) => {
      mutations.forEach((mutation) => {
        mutation.addedNodes.forEach((node) => {
          if (node.nodeType === Node.ELEMENT_NODE) {
            if (node.matches && node.matches('textarea.textarea-expandable')) {
              this.processTextarea(node);
            }
            const textareas = node.querySelectorAll && node.querySelectorAll('textarea.textarea-expandable');
            if (textareas) {
              textareas.forEach(t => this.processTextarea(t));
            }
          }
        });
      });
    });

    observer.observe(document.body, {
      childList: true,
      subtree: true
    });

    // Procesar textareas existentes
    document.querySelectorAll('textarea.textarea-expandable').forEach(t => this.processTextarea(t));
  }

  static processTextarea(textarea) {
    // Evitar procesar dos veces
    if (textarea.dataset.expanderProcessed) return;
    textarea.dataset.expanderProcessed = 'true';

    // Crear wrapper si no existe
    let wrapper = textarea.closest('.textarea-wrapper');
    if (!wrapper) {
      wrapper = document.createElement('div');
      wrapper.className = 'textarea-wrapper';
      textarea.parentNode.insertBefore(wrapper, textarea);
      wrapper.appendChild(textarea);
    }

    // Agregar botón si no existe
    if (!wrapper.querySelector('.textarea-expand-btn')) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'textarea-expand-btn';
      btn.innerHTML = `
        <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
          <path d="M21 11V3h-8l3.29 3.29-10 10L3 13v8h8l-3.29-3.29 10-10z"/>
        </svg>
      `;
      btn.title = 'Expandir';
      wrapper.appendChild(btn);
    }
  }

  static expand(textarea) {
    this.activeTextarea = textarea;
    const value = textarea.value || '';
    const label = this.getLabel(textarea);
    const maxLength = textarea.maxLength > 0 ? textarea.maxLength : null;
    const placeholder = textarea.placeholder || 'Escribe aquí...';

    const content = this.buildModalContent(value, placeholder, maxLength);

    const { modalId } = ogModal.open(content, {
      title: label,
      html: true,
      width: '90%',
      maxWidth: '800px',
      footer: this.buildFooter(),
      showFooter: true
    });

    this.currentModalId = modalId;

    // Esperar que el modal renderice
    setTimeout(() => {
      const modalTextarea = document.querySelector(`#${modalId} #expanded-textarea`);
      if (modalTextarea) {
        modalTextarea.value = value;
        modalTextarea.focus();
        modalTextarea.setSelectionRange(value.length, value.length);

        // Eventos
        this.bindModalEvents(modalTextarea, maxLength);
      }
    }, 50);
  }

  static buildModalContent(value, placeholder, maxLength) {
    const maxLengthAttr = maxLength ? `maxlength="${maxLength}"` : '';
    const helpText = maxLength 
      ? `Máximo ${maxLength} caracteres. Ctrl+Enter para guardar.`
      : 'Ctrl+Enter para guardar.';

    return `
      <div class="form-group">
        <textarea 
          id="expanded-textarea" 
          class="form-input" 
          placeholder="${placeholder}"
          ${maxLengthAttr}
          style="min-height: 400px; resize: vertical; font-size: 14px; line-height: 1.6;"
        ></textarea>
        <small class="form-hint">${helpText}</small>
        <small id="char-counter" style="display: block; margin-top: 0.5rem; color: #6c757d;"></small>
      </div>
    `;
  }

  static buildFooter() {
    return `
      <button class="btn btn-secondary" onclick="ogTextareaExpander.cancel()">Cancelar</button>
      <button class="btn btn-primary" onclick="ogTextareaExpander.save()">Guardar</button>
    `;
  }

  static bindModalEvents(modalTextarea, maxLength) {
    const updateCounter = () => {
      const counter = document.getElementById('char-counter');
      if (!counter) return;

      const count = modalTextarea.value.length;
      if (maxLength) {
        const remaining = maxLength - count;
        if (remaining < 0) {
          counter.innerHTML = `<span style="color: #e74c3c;">${count} / ${maxLength} (excedido por ${Math.abs(remaining)})</span>`;
        } else if (remaining <= 100) {
          counter.innerHTML = `<span style="color: #f39c12;">${count} / ${maxLength} (quedan ${remaining})</span>`;
        } else {
          counter.textContent = `${count} / ${maxLength}`;
        }
      } else {
        counter.textContent = `${count} caracteres`;
      }
    };

    modalTextarea.addEventListener('input', updateCounter);
    
    modalTextarea.addEventListener('keydown', (e) => {
      if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        e.preventDefault();
        this.save();
      }
    });

    updateCounter();
  }

  static save() {
    if (!this.activeTextarea || !this.currentModalId) return;

    const modalTextarea = document.querySelector(`#${this.currentModalId} #expanded-textarea`);
    if (modalTextarea) {
      this.activeTextarea.value = modalTextarea.value;
      
      // Disparar eventos
      this.activeTextarea.dispatchEvent(new Event('input', { bubbles: true }));
      this.activeTextarea.dispatchEvent(new Event('change', { bubbles: true }));
    }

    ogModal.close(this.currentModalId);
    this.cleanup();
  }

  static cancel() {
    if (this.currentModalId) {
      ogModal.close(this.currentModalId);
    }
    this.cleanup();
  }

  static cleanup() {
    this.activeTextarea = null;
    this.currentModalId = null;
  }

  static getLabel(textarea) {
    // Buscar label por id
    if (textarea.id) {
      const label = document.querySelector(`label[for="${textarea.id}"]`);
      if (label) return label.textContent.trim().replace('*', '');
    }

    // Buscar label en form-group
    const formGroup = textarea.closest('.form-group');
    if (formGroup) {
      const label = formGroup.querySelector('label');
      if (label) return label.textContent.trim().replace('*', '');
    }

    return 'Editar texto';
  }
}

// Exportar globalmente
window.ogTextareaExpander = ogTextareaExpander;

// Registrar en framework
if (typeof window.ogFramework !== 'undefined') {
  window.ogFramework.components.textareaExpander = ogTextareaExpander;
}

// Auto-inicializar cuando DOM esté listo
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => ogTextareaExpander.init());
} else {
  ogTextareaExpander.init();
}