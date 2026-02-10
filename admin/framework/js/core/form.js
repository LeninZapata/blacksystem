// ====================================================================
// OGFORM - FACHADA PRINCIPAL
// Integra todos los módulos de formularios en una sola interfaz
// ====================================================================

class ogForm {
  // ========== PROPERTIES ==========
  static get schemas() { return ogFormCore.schemas; }
  static get registeredEvents() { return ogFormCore.registeredEvents; }
  static get selectCache() { return ogFormCore.selectCache; }
  static get typeAliases() { return ogFormCore.typeAliases; }
  static get transforms() { return ogFormCore.transforms; }
  static get validationToTransformMap() { return ogFormCore.validationToTransformMap; }

  // ========== CORE METHODS ==========
  static getConfig() { return ogFormCore.getConfig(); }
  static normalizeFieldType(field) { return ogFormCore.normalizeFieldType(field); }
  static t(text) { return ogFormCore.t(text); }
  static async load(...args) { return ogFormCore.load(...args); }
  static bindEventsOnce() { return ogFormCore.bindEventsOnce(); }
  static hasRoleAccess(field) { return ogFormCore.hasRoleAccess(field); }
  static processI18nTitle(title) { return ogFormCore.processI18nTitle(title); }
  static camelToKebab(str) { return ogFormCore.camelToKebab(str); }
  static clearSelectCache(source) { return ogFormCore.clearSelectCache(source); }

  // ========== RENDER METHODS ==========
  static render(schema) { return ogFormRender.render(schema); }
  static renderToolbar(items) { return ogFormRender.renderToolbar(items); }
  static renderStatusbar(items) { return ogFormRender.renderStatusbar(items); }
  static renderFields(fields, path) { return ogFormRender.renderFields(fields, path); }
  static renderRepeatable(field, path) { return ogFormRender.renderRepeatable(field, path); }
  static renderGroup(field, basePath) { return ogFormRender.renderGroup(field, basePath); }
  static renderGrouper(field, parentPath, index) { return ogFormRender.renderGrouper(field, parentPath, index); }
  static renderGrouperLinear(field, grouperId, parentPath, fieldPath) { 
    return ogFormRender.renderGrouperLinear(field, grouperId, parentPath, fieldPath); 
  }
  static renderGrouperTabs(field, grouperId, parentPath, fieldPath) { 
    return ogFormRender.renderGrouperTabs(field, grouperId, parentPath, fieldPath); 
  }
  static bindGrouperEvents(grouperId, mode) { return ogFormRender.bindGrouperEvents(grouperId, mode); }

  // ========== INPUT METHODS ==========
  static getTransformClasses(field) { return ogFormInputs.getTransformClasses(field); }
  static getValidationAttributes(field) { return ogFormInputs.getValidationAttributes(field); }
  static buildStyleAttr(styleConfig) { return ogFormInputs.buildStyleAttr(styleConfig); }
  static buildPropsAttr(props) { return ogFormInputs.buildPropsAttr(props); }
  static renderField(field, path) { return ogFormInputs.renderField(field, path); }
  static renderButton(field, label, propsAttr) { return ogFormInputs.renderButton(field, label, propsAttr); }
  static renderSelect(field, path, label, labelI18n, requiredAsterisk, common, styleAttr, propsAttr) { 
    return ogFormInputs.renderSelect(field, path, label, labelI18n, requiredAsterisk, common, styleAttr, propsAttr); 
  }
  static renderTextarea(field, label, labelI18n, requiredAsterisk, common, styleAttr, propsAttr) { 
    return ogFormInputs.renderTextarea(field, label, labelI18n, requiredAsterisk, common, styleAttr, propsAttr); 
  }
  static renderCheckbox(field, path, label, labelI18n, requiredAsterisk, styleAttr, propsAttr) { 
    return ogFormInputs.renderCheckbox(field, path, label, labelI18n, requiredAsterisk, styleAttr, propsAttr); 
  }
  static renderButtonGroup(field, path, label, labelI18n, requiredAsterisk) { 
    return ogFormInputs.renderButtonGroup(field, path, label, labelI18n, requiredAsterisk); 
  }
  static renderRadio(field, path, label, labelI18n, requiredAsterisk) { 
    return ogFormInputs.renderRadio(field, path, label, labelI18n, requiredAsterisk); 
  }
  static async loadSelectFromAPI(...args) { return ogFormInputs.loadSelectFromAPI(...args); }
  static populateSelect(...args) { return ogFormInputs.populateSelect(...args); }

  // ========== REPEATABLE METHODS ==========
  static initRepeatables(formId, container) { return ogFormRepeatables.initRepeatables(formId, container); }
  static initRepeatableContainer(container, field, path) { 
    return ogFormRepeatables.initRepeatableContainer(container, field, path); 
  }
  static addRepeatableItem(path, buttonElement) { return ogFormRepeatables.addRepeatableItem(path, buttonElement); }
  static getDragAfterElement(container, y) { return ogFormRepeatables.getDragAfterElement(container, y); }
  static reindexRepeatableItems(container) { return ogFormRepeatables.reindexRepeatableItems(container); }
  static findNestedRepeatables(fields, basePath) { return ogFormRepeatables.findNestedRepeatables(fields, basePath); }
  static setupDragAndDrop() { return ogFormRepeatables.setupDragAndDrop(); }
  static bindRepeatableEvents() { return ogFormRepeatables.bindRepeatableEvents(); }

  // ========== DATA METHODS ==========
  static getData(formId) { return ogFormData.getData(formId); }
  static setNestedValue(obj, path, value) { return ogFormData.setNestedValue(obj, path, value); }
  static fill(formId, data, container, skipRepeatables) { 
    return ogFormData.fill(formId, data, container, skipRepeatables); 
  }
  static fillRepeatable(formEl, repeatableName, data, fieldSchema) { 
    return ogFormData.fillRepeatable(formEl, repeatableName, data, fieldSchema); 
  }
  static setInputValue(input, value, isFilling) { return ogFormData.setInputValue(input, value, isFilling); }
  static bindTransforms(formId, container) { return ogFormData.bindTransforms(formId, container); }
  static applyDefaultValues(formId, container) { return ogFormData.applyDefaultValues(formId, container); }
  static applyDefaultsToFields(fields, parentPath, formEl) { 
    return ogFormData.applyDefaultsToFields(fields, parentPath, formEl); 
  }
  static processDefaultValue(value) { return ogFormData.processDefaultValue(value); }

  // ========== VALIDATION METHODS ==========
  static validate(formId) { return ogFormValidation.validate(formId); }
  static validateField(formEl, field, fieldPath) { return ogFormValidation.validateField(formEl, field, fieldPath); }
  static validateRequired(value) { return ogFormValidation.validateRequired(value); }
  static validateEmail(value) { return ogFormValidation.validateEmail(value); }
  static getFieldValue(input) { return ogFormValidation.getFieldValue(input); }
  static showFieldError(formEl, fieldPath, message) { 
    return ogFormValidation.showFieldError(formEl, fieldPath, message); 
  }
  static hideFieldError(formEl, fieldPath) { return ogFormValidation.hideFieldError(formEl, fieldPath); }
  static setError(formId, fieldName, errorMessage) { 
    return ogFormValidation.setError(formId, fieldName, errorMessage); 
  }
  static clearError(formId, fieldName) { return ogFormValidation.clearError(formId, fieldName); }
  static clearAllErrors(formId) { return ogFormValidation.clearAllErrors(formId); }
}

// Global
window.ogForm = ogForm;

// Registrar en ogFramework
if (typeof window.ogFramework !== 'undefined') {
  window.ogFramework.core.form = ogForm;
}