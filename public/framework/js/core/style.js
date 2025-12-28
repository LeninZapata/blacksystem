/**
 * ogStyle - Sistema de Design Tokens
 * Convierte tokens abstractos a CSS (web) o StyleSheet (React Native)
 */
class ogStyle {
  static tokens = {
    colors: {
      primary: '#007bff',
      secondary: '#6c757d',
      success: '#28a745',
      danger: '#dc3545',
      warning: '#ffc107',
      info: '#17a2b8',
      white: '#ffffff',
      black: '#000000',
      gray: '#6c757d',
      'gray-light': '#f8f9fa',
      'gray-dark': '#343a40',
      'text-primary': '#212529',
      'text-secondary': '#6c757d',
      'text-muted': '#adb5bd',
      'bg-light': '#f8f9fa',
      'bg-white': '#ffffff',
      'border-light': '#dee2e6'
    },

    spacing: {
      none: '0',
      xs: '4px',
      sm: '8px',
      md: '16px',
      lg: '24px',
      xl: '32px',
      '2xl': '48px'
    },

    fontSize: {
      xs: '12px',
      sm: '14px',
      md: '16px',
      lg: '18px',
      xl: '20px',
      '2xl': '24px'
    },

    fontWeight: {
      light: '300',
      normal: '400',
      medium: '500',
      semibold: '600',
      bold: '700'
    },

    borderRadius: {
      none: '0',
      sm: '4px',
      md: '8px',
      lg: '12px',
      xl: '16px',
      full: '9999px'
    },

    shadow: {
      none: 'none',
      sm: '0 1px 2px rgba(0,0,0,0.05)',
      md: '0 4px 6px rgba(0,0,0,0.1)',
      lg: '0 10px 15px rgba(0,0,0,0.1)',
      xl: '0 20px 25px rgba(0,0,0,0.15)'
    }
  };

  static propertyMappings = {
    'backgroundColor': 'colors',
    'color': 'colors',
    'borderColor': 'colors',
    'padding': 'spacing',
    'paddingTop': 'spacing',
    'paddingRight': 'spacing',
    'paddingBottom': 'spacing',
    'paddingLeft': 'spacing',
    'margin': 'spacing',
    'marginTop': 'spacing',
    'marginRight': 'spacing',
    'marginBottom': 'spacing',
    'marginLeft': 'spacing',
    'fontSize': 'fontSize',
    'fontWeight': 'fontWeight',
    'borderRadius': 'borderRadius',
    'boxShadow': 'shadow'
  };

  static resolve(styleObj) {
    if (!styleObj || typeof styleObj !== 'object') {
      return '';
    }

    const cssProps = [];

    for (const [key, value] of Object.entries(styleObj)) {
      const cssKey = this.camelToKebab(key);
      const cssValue = this.resolveValue(key, value);

      if (cssValue !== null && cssValue !== undefined) {
        cssProps.push(`${cssKey}: ${cssValue}`);
      }
    }

    return cssProps.join('; ');
  }

  static resolveValue(property, value) {
    if (value === null || value === undefined) {
      return null;
    }

    if (typeof value === 'number') {
      return `${value}px`;
    }

    if (typeof value !== 'string') {
      return String(value);
    }

    const tokenGroup = this.propertyMappings[property];

    if (tokenGroup && this.tokens[tokenGroup] && this.tokens[tokenGroup][value]) {
      return this.tokens[tokenGroup][value];
    }

    return value;
  }

  static camelToKebab(str) {
    return str.replace(/([a-z0-9])([A-Z])/g, '$1-$2').toLowerCase();
  }

  static toInlineStyle(styleObj) {
    return this.resolve(styleObj);
  }

  static toReactNative(styleObj) {
    const rnStyle = {};

    for (const [key, value] of Object.entries(styleObj)) {
      const resolvedValue = this.resolveValue(key, value);

      if (typeof resolvedValue === 'string' && resolvedValue.endsWith('px')) {
        rnStyle[key] = parseInt(resolvedValue);
      } else {
        rnStyle[key] = resolvedValue;
      }
    }

    return rnStyle;
  }
}

// global
window.ogStyle = ogStyle;

// Registrar en ogFramework (preferido)
if (typeof window.ogFramework !== 'undefined') {
  window.ogFramework.core.style = ogStyle;
}