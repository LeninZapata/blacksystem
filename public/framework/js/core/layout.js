class ogLayout {
  static init(mode = 'app', container = null) {
    const app = container || document.getElementById('app');
    if (!app) return;

    if (mode === 'auth') {
      app.innerHTML = `
        <div class="layout">
          <main class="content" id="content"></main>
        </div>
      `;
    } else {
      app.innerHTML = `
        <div class="layout">
          <header class="header" id="header">
            <button class="menu-toggle" id="menu-toggle" style="display: none;">☰</button>
            <span>${__('core.layout.system_title')}</span>
          </header>
          <div class="sidebar-overlay" id="sidebar-overlay"></div>
          <aside class="sidebar" id="sidebar"></aside>
          <main class="content" id="content">
            <div class="view-container">
              <div class="welcome-message">
                <p>${__('core.layout.loading')}</p>
              </div>
            </div>
            <footer class="footer">${__('core.layout.footer')}</footer>
          </main>
        </div>
      `;
      this.initResponsive();
    }
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
