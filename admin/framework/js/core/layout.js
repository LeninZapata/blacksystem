class ogLayout {
  static init(mode = 'app', container = null) {
    ogLogger?.info('core:layout', `🎨 === INIT LAYOUT CALLED ===`);
    ogLogger?.info('core:layout', `📦 mode: ${mode}`);
    ogLogger?.info('core:layout', `📦 container param:`, container);
    
    // OBTENER EL CONTENEDOR DE LA CONFIGURACIÓN ACTIVA
    let app = container;

    if (!app) {
      ogLogger?.info('core:layout', `🔍 Container es null, buscando en config...`);
      const config = window.ogFramework?.activeConfig;
      ogLogger?.info('core:layout', `🔍 config:`, config);
      
      if (config && config.container) {
        ogLogger?.info('core:layout', `🔍 Buscando selector: ${config.container}`);
        app = document.querySelector(config.container);
        ogLogger?.info('core:layout', `🔍 Resultado querySelector:`, app);
      } else {
        ogLogger?.info('core:layout', `🔍 Fallback a #app`);
        app = document.getElementById('app');
        ogLogger?.info('core:layout', `🔍 Resultado getElementById:`, app);
      }
    }

    if (!app) {
      ogLogger?.error('core:layout', '❌ Layout: Contenedor no encontrado');
      return;
    }

    ogLogger?.info('core:layout', `✅ Contenedor encontrado:`, app);
    ogLogger?.info('core:layout', `📝 Contenedor HTML antes:`, app.innerHTML.substring(0, 100));

    if (mode === 'auth') {
      ogLogger?.info('core:layout', `🔐 Renderizando layout AUTH mode`);
      app.innerHTML = `
        <div class="og-layout auth-mode">
          <div class="og-content" id="content"></div>
        </div>
      `;
    } else {
      ogLogger?.info('core:layout', `🏠 Renderizando layout APP mode`);
      const html = `
        <div class="og-layout">
          <header class="og-header" id="header">
            <button class="og-menu-toggle" id="menu-toggle" style="display: none;">☰</button>
            <span>${__('core.layout.system_title')}</span>
          </header>
          <div class="og-sidebar-overlay" id="sidebar-overlay"></div>
          <aside class="og-sidebar" id="sidebar"></aside>
          <main class="og-content" id="content">
            <div class="view-container">
              <div class="welcome-message">
                <p>${__('core.layout.loading')}</p>
              </div>
            </div>
            <footer class="og-footer">${__('core.layout.footer')}</footer>
          </main>
        </div>
      `;
      
      ogLogger?.info('core:layout', `📝 HTML a insertar (primeros 200 chars):`, html.substring(0, 200));
      app.innerHTML = html;
      
      ogLogger?.info('core:layout', `📝 Contenedor HTML después:`, app.innerHTML.substring(0, 200));
      
      // Verificar que el sidebar existe después de insertar
      const sidebarCheck = document.getElementById('sidebar');
      ogLogger?.info('core:layout', `🔍 Verificando #sidebar existe:`, !!sidebarCheck);
      
      if (sidebarCheck) {
        ogLogger?.success('core:layout', `✅ #sidebar creado exitosamente`);
      } else {
        ogLogger?.error('core:layout', `❌ #sidebar NO encontrado después de insertar HTML`);
      }
      
      this.initResponsive();
    }
    
    ogLogger?.success('core:layout', `✅ Layout init completado`);
  }

  static initResponsive() {
    const toggle = document.getElementById('menu-toggle');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebar-overlay');

    const updateUI = () => {
      if (window.innerWidth <= 1024) {
        toggle.style.display = 'flex';
      } else {
        toggle.style.display = 'none';
        sidebar.classList.remove('open');
        overlay.classList.remove('active');
      }
    };

    toggle?.addEventListener('click', () => {
      sidebar.classList.toggle('open');
      overlay.classList.toggle('active');
    });

    overlay?.addEventListener('click', () => {
      sidebar.classList.remove('open');
      overlay.classList.remove('active');
    });

    window.addEventListener('resize', updateUI);
    updateUI();
  }
}

// Global
window.ogLayout = ogLayout;

// Registrar en ogFramework (preferido)
if (typeof window.ogFramework !== 'undefined') {
  window.ogFramework.core.layout = ogLayout;
}