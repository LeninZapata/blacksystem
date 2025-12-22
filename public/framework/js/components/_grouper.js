/**
 * Grouper Component - Agrupa elementos visualmente
 * Modos: linear (acordeón) y tabs
 */
class grouper {
  static counter = 0;
  static instances = new Map();

  static render(config, container) {
    const grouperId = `grouper-${++this.counter}`;
    const mode = config.mode || 'linear';

    if (!['linear', 'tabs'].includes(mode)) {
      logger.error('com:grouper', `Modo "${mode}" no válido`);
      return;
    }

    const html = mode === 'linear' ?
      this.renderLinear(grouperId, config) :
      this.renderTabs(grouperId, config);

    container.innerHTML = html;

    this.instances.set(grouperId, { mode, config, container });

    // Usar setTimeout para asegurar que el DOM esté listo
    setTimeout(() => {
      const grouperElement = document.getElementById(grouperId);
      if (grouperElement) {
        this.bindEvents(grouperId);
        this.loadDynamicForms(grouperElement);
      } else {
        logger.error('com:grouper', `Elemento ${grouperId} no encontrado en el DOM`);
      }
    }, 50);

    return grouperId;
  }

  static async loadDynamicForms(container) {
    const formContainers = container.querySelectorAll('.dynamic-form[data-form-json]');

    if (formContainers.length === 0) {
      return;
    }

    for (const formContainer of formContainers) {
      const formJson = formContainer.dataset.formJson;
      try {
        await form.load(formJson, formContainer);
      } catch (error) {
        logger.error('com:grouper', `Error cargando formulario ${formJson}:`, error);
        formContainer.innerHTML = `<div class="error" style="padding: 1rem; background: #fee; border: 1px solid #fcc; border-radius: 0.5rem;">${__('com.grouper.error_loading_form', { form: formJson })}</div>`;
      }
    }
  }

  static renderLinear(grouperId, config) {
    const collapsible = config.collapsible !== false;
    const openFirst = config.openFirst !== false;
    let html = `<div class="grouper grouper-linear" id="${grouperId}">`;

    config.groups.forEach((group, index) => {
      const isOpen = openFirst && index === 0;
      const groupId = `${grouperId}-group-${index}`;
      html += `
        <div class="grouper-section ${isOpen ? 'open' : ''}" data-group-index="${index}">
          <div class="grouper-header ${collapsible ? 'collapsible' : ''}" ${collapsible ? `data-toggle="${groupId}"` : ''}>
            <h3 class="grouper-title">${group.title || `Grupo ${index + 1}`}</h3>
            ${collapsible ? '<span class="grouper-toggle">▼</span>' : ''}
          </div>
          <div class="grouper-content" id="${groupId}" ${!isOpen ? 'style="display:none"' : ''}>
            ${group.content || ''}
          </div>
        </div>`;
    });

    return html + `</div>`;
  }

  static renderTabs(grouperId, config) {
    const activeIndex = config.activeIndex || 0;
    let html = `<div class="grouper grouper-tabs" id="${grouperId}"><div class="grouper-tabs-header">`;

    config.groups.forEach((group, index) => {
      html += `<button class="grouper-tab-btn ${index === activeIndex ? 'active' : ''}" data-tab-index="${index}">
          ${group.title || `Tab ${index + 1}`}</button>`;
    });

    html += `</div><div class="grouper-tabs-content">`;

    config.groups.forEach((group, index) => {
      html += `<div class="grouper-tab-panel ${index === activeIndex ? 'active' : ''}" data-panel-index="${index}">
          ${group.content || ''}</div>`;
    });

    return html + `</div></div>`;
  }

  static bindEvents(grouperId) {
    const container = document.getElementById(grouperId);

    if (!container) {
      logger.error('com:grouper', `Container ${grouperId} no encontrado al bindear eventos`);
      return;
    }

    const instance = this.instances.get(grouperId);

    if (!instance) {
      logger.error('com:grouper', `Instancia ${grouperId} no encontrada al bindear eventos`);
      return;
    }

    if (instance.mode === 'linear') {
      this.bindLinearEvents(grouperId, container);
    } else {
      this.bindTabsEvents(grouperId, container);
    }
  }

  static bindLinearEvents(grouperId, container) {
    const headers = container.querySelectorAll('.grouper-header.collapsible');

    headers.forEach((header, index) => {
      header.addEventListener('click', () => {
        const content = document.getElementById(header.dataset.toggle);
        const section = header.closest('.grouper-section');

        if (!content) {
          logger.error('com:grouper', `Content no encontrado para toggle: ${header.dataset.toggle}`);
          return;
        }

        const isOpen = section.classList.contains('open');
        section.classList.toggle('open', !isOpen);
        content.style.display = isOpen ? 'none' : 'block';

        // Cargar formularios dinámicos al abrir la sección
        if (!isOpen) {
          this.loadDynamicForms(content);
        }
      });
    });
  }

  static bindTabsEvents(grouperId, container) {
    const tabButtons = container.querySelectorAll('.grouper-tab-btn');
    const tabPanels = container.querySelectorAll('.grouper-tab-panel');

    // Detectar overflow para mostrar degradado
    this.checkTabsOverflow(grouperId, container);

    // Recheck on window resize
    window.addEventListener('resize', () => this.checkTabsOverflow(grouperId, container));

    tabButtons.forEach((button, index) => {
      button.addEventListener('click', () => {
        const tabIndex = parseInt(button.dataset.tabIndex);

        tabButtons.forEach(btn => btn.classList.remove('active'));
        tabPanels.forEach(panel => panel.classList.remove('active'));

        button.classList.add('active');

        // Scroll suave al tab activo si está fuera de vista
        button.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });

        const targetPanel = container.querySelector(`[data-panel-index="${tabIndex}"]`);
        if (targetPanel) {
          targetPanel.classList.add('active');

          // Cargar formularios dinámicos al cambiar de tab
          this.loadDynamicForms(targetPanel);
        } else {
          logger.error('com:grouper', `Panel ${tabIndex} no encontrado`);
        }
      });
    });
  }

  static checkTabsOverflow(grouperId, container) {
    const tabsHeader = container.querySelector('.grouper-tabs-header');
    if (!tabsHeader) return;

    const updateOverflow = () => {
      const scrollLeft = tabsHeader.scrollLeft;
      const scrollWidth = tabsHeader.scrollWidth;
      const clientWidth = tabsHeader.clientWidth;

      // Hay overflow si el contenido es más ancho que el contenedor
      const hasOverflow = scrollWidth > clientWidth;

      if (!hasOverflow) {
        tabsHeader.classList.remove('has-overflow-left', 'has-overflow-right');
        return;
      }

      // Detectar si está al inicio
      const isAtStart = scrollLeft <= 5;

      // Detectar si está al final
      const isAtEnd = scrollLeft + clientWidth >= scrollWidth - 5;

      // Agregar/quitar clases según posición
      if (isAtStart) {
        tabsHeader.classList.remove('has-overflow-left');
      } else {
        tabsHeader.classList.add('has-overflow-left');
      }

      if (isAtEnd) {
        tabsHeader.classList.remove('has-overflow-right');
      } else {
        tabsHeader.classList.add('has-overflow-right');
      }
    };

    // Ejecutar al cargar
    updateOverflow();

    // Actualizar en scroll
    tabsHeader.addEventListener('scroll', updateOverflow);
  }

  static switchTab(grouperId, tabIndex) {
    const button = document.getElementById(grouperId)?.querySelector(`[data-tab-index="${tabIndex}"]`);
    if (button) {
      button.click();
    } else {
      logger.error('com:grouper', `Tab ${tabIndex} no encontrado en ${grouperId}`);
    }
  }

  static toggleSection(grouperId, sectionIndex, forceOpen = null) {
    const section = document.getElementById(grouperId)?.querySelector(`[data-group-index="${sectionIndex}"]`);
    if (!section) {
      logger.error('com:grouper', `Sección ${sectionIndex} no encontrada en ${grouperId}`);
      return;
    }

    const header = section.querySelector('.grouper-header');
    const isOpen = section.classList.contains('open');

    if (forceOpen === null || (forceOpen && !isOpen) || (!forceOpen && isOpen)) {
      header.click();
    }
  }

  static openAll(grouperId) {
    document.getElementById(grouperId)?.querySelectorAll('.grouper-section').forEach((_, index) => {
      this.toggleSection(grouperId, index, true);
    });
  }

  static closeAll(grouperId) {
    document.getElementById(grouperId)?.querySelectorAll('.grouper-section').forEach((_, index) => {
      this.toggleSection(grouperId, index, false);
    });
  }
}

window.grouper = grouper;