class budgetAdjust {
  static currentAssetId = null;
  static currentAssetType = null;  // ← AGREGAR
  static currentBudget = null;
  static currentProductAdAssetId = null;

  // Abrir modal de ajuste (llamado desde scaleRuleStats)
  static open(assetId, assetType, productAdAssetId) {  // ← RECIBIR assetType
    if (!assetId || !productAdAssetId) {
      ogComponent('toast').error('Debes seleccionar un activo publicitario primero');
      return;
    }

    this.currentAssetId = assetId;
    this.currentAssetType = assetType;  // ← GUARDAR
    this.currentProductAdAssetId = productAdAssetId;

    ogModal.open('automation|forms/budget-adjust-form', {
      title: '💰 Ajustar Presupuesto',
      width: '90%',
      maxWidth: '600px',
      showFooter: false,
      afterRender: (formId) => {
        budgetAdjust.loadBudgetData();
        budgetAdjust.setupListeners(formId);
      }
    });
  }

  // Cargar datos del presupuesto
  static async loadBudgetData() {
    const currentDisplay = document.getElementById('current-budget-display');
    const spentDisplay = document.getElementById('spent-today-display');
    const remainingDisplay = document.getElementById('remaining-display');

    if (currentDisplay) currentDisplay.textContent = '⏳ Consultando...';
    if (spentDisplay) spentDisplay.textContent = '...';
    if (remainingDisplay) remainingDisplay.textContent = '...';

    try {
      // IMPORTANTE: Agregar timestamp para evitar cache del navegador
      const timestamp = Date.now();
      const url = `/api/adMetrics/budget-status?ad_asset_id=${this.currentAssetId}&real_time=1&_t=${timestamp}`;
      
      // Agregar cache: 'no-store' para forzar consulta real
      const response = await ogModule('api').get(url, { cache: 'no-store' });

      if (!response.success) {
        throw new Error(response.error || 'Error obteniendo presupuesto');
      }

      this.currentBudget = response.budget.current_daily;
      const spent = response.budget.spent || 0;
      const remaining = response.budget.remaining_daily || 0;

      if (currentDisplay) currentDisplay.textContent = `$${this.currentBudget.toFixed(2)}`;
      if (spentDisplay) spentDisplay.textContent = `$${spent.toFixed(2)}`;
      if (remainingDisplay) {
        remainingDisplay.textContent = `$${remaining.toFixed(2)}`;
        remainingDisplay.style.color = remaining < 0.5 ? '#ef4444' : 'white';
      }

    } catch (error) {
      ogLogger.error('ext:automation:budgetAdjust', 'Error cargando presupuesto', error);
      if (currentDisplay) {
        currentDisplay.textContent = '❌ Error';
        currentDisplay.style.fontSize = '1.5rem';
      }
      ogComponent('toast').error('Error al cargar el presupuesto actual');
    }
  }

  // Configurar listeners
  static setupListeners(formId) {
    const formEl = document.getElementById(formId);
    if (!formEl) return;

    const adjustmentInput = formEl.querySelector('[name="adjustment_amount"]');
    const previewContainer = document.getElementById('new-budget-preview');

    if (adjustmentInput && previewContainer) {
      adjustmentInput.addEventListener('input', () => {
        const adjustment = parseFloat(adjustmentInput.value) || 0;

        if (adjustment === 0 || this.currentBudget === null) {
          previewContainer.style.display = 'none';
          return;
        }

        const newBudget = this.currentBudget + adjustment;
        const previewText = previewContainer.querySelector('div:last-child');

        if (newBudget <= 0) {
          previewContainer.style.background = '#fee2e2';
          previewContainer.style.borderColor = '#ef4444';
          previewText.style.color = '#dc2626';
          previewText.textContent = '❌ Presupuesto no puede ser $0 o negativo';
          previewContainer.style.display = 'block';
          return;
        }

        previewContainer.style.background = '#ecfdf5';
        previewContainer.style.borderColor = '#10b981';
        previewText.style.color = '#059669';
        previewText.textContent = `$${newBudget.toFixed(2)}`;
        previewContainer.style.display = 'block';

        if (adjustment > 0) {
          previewContainer.querySelector('div:first-child').textContent = '📈 Nuevo Presupuesto (Incremento)';
        } else {
          previewContainer.querySelector('div:first-child').textContent = '📉 Nuevo Presupuesto (Decremento)';
        }
      });
    }
  }

  // Aplicar ajuste
  static async applyAdjustment(formId) {
    const validation = ogModule('form').validate(formId);
    
    if (!validation.success) {
      ogComponent('toast').error(validation.message);
      return;
    }

    const adjustment = parseFloat(validation.data.adjustment_amount);
    const reason = validation.data.reason || '';

    if (adjustment === 0) {
      ogComponent('toast').warning('El monto de ajuste no puede ser $0');
      return;
    }

    if (this.currentBudget === null) {
      ogComponent('toast').error('No se pudo obtener el presupuesto actual');
      return;
    }

    const newBudget = this.currentBudget + adjustment;

    if (newBudget <= 0) {
      ogComponent('toast').error('El nuevo presupuesto no puede ser $0 o negativo');
      return;
    }

    const action = adjustment > 0 ? 'incrementar' : 'decrementar';
    const confirmMsg = `¿Confirmas que deseas ${action} el presupuesto?\n\n` +
                      `Presupuesto actual: $${this.currentBudget.toFixed(2)}\n` +
                      `Ajuste: ${adjustment > 0 ? '+' : ''}$${adjustment.toFixed(2)}\n` +
                      `Nuevo presupuesto: $${newBudget.toFixed(2)}`;

    if (!confirm(confirmMsg)) return;

    const submitBtn = document.querySelector(`#${formId} button[type="submit"]`);
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = '⏳ Aplicando...';
    }

    const body = {
      product_ad_asset_id: this.currentProductAdAssetId,
      ad_asset_id: this.currentAssetId,
      ad_asset_type: this.currentAssetType,  // ← AGREGAR
      budget_before: this.currentBudget,
      budget_after: newBudget,
      adjustment_amount: adjustment,
      reason: reason,
      execution_source: 'manual'
    };

    try {
      const response = await ogModule('api').post('/api/adAutoScale/adjust-budget', body);

      if (!response.success) {
        throw new Error(response.error || 'Error al aplicar el ajuste');
      }

      ogComponent('toast').success(
        `✅ Presupuesto ${adjustment > 0 ? 'incrementado' : 'decrementado'} a $${newBudget.toFixed(2)}`
      );

      ogModal.closeAll();
      
      if (typeof window.scaleRuleStats !== 'undefined') {
        scaleRuleStats.loadData();
      }

    } catch (error) {
      ogLogger.error('ext:automation:budgetAdjust', 'Error aplicando ajuste', error);
      ogComponent('toast').error(`Error: ${error.message}`);

      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = '💾 Aplicar Ajuste';
      }
    }
  }
}

window.budgetAdjust = budgetAdjust;