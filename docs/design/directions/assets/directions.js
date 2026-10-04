// Theme toggle for the static style-direction pages. Without a choice the OS preference applies.
(function () {
  var root = document.documentElement;
  var btn = document.querySelector('[data-theme-toggle]');
  function current() {
    return root.getAttribute('data-theme') || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
  }
  function sync() {
    var dark = current() === 'dark';
    btn.setAttribute('aria-pressed', String(dark));
    btn.querySelector('[data-when=light]').hidden = dark;
    btn.querySelector('[data-when=dark]').hidden = !dark;
  }
  var saved = null;
  try { saved = localStorage.getItem('dir-theme'); } catch (e) {}
  var q = /[?&]theme=(light|dark)/.exec(location.search);
  if (q) saved = q[1];
  if (saved) root.setAttribute('data-theme', saved);
  btn.addEventListener('click', function () {
    var next = current() === 'dark' ? 'light' : 'dark';
    root.setAttribute('data-theme', next);
    try { localStorage.setItem('dir-theme', next); } catch (e) {}
    sync();
  });
  sync();
})();
