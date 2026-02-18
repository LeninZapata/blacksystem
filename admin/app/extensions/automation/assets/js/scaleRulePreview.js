/**
 * scaleRulePreview.js
 * 
 * Genera un preview en tiempo real de las condiciones de las reglas de escalado
 * Convierte la estructura JSON del formulario a texto legible en español
 */

class scaleRulePreview {
  static formId = 'scale-rule-form-v2';
  static previewContainerId = 'scale-rule-preview-container';
  static debounceTimer = null;
  static debounceDelay = 300; // ms

  /**
   * Inicializa el sistema de preview
   */
  static init(formId = null) {
    if (formId) this.formId = formId;
    
    // Esperar a que el formulario esté listo
    setTimeout(() => {
      this.bindEvents();
      this.updatePreview();
    }, 500);
  }

  /**
   * Vincula eventos al formulario para actualizar el preview
   */
  static bindEvents() {
    const formEl = document.getElementById(this.formId);
    if (!formEl) {
      ogLogger?.warn('ext:automation:preview', `Formulario "${this.formId}" no encontrado`);
      return;
    }

    // Escuchar cambios en el formulario en tiempo real
    ['change', 'input'].forEach(eventType => {
      formEl.addEventListener(eventType, (e) => {
        // Debounce para evitar demasiadas actualizaciones
        clearTimeout(this.debounceTimer);
        this.debounceTimer = setTimeout(() => {
          this.updatePreview();
        }, this.debounceDelay);
      });
    });

    // Escuchar el evento 'form:filled' que se dispara cuando el formulario termina de llenarse
    formEl.addEventListener('form:filled', (e) => {
      ogLogger?.info('ext:automation:preview', `Formulario llenado completamente, actualizando preview`);
      // Sin debounce para actualizar inmediatamente después de llenar
      this.updatePreview();
    });

    ogLogger?.info('ext:automation:preview', 'Preview inicializado correctamente');
  }

  /**
   * Actualiza el contenido del preview
   */
  static updatePreview() {
    const container = document.getElementById(this.previewContainerId);
    if (!container) return;

    const formData = ogModule('form')?.getData(this.formId);
    if (!formData || !formData.condition_blocks || formData.condition_blocks.length === 0) {
      container.innerHTML = this.renderEmptyState();
      return;
    }

    const html = this.generatePreviewHTML(formData.condition_blocks);
    container.innerHTML = html;
  }

  /**
   * Genera el HTML del preview basado en los condition_blocks
   */
  static generatePreviewHTML(blocks) {
    if (!blocks || blocks.length === 0) {
      return this.renderEmptyState();
    }

    let html = '<div class="og-scale-rule-preview">';
    
    blocks.forEach((block, blockIndex) => {
      const blockNumber = blockIndex + 1;
      const blockName = block.block_name || `Bloque ${blockNumber}`;
      
      html += `<div class="og-preview-block og-bg-gray-50" style="padding: 1rem; margin-bottom: 1rem; border-radius: 0.375rem; border-left: 4px solid var(--og-blue-500);">`;
      html += `<div style="font-weight: 600; font-size: 0.95rem; margin-bottom: 0.75rem; color: var(--og-blue-700);">
        📌 ${blockName}
      </div>`;

      // Renderizar condiciones
      if (block.condition_groups && block.condition_groups.length > 0) {
        html += this.renderConditions(block.condition_groups, block.conditions_logic);
      } else {
        html += `<div style="color: var(--og-gray-500); font-style: italic; font-size: 0.875rem;">
          Sin condiciones configuradas
        </div>`;
      }

      // Renderizar acciones
      html += `<div style="margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid var(--og-gray-200);">`;
      html += `<div style="font-weight: 500; font-size: 0.875rem; color: var(--og-green-700); margin-bottom: 0.5rem;">
        ⚡ ENTONCES ejecutar:
      </div>`;
      
      if (block.actions && block.actions.length > 0) {
        html += '<ul style="margin: 0; padding-left: 1.5rem; font-size: 0.875rem;">';
        block.actions.forEach(action => {
          html += `<li style="margin-bottom: 0.25rem;">${this.renderAction(action)}</li>`;
        });
        html += '</ul>';
      } else {
        html += `<div style="color: var(--og-gray-500); font-style: italic; font-size: 0.875rem;">
          Sin acciones configuradas
        </div>`;
      }
      
      html += `</div>`; // Cierre acciones
      html += `</div>`; // Cierre block
      
      // Agregar separador "CASO CONTRARIO" entre bloques (excepto después del último)
      if (blockIndex < blocks.length - 1) {
        html += `<div style="text-align: center; margin: 1rem 0; color: var(--og-orange-600); font-weight: 600; font-size: 0.875rem; position: relative;">
          <div style="position: absolute; left: 0; right: 0; top: 50%; height: 1px; background: var(--og-gray-300);"></div>
          <span style="position: relative; background: white; padding: 0 1rem; display: inline-block;">
            ⚠️ CASO CONTRARIO
          </span>
        </div>`;
      }
    });

    html += '</div>'; // Cierre container
    return html;
  }

  /**
   * Renderiza las condiciones de un bloque
   */
  static renderConditions(groups, logic) {
    if (!groups || groups.length === 0) return '';

    const logicOperator = logic === 'or_and_or' ? '<strong>Y</strong>' : '<strong>O</strong>';
    
    let html = '<div style="font-size: 0.875rem;">';
    
    groups.forEach((group, groupIndex) => {
      if (groupIndex > 0) {
        html += `<div style="text-align: left; margin: 0.5rem 0; color: var(--og-purple-600); font-weight: 600;">
          ${logicOperator}
        </div>`;
      }

      if (group.conditions && group.conditions.length > 0) {
        const groupLogic = logic === 'and_or_and' ? 'AND' : 'OR';
        
        html += '<div style="padding-left: 1rem; border-left: 2px solid var(--og-gray-300);">';
        html += '<div style="color: var(--og-gray-700);">';
        html += '<strong style="color: var(--og-indigo-600);">SI</strong> (';
        
        group.conditions.forEach((condition, condIndex) => {
          if (condIndex > 0) {
            html += ` <strong style="color: var(--og-indigo-600);">${groupLogic}</strong> `;
          }
          html += this.renderCondition(condition);
        });
        
        html += ')';
        html += '</div>';
        html += '</div>';
      }
    });

    html += '</div>';
    return html;
  }

  /**
   * Renderiza una condición individual
   */
  static renderCondition(condition) {
    if (!condition || !condition.metric) return '<span style="color: var(--og-gray-400);">Configurar...</span>';

    const metricLabel = this.getMetricLabel(condition.metric);
    const operatorSymbol = this.getOperatorSymbol(condition.operator);
    let value = '';

    // Determinar el valor según el tipo de métrica
    if (condition.metric === 'current_hour') {
      const hour = condition.value_hour || 0;
      value = `${hour}:00h`;
    } else if (condition.metric === 'current_day_of_week') {
      value = this.getDayOfWeekLabel(condition.value_day);
    } else {
      const numValue = condition.value || 0;
      const suffix = condition.value_suffix || '';
      
      if (suffix === 'percent') {
        value = `${numValue}%`;
      } else if (suffix === 'currency') {
        value = `$${numValue}`;
      } else {
        value = numValue;
      }
      
      // Agregar rango de tiempo si aplica
      if (condition.time_range && !['current_hour', 'current_day_of_week'].includes(condition.metric)) {
        const timeRangeLabel = this.getTimeRangeLabel(condition.time_range);
        value += ` <span style="color: var(--og-gray-500); font-size: 0.85em;">[${timeRangeLabel}]</span>`;
      }
    }

    return `<strong style="color: var(--og-blue-600);">${metricLabel}</strong> ${operatorSymbol} <strong style="color: var(--og-orange-600);">${value}</strong>`;
  }

  /**
   * Renderiza una acción
   */
  static renderAction(action) {
    if (!action || !action.action_type) return '<span style="color: var(--og-gray-400);">Configurar acción...</span>';

    const actionLabel = this.getActionLabel(action.action_type);
    let details = '';

    switch (action.action_type) {
      case 'increase_budget':
      case 'decrease_budget':
        const changeType = this.getChangeTypeLabel(action.change_type);
        const changeBy = action.change_by || 0;
        const suffix = action.value_suffix || '';
        let changeValue = changeBy;
        
        if (suffix === 'percent') {
          changeValue = `${changeBy}%`;
        } else if (suffix === 'currency') {
          changeValue = `$${changeBy}`;
        }
        
        details = ` ${changeType} <strong>${changeValue}</strong>`;
        
        if (action.until_limit) {
          details += ` hasta <strong>$${action.until_limit}</strong>`;
        }
        break;

      case 'adjust_to_spend':
        const adjustType = action.adjustment_type === 'add' ? 'Agregar' : 'Restar';
        const adjustValue = action.adjustment_value || 0;
        details = ` ${adjustType} <strong>$${adjustValue}</strong> al gasto actual`;
        break;

      case 'disable_product':
        // Podríamos mostrar el nombre del producto si lo tenemos
        details = '';
        break;

      case 'pause':
        details = '';
        break;
    }

    // Agregar período de tiempo si existe
    let timePeriod = '';
    if (action.action_type === 'adjust_to_spend' && action.cooldown_hours) {
      timePeriod = ` <span style="color: var(--og-gray-500);">[${this.getTimePeriodLabel(action.cooldown_hours)}]</span>`;
    } else if (action.time_period) {
      timePeriod = ` <span style="color: var(--og-gray-500);">[${this.getTimePeriodLabel(action.time_period)}]</span>`;
    }

    return `<span style="color: var(--og-green-600);"><strong>${actionLabel}</strong></span>${details}${timePeriod}`;
  }

  /**
   * Estado vacío cuando no hay bloques
   */
  static renderEmptyState() {
    return `
      <div class="og-bg-gray-50" style="padding: 2rem; text-align: center; border-radius: 0.375rem; border: 2px dashed var(--og-gray-300);">
        <div style="font-size: 2rem; margin-bottom: 0.5rem;">📋</div>
        <div style="color: var(--og-gray-600); font-size: 0.875rem;">
          Comienza agregando bloques de reglas para ver el preview aquí
        </div>
      </div>
    `;
  }

  // ========== HELPERS DE ETIQUETAS ==========

  static getMetricLabel(metric) {
    const labels = {
      'roas': 'ROAS',
      'profit': 'Ganancia',
      'results': 'Resultados',
      'cost_per_result': 'Costo/Resultado',
      'spend': 'Gasto',
      'impressions': 'Impresiones',
      'frequency': 'Frecuencia',
      'roas_change_1h': 'Cambio ROAS (1h)',
      'roas_change_2h': 'Cambio ROAS (2h)',
      'roas_change_3h': 'Cambio ROAS (3h)',
      'profit_change_1h': 'Cambio Ganancia (1h)',
      'profit_change_2h': 'Cambio Ganancia (2h)',
      'profit_change_3h': 'Cambio Ganancia (3h)',
      'current_hour': 'Hora',
      'current_day_of_week': 'Día'
    };
    return labels[metric] || metric;
  }

  static getOperatorSymbol(operator) {
    const symbols = {
      '>': '>',
      '>=': '≥',
      '<': '<',
      '<=': '≤',
      '==': '=',
      '!=': '≠',
      'is_within': 'está dentro de'
    };
    return symbols[operator] || operator;
  }

  static getTimeRangeLabel(range) {
    const labels = {
      'today': 'Hoy',
      'yesterday': 'Ayer',
      'last_3d': 'Últimos 3 días',
      'last_7d': 'Últimos 7 días',
      'last_14d': 'Últimos 14 días',
      'last_30d': 'Últimos 30 días',
      'lifetime': 'Todo el tiempo'
    };
    return labels[range] || range;
  }

  static getActionLabel(actionType) {
    const labels = {
      'pause': '⏸️ Pausar',
      'increase_budget': '📈 Aumentar Presupuesto',
      'decrease_budget': '📉 Disminuir Presupuesto',
      'adjust_to_spend': '⚖️ Ajustar al Gasto',
      'disable_product': '🚫 Desactivar Producto'
    };
    return labels[actionType] || actionType;
  }

  static getChangeTypeLabel(changeType) {
    const labels = {
      'increase': 'aumentar',
      'decrease': 'disminuir',
      'set': 'establecer'
    };
    return labels[changeType] || changeType;
  }

  static getTimePeriodLabel(period) {
    const labels = {
      'everytime': 'Siempre',
      'once': 'Una vez',
      'daily': 'Diario',
      'every_3h': 'Cada 3h',
      'every_6h': 'Cada 6h'
    };
    return labels[period] || period;
  }

  static getDayOfWeekLabel(day) {
    const labels = {
      '1': 'Lunes',
      '2': 'Martes',
      '3': 'Miércoles',
      '4': 'Jueves',
      '5': 'Viernes',
      '6': 'Sábado',
      '7': 'Domingo'
    };
    return labels[day] || day;
  }
}

// Exponer globalmente
window.scaleRulePreview = scaleRulePreview;
