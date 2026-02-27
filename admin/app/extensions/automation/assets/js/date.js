/* date.js - Utilidad de fechas relativas en español */

class bsDate {

  static _months = [
    'enero','febrero','marzo','abril','mayo','junio',
    'julio','agosto','septiembre','octubre','noviembre','diciembre'
  ];

  /**
   * Formato corto para listas/sidebars.
   * - Hoy     → "14:32"
   * - Ayer    → "ayer 09:15"
   * - Resto   → "24 feb"
   *
   * @param {string} dateStr
   * @returns {string}
   */
  static relativeShort(dateStr) {
    if (!dateStr) return '';

    const date = new Date(dateStr.replace(' ', 'T'));
    if (isNaN(date)) return dateStr;

    const time = date.toTimeString().substring(0, 5);

    const now      = new Date();
    const dateDay  = new Date(date.getFullYear(), date.getMonth(), date.getDate());
    const today    = new Date(now.getFullYear(),  now.getMonth(),  now.getDate());
    const diffDays = Math.round((today - dateDay) / 86400000);

    if (diffDays === 0) return time;
    if (diffDays === 1) return `ayer ${time}`;

    const d = date.getDate();
    const m = this._months[date.getMonth()].substring(0, 3);
    return `${d} ${m} ${time}`;
  }

  /**
   * Convierte una fecha a texto relativo en español.
   * - Hoy     → "hoy 14:32"
   * - Ayer    → "ayer 09:15"
   * - Resto   → "17 de febrero 2026 a las 14:32"
   *
   * @param {string} dateStr  Fecha en formato "YYYY-MM-DD HH:mm:ss" o ISO
   * @returns {string}
   */
  static relative(dateStr) {
    if (!dateStr) return '';

    // Normalizar separador para Safari (no entiende "2026-02-25 10:32:00")
    const date = new Date(dateStr.replace(' ', 'T'));
    if (isNaN(date)) return dateStr;

    const time = date.toTimeString().substring(0, 5); // "HH:mm"

    const now     = new Date();
    const dateDay = new Date(date.getFullYear(), date.getMonth(), date.getDate());
    const today   = new Date(now.getFullYear(),  now.getMonth(),  now.getDate());
    const diffMs  = today - dateDay;
    const diffDays = Math.round(diffMs / 86400000);

    if (diffDays === 0) return `hoy ${time}`;
    if (diffDays === 1) return `ayer ${time}`;

    const d = date.getDate();
    const m = this._months[date.getMonth()];
    const y = date.getFullYear();
    return `${d} de ${m} ${y} a las ${time}`;
  }
  /**
   * Tiempo restante desde ahora hasta dateStr (1 solo nivel).
   * Ejemplos: "5 minutos", "2 horas", "1 día", "expirado"
   * @param {string} dateStr
   * @returns {string}
   */
  static timeRemaining(dateStr) {
    if (!dateStr) return '';
    const target = new Date(dateStr.replace(' ', 'T'));
    if (isNaN(target)) return '';
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
