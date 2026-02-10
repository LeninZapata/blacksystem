// ====================================================================
// OGCONDITIONS - FACHADA PRINCIPAL
// Integra: Core, Evaluator y Operators en una sola interfaz
// ====================================================================

class ogConditions {
  // Delegar propiedades al Core
  static get rules() { return ogConditionsCore.rules; }
  static get watchers() { return ogConditionsCore.watchers; }
  static get initialized() { return ogConditionsCore.initialized; }
  static get evaluateTimeout() { return ogConditionsCore.evaluateTimeout; }
  static get isFillingForm() { return ogConditionsCore.isFillingForm; }
  
  static set isFillingForm(value) { ogConditionsCore.isFillingForm = value; }

  // ============================================================================
  // MÉTODOS DEL CORE (Inicialización y Gestión)
  // ============================================================================
  
  static init(formId) {
    return ogConditionsCore.init(formId);
  }

  static setupRepeatableObserver(formId) {
    return ogConditionsCore.setupRepeatableObserver(formId);
  }

  static extractConditions(fields, rulesMap, parentPath = '') {
    return ogConditionsCore.extractConditions(fields, rulesMap, parentPath);
  }

  static setupWatchers(formId) {
    return ogConditionsCore.setupWatchers(formId);
  }

  static destroy(formId) {
    return ogConditionsCore.destroy(formId);
  }

  static debug(formId) {
    return ogConditionsCore.debug(formId);
  }

  // ============================================================================
  // MÉTODOS DEL EVALUATOR (Evaluación y Visibilidad)
  // ============================================================================
  
  static evaluate(formId) {
    return ogConditionsEvaluator.evaluate(formId);
  }

  static pauseEvaluations() {
    return ogConditionsEvaluator.pauseEvaluations();
  }

  static resumeEvaluations(formId) {
    return ogConditionsEvaluator.resumeEvaluations(formId);
  }

  static evaluateRepeatable(formEl, targetFieldPath, rule) {
    return ogConditionsEvaluator.evaluateRepeatable(formEl, targetFieldPath, rule);
  }

  static applyVisibilityToAll(formEl, fieldPath, shouldShow) {
    return ogConditionsEvaluator.applyVisibilityToAll(formEl, fieldPath, shouldShow);
  }

  static applyVisibilitySimple(formEl, fieldPath, shouldShow) {
    return ogConditionsEvaluator.applyVisibilitySimple(formEl, fieldPath, shouldShow);
  }

  static checkConditions(formEl, rule, targetFieldPath) {
    return ogConditionsEvaluator.checkConditions(formEl, rule, targetFieldPath);
  }

  static checkConditionsWithContext(context, rule) {
    return ogConditionsEvaluator.checkConditionsWithContext(context, rule);
  }

  static checkCondition(context, condition) {
    return ogConditionsEvaluator.checkCondition(context, condition);
  }

  static getContext(formEl, targetFieldPath, contextType) {
    return ogConditionsEvaluator.getContext(formEl, targetFieldPath, contextType);
  }

  static findFieldElement(formEl, fieldPath) {
    return ogConditionsEvaluator.findFieldElement(formEl, fieldPath);
  }

  // ============================================================================
  // MÉTODOS DE OPERATORS (Operadores y Utilidades)
  // ============================================================================
  
  static evalOperator(operator, fieldValue, targetValue) {
    return ogConditionsOperators.checkOperator(fieldValue, operator, targetValue);
  }

  static checkOperator(fieldValue, operator, targetValue) {
    return ogConditionsOperators.checkOperator(fieldValue, operator, targetValue);
  }

  static normalize(value) {
    return ogConditionsOperators.normalize(value);
  }

  static getFieldValue(fieldEl) {
    return ogConditionsOperators.getFieldValue(fieldEl);
  }

  static getFieldName(element) {
    return ogConditionsOperators.getFieldName(element);
  }
}

// Global
window.ogConditions = ogConditions;

// Registrar en ogFramework (preferido)
if (typeof window.ogFramework !== 'undefined') {
  window.ogFramework.core.conditions = ogConditions;
}