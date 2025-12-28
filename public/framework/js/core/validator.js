class ogValidator {
  static schemas = {
    view: {
      required: ['id', 'title'],
      optional: ['layout', 'scripts', 'styles', 'content', 'tabs', 'toolbar', 'statusbar'],
      types: {
        id: 'string',
        title: 'string',
        layout: 'string',
        scripts: 'array',
        styles: 'array',
        content: 'array',
        tabs: 'array'
      }
    },
    
    form: {
      required: ['id', 'fields'],
      optional: ['title', 'description', 'toolbar', 'statusbar'],
      types: {
        id: 'string',
        title: 'string',
        description: 'string',
        fields: 'array',
        toolbar: 'array',
        statusbar: 'array'
      }
    }
  };

  static validate(type, data, filename = '') {
    const schema = this.schemas[type];
    if (!schema) return { valid: true };

    const errors = [];

    // Validar campos requeridos
    for (const field of schema.required) {
      if (!(field in data)) {
        errors.push(`Falta campo requerido: "${field}"`);
      }
    }

    // Validar tipos
    for (const [field, expectedType] of Object.entries(schema.types)) {
      if (!(field in data)) continue;

      const value = data[field];
      const actualType = Array.isArray(value) ? 'array' : typeof value;

      if (actualType !== expectedType) {
        errors.push(`Campo "${field}" debe ser ${expectedType}, es ${actualType}`);
      }
    }

    if (errors.length > 0) {
      const location = filename ? ` en ${filename}` : '';
      return {
        valid: false,
        errors,
        message: `❌ Errores${location}:\n  • ${errors.join('\n  • ')}`
      };
    }

    return { valid: true };
  }
}

// Mantener en window para compatibilidad (temporal)
window.ogValidator = ogValidator;

// Registrar en ogFramework (preferido)
if (typeof window.ogFramework !== 'undefined') {
  window.ogFramework.core.validator = ogValidator;
}