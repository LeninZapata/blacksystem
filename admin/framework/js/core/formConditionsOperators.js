class ogConditionsOperators {
  static checkOperator(fieldValue, operator, targetValue) {
    switch (operator) {
      case '==':
        return this.normalize(fieldValue) == this.normalize(targetValue);

      case '!=':
        return this.normalize(fieldValue) != this.normalize(targetValue);

      case '>':
        return parseFloat(fieldValue) > parseFloat(targetValue);

      case '<':
        return parseFloat(fieldValue) < parseFloat(targetValue);

      case '>=':
        return parseFloat(fieldValue) >= parseFloat(targetValue);

      case '<=':
        return parseFloat(fieldValue) <= parseFloat(targetValue);

      case 'any':
        // Si el valor del campo está en la lista separada por comas
        const anyList = String(targetValue).split(',').map(v => v.trim());
        if (Array.isArray(fieldValue)) {
          return fieldValue.some(v => anyList.includes(String(v).trim()));
        }
        return anyList.includes(String(fieldValue).trim());

      case 'not-any':
        // Si el valor del campo NO está en la lista
        const notAnyList = String(targetValue).split(',').map(v => v.trim());
        if (Array.isArray(fieldValue)) {
          return !fieldValue.some(v => notAnyList.includes(String(v).trim()));
        }
        return !notAnyList.includes(String(fieldValue).trim());

      case 'empty':
        // Campo vacío (null, undefined, '', [])
        if (Array.isArray(fieldValue)) return fieldValue.length === 0;
        return !fieldValue || String(fieldValue).trim() === '';

      case 'not-empty':
        // Campo NO vacío
        if (Array.isArray(fieldValue)) return fieldValue.length > 0;
        return fieldValue && String(fieldValue).trim() !== '';

      case 'contains':
        // El campo contiene el texto
        return String(fieldValue).toLowerCase().includes(String(targetValue).toLowerCase());

      case 'not-contains':
        // El campo NO contiene el texto
        return !String(fieldValue).toLowerCase().includes(String(targetValue).toLowerCase());

      default:
        ogLogger?.warn('core:conditions', `Operador desconocido "${operator}"`);
        return false;
    }
  }

  static normalize(value) {
    // Normalizar booleanos y strings
    if (value === true || value === 'true' || value === '1' || value === 1) return true;
    if (value === false || value === 'false' || value === '0' || value === 0) return false;
    return value;
  }

  static getFieldValue(fieldEl) {
    const type = fieldEl.type;
    const name = fieldEl.name;

    // Checkbox
    if (type === 'checkbox') {
      return fieldEl.checked;
    }

    // Radio buttons (buscar todos con el mismo name)
    if (type === 'radio') {
      const form = fieldEl.closest('form');
      const radios = form.querySelectorAll(`input[name="${name}"]`);
      const checked = Array.from(radios).find(r => r.checked);
      return checked ? checked.value : '';
    }

    // Select multiple
    if (fieldEl.tagName === 'SELECT' && fieldEl.multiple) {
      return Array.from(fieldEl.selectedOptions).map(opt => opt.value);
    }

    // Cualquier otro input
    return fieldEl.value;
  }

  static getFieldName(element) {
    const name = element.name || '';
    // Remover índices de repetibles: "proyectos[0].nombre" -> "nombre"
    // Extraer solo el último segmento después del punto
    const withoutIndexes = name.replace(/\[\d+\]/g, '');
    const parts = withoutIndexes.split('.');
    return parts[parts.length - 1]; // Retornar solo la última parte
  }
}

// Global
window.ogConditionsOperators = ogConditionsOperators;

// Registrar en ogFramework
if (typeof window.ogFramework !== 'undefined') {
  window.ogFramework.core.conditionsOperators = ogConditionsOperators;
}