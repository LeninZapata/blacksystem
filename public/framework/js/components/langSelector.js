// Función global para inicializar el selector de idioma
window.initLangSelector = function() {
  const header = document.querySelector('.header, #header');
  if (!header) {
    ogLogger.warn('com:langSelector', 'Header no encontrado');
    return;
  }

  // Verificar si ya existe
  if (header.querySelector('.lang-selector')) {
    return;
  }

  const currentLang = i18n.getLang();
  const availableLangs = i18n.getAvailableLangs();

  const getLangLabel = (lang) => {
    const labels = {
      'es': '🇪🇸 Español',
      'en': '🇺🇸 English',
      'fr': '🇫🇷 Français',
      'de': '🇩🇪 Deutsch',
      'pt': '🇧🇷 Português',
      'it': '🇮🇹 Italiano'
    };
    return labels[lang] || lang.toUpperCase();
  };

  const html = `
    <div class="lang-selector">
      <select class="lang-select" id="lang-select">
        ${availableLangs.map(lang => `
          <option value="${lang}" ${lang === currentLang ? 'selected' : ''}>
            ${getLangLabel(lang)}
          </option>
        `).join('')}
      </select>
    </div>
  `;

  header.insertAdjacentHTML('beforeend', html);

  // Bind evento
  const select = document.getElementById('lang-select');
  select.addEventListener('change', async (e) => {
    const newLang = e.target.value;
    select.disabled = true;
    await i18n.setLang(newLang);
    if (!i18n.config.refreshOnChange) {
      select.disabled = false;
    }
  });
};

// Registrar en ogFramework (preferido)
if (typeof window.ogFramework !== 'undefined') {
  window.ogFramework.components.langSelector = { init: window.initLangSelector };
}