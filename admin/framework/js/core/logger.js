class ogLogger {
  static forceDebug = false; // Para forzar debug temporalmente

  // Getter dinámico que lee el config cada vez
  static get ogIsDev() {
    const config = window.ogFramework?.activeConfig || window.appConfig;
    return config?.isDevelopment || false;
  }

  static styles = {
    debug: 'color: #646464ff; font-weight: bold',
    info: 'color: #2c7ab8; font-weight: bold',
    warn: 'color: #a55617; font-weight: bold',
    success: 'color: #1f8a4f; font-weight: bold',
    error: 'color: #c0392b; font-weight: bold',
    log: 'color: #7a8a8f; font-weight: bold',
    module: 'color: #575757; font-weight: bold',
    text: 'color: inherit'
  };

  // Habilitar/deshabilitar debug
  static setLevel(level) {
    if (level === 'debug') {
      this.forceDebug = true;
      console.log('✅ Debug mode enabled');
    } else {
      this.forceDebug = false;
      console.log('ℹ️ Debug mode disabled');
    }
  }

  static print(type, module, ...args) {
    if (type === 'debug' && !this.ogIsDev && !this.forceDebug) return;
    const typeLabel = type.toLowerCase();
    console.log(
      `%c[${module.toLowerCase()}]%c %c[${typeLabel}]%c`,
      this.styles.module, '', this.styles[type], this.styles.text, ...args
    );
  }

  static debug = (module, ...args) => this.print('debug', module, ...args);
  static info = (module, ...args) => this.print('info', module, ...args);
  static warn = (module, ...args) => this.print('warn', module, ...args);
  static success = (module, ...args) => this.print('success', module, ...args);
  static error = (module, ...args) => this.print('error', module, ...args);
  static log = (module, ...args) => this.print('log', module, ...args);
}

// Registrar en ogFramework (preferido)
if (typeof window.ogFramework !== 'undefined') {
  window.ogFramework.core.logger = ogLogger;
  window.ogLogger = ogLogger;
}