class logger {
  static isDev = window.appConfig?.isDevelopment || false;

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

  static print(type, module, ...args) {
    if (type === 'debug' && !this.isDev) return;

    const typeLabel = type.toLowerCase();

    console.log(
      `%c[${module.toLowerCase()}]%c %c[${typeLabel}]%c`,
      this.styles.module,
      '',
      this.styles[type],
      this.styles.text,
      ...args
    );
  }

  static debug = (module, ...args) => this.print('debug', module, ...args);
  static info = (module, ...args) => this.print('info', module, ...args);
  static warn = (module, ...args) => this.print('warn', module, ...args);
  static success = (module, ...args) => this.print('success', module, ...args);
  static error = (module, ...args) => this.print('error', module, ...args);
  static log = (module, ...args) => this.print('log', module, ...args);
}

window.logger = logger;

/**
 * FORMATOS DE LOGS POR TIPO DE MÓDULO:
 *
 * CORE (archivos del sistema principal):
 * logger.debug('core:auth', 'Usuario autenticado');
 * logger.info('core:view', 'Vista cargada: dashboard');
 * logger.error('core:api', 'Error en petición:', error);
 *
 * COMPONENTES (componentes reutilizables):
 * logger.warn('com:modal', 'Modal no encontrado');
 * logger.success('com:datatable', 'Datos cargados correctamente');
 * logger.debug('com:tabs', 'Tab cambiado: perfil');
 *
 * EXTENSIONS (funcionalidades extendidas):
 * logger.error('ext:permissions', 'Container no encontrado');
 * logger.info('ext:chart', 'Gráficos inicializados');
 * logger.debug('ext:reportes', 'Generando reporte...');
 *
 * EJEMPLOS DE USO:
 *
 * // En core/auth.js
 * logger.success('core:auth', 'Permisos cargados');
 *
 * // En components/modal.js
 * logger.warn('com:modal', 'Selector no encontrado');
 *
 * // En extensions/permissions.js
 * logger.error('p:permissions', 'Error cargando vista:', error);
 */