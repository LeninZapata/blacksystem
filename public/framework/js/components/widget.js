class widget {
  static grids = new Map();
  static draggedWidget = null;

  static async render(config, container) {
    if (!container || typeof container.appendChild !== 'function') {
      logger.error('com:widget', 'Container no válido');
      return;
    }

    if (!config || !config.widgets) {
      logger.error('com:widget', 'Config no válido o sin widgets');
      return;
    }

    const gridId = `widget-grid-${window.VERSION}`;
    this.grids.set(gridId, config);
    const cols = config.columns || 2;

    const grid = document.createElement('div');
    grid.className = 'widget-grid';
    grid.id = gridId;
    grid.dataset.cols = cols;

    container.innerHTML = '';
    container.appendChild(grid);

    for (const widgetConfig of config.widgets || []) {
      await this.addWidget(grid, widgetConfig);
    }

    this.bindDragEvents(grid);
  }

  static async addWidget(grid, config) {
    if (!grid) return;

    const widgetId = `widget-${window.VERSION}-${Math.random().toString(36).substr(2, 9)}`;

    const widget = document.createElement('div');
    widget.className = 'widget-item';
    widget.id = widgetId;
    widget.draggable = true;
    widget.dataset.order = config.order || 999;

    const widgetBody = document.createElement('div');
    widgetBody.className = 'widget-body';
    widgetBody.setAttribute('data-widget-id', widgetId);
    widgetBody.innerHTML = `<div class="widget-loading">${__('com.widget.loading')}</div>`;

    const widgetHeader = document.createElement('div');
    widgetHeader.className = 'widget-header';
    widgetHeader.innerHTML = `
      <h4>${config.title || __('com.widget.title')}</h4>
      <span class="widget-drag">⋮⋮</span>
    `;

    widget.appendChild(widgetHeader);
    widget.appendChild(widgetBody);

    grid.appendChild(widget);

    await this.loadWidgetContent(widgetBody, config);
  }

  static async loadWidgetContent(body, config) {
    if (!body) {
      logger.error('com:widget', `Body no válido`);
      return;
    }

    try {
      if (config.component) {
        const compConfig = config.config || {};
        const placeholder = document.createElement('div');
        placeholder.className = 'dynamic-component';
        body.innerHTML = '';
        body.appendChild(placeholder);

        if (window[config.component]?.render) {
          await window[config.component].render(compConfig, placeholder);
        } else {
          logger.error('com:widget', `Componente ${config.component} no existe en window`);
        }
      } else if (config.view) {
        await view.loadView(config.view, body);
      } else if (config.form) {
        await form.load(config.form, body);
      } else if (config.html) {
        body.innerHTML = config.html;
      } else if (config.content) {
        body.innerHTML = this.renderContent(config.content);
      }
    } catch (error) {
      logger.error('com:widget', `Error cargando widget:`, error);
      body.innerHTML = `<div class="widget-error">${__('com.widget.error_loading')}</div>`;
    }
  }

  static renderContent(content) {
    if (typeof content === 'string') return content;
    if (Array.isArray(content)) {
      return content.map(item => {
        if (typeof item === 'string') return item;
        if (item.type === 'html') return item.content || '';
        return '';
      }).join('');
    }
    return '';
  }

  static bindDragEvents(grid) {
    if (!grid) return;

    const widgets = grid.querySelectorAll('.widget-item');
    if (!widgets || widgets.length === 0) return;

    widgets.forEach(w => {
      w.addEventListener('dragstart', (e) => {
        this.draggedWidget = w;
        w.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
      });

      w.addEventListener('dragend', () => {
        w.classList.remove('dragging');
        this.draggedWidget = null;
      });

      w.addEventListener('dragover', (e) => {
        e.preventDefault();
        if (!this.draggedWidget || this.draggedWidget === w) return;

        const rect = w.getBoundingClientRect();
        const midY = rect.top + rect.height / 2;

        if (e.clientY < midY) {
          w.parentNode.insertBefore(this.draggedWidget, w);
        } else {
          w.parentNode.insertBefore(this.draggedWidget, w.nextSibling);
        }
      });
    });
  }
}

window.widget = widget;