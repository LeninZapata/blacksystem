class api {
  static baseURL = window.BASE_URL || window.appConfig?.api?.baseURL || '';
  static headers = { 'Content-Type': 'application/json', ...window.appConfig?.api?.headers };

  static async request(endpoint, options = {}) {
    let fullURL = `${this.baseURL}${endpoint}`;
    const protocolMatch = fullURL.match(/^(https?:\/\/)/);
    const protocol = protocolMatch ? protocolMatch[1] : '';
    const urlWithoutProtocol = protocol ? fullURL.slice(protocol.length) : fullURL;
    const normalizedPath = urlWithoutProtocol.replace(/\/+/g, '/');
    fullURL = protocol + normalizedPath;

    logger.info('core:api', `📡 Ejecutando: ${options.method || 'GET'} ${fullURL}`);

    const headers = { ...this.headers };

    if (!options.skipAuth) {
      const token = auth?.getToken?.();
      if (token) {
        headers['Authorization'] = `Bearer ${token}`;
        const tokenDisplay = window.IS_DEV ? token : `${token.substring(0, 20)}...`;
        logger.debug('core:api', `🔐 Token incluido: ${tokenDisplay}`);
      } else {
        logger.warn('core:api', '⚠️ NO se encontró token para esta petición');
      }
    }

    try {
      const res = await fetch(fullURL, { ...options, headers });

      // ❌ ELIMINADO: No manejar 401 aquí, dejarlo al caller (auth.checkSessionWithServer)
      // if (res.status === 401) {...}

      if (res.status === 400) {
        logger.error('core:api', `❌ Bad Request (400) - ${fullURL}`);
        const contentType = res.headers.get('content-type') || '';
        let errorMsg = __('core.api.bad_request');

        if (contentType.includes('application/json')) {
          try {
            const errorData = await res.json();
            errorMsg = errorData.error || errorData.message || __('core.api.bad_request');
          } catch (parseError) {}
        }

        if (window.toast && typeof toast.error === 'function') {
          toast.error(errorMsg);
        }

        throw new Error(errorMsg);
      }

      if (!res.ok) {
        const contentType = res.headers.get('content-type') || '';

        if (contentType.includes('application/json')) {
          const text = await res.text();

          try {
            const errorData = JSON.parse(text);
            logger.error('core:api', `❌ Error ${res.status}:`, errorData);
            const errorMsg = errorData.message || errorData.error || `HTTP ${res.status}`;

            if (window.toast && typeof toast.error === 'function') {
              toast.error(errorMsg);
            }

            throw new Error(errorMsg);
          } catch (parseError) {
            logger.error('core:api', `❌ Error ${res.status} - JSON corrupto`);
            console.group(`🚨 JSON Corrupto - ${fullURL}`);
            console.error('Status:', res.status);
            console.error('Content-Type:', contentType);
            console.error('Parse Error:', parseError.message);
            console.log('--- RESPUESTA COMPLETA ---');
            console.log(text);
            console.groupEnd();

            const errorMatch = text.match(/<b>(Warning|Fatal error|Error)<\/b>:([^<]+)/);
            const errorMsg = errorMatch ? errorMatch[2].trim() : __('core.api.json_corrupted');
            throw new Error(`${__('core.api.backend_error')}: ${errorMsg}`);
          }
        } else if (contentType.includes('text/html')) {
          const htmlError = await res.text();
          logger.error('core:api', `❌ Error ${res.status} - Respuesta HTML`);
          console.group(`🚨 Error HTML Backend - ${fullURL}`);
          console.error('Status:', res.status);
          console.log(htmlError);
          console.groupEnd();

          const errorMatch = htmlError.match(/<b>(Warning|Fatal error|Error)<\/b>:([^<]+)/);
          const errorMsg = errorMatch ? errorMatch[2].trim() : __('core.api.backend_error');
          throw new Error(`${__('core.api.backend_error')}: ${errorMsg}`);
        } else {
          const textError = await res.text();
          logger.error('core:api', `❌ Error ${res.status}`);
          throw new Error(`HTTP ${res.status}: ${textError.substring(0, 100)}`);
        }
      }

      const contentType = res.headers.get('content-type') || '';

      if (contentType.includes('application/json')) {
        const text = await res.text();

        try {
          return JSON.parse(text);
        } catch (parseError) {
          logger.error('core:api', `⚠️ Respuesta exitosa pero JSON corrupto`);
          console.group(`⚠️ JSON Corrupto (200 OK) - ${fullURL}`);
          console.warn('Status:', res.status);
          console.error('Parse Error:', parseError.message);
          console.log(text);
          console.groupEnd();

          throw new Error(__('core.api.json_corrupted_success'));
        }
      } else if (contentType.includes('text/html')) {
        const htmlResponse = await res.text();
        logger.error('core:api', `⚠️ Backend devolvió HTML en lugar de JSON`);
        console.group(`⚠️ Respuesta HTML inesperada - ${fullURL}`);
        console.log(htmlResponse);
        console.groupEnd();

        throw new Error(__('core.api.html_instead_json'));
      } else {
        const text = await res.text();
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