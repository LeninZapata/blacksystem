class ogFormRender {
  static render(schema) {
    const core = ogModule('formCore');
    const realId = schema.id.split('-')[0];
    
    return `
      <div class="og-form-container">
        ${schema.title ? `<h2>${core?.t(schema.title)}</h2>` : ''}
        ${schema.description ? `<p class="og-form-desc">${core?.t(schema.description)}</p>` : ''}

        <form id="${schema.id}" data-form-id="${schema.id}" data-real-id="${realId}" method="post">
          ${schema.toolbar ? this.renderToolbar(schema.toolbar) : ''}
          ${schema.fields ? this.renderFields(schema.fields) : ''}
          ${schema.statusbar ? this.renderStatusbar(schema.statusbar) : ''}
        </form>
      </div>
    `;
  }

  static renderToolbar(items) {
    const leftItems = [];
    const rightItems = [];

    items.forEach(item => {
      if (item.align === 'left') {
        leftItems.push(item);
      } else {
        rightItems.push(item);
      }
    });

    const leftHtml = leftItems.length > 0 ? `<div class="og-flex og-gap-sm">${this.renderFields(leftItems)}</div>` : '';
    const rightHtml = rightItems.length > 0 ? `<div class="og-flex og-gap-sm">${this.renderFields(rightItems)}</div>` : '';

    return `<div class="og-form-toolbar og-flex og-between">${leftHtml}${rightHtml}</div>`;
  }

  static renderStatusbar(items) {
    const leftItems = [];
    const rightItems = [];

    items.forEach(item => {
      if (item.align === 'left') {
        leftItems.push(item);
      } else {
        rightItems.push(item);
      }
    });

    const leftHtml = leftItems.length > 0 ? `<div class="og-statusbar-left">${this.renderFields(leftItems)}</div>` : '';
    const rightHtml = rightItems.length > 0 ? `<div class="og-statusbar-right">${this.renderFields(rightItems)}</div>` : '';

    return `<div class="og-form-statusbar">${leftHtml}${rightHtml}</div>`;
  }

  static renderFields(fields, path = '') {
    const core = ogModule('formCore');
    const inputs = ogModule('formInputs');
    
    return fields.map((field, index) => {
      const normalizedField = core?.normalizeFieldType(field);

      if (!core?.hasRoleAccess(normalizedField)) return '';

      const fieldPath = path ? `${path}.${normalizedField.name}` : normalizedField.name;

      if (normalizedField.type === 'repeatable') {
        return this.renderRepeatable(normalizedField, fieldPath);
      }

      if (normalizedField.type === 'group') {
        return this.renderGroup(normalizedField, path);
      }

      if (normalizedField.type === 'grouper') {
        return this.renderGrouper(normalizedField, path, index);
      }

      return inputs?.renderField(normalizedField, fieldPath) || '';
    }).join('');
  }

  static renderRepeatable(field, path) {
    const core = ogModule('formCore');
    const addText = core?.t(field.addText) || 'Agregar';
    const buttonPosition = field.buttonPosition || 'top';

    const description = field.description
      ? `<p class="og-repeatable-description">${core?.t(field.description)}</p>`
      : '';

    const processedAddText = core?.processI18nTitle(addText);
    const addButton = `
      <button type="button" class="btn btn-primary btn-sm og-repeatable-add" data-path="${path}">
        ${processedAddText}
      </button>
    `;

    const headerContent = `
      <div class="og-repeatable-header-content">
        <h4>${core?.t(field.label)}</h4>
        ${description}
      </div>
    `;

    // Serializar el schema de fields para guardar en data-field-schema
    const fieldSchemaJson = JSON.stringify(field.fields || []).replace(/"/g, '&quot;');

    if (buttonPosition === 'middle') {
      return `
        <div class="og-form-repeatable" data-field-path="${path}">
          <div class="og-repeatable-header">
            ${headerContent}
          </div>
          <div class="og-repeatable-add-container" style="margin: 0.5rem 0;">
            ${addButton}
          </div>
          <div class="og-repeatable-items" data-path="${path}" data-field-schema="${fieldSchemaJson}"></div>
        </div>
      `;
    } else if (buttonPosition === 'bottom') {
      return `
        <div class="og-form-repeatable" data-field-path="${path}">
          <div class="og-repeatable-header">
            ${headerContent}
          </div>
          <div class="og-repeatable-items" data-path="${path}" data-field-schema="${fieldSchemaJson}"></div>
          <div class="og-repeatable-add-container" style="margin: 0.5rem 0; text-align: center;">
            ${addButton}
          </div>
        </div>
      `;
    } else {
      return `
        <div class="og-form-repeatable" data-field-path="${path}">
          <div class="og-repeatable-header">
            ${headerContent}
            ${addButton}
          </div>
          <div class="og-repeatable-items" data-path="${path}" data-field-schema="${fieldSchemaJson}"></div>
        </div>
      `;
    }
  }

  static renderGroup(field, basePath) {
    const core = ogModule('formCore');
    const inputs = ogModule('formInputs');
    const columns = field.columns || 2;
    const gap = field.gap || 'normal';

    const groupFieldPath = field.name ? (basePath ? `${basePath}.${field.name}` : field.name) : '';
    const dataPath = groupFieldPath ? `data-field-path="${groupFieldPath}"` : '';

    const groupClass = `og-form-group-cols og-form-group-cols-${columns} og-form-group-gap-${gap}`;

    return `
      <div class="${groupClass}" ${dataPath}>
        ${field.fields ? field.fields.map(subField => {
          const normalizedSubField = core?.normalizeFieldType(subField);

          if (!core?.hasRoleAccess(normalizedSubField)) return '';

          const fieldPath = basePath ? `${basePath}.${normalizedSubField.name}` : normalizedSubField.name;
          return inputs?.renderField(normalizedSubField, fieldPath) || '';
        }).join('') : ''}
      </div>
    `;
  }

  static renderGrouper(field, parentPath, index = 0) {
    const core = ogModule('formCore');
    const mode = field.mode || 'linear';
    const grouperId = `og-grouper-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;

    let fieldPath;
    if (field.name) {
      fieldPath = parentPath ? `${parentPath}.${field.name}` : field.name;
    } else {
      fieldPath = parentPath ? `${parentPath}.__grouper_${index}` : `__grouper_${index}`;
    }

    let html = '';

    if (mode === 'linear') {
      html += this.renderGrouperLinear(field, grouperId, parentPath, fieldPath);
    } else if (mode === 'tabs') {
      html += this.renderGrouperTabs(field, grouperId, parentPath, fieldPath);
    }

    setTimeout(() => {
      this.bindGrouperEvents(grouperId, mode);
    }, 10);

    return html;
  }

  static renderGrouperLinear(field, grouperId, parentPath, fieldPath = "") {
    const core = ogModule('formCore');
    const collapsible = field.collapsible !== false;
    const openFirst = field.openFirst !== false;

    let html = `<div class="og-grouper og-grouper-linear" id="${grouperId}" data-field-path="${fieldPath}">`;

    field.groups.forEach((group, index) => {
      const isOpen = openFirst && index === 0;
      const contentId = `${grouperId}-content-${index}`;
      const processedTitle = core?.processI18nTitle(group.title) || `Grupo ${index + 1}`;

      html += `
        <div class="og-grouper-section ${isOpen ? 'open' : ''} ${!collapsible ? 'non-collapsible' : ''}" data-group-index="${index}">
          <div class="og-grouper-header ${collapsible ? 'collapsible' : 'non-collapsible'}"
              ${collapsible ? `data-toggle="${contentId}"` : ''}>
            <h3 class="og-grouper-title">${processedTitle}</h3>
            ${collapsible ? '<span class="og-grouper-toggle">▼</span>' : ''}
          </div>
          <div class="og-grouper-content" id="${contentId}" ${!isOpen && collapsible ? 'style="display:none"' : ''}>
      `;

      if (group.fields && Array.isArray(group.fields)) {
        html += this.renderFields(group.fields, parentPath);
      }

      html += `
          </div>
        </div>
      `;
    });

    html += `</div>`;
    return html;
  }

  static renderGrouperTabs(field, grouperId, parentPath, fieldPath = "") {
    const core = ogModule('formCore');
    const activeIndex = field.activeIndex || 0;

    let html = `<div class="og-grouper og-grouper-tabs" id="${grouperId}" data-field-path="${fieldPath}">`;

    html += `<div class="og-grouper-tabs-header">`;
    field.groups.forEach((group, index) => {
      const isActive = index === activeIndex;
      const processedTitle = core?.processI18nTitle(group.title) || `Tab ${index + 1}`;

      html += `
        <button type="button" class="og-grouper-tab-btn ${isActive ? 'active' : ''}"
                data-tab-index="${index}">
          ${processedTitle}
        </button>
      `;
    });
    html += `</div>`;

    html += `<div class="og-grouper-tabs-content">`;
    field.groups.forEach((group, index) => {
      const isActive = index === activeIndex;

      html += `
        <div class="og-grouper-tab-panel ${isActive ? 'active' : ''}"
            data-panel-index="${index}">
      `;

      if (group.fields && Array.isArray(group.fields)) {
        html += this.renderFields(group.fields, parentPath);
      }

      html += `</div>`;
    });
    html += `</div>`;

    html += `</div>`;
    return html;
  }

  static bindGrouperEvents(grouperId, mode) {
    const container = document.getElementById(grouperId);
    const conditions = ogModule('conditions');
    if (!container) return;

    if (mode === 'linear') {
      container.querySelectorAll(':scope > .og-grouper-section > .og-grouper-header.collapsible').forEach(header => {
        header.addEventListener('click', (e) => {
          const targetId = header.dataset.toggle;
          const content = document.getElementById(targetId);
          const section = header.closest('.og-grouper-section');

          if (!content) return;

          const isOpen = section.classList.contains('open');

          if (isOpen) {
            section.classList.remove('open');
            content.style.display = 'none';
          } else {
            section.classList.add('open');
            content.style.display = 'block';

            if (conditions) {
              const formId = container.closest('form')?.id;
              if (formId) {
                setTimeout(() => {
                  conditions.evaluate(formId);
                }, 50);
              }
            }
          }
        });
      });
    } else if (mode === 'tabs') {
      const tabButtons = container.querySelectorAll(':scope > .og-grouper-tabs-header > .og-grouper-tab-btn');
      const tabPanels = container.querySelectorAll(':scope > .og-grouper-tabs-content > .og-grouper-tab-panel');

      tabButtons.forEach(button => {
        button.addEventListener('click', () => {
          const index = parseInt(button.dataset.tabIndex);

          tabButtons.forEach(btn => btn.classList.remove('active'));
          tabPanels.forEach(panel => panel.classList.remove('active'));

          button.classList.add('active');

          if (tabPanels[index]) {
            tabPanels[index].classList.add('active');

            if (conditions) {
              const formId = container.closest('form')?.id;
              if (formId) {
                setTimeout(() => {
                  conditions.evaluate(formId);
                }, 50);
              }
            }
          }
        });
      });
    }
  }
}

// Global
window.ogFormRender = ogFormRender;

// Registrar en ogFramework
if (typeof window.ogFramework !== 'undefined') {
  window.ogFramework.core.formRender = ogFormRender;
}