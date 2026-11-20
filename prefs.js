/*
 * Display & accessibility settings, kept in this browser (localStorage "mm-prefs").
 * Loaded in <head> before the styles paint, so the chosen theme, font and size show from the first frame:
 *   theme   dark | light | system      (dark, oxblood by candlelight, is the default; system follows the device)
 *   font    default | dyslexic | legible   (OpenDyslexic, Atkinson Hyperlegible)
 *   size    normal | large | larger
 *   spacing normal | wide              (more space between lines, letters and words)
 *   motion  system | reduce            (stops animations: flickering candles, twinkles, page turns)
 * Each choice is an attribute on <html> (data-theme, data-font…) that styles.css responds to.
 * Any form marked data-prefs on the page becomes the settings panel.
 */
(function () {
  var KEY = 'mm-prefs', root = document.documentElement;
  var DEFAULTS = { theme: 'dark', font: 'default', size: 'normal', spacing: 'normal', motion: 'system' };
  function load() {
    var p = {};
    try { p = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) {}
    // Before v2 the default theme was "system", and saving any setting stored it. Those visitors never picked it,
    // so they move to the new default (dark) once. A "system" chosen from v2 on is kept.
    if (!p.v) { if (p.theme === 'system') delete p.theme; p.v = 2; }
    for (var k in DEFAULTS) if (!(k in p)) p[k] = DEFAULTS[k];
    return p;
  }
  function apply(p) {
    if (p.theme === 'light' || p.theme === 'dark') root.setAttribute('data-theme', p.theme); else root.removeAttribute('data-theme');
    themeColor();
    for (var k in DEFAULTS) {
      if (k === 'theme') continue;
      if (p[k] && p[k] !== DEFAULTS[k]) root.setAttribute('data-' + k, p[k]); else root.removeAttribute('data-' + k);
    }
  }
  // The browser's own bar (phones) matches the header: oxblood, or parchment by day.
  function themeColor() {
    var dark = root.getAttribute('data-theme') ? root.getAttribute('data-theme') === 'dark'
      : !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
    var metas = document.querySelectorAll('meta[name="theme-color"]');
    for (var i = 0; i < metas.length; i++) { metas[i].removeAttribute('media'); metas[i].setAttribute('content', dark ? '#3d000c' : '#ede0d6'); }
  }
  apply(load());

  // The settings panel: radio groups named theme, font, size, spacing and motion. Changes apply at once.
  function wire() {
    var forms = document.querySelectorAll('form[data-prefs]');
    if (!forms.length) return;
    var p = load();
    Array.prototype.forEach.call(forms, function (f) {
      for (var k in DEFAULTS) {
        var r = f.querySelector('input[name="' + k + '"][value="' + p[k] + '"]');
        if (r) r.checked = true;
      }
      f.addEventListener('change', function (e) {
        if (!e.target.name || !(e.target.name in DEFAULTS)) return;
        p[e.target.name] = e.target.value;
        try { localStorage.setItem(KEY, JSON.stringify(p)); } catch (err) {}
        apply(p); fitNav();
        var note = f.querySelector('[data-prefs-saved]');
        if (note) { note.hidden = false; clearTimeout(note._t); note._t = setTimeout(function () { note.hidden = true; }, 2200); }
      });
      f.addEventListener('submit', function (e) { e.preventDefault(); });
      var reset = f.querySelector('[data-prefs-reset]');
      if (reset) reset.addEventListener('click', function () {
        p = {}; for (var k in DEFAULTS) p[k] = DEFAULTS[k];
        try { localStorage.removeItem(KEY); } catch (err) {}
        apply(p); fitNav();
        for (var k2 in DEFAULTS) { var r = f.querySelector('input[name="' + k2 + '"][value="' + p[k2] + '"]'); if (r) r.checked = true; }
      });
      f.hidden = false;
    });
    Array.prototype.forEach.call(document.querySelectorAll('[data-prefs-nojs]'), function (n) { n.hidden = true; });
  }
  // A wide font or larger text can make the header's five sections overflow: then they fold into the Menu button.
  function fitNav() {
    var nav = document.querySelector('.nav nav'); if (!nav) return;
    root.classList.remove('nav-fold');
    if (getComputedStyle(nav).display === 'none') return; // narrow screens fold already
    var bar = nav.closest('.nav') || nav.parentNode, r = nav.getBoundingClientRect();
    if (nav.scrollWidth > nav.clientWidth + 1 || bar.scrollWidth > bar.clientWidth + 1 || r.right > document.documentElement.clientWidth) root.classList.add('nav-fold');
  }
  // The theme toggle in the header: a pointed arch from the logo with the four-point star in its window.
  // It starts from whatever is showing (the device's setting, until a mode is picked) and flips between day and candlelight.
  // Settings keeps the third choice, "match my device".
  var darkQuery = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
  function isDark() {
    var t = root.getAttribute('data-theme');
    return t ? t === 'dark' : !!(darkQuery && darkQuery.matches);
  }
  var ARCH = '<svg viewBox="0 0 28 36" aria-hidden="true" focusable="false">'
    + '<path class="tt-glass" d="M5 34V17c0-5 4-6.6 7-9.4C13.3 6.3 13.8 4 14 1.5c.2 2.5.7 4.8 2 6.1 3 2.8 7 4.4 7 9.4v17z"/>'
    + '<path class="tt-arch" d="M3 34.5V16.6c0-6 4.6-7.9 7.7-10.7C12.6 4.2 13.6 2.3 14 .5c.4 1.8 1.4 3.7 3.3 5.4 3.1 2.8 7.7 4.7 7.7 10.7v17.9zM1.5 34.5h25"/>'
    + '<path class="tt-star" d="M14 13.5c.5 4.3 2.2 6 6.5 6.5-4.3.5-6 2.2-6.5 6.5-.5-4.3-2.2-6-6.5-6.5 4.3-.5 6-2.2 6.5-6.5z"/></svg>';
  function syncToggle(btn) {
    var dark = isDark();
    btn.setAttribute('aria-pressed', dark ? 'true' : 'false');
    btn.setAttribute('title', dark ? 'Candlelight is on. Switch to daylight' : 'Switch to candlelight (dark mode)');
  }
  function addToggle() {
    var bar = document.querySelector('header.site .nav');
    if (!bar || bar.querySelector('.theme-toggle')) return;
    var btn = document.createElement('button');
    btn.type = 'button'; btn.className = 'theme-toggle';
    btn.setAttribute('aria-label', 'Dark mode');
    btn.innerHTML = ARCH;
    syncToggle(btn);
    btn.addEventListener('click', function () {
      var p = load();
      p.theme = isDark() ? 'light' : 'dark';
      try { localStorage.setItem(KEY, JSON.stringify(p)); } catch (err) {}
      apply(p); syncToggle(btn); fitNav();
      var r = document.querySelector('form[data-prefs] input[name="theme"][value="' + p.theme + '"]');
      if (r) r.checked = true;
    });
    bar.appendChild(btn);
    if (darkQuery && darkQuery.addEventListener) darkQuery.addEventListener('change', function () { syncToggle(btn); themeColor(); });
    document.addEventListener('change', function (e) { if (e.target && e.target.name === 'theme') syncToggle(btn); });
  }
  function ready() { themeColor(); addToggle(); wire(); fitNav(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready); else ready();
  window.addEventListener('resize', fitNav);
  window.addEventListener('load', fitNav);
  if (document.fonts) { if (document.fonts.ready) document.fonts.ready.then(fitNav); if (document.fonts.addEventListener) document.fonts.addEventListener('loadingdone', fitNav); }
  window.mmFitNav = fitNav;
})();
