/* date.js - Utilidad de fechas relativas en español con soporte de timezone */

class bsDate {

  static _months = [
    'enero','febrero','marzo','abril','mayo','junio',
    'julio','agosto','septiembre','octubre','noviembre','diciembre'
  ];

  /**
   * Timezone del usuario. Se establece tras el login con el valor de
   * ogAuth.userPreferences.timezone. Si es null usa el timezone del browser.
   * @type {string|null}
   */
  static timezone = null;

  /** Devuelve el timezone efectivo (usuario o browser). */
  static _tz() {
    return this.timezone || Intl.DateTimeFormat().resolvedOptions().timeZone;
  }

  /**
   * Parsea un string de fecha UTC de la BD ("YYYY-MM-DD HH:mm:ss" o ISO)
   * y lo convierte a un objeto Date correcto (añade 'Z' para marcar UTC).
   */
  static _parse(dateStr) {
    if (!dateStr) return null;
    // Normalizar separador + añadir Z para que el browser lo trate como UTC
    const iso = dateStr.replace(' ', 'T').replace(/Z?$/, 'Z');
    const d = new Date(iso);
    return isNaN(d) ? null : d;
  }

  /**
   * Devuelve las partes de fecha/hora de un Date en el timezone del usuario.
   * @returns {{ year, month (0-based), day, time ("HH:mm") }}
   */
  static _parts(date) {
    const tz = this._tz();
    const fmt = new Intl.DateTimeFormat('es-EC', {
      timeZone: tz,
      year: 'numeric', month: 'numeric', day: 'numeric',
      hour: '2-digit', minute: '2-digit', hour12: false,
    });
    const p = Object.fromEntries(
      fmt.formatToParts(date)
        .filter(x => x.type !== 'literal')
        .map(x => [x.type, x.value])
    );
    const hh = String(parseInt(p.hour) % 24).padStart(2, '0');
    const mm = (p.minute ?? '00').padStart(2, '0');
    return {
      year:  parseInt(p.year),
      month: parseInt(p.month) - 1, // 0-based para _months[]
      day:   parseInt(p.day),
      time:  `${hh}:${mm}`,
    };
  }

  /**
   * Formato corto para listas/sidebars.
   * - Hoy     → "14:32"
   * - Ayer    → "ayer 09:15"
   * - Resto   → "24 feb 14:32"
   */
  static relativeShort(dateStr) {
    const date = this._parse(dateStr);
    if (!date) return dateStr || '';

    const p  = this._parts(date);
    const np = this._parts(new Date());
    const diffDays = Math.round(
      (Date.UTC(np.year, np.month, np.day) - Date.UTC(p.year, p.month, p.day)) / 86400000
    );

    if (diffDays === 0) return p.time;
    if (diffDays === 1) return `ayer ${p.time}`;

    const m = this._months[p.month].substring(0, 3);
    return `${p.day} ${m} ${p.time}`;
  }

  /**
   * Convierte una fecha UTC a texto relativo en español.
   * - Hoy     → "hoy 14:32"
   * - Ayer    → "ayer 09:15"
   * - Resto   → "17 de febrero 2026 a las 14:32"
   */
  static relative(dateStr) {
    const date = this._parse(dateStr);
    if (!date) return dateStr || '';

    const p  = this._parts(date);
    const np = this._parts(new Date());
    const diffDays = Math.round(
      (Date.UTC(np.year, np.month, np.day) - Date.UTC(p.year, p.month, p.day)) / 86400000
    );

    if (diffDays === 0) return `hoy ${p.time}`;
    if (diffDays === 1) return `ayer ${p.time}`;

    const m = this._months[p.month];
    return `${p.day} de ${m} ${p.year} a las ${p.time}`;
  }

  /**
   * Tiempo restante desde ahora hasta dateStr UTC (1 solo nivel).
   * Ejemplos: "5 minutos", "2 horas", "1 día", "expirado"
   */
  static timeRemaining(dateStr) {
    const target = this._parse(dateStr);
    if (!target) return '';
    const diffMs = target - Date.now();
    if (diffMs <= 0) return 'expirado';
    const mins  = Math.floor(diffMs / 60000);
    const hours = Math.floor(diffMs / 3600000);
    const days  = Math.floor(diffMs / 86400000);
    if (days >= 1)  return `${days} día${days > 1 ? 's' : ''}`;
    if (hours >= 1) return `${hours} hora${hours > 1 ? 's' : ''}`;
    return `${mins} minuto${mins !== 1 ? 's' : ''}`;
  }
}

window.bsDate = bsDate;
