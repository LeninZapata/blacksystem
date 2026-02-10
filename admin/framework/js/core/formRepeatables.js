class ogFormRepeatables {
  static initRepeatables(formId, container = null) {
    const core = ogModule('formCore');
    const schema = core?.schemas?.get(formId);
    if (!schema || !schema.fields) return;

    const formEl = container
      ? container.querySelector(`#${formId}`)
      : document.getElementById(formId);

    if (!formEl) return;

    // Función recursiva para encontrar todos los repeatables (con nivel)
    const findRepeatables = (fields, basePath = '', level = 0) => {
      const repeatables = [];

      fields?.forEach(field => {
        if (field.type === 'repeatable') {
          const fieldPath = basePath ? `${basePath}.${field.name}` : field.name;
          repeatables.push({ field, path: fieldPath, level });

          if (field.fields && field.fields.length > 0) {
            const nested = findRepeatables(field.fields, fieldPath, level + 1);
            repeatables.push(...nested);
          }
        }
        else if (field.type === 'group' && field.fields) {
          repeatables.push(...findRepeatables(field.fields, basePath, level));
        }
        else if (field.type === 'grouper' && field.groups) {
          field.groups.forEach(group => {
            if (group.fields) {
              repeatables.push(...findRepeatables(group.fields, basePath, level));
            }
          });
        }
      });

      return repeatables;
    };

    const repeatables = findRepeatables(schema.fields);
    const topLevelRepeatables = repeatables.filter(r => r.level === 0);

    topLevelRepeatables.forEach(({ field, path }) => {
      const containerEl = formEl.querySelector(`.og-repeatable-items[data-path="${path}"]`);

      if (containerEl) {
        this.initRepeatableContainer(containerEl, field, path);
      } else {
        ogLogger?.error('core:form', `Container no encontrado para repeatable: "${path}"`);
      }
    });
  }

  static initRepeatableContainer(container, field, path) {
    container.dataset.fieldSchema = JSON.stringify(field.fields);
    container.dataset.itemCount = '0';

    if (field.columns) container.dataset.columns = field.columns;
    if (field.gap) container.dataset.gap = field.gap;
    if (field.accordion !== undefined) container.dataset.accordion = field.accordion;
    if (field.hasHeader !== undefined) container.dataset.hasHeader = field.hasHeader;
    if (field.headerTitle) container.dataset.headerTitle = field.headerTitle;
    if (field.removeText) container.dataset.removeText = field.removeText;
    if (field.accordionSingle !== undefined) container.dataset.accordionSingle = field.accordionSingle;
    if (field.accordionOpenFirst !== undefined) container.dataset.accordionOpenFirst = field.accordionOpenFirst;
    if (field.accordionOpenAll !== undefined) container.dataset.accordionOpenAll = field.accordionOpenAll;
    if (field.sortable !== undefined) container.dataset.sortable = field.sortable;

    const formEl = container.closest('form');
    const hasFillData = formEl?.dataset.hasFillData === 'true';
    const initialItems = parseInt(field.initialItems) || 0;
    
    if (initialItems > 0 && !hasFillData) {
      for (let i = 0; i < initialItems; i++) {
        this.addRepeatableItem(path);
      }
    }
  }

  static addRepeatableItem(path, buttonElement = null) {
    const core = ogModule('formCore');
    const render = ogModule('formRender');
    const inputs = ogModule('formInputs');
    const dataModule = ogModule('formData');
    
    let container;

    if (buttonElement) {
      const form = buttonElement.closest('form');
      if (form) {
        container = form.querySelector(`.og-repeatable-items[data-path="${path}"]`);
      } else {
        const parentContainer = buttonElement.closest('.og-repeatable-items');
        if (parentContainer) {
          container = parentContainer.querySelector(`.og-repeatable-items[data-path="${path}"]`);
        }
      }
    }

    if (!container) {
      container = document.querySelector(`.og-repeatable-items[data-path="${path}"]`);
    }

    if (!container) {
      ogLogger?.error('core:form', `Container no encontrado para: "${path}"`);
      return;
    }

    const fieldSchema = JSON.parse(container.dataset.fieldSchema || '[]');
    const itemCount = parseInt(container.dataset.itemCount || '0');
    const newIndex = itemCount;

    const columns = container.dataset.columns ? parseInt(container.dataset.columns) : null;
    const gap = container.dataset.gap || 'normal';
    const accordion = container.dataset.accordion === 'true';
    const hasHeader = container.dataset.hasHeader === 'true' || accordion;
    const headerTitle = container.dataset.headerTitle || 'Item #{index}';
    const removeText = core?.processI18nTitle(container.dataset.removeText || 'Eliminar');
    const accordionSingle = container.dataset.accordionSingle === 'true';
    const accordionOpenFirst = container.dataset.accordionOpenFirst === 'true';
    const accordionOpenAll = container.dataset.accordionOpenAll === 'true';
    const sortable = container.dataset.sortable === 'true';

    const itemPath = `${path}[${newIndex}]`;

    const itemFields = fieldSchema.map((field, fieldIndex) => {
      const fieldPath = `${itemPath}.${field.name}`;

      if (field.type === 'repeatable') {
        return render?.renderRepeatable(field, fieldPath);
      }

      if (field.type === 'group') {
        return render?.renderGroup(field, itemPath);
      }

      if (field.type === 'grouper') {
        return render?.renderGrouper(field, itemPath, fieldIndex);
      }

      return inputs?.renderField(field, fieldPath);
    }).join('');

    // Aplicar columns/gap si está configurado
    let fieldsHtml;
    if (columns) {
      const groupClass = `og-form-group-cols og-form-group-cols-${columns} og-form-group-gap-${gap}`;
      fieldsHtml = `<div class="${groupClass}">${itemFields}</div>`;
    } else {
      fieldsHtml = itemFields;
    }

    // Determinar si este item debe estar abierto por default
    let accordionDefaultOpen = false;
    if (accordion) {
      if (accordionOpenAll) {
        accordionDefaultOpen = true;
      } else if (accordionOpenFirst && newIndex === 0) {
        accordionDefaultOpen = true;
      } else if (!accordionOpenFirst && !accordionOpenAll) {
        accordionDefaultOpen = false;
      }
    }

    let itemHtml;
    if (hasHeader) {
      const titleText = headerTitle.replace('{index}', (newIndex + 1).toString());
      const processedTitle = core?.processI18nTitle(titleText);
      
      // Clases según si es acordeón o solo header
      const headerClass = accordion ? 'og-repeatable-item-accordion' : 'og-repeatable-item-with-header';
      const contentClass = accordion ? 'og-repeatable-item-body' : 'og-repeatable-content';
      
      const dragHandle = sortable ? `<span class="og-repeatable-drag-handle" draggable="true">⋮⋮</span>` : '';
      const toggleIcon = accordion ? `<span class="og-repeatable-toggle">${accordionDefaultOpen ? '▼' : '▶'}</span>` : '';
      const bodyDisplay = accordion ? (accordionDefaultOpen ? 'block' : 'none') : 'block';
      const collapsedClass = accordion && !accordionDefaultOpen ? 'og-collapsed' : '';

      itemHtml = `
        <div class="og-repeatable-item ${headerClass} ${collapsedClass}" data-index="${newIndex}">
          <div class="og-repeatable-item-header ${accordion ? 'og-clickable' : ''}" ${accordion ? 'data-toggle="accordion"' : ''}>
            <span class="og-repeatable-item-title">${processedTitle}</span>
            <div class="og-repeatable-item-header-actions">
              ${dragHandle}
              <button type="button" class="btn btn-danger btn-sm og-btn-repeatable-remove" title="${removeText}">
                <span class="og-repeatable-remove-icon">×</span>
              </button>
              ${toggleIcon}
            </div>
          </div>
          <div class="${contentClass}" style="display: ${bodyDisplay};">
            ${fieldsHtml}
          </div>
        </div>
      `;
    } else {
      itemHtml = `
        <div class="og-repeatable-item" data-index="${newIndex}">
          <div class="og-repeatable-content">
            ${fieldsHtml}
          </div>
          <div class="og-repeatable-remove">
            <button type="button" class="btn btn-danger btn-sm og-btn-repeatable-remove">${removeText}</button>
          </div>
        </div>
      `;
    }

    container.insertAdjacentHTML('beforeend', itemHtml);
    container.dataset.itemCount = (newIndex + 1).toString();

    // Inicializar repetibles anidados que puedan existir
    const addedItem = container.lastElementChild;
    if (addedItem) {
      const nestedRepeatables = addedItem.querySelectorAll('.og-repeatable-items');
      nestedRepeatables.forEach(nestedContainer => {
        const nestedPath = nestedContainer.dataset.path;
        const nestedSchema = JSON.parse(nestedContainer.dataset.fieldSchema || '[]');
        
        const nestedField = {
          fields: nestedSchema,
          columns: nestedContainer.dataset.columns,
          gap: nestedContainer.dataset.gap,
          accordion: nestedContainer.dataset.accordion === 'true',
          hasHeader: nestedContainer.dataset.hasHeader === 'true',
          headerTitle: nestedContainer.dataset.headerTitle,
          removeText: nestedContainer.dataset.removeText,
          accordionSingle: nestedContainer.dataset.accordionSingle === 'true',
          sortable: nestedContainer.dataset.sortable === 'true'
        };
        
        this.initRepeatableContainer(nestedContainer, nestedField, nestedPath);
      });
    }

    // Re-aplicar transforms y defaults a los nuevos campos
    setTimeout(() => {
      const formId = container.closest('form')?.id;
      if (formId) {
        dataModule?.bindTransforms(formId);

        // Aplicar defaults solo a los campos del nuevo item
        fieldSchema.forEach(field => {
          if (field.defaultValue !== undefined && field.defaultValue !== null) {
            const fieldPath = `${itemPath}.${field.name}`;
            const fieldEl = container.querySelector(`[name="${fieldPath}"]`);

            if (fieldEl) {
              const processedValue = dataModule?.processDefaultValue?.(field.defaultValue);

              if (fieldEl.type === 'checkbox' || fieldEl.type === 'radio') {
                fieldEl.checked = !!processedValue;
              } else {
                fieldEl.value = processedValue;
              }
            }
          }
        });

        ogModule('conditions')?.init(formId);
      }
    }, 10);
  }

  static getDragAfterElement(container, y) {
    const draggableElements = [...container.querySelectorAll('.og-repeatable-item:not(.og-dragging)')];

    return draggableElements.reduce((closest, child) => {
      const box = child.getBoundingClientRect();
      const offset = y - box.top - box.height / 2;

      if (offset < 0 && offset > closest.offset) {
        return { offset: offset, element: child };
      } else {
        return closest;
      }
    }, { offset: Number.NEGATIVE_INFINITY }).element;
  }

  static reindexRepeatableItems(container) {
    const core = ogModule('formCore');
    const items = container.querySelectorAll(':scope > .og-repeatable-item');
    const headerTitle = container.dataset.headerTitle || 'Item #{index}';
    const path = container.dataset.path;

    items.forEach((item, index) => {
      item.dataset.index = index;

      if (headerTitle.includes('{index}')) {
        const titleEl = item.querySelector('.og-repeatable-item-title');
        if (titleEl) {
          const newTitle = headerTitle.replace('{index}', (index + 1).toString());
          titleEl.textContent = core?.processI18nTitle(newTitle);
        }
      }

      const inputs = item.querySelectorAll('input, select, textarea');
      inputs.forEach(input => {
        const currentName = input.getAttribute('name');
        if (currentName) {
          const newName = currentName.replace(/\[(\d+)\]/, `[${index}]`);
          input.setAttribute('name', newName);
        }
      });
    });

    container.dataset.itemCount = items.length.toString();
  }

  static findNestedRepeatables(fields, basePath = '') {
    const repeatables = [];

    fields?.forEach(field => {
      if (field.type === 'repeatable') {
        const fieldPath = `${basePath}.${field.name}`;
        repeatables.push({ field, path: fieldPath });
      }
      else if (field.type === 'group' && field.fields) {
        repeatables.push(...this.findNestedRepeatables(field.fields, basePath));
      }
    });

    return repeatables;
  }

  static setupDragAndDrop() {
    let draggedItem = null;
    let draggedContainer = null;

    document.addEventListener('dragstart', (e) => {
      const dragHandle = e.target.closest('.og-repeatable-drag-handle');
      if (!dragHandle) return;

      const item = dragHandle.closest('.og-repeatable-item');
      const container = item?.closest('.og-repeatable-items');

      if (item && container && container.dataset.sortable === 'true') {
        draggedItem = item;
        draggedContainer = container;
        item.classList.add('og-dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/html', item.innerHTML);
      }
    });

    document.addEventListener('dragover', (e) => {
      if (!draggedItem) return;
      e.preventDefault();

      const afterElement = this.getDragAfterElement(draggedContainer, e.clientY);

      if (afterElement == null) {
        draggedContainer.appendChild(draggedItem);
      } else {
        draggedContainer.insertBefore(draggedItem, afterElement);
      }
    });

    document.addEventListener('dragend', (e) => {
      if (!draggedItem) return;

      draggedItem.classList.remove('og-dragging');

      if (draggedContainer) {
        this.reindexRepeatableItems(draggedContainer);
      }

      draggedItem = null;
      draggedContainer = null;
    });
  }

  static bindRepeatableEvents() {
    document.addEventListener('click', (e) => {
      // Botón agregar
      if (e.target.classList.contains('og-repeatable-add')) {
        const path = e.target.dataset.path;
        this.addRepeatableItem(path, e.target);
      }

      // Botón eliminar
      if (e.target.classList.contains('og-btn-repeatable-remove')) {
        e.stopPropagation();
        const item = e.target.closest('.og-repeatable-item');
        if (item && confirm('¿Eliminar este elemento?')) {
          item.remove();
        }
      }

      // Toggle de acordeón
      const header = e.target.closest('.og-repeatable-item-header');
      if (header && header.dataset.toggle === 'accordion') {
        if (e.target.classList.contains('og-btn-repeatable-remove') ||
            e.target.closest('.og-repeatable-remove') ||
            e.target.classList.contains('og-repeatable-drag-handle') ||
            e.target.closest('.og-repeatable-drag-handle')) {
          return;
        }

        const item = header.closest('.og-repeatable-item');
        const body = item.querySelector('.og-repeatable-item-body');
        const toggle = header.querySelector('.og-repeatable-toggle');

        if (body) {
          const isOpen = body.style.display !== 'none';

          const container = item.closest('.og-repeatable-items');
          if (container && container.dataset.accordionSingle === 'true' && !isOpen) {
            const allItems = container.querySelectorAll(':scope > .og-repeatable-item');

            allItems.forEach(otherItem => {
              if (otherItem !== item) {
                const otherBody = otherItem.querySelector('.og-repeatable-item-body');
                const otherToggle = otherItem.querySelector('.og-repeatable-toggle');

                if (otherBody && otherBody.style.display !== 'none') {
                  otherBody.style.display = 'none';
                  if (otherToggle) {
                    otherToggle.textContent = '▶';
                  }
                  otherItem.classList.add('og-collapsed');
                }
              }
            });
          }

          body.style.display = isOpen ? 'none' : 'block';
          if (toggle) {
            toggle.textContent = isOpen ? '▶' : '▼';
          }
          item.classList.toggle('og-collapsed', isOpen);
        }
      }
    });
  }
}

// Global
window.ogFormRepeatables = ogFormRepeatables;

// Registrar en ogFramework
if (typeof window.ogFramework !== 'undefined') {
  window.ogFramework.core.formRepeatables = ogFormRepeatables;
}