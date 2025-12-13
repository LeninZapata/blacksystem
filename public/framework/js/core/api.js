class api {
  static baseURL = window.BASE_URL || window.appConfig?.api?.baseURL || '';
  static headers = { 'Content-Type': 'application/json', ...window.appConfig?.api?.headers };

  static async request(endpoint, options = {}) {
    // Normalizar URL: eliminar slashes duplicados (excepto en protocolo)
    let fullURL = `${this.baseURL}${endpoint}`;

    // Separar protocolo del resto
    const protocolMatch = fullURL.match(/^(https?:\/\/)/);
    const protocol = protocolMatch ? protocolMatch[1] : '';
    const urlWithoutProtocol = protocol ? fullURL.slice(protocol.length) : fullURL;

    // Eliminar slashes duplicados en la ruta
    const normalizedPath = urlWithoutProtocol.replace(/\/+/g, '/');

    // Reconstruir URL
    fullURL = protocol + normalizedPath;

    logger.info('core:api', `📡 Ejecutando: ${options.method || 'GET'} ${fullURL}`);

    const headers = { ...this.headers };

    // Solo agregar token si NO es skipAuth
    if (!options.skipAuth) {
      const token = auth?.getToken?.();

      if (token) {
        headers['Authorization'] = `Bearer ${token}`;
        logger.debug('core:api', `🔐 Token incluido: ${token.substring(0, 20)}...`);
      } else {
        logger.warn('core:api', '⚠️ NO se encontró token para esta petición');
      }
    }

    try {
      const res = await fetch(fullURL, { ...options, headers });

      // Manejo de 400 Bad Request
      if (res.status === 400) {
        logger.error('core:api', `❌ Bad Request (400) - ${fullURL}`);

        // Intentar obtener detalles del error
        const contentType = res.headers.get('content-type') || '';
        let errorMsg = __('core.api.bad_request');

        if (contentType.includes('application/json')) {
          try {
            const errorData = await res.json();
            errorMsg = errorData.error || errorData.message || __('core.api.bad_request');
          } catch (parseError) {
            // Si no puede parsear, usar mensaje genérico
          }
        }

        // Mostrar toast
        if (window.toast && typeof toast.error === 'function') {
          toast.error(errorMsg);
        }

        throw new Error(errorMsg);
      }

      // Manejo mejorado de 401
      if (res.status === 401) {
        if (auth?.isAuthenticated?.()) {
          logger.warn('core:api', 'Token inválido (401), cerrando sesión');
          auth.handleExpiredSession();
        }
        throw new Error(__('core.api.unauthorized'));
      }

      if (!res.ok) {
        // ❌ Error HTTP - intentar obtener detalles
        const contentType = res.headers.get('content-type') || '';

        if (contentType.includes('application/json')) {
          // Dice ser JSON - pero puede estar corrupto
          const text = await res.text();

          try {
            const errorData = JSON.parse(text);
            logger.error('core:api', `❌ Error ${res.status}:`, errorData);

            const errorMsg = errorData.message || errorData.error || `HTTP ${res.status}`;

            // Mostrar toast para errores
            if (window.toast && typeof toast.error === 'function') {
              toast.error(errorMsg);
            }

            throw new Error(errorMsg);
          } catch (parseError) {
            // JSON corrupto - probablemente tiene HTML mezclado
            logger.error('core:api', `❌ Error ${res.status} - JSON corrupto (contiene HTML/PHP)`);

            console.group(`🚨 JSON Corrupto - ${fullURL}`);
            console.error('Status:', res.status);
            console.error('Content-Type:', contentType);
            console.error('Parse Error:', parseError.message);
            console.log('--- RESPUESTA COMPLETA (con HTML mezclado) ---');
            console.log(text);
            console.log('--- FIN RESPUESTA ---');
            console.groupEnd();

            // Intentar extraer mensaje útil
            const errorMatch = text.match(/<b>(Warning|Fatal error|Error)<\/b>:([^<]+)/);
            const errorMsg = errorMatch ? errorMatch[2].trim() : __('core.api.json_corrupted');

            throw new Error(`${__('core.api.backend_error')}: ${errorMsg}`);
          }
        } else if (contentType.includes('text/html')) {
          // Es HTML puro
          const htmlError = await res.text();
          logger.error('core:api', `❌ Error ${res.status} - Respuesta HTML del backend:`);

          console.group(`🚨 Error HTML Backend - ${fullURL}`);
          console.error('Status:', res.status);
          console.error('Content-Type:', contentType);
          console.log('--- HTML COMPLETO ---');
          console.log(htmlError);
          console.log('--- FIN HTML ---');
          console.groupEnd();

          const errorMatch = htmlError.match(/<b>(Warning|Fatal error|Error)<\/b>:([^<]+)/);
          const errorMsg = errorMatch ? errorMatch[2].trim() : __('core.api.backend_error');

          throw new Error(`${__('core.api.backend_error')}: ${errorMsg}`);
        } else {
          // Otro tipo de contenido
          const textError = await res.text();
          logger.error('core:api', `❌ Error ${res.status} - Content-Type: ${contentType}`);
          console.error('Respuesta completa:', textError);
          throw new Error(`HTTP ${res.status}: ${textError.substring(0, 100)}`);
        }
      }

      // ✅ Respuesta exitosa - verificar tipo de contenido
      const contentType = res.headers.get('content-type') || '';

      if (contentType.includes('application/json')) {
        // Dice ser JSON - pero verificar que realmente lo sea
        const text = await res.text();

        try {
          return JSON.parse(text);
        } catch (parseError) {
          // ⚠️ JSON corrupto en respuesta exitosa (200)
          logger.error('core:api', `⚠️ Respuesta exitosa pero JSON corrupto:`);

          console.group(`⚠️ JSON Corrupto (200 OK) - ${fullURL}`);
          console.warn('Status:', res.status);
          console.warn('Content-Type:', contentType);
          console.error('Parse Error:', parseError.message);
          console.log('--- RESPUESTA COMPLETA (con HTML/PHP mezclado) ---');
          console.log(text);
          console.log('--- FIN RESPUESTA ---');
          console.groupEnd();

          throw new Error(__('core.api.json_corrupted_success'));
        }
      } else if (contentType.includes('text/html')) {
        // Backend devolvió HTML cuando esperábamos JSON
        const htmlResponse = await res.text();
        logger.error('core:api', `⚠️ Backend devolvió HTML en lugar de JSON:`);

        console.group(`⚠️ Respuesta HTML inesperada - ${fullURL}`);
        console.warn('Status:', res.status, '(success)');
        console.warn('Content-Type:', contentType);
        console.log('--- HTML COMPLETO ---');
        console.log(htmlResponse);
        console.log('--- FIN HTML ---');
        console.groupEnd();

        throw new Error(__('core.api.html_instead_json'));
      } else {
        // Otro tipo de contenido
        const text = await res.text();
        logger.warn('core:api', `⚠️ Content-Type inesperado: ${contentType}`);
        console.warn('Respuesta:', text);

        // Intentar parsear como JSON de todas formas
        try {
          return JSON.parse(text);
        } catch {
          throw new Error(`${__('core.api.invalid_content_type')}: ${contentType}`);
        }
      }

    } catch (error) {
      logger.error('core:api', `Error: ${fullURL}`, error.message);
      throw error;
    }
  }

  static get = (e, opts = {}) => this.request(e, opts);
  static post = (e, d, opts = {}) => this.request(e, { method: 'POST', body: JSON.stringify(d), ...opts });
  static put = (e, d, opts = {}) => this.request(e, { method: 'PUT', body: JSON.stringify(d), ...opts });
  static delete = (e, opts = {}) => this.request(e, { method: 'DELETE', ...opts });
}

window.api = api;