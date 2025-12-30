class ogGrouper {
  static async render(config, container) {
    ogLogger.info('com:grouper', '🔎 render() llamado', { config, container });

    if (!config?.groups) {
      ogLogger.error('com:grouper', 'Config inválido o sin groups');
      return;
    }

    const mode = config.mode || 'linear';
    ogLogger.info('com:grouper', `📦 Creando grouper ${mode} con ${config.groups.length} grupos`);

    if (mode === 'linear') {
      await this.renderLinear(config, container);
    } else if (mode === 'tabs') {
      await this.renderTabs(config, container);
    }

    ogLogger.info('com:grouper', '✅ render completado');
  }

  static async renderLinear(config, container) {
    const grouperId = `grouper-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
    const collapsible = config.collapsible !== false;
    const openFirst = config.openFirst !== false;

    let html = `<div class="grouper grouper-linear" id="${grouperId}">`;

    config.groups.forEach((group, index) => {
      const isOpen = openFirst && index === 0;
      const contentId = `${grouperId}-content-${index}`;

      html += `
        <div class="grouper-section ${isOpen ? 'open' : ''}" data-group-index="${index}">
          <div class="grouper-header ${collapsible ? 'collapsible' : ''}" data-toggle="${contentId}">
            <h3 class="grouper-title">${group.title || `Grupo ${index + 1}`}</h3>
            ${collapsible ? '<span class="grouper-toggle">▼</span>' : ''}
          </div>
          <div class="grouper-content" id="${contentId}" style="${isOpen ? '' : 'display:none'}">
            ${group.content || ''}
          </div>
        </div>
      `;
    });

    html += '</div>';
    container.innerHTML = html;

    if (collapsible) {
      const headers = container.querySelectorAll('.grouper-header.collapsible');

      headers.forEach(header => {
        header.addEventListener('click', () => {
          const contentId = header.dataset.toggle;
          const content = document.getElementById(contentId);
          const section = header.closest('.grouper-section');
          const isOpen = section.classList.contains('open');

          const allSections = container.querySelectorAll('.grouper-section');
          allSections.forEach(s => s.classList.remove('open'));

          const allContents = container.querySelectorAll('.grouper-content');
          allContents.forEach(c => c.style.display = 'none');

          if (!isOpen) {
            section.classList.add('open');
            content.style.display = '';
          }
        });
      });
    }

    await this.initDynamicContent(container);
  }

  static async renderTabs(config, container) {
    const grouperId = `grouper-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
    const activeIndex = config.activeIndex || 0;

    let html = `<div class="grouper grouper-tabs" id="${grouperId}">`;
    html += '<div class="grouper-tabs-header">';

    config.groups.forEach((group, index) => {
      const isActive = index === activeIndex;
      html += `
        <button class="grouper-tab-btn ${isActive ? 'active' : ''}" data-tab-index="${index}">
          ${group.title || `Tab ${index + 1}`}
        </button>
      `;
    });

    html += '</div><div class="grouper-tabs-content">';

    config.groups.forEach((group, index) => {
      const isActive = index === activeIndex;
      html += `
        <div class="grouper-tab-panel ${isActive ? 'active' : ''}" data-tab-index="${index}">
          ${group.content || ''}
        </div>
      `;
    });

    html += '</div></div>';
    container.innerHTML = html;

    const buttons = container.querySelectorAll('.grouper-tab-btn');
    const panels = container.querySelectorAll('.grouper-tab-panel');

    buttons.forEach((button, index) => {
      button.addEventListener('click', () => {
        buttons.forEach(b => b.classList.remove('active'));
        panels.forEach(p => p.classList.remove('active'));

        button.classList.add('active');
        panels[index].classList.add('active');
      });
    });

    await this.initDynamicContent(container);
  }

  static async initDynamicContent(container) {
    const dynamicForms = container.querySelectorAll('.dynamic-form[data-form-json]');
    const form = ogModule('form');

    for (const formContainer of dynamicForms) {
      const formJson = formContainer.dataset.formJson;
      if (formJson && form) {
        try {
          ogLogger.info('com:grouper', `Cargando form: ${formJson}`);
          await form.load(formJson, formContainer);
        } catch (error) {
          ogLogger.error('com:grouper', `Error cargando form ${formJson}:`, error);
        }
      }
    }
  }
}

window.ogGrouper = ogGrouper;

if (typeof window.ogFramework !== 'undefined') {
  window.ogFramework.components.grouper = ogGrouper;
  ogLogger.info('com:grouper', '✅ Registrado en ogFramework.components.grouper');
}

ogLogger.info('com:grouper', '✅ Registrado en window.grouper');