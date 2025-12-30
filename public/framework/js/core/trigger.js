class ogTrigger {
  static registry = [];

  // Registrar un trigger con target específico
  static register(target, className, methodName) {
    if (!target || !className || !methodName) {
      ogLogger?.error('core:trigger', 'Parámetros inválidos para register()');
      return;
    }

    this.registry.push({
      target,
      className,
      methodName
    });

    ogLogger?.info('core:trigger', `Registrado: ${className}.${methodName}() → ${target}`);
  }

  // Ejecutar triggers de un target específico
  static execute(targetFilter) {
    const triggers = targetFilter 
      ? this.registry.filter(t => t.target.startsWith(targetFilter))
      : this.registry;

    if (triggers.length === 0) {
      ogLogger?.info('core:trigger', `No hay triggers para: ${targetFilter || 'all'}`);
      return;
    }

    ogLogger?.info('core:trigger', `Ejecutando ${triggers.length} triggers para: ${targetFilter || 'all'}`);

    triggers.forEach(trigger => {
      try {
        const classObj = window[trigger.className];

        if (!classObj) {
          ogLogger?.error('core:trigger', `Clase no encontrada: ${trigger.className}`);
          return;
        }

        if (typeof classObj[trigger.methodName] !== 'function') {
          ogLogger?.error('core:trigger', `Método no encontrado: ${trigger.className}.${trigger.methodName}()`);
          return;
        }

        classObj[trigger.methodName](trigger.target);
        ogLogger?.success('core:trigger', `✅ ${trigger.className}.${trigger.methodName}() ejecutado`);

      } catch (error) {
        ogLogger?.error('core:trigger', `Error ejecutando ${trigger.className}.${trigger.methodName}():`, error);
      }
    });
  }

  // Reset (útil para testing)
  static reset() {
    this.registry = [];
  }

  // Debug
  static list(targetFilter = null) {
    const triggers = targetFilter 
      ? this.registry.filter(t => t.target.startsWith(targetFilter))
      : this.registry;
    
    return triggers.map(t => `${t.className}.${t.methodName}() → ${t.target}`);
  }
}

window.ogTrigger = ogTrigger;

if (typeof window.ogFramework !== 'undefined') {
  window.ogFramework.core.trigger = ogTrigger;
}

