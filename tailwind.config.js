/**
 * Tailwind CSS (standalone CLI, no Node). Colors and radii are CSS variables
 * (see resources/css/app.css) so that light/dark themes switch without rebuilding.
 */
const v = (name) => `rgb(var(--${name}) / <alpha-value>)`;

module.exports = {
  content: [
    './templates/**/*.twig',
    './public/assets/js/**/*.js',
    './docs/design/directions/*.html',
  ],
  darkMode: ['selector', '[data-theme="dark"]'],
  theme: {
    extend: {
      fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] },
      colors: {
        bg: v('bg'), surface: v('surface'), sunken: v('sunken'), line: v('line'),
        fg: v('fg'), muted: v('muted'),
        primary: { DEFAULT: v('p'), hover: v('p-hover'), fg: v('p-fg'), soft: v('p-soft'), softfg: v('p-softfg'), text: v('p-text') },
        ok: { DEFAULT: v('ok'), soft: v('ok-soft'), fg: v('ok-fg') },
        warn: { DEFAULT: v('warn'), soft: v('warn-soft'), fg: v('warn-fg') },
        bad: { DEFAULT: v('bad'), soft: v('bad-soft'), fg: v('bad-fg') },
        info: { DEFAULT: v('info'), soft: v('info-soft'), fg: v('info-fg') },
        vk: v('vk'), tg: v('tg'), max: v('max'), ig: v('ig'),
      },
      borderRadius: { ctl: 'var(--r-md)', card: 'var(--r-lg)', pill: '9999px' },
      boxShadow: { card: 'var(--shadow-card)', pop: 'var(--shadow-pop)' },
    },
  },
};
