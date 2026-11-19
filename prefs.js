/*
 * Display & accessibility settings, kept in this browser (localStorage "mm-prefs").
 * Loaded in <head> before the styles paint, so the chosen theme, font and size show from the first frame:
 *   theme   system | light | dark      (system follows the device; dark is a warm charcoal)
 *   font    default | dyslexic | legible   (OpenDyslexic, Atkinson Hyperlegible)
 *   size    normal | large | larger
 *   spacing normal | wide              (more space between lines, letters and words)
 *   motion  system | reduce            (stops animations: flickering candles, twinkles, page turns)
 * Each choice is an attribute on <html> (data-theme, data-font…) that styles.css responds to.
 * Any form marked data-prefs on the page becomes the settings panel.
 */
(function () {
  var KEY = 'mm-prefs', root = document.documentElement;
  var DEFAULTS = { theme: 'system', font: 'default', size: 'normal', spacing: 'normal', motion: 'system' };
  function load() {
    var p = {};
    try { p = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) {}
    for (var k in DEFAULTS) if (!(k in p)) p[k] = DEFAULTS[k];
    return p;
  }
  function apply(p) {
    if (p.theme === 'light' || p.theme === 'dark') root.setAttribute('data-theme', p.theme); else root.removeAttribute('data-theme');
    for (var k in DEFAULTS) {
      if (k === 'theme') continue;
      if (p[k] && p[k] !== DEFAULTS[k]) root.setAttribute('data-' + k, p[k]); else root.removeAttribute('data-' + k);
    }
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
  function ready() { wire(); fitNav(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready); else ready();
  window.addEventListener('resize', fitNav);
  window.addEventListener('load', fitNav);
  if (document.fonts) { if (document.fonts.ready) document.fonts.ready.then(fitNav); if (document.fonts.addEventListener) document.fonts.addEventListener('loadingdone', fitNav); }
  window.mmFitNav = fitNav;
})();
