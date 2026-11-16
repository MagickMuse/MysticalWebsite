(function () {
  // Mobile menu (same behaviour as the main site)
  var btn = document.querySelector('.menu');
  var menu = document.getElementById('site-menu');
  if (btn && menu) {
    btn.addEventListener('click', function () {
      var open = btn.getAttribute('aria-expanded') !== 'true';
      btn.setAttribute('aria-expanded', String(open));
      menu.classList.toggle('open', open);
    });
  }

  // Type-to-filter on database pages
  document.querySelectorAll('[data-filter]').forEach(function (input) {
    var list = input.closest('article, section').querySelector('[data-filterable]');
    if (!list) return;
    var items = Array.prototype.slice.call(list.children);
    var empty = document.createElement('p');
    empty.className = 'filter-empty';
    empty.textContent = 'No entries match.';
    empty.hidden = true;
    list.after(empty);
    input.addEventListener('input', function () {
      var q = input.value.trim().toLowerCase();
      var shown = 0;
      items.forEach(function (li) {
        var hit = !q || li.getAttribute('data-name').indexOf(q) !== -1;
        li.hidden = !hit;
        if (hit) shown++;
      });
      empty.hidden = shown !== 0;
    });
  });

  // Live search suggestions
  var form = document.querySelector('.mm-search');
  var box = document.getElementById('mm-q');
  if (form && box) {
    var list = document.createElement('ul');
    list.className = 'suggest';
    list.id = 'mm-suggest';
    list.setAttribute('role', 'listbox');
    list.hidden = true;
    form.appendChild(list);
    box.setAttribute('autocomplete', 'off');
    box.setAttribute('aria-controls', 'mm-suggest');
    box.setAttribute('aria-autocomplete', 'list');
    var timer, seq = 0, active = -1;
    var endpoint = form.getAttribute('action').replace(/search$/, 'suggest');
    var links = function () { return list.querySelectorAll('a'); };
    var close = function () { list.hidden = true; active = -1; box.setAttribute('aria-expanded', 'false'); };
    var mark = function (i) {
      var a = links();
      a.forEach(function (el, j) { el.setAttribute('aria-selected', String(j === i)); });
      active = i;
    };
    box.addEventListener('input', function () {
      clearTimeout(timer);
      var q = box.value.trim();
      if (q.length < 2) { close(); return; }
      timer = setTimeout(function () {
        var mine = ++seq;
        fetch(endpoint + '?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (rows) {
            if (mine !== seq) return;
            list.textContent = '';
            rows.forEach(function (r) {
              var li = document.createElement('li');
              var a = document.createElement('a');
              a.href = r.url;
              a.setAttribute('role', 'option');
              a.textContent = (r.icon ? r.icon + ' ' : '') + r.title;
              if (r.where) { var s = document.createElement('small'); s.textContent = r.where; a.appendChild(s); }
              li.appendChild(a);
              list.appendChild(li);
            });
            var all = document.createElement('li');
            all.className = 'all';
            var aa = document.createElement('a');
            aa.href = form.getAttribute('action') + '?q=' + encodeURIComponent(q);
            aa.textContent = rows.length ? 'Search all pages for “' + q + '”' : 'No titles match. Search all text for “' + q + '”';
            all.appendChild(aa);
            list.appendChild(all);
            list.hidden = false;
            active = -1;
            box.setAttribute('aria-expanded', 'true');
          })
          .catch(close);
      }, 160);
    });
    box.addEventListener('keydown', function (e) {
      if (list.hidden) return;
      var a = links();
      if (e.key === 'ArrowDown') { e.preventDefault(); mark(Math.min(active + 1, a.length - 1)); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); mark(Math.max(active - 1, -1)); }
      else if (e.key === 'Enter' && active >= 0) { e.preventDefault(); location.href = a[active].href; }
      else if (e.key === 'Escape') { close(); }
    });
    document.addEventListener('click', function (e) { if (!form.contains(e.target)) close(); });
  }

  // Chart hover tooltips (dashboard)
  document.querySelectorAll('.chart-wrap').forEach(function (wrap) {
    var tip = document.createElement('div');
    tip.className = 'chart-tip';
    tip.hidden = true;
    wrap.appendChild(tip);
    wrap.querySelectorAll('[data-tip]').forEach(function (hit) {
      hit.addEventListener('mouseenter', function () {
        var parts = hit.getAttribute('data-tip').split('|');
        tip.textContent = '';
        var s = document.createElement('strong');
        s.textContent = parts.shift();
        tip.appendChild(s);
        parts.forEach(function (p) { tip.appendChild(document.createTextNode(p)); tip.appendChild(document.createElement('br')); });
        tip.hidden = false;
      });
      hit.addEventListener('mousemove', function (e) {
        var r = wrap.getBoundingClientRect();
        var x = e.clientX - r.left + 14;
        if (x + tip.offsetWidth > r.width) x = e.clientX - r.left - tip.offsetWidth - 14;
        tip.style.left = x + 'px';
        tip.style.top = Math.max(0, e.clientY - r.top - tip.offsetHeight / 2) + 'px';
      });
      hit.addEventListener('mouseleave', function () { tip.hidden = true; });
    });
  });

  // Small message at the bottom of the screen
  var toastTimer;
  function toast(text) {
    var t = document.querySelector('.mm-toast');
    if (!t) { t = document.createElement('div'); t.className = 'mm-toast'; t.setAttribute('role', 'status'); document.body.appendChild(t); }
    t.textContent = text; t.hidden = false;
    clearTimeout(toastTimer); toastTimer = setTimeout(function () { t.hidden = true; }, 2600);
  }

  // Save / unsave a page without reloading (falls back to a normal form post without JavaScript)
  document.querySelectorAll('.save-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = form.querySelector('.save-btn');
      btn.disabled = true;
      fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
        .then(function (r) { if (!r.ok) throw new Error(); return r.json(); })
        .then(function (d) {
          btn.setAttribute('aria-pressed', String(d.saved));
          btn.querySelector('.save-star').textContent = d.saved ? '★' : '☆';
          btn.querySelector('.save-label').textContent = d.saved ? 'Saved' : 'Save';
          toast(d.saved ? 'Saved. Find it under ★ Saved at the top.' : 'Removed from your saved pages.');
        })
        .catch(function () { form.submit(); })
        .then(function () { btn.disabled = false; });
    });
  });

  // Copy protection on Manuscript pages (members only; the curator is exempt)
  if (document.body.classList.contains('no-copy')) {
    var main = document.querySelector('.mm-main');
    var inField = function (el) { return el && el.closest && el.closest('input, textarea, [contenteditable="true"]'); };
    var inMain = function (el) { return main && el && main.contains(el); };
    var stop = function (msg) { return function (e) { if (inField(e.target)) return; if (e.type !== 'copy' && e.type !== 'cut' && !inMain(e.target)) return; e.preventDefault(); if (msg) toast(msg); }; };
    var msg = 'Copying is turned off for the Manuscript.';
    document.addEventListener('copy', stop(msg));
    document.addEventListener('cut', stop(msg));
    document.addEventListener('contextmenu', stop(''));
    document.addEventListener('dragstart', stop(''));
    document.addEventListener('selectstart', stop(''));
    document.addEventListener('keydown', function (e) {
      if (!(e.ctrlKey || e.metaKey) || inField(e.target)) return;
      var k = (e.key || '').toLowerCase();
      if (k === 'c' || k === 'x' || k === 'a' || k === 's' || k === 'p') { e.preventDefault(); if (k !== 'a') toast(k === 'p' ? 'The Manuscript can’t be printed.' : msg); }
    });
    window.addEventListener('beforeprint', function () { toast('The Manuscript can’t be printed.'); });
  }
})();
