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

  // The GitHub Pages copy has no server: its search form points at search/ (with a slash), and searching
  // happens here in the browser, over the titles and other names in search/index.json.
  var staticSearch = (function () {
    var f = document.querySelector('.mm-search'), action = f && f.getAttribute('action');
    if (!action || !/search\/$/.test(action)) return null;
    var root = new URL(action + '../', location.href), loaded = null;
    var fold = function (t) { return (t || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase(); };
    function load() {
      return loaded || (loaded = fetch(new URL('search/index.json', root)).then(function (r) { return r.json(); }).then(function (d) {
        d.p.forEach(function (r) { r.t = fold(r[0]); r.a = fold(r[1]); r.w = (r.t + ' ' + r.a).split(/[^\p{L}\p{N}]+/u); });
        return d;
      }));
    }
    // Every word typed must start a word of the title or other names ("rose qua" finds Rose Quartz). Best matches first.
    function find(q, grimoire) {
      var terms = fold(q).split(/[^\p{L}\p{N}]+/u).filter(Boolean).slice(0, 8), whole = fold(q).trim();
      return load().then(function (d) {
        var hits = [];
        if (terms.length) d.p.forEach(function (r) {
          if (grimoire && r[3] !== grimoire) return;
          for (var i = 0; i < terms.length; i++) if (!r.w.some(function (w) { return w.indexOf(terms[i]) === 0; })) return;
          var score = r.t === whole ? 0 : r.t.indexOf(whole) === 0 ? 1 : r.t.indexOf(whole) >= 0 ? 2 : r.a.indexOf(whole) >= 0 ? 3 : 4;
          hits.push({ score: score, len: r[0].length, title: r[0], aka: r[1], url: new URL(r[2], root).href, grimoire: r[3],
            where: r[4] || d.g[r[3]] || '', icon: r[5] });
        });
        hits.sort(function (a, b) { return a.score - b.score || a.len - b.len || a.title.localeCompare(b.title); });
        return { hits: hits, grimoires: d.g };
      });
    }
    return { find: find, action: action };
  })();

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
        (staticSearch ? staticSearch.find(q).then(function (res) { return res.hits.slice(0, 8); })
          : fetch(endpoint + '?q=' + encodeURIComponent(q), { credentials: 'same-origin' }).then(function (r) { return r.json(); }))
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
            aa.textContent = rows.length ? 'Search all pages for “' + q + '”' : staticSearch ? 'No titles match “' + q + '”' : 'No titles match. Search all text for “' + q + '”';
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

  // The search results page of the GitHub Pages copy: filled in here from the index.
  var results = document.querySelector('[data-static-results]');
  if (results && staticSearch) {
    var params = new URLSearchParams(location.search), q = (params.get('q') || '').trim(), only = params.get('g') || '';
    var head = results.querySelector('h1'), out = results.querySelector('.results'), chipsBox = results.querySelector('.filters');
    if (box) box.value = q;
    var put = function (parent, tag, cls, txt) { var n = document.createElement(tag); if (cls) n.className = cls; if (txt) n.textContent = txt; parent.appendChild(n); return n; };
    var heading = function (n) {
      head.textContent = '';
      head.append(n ? (n > 100 ? '100+' : n) + (n === 1 ? ' result for ' : ' results for ') : 'Nothing found for ');
      put(head, 'em', '', q);
    };
    if (!q) { head.textContent = 'Search the Manuscript'; out.hidden = true; }
    else staticSearch.find(q).then(function (res) {
      var counts = {};
      res.hits.forEach(function (h) { counts[h.grimoire] = (counts[h.grimoire] || 0) + 1; });
      var hits = only ? res.hits.filter(function (h) { return h.grimoire === only; }) : res.hits;
      heading(hits.length);
      document.title = 'Search: ' + q + ' | The Magick Manuscript';
      // Filter by grimoire, when the results span several.
      var keys = Object.keys(counts).sort(function (a, b) { return counts[b] - counts[a]; });
      if (keys.length > 1 || only) {
        var link = function (label, g, n) {
          var a = put(chipsBox, 'a', 'chip', label + ' ');
          a.href = staticSearch.action + '?q=' + encodeURIComponent(q) + (g ? '&g=' + encodeURIComponent(g) : '');
          if (g === only) a.setAttribute('aria-current', 'true');
          put(a, 'small', '', String(n));
        };
        link('All', '', res.hits.length);
        keys.forEach(function (g) { link(res.grimoires[g] || g, g, counts[g]); });
        chipsBox.hidden = false;
      }
      if (!hits.length) { put(out, 'li', 'lede', 'Try another spelling, a shorter word, or a related term.'); return; }
      hits.slice(0, 100).forEach(function (h) {
        var li = put(out, 'li'), a = put(li, 'a');
        a.href = h.url;
        if (h.icon) put(a, 'span', 'pg-icon', h.icon).setAttribute('aria-hidden', 'true');
        put(a, 'strong', '', h.title);
        if (h.where) put(li, 'small', '', h.where);
        if (h.aka) put(li, 'small', 'aka-s', 'Also: ' + h.aka);
      });
    }).catch(function () { head.textContent = 'Search could not load. Please refresh the page.'; });
  }

  // Pictures open full size in an overlay; a click anywhere, the × or Escape closes it.
  document.addEventListener('click', function (e) {
    var a = e.target.closest('a[data-zoom]');
    if (!a || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0 || typeof HTMLDialogElement !== 'function') return;
    e.preventDefault();
    var d = document.createElement('dialog'); d.className = 'lightbox'; d.setAttribute('aria-label', 'Picture');
    var x = document.createElement('button'); x.className = 'lb-x'; x.type = 'button'; x.setAttribute('aria-label', 'Close'); x.textContent = '×';
    var img = document.createElement('img'); img.src = a.getAttribute('href'); img.alt = (a.querySelector('img') || {}).alt || '';
    d.appendChild(x); d.appendChild(img); document.body.appendChild(d);
    d.addEventListener('click', function () { d.close(); });
    d.addEventListener('close', function () { d.remove(); a.focus(); });
    d.showModal();
  });

  // How tall the pinned site header is, so pinned sidebars and jumped-to sections stay below it.
  (function () {
    var head = document.querySelector('header.site'); if (!head) return;
    var set = function () { document.documentElement.style.setProperty('--site-head', head.offsetHeight + 'px'); };
    set(); window.addEventListener('resize', set);
  })();

  // Table of contents for a page with written content: it shows while the reader scrolls, fades out
  // a moment after scrolling stops, and a click on a title goes to that section (and closes it).
  (function () {
    var prose = document.querySelector('.pg.with-aside > .mm-prose'); if (!prose) return;
    var heads = Array.prototype.filter.call(prose.querySelectorAll('h2[id], h3[id], h4[id]'), function (h) { return h.textContent.trim(); });
    if (heads.length < 2) return;
    var still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var nav = document.createElement('nav'); nav.className = 'toc'; nav.setAttribute('aria-label', 'On this page');
    var t = document.createElement('p'); t.className = 'toc-title'; t.textContent = 'On this page'; nav.appendChild(t);
    var ol = document.createElement('ol'); nav.appendChild(ol);
    var links = heads.map(function (h) {
      var li = document.createElement('li'); li.className = 'lvl-' + h.tagName.charAt(1);
      var a = document.createElement('a'); a.href = '#' + h.id; a.textContent = h.textContent.trim();
      li.appendChild(a); ol.appendChild(li); return a;
    });
    document.body.appendChild(nav);
    var timer = 0, jumping = false, over = false;
    function show() { nav.classList.add('show'); }
    function hide() { if (!over && !nav.contains(document.activeElement)) nav.classList.remove('show'); }
    function later() { clearTimeout(timer); timer = setTimeout(hide, 1100); }
    function mark() {
      var cur = -1;
      heads.forEach(function (h, i) { if (h.getBoundingClientRect().top < 140) cur = i; });
      links.forEach(function (a, i) { if (i === cur) a.setAttribute('aria-current', 'true'); else a.removeAttribute('aria-current'); });
    }
    window.addEventListener('scroll', function () {
      mark();
      if (jumping) { clearTimeout(timer); timer = setTimeout(function () { jumping = false; }, 160); return; }
      show(); later();
    }, { passive: true });
    nav.addEventListener('pointerenter', function () { over = true; clearTimeout(timer); });
    nav.addEventListener('pointerleave', function () { over = false; later(); });
    nav.addEventListener('focusin', show);
    nav.addEventListener('focusout', later);
    nav.addEventListener('click', function (e) {
      var a = e.target.closest('a'); if (!a) return;
      e.preventDefault();
      var h = document.getElementById(a.getAttribute('href').slice(1));
      for (var d = h.closest('details'); d; d = d.parentElement && d.parentElement.closest('details')) d.open = true; // sections folded in a toggle
      jumping = true; over = false; nav.classList.remove('show'); a.blur();
      h.scrollIntoView({ behavior: still ? 'auto' : 'smooth', block: 'start' });
      history.replaceState(null, '', '#' + h.id);
    });
    mark();
  })();

  // The Calendar: one month at a time, days under their weekdays for the year shown, today marked.
  (function () {
    var cal = document.querySelector('[data-calendar]'); if (!cal) return;
    var months = cal.querySelectorAll('.cal-m'), nav = cal.querySelector('.cal-nav'), title = cal.querySelector('.cal-title');
    var now = new Date(), year = now.getFullYear(), month = now.getMonth() + 1;
    var leap = function (y) { return (y % 4 === 0 && y % 100 !== 0) || y % 400 === 0; };
    cal.classList.add('js'); nav.hidden = false;
    months.forEach(function (m) {
      var row = document.createElement('div'); row.className = 'cal-week'; row.setAttribute('aria-hidden', 'true');
      ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].forEach(function (n) { var c = document.createElement('span'); c.textContent = n; row.appendChild(c); });
      m.querySelector('.cal-days').before(row);
    });
    function show() {
      var first = new Date(year, month - 1, 1).getDay(), cur = null;
      months.forEach(function (m) { var on = +m.getAttribute('data-month') === month; m.hidden = !on; if (on) cur = m; });
      cur.querySelectorAll('.cal-days li').forEach(function (li, i) {
        var d = +li.getAttribute('data-day'), a = li.firstElementChild;
        li.style.gridColumnStart = i === 0 ? String(first + 1) : '';
        li.hidden = month === 2 && d === 29 && !leap(year);
        if (year === now.getFullYear() && month === now.getMonth() + 1 && d === now.getDate()) a.setAttribute('aria-current', 'date'); else a.removeAttribute('aria-current');
      });
      title.textContent = cur.getAttribute('aria-label') + ' ' + year;
    }
    nav.addEventListener('click', function (e) {
      var b = e.target.closest('[data-cal]'); if (!b) return;
      var step = +b.getAttribute('data-cal');
      if (step === 0) { year = now.getFullYear(); month = now.getMonth() + 1; }
      else { month += step; if (month < 1) { month = 12; year--; } if (month > 12) { month = 1; year++; } }
      show();
    });
    show();
  })();

  // GitHub Pages copy: a property value clicked on an entry (?f_type=Flower#list-plants) filters the grimoire's list here,
  // from values.json beside the page (built by .github/scripts/build-static-values.py).
  (function () {
    if (!staticSearch || !document.querySelector('section.collection')) return;
    var params = new URLSearchParams(location.search), wanted = [];
    params.forEach(function (v, k) { if (k.indexOf('f_') === 0 && v) wanted.push([k.slice(2), v]); });
    if (!wanted.length) return;
    fetch(new URL('values.json', location.href)).then(function (r) { return r.json(); }).then(function (data) {
      var key = params.get('list') || Object.keys(data.lists).find(function (k) { return data.lists[k].props[wanted[0][0]]; });
      var list = data.lists[key], section = document.getElementById('list-' + key);
      if (!list || !section) return;
      var keep = null;
      wanted.forEach(function (w) {
        var ids = new Set(((list.props[w[0]] || {})[w[1]] || []).map(function (i) { return list.slugs[i]; }));
        keep = keep ? new Set([...keep].filter(function (s) { return ids.has(s); })) : ids;
      });
      // Every entry of this list once, in A–Z order, then only the ones with the value.
      var seen = {}, items = [];
      section.querySelectorAll('.entry-grid li').forEach(function (li) {
        var a = li.querySelector('a'), m = a && a.getAttribute('href').match(/\/([^\/]+)\/$/);
        if (!m || seen[m[1]]) return;
        seen[m[1]] = true;
        if (keep.has(m[1])) { var c = li.cloneNode(true); c.hidden = false; items.push(c); }
      });
      items.sort(function (x, y) { return x.textContent.trim().localeCompare(y.textContent.trim(), undefined, { numeric: true }); });
      section.querySelectorAll('.col-group, ul[data-col-list], .col-more, .collection > .lede').forEach(function (n) { n.remove(); });
      var ul = document.createElement('ul'); ul.className = 'entry-grid';
      items.forEach(function (li) { ul.appendChild(li); });
      var bar = section.querySelector('.col-bar');
      bar.after(ul);
      if (!items.length) { var none = document.createElement('p'); none.className = 'lede'; none.textContent = 'No entries match.'; ul.replaceWith(none); }
      var count = bar.querySelector('.col-count');
      if (count) count.textContent = items.length + ' of ' + Object.keys(seen).length + ' entries';
      var tags = bar.querySelector('.atags') || bar.insertBefore(Object.assign(document.createElement('div'), { className: 'atags' }), count);
      wanted.forEach(function (w) {
        var t = document.createElement('a'); t.className = 'atag'; t.title = 'Remove';
        t.href = location.pathname + '#list-' + key; t.textContent = w[1] + ' ';
        var x = document.createElement('span'); x.setAttribute('aria-hidden', 'true'); x.textContent = '×'; t.appendChild(x);
        tags.appendChild(t);
      });
      section.scrollIntoView();
    }).catch(function () {});
  })();

  // GitHub Pages copy: a group shows its first entries; "Show all" reveals the rest in place.
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-show-all]'); if (!b) return;
    b.parentNode.querySelectorAll('li[hidden]').forEach(function (li) { li.hidden = false; });
    b.remove();
  });

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

  // "Show all" on long relation rows: load the rest of the links only when asked
  document.addEventListener('click', function (e) {
    var more = e.target.closest('.rel-more[data-more]');
    if (!more) return;
    e.preventDefault();
    if (more.getAttribute('aria-busy') === 'true') return;
    more.setAttribute('aria-busy', 'true');
    more.textContent = 'Loading…';
    fetch(more.getAttribute('data-more'), { credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error(); return r.json(); })
      .then(function (d) { var box = more.closest('[data-rels]'); box.innerHTML = d.html; })
      .catch(function () { location.href = more.getAttribute('href'); });
  });

  // Grimoire lists: apply sort / group / "only my saved" as soon as they change
  document.querySelectorAll('[data-autosubmit]').forEach(function (el) {
    el.addEventListener('change', function () { el.form.submit(); });
  });
  // "Show more" on a grimoire list: add the next 120 without reloading
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-more-list]');
    if (!btn) return;
    e.preventDefault();
    var list = document.querySelector('[data-col-list]');
    btn.textContent = 'Loading…';
    fetch(btn.getAttribute('data-more-list'), { credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error(); return r.text(); })
      .then(function (html) {
        list.insertAdjacentHTML('beforeend', html);
        var next = list.querySelector('.more-row');
        if (next) { var url = next.getAttribute('data-next'); next.remove(); btn.href = url; btn.setAttribute('data-more-list', url + (url.indexOf('?') > -1 ? '&' : '?') + 'part=1'); btn.textContent = 'Show more'; }
        else { btn.parentNode.remove(); }
      })
      .catch(function () { location.href = btn.href; });
  });

  // Sort & filter drop-down: close on a click outside it or on Escape (not when it's the sidebar on a wide screen)
  var dropdowns = function () { return Array.prototype.filter.call(document.querySelectorAll('.col-filter[open]'), function (d) { return !d.closest('.tools-aside'); }); };
  document.addEventListener('click', function (e) {
    dropdowns().forEach(function (d) { if (!d.contains(e.target)) d.open = false; });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') dropdowns().forEach(function (d) { d.open = false; d.querySelector('summary').focus(); });
  });

  // Wide screens: a grimoire's Sort & filter sits open in a sidebar on the left of its list.
  (function () {
    var lists = document.querySelectorAll('section.collection'); if (!lists.length || !window.matchMedia) return;
    var wide = window.matchMedia('(min-width: 1000px)');
    var apply = function () {
      lists.forEach(function (c) {
        var d = c.querySelector('.col-filter'); if (!d) return;
        c.classList.toggle('tools-aside', wide.matches);
        d.open = wide.matches;
      });
    };
    apply();
    if (wide.addEventListener) wide.addEventListener('change', apply);
  })();

  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // Visitors: anything inside the Manuscript (a book, a connection, a link) opens the join pop-up,
  // except the pages anyone can read. Runs first (capture), so the book animation never starts for them.
  (function () {
    var body = document.body, pop = document.getElementById('join-pop');
    if (!body.hasAttribute('data-visitor') || !pop || !pop.showModal) return;
    var base = (body.getAttribute('data-base') || '').replace(/\/$/, '');
    var open = []; try { open = JSON.parse(body.getAttribute('data-open') || '[]'); } catch (err) {}
    var free = ['join', 'login', 'shop', 'checkout', 'account', 'welcome', 'sneak-peek', 'media', 'auth', 'logout'];
    function locked(a) {
      var u; try { u = new URL(a.href, location.href); } catch (err) { return false; }
      if (u.origin !== location.origin) return false;
      var path = u.pathname.replace(/\/$/, '');
      if (path.indexOf(base + '/') !== 0) return false;
      var first = path.slice(base.length + 1).split('/')[0];
      return first !== '' && free.indexOf(first) === -1 && open.indexOf(path) === -1;
    }
    window.mmJoinPop = function () { if (!pop.open) pop.showModal(); };
    document.addEventListener('click', function (e) {
      var a = e.target.closest && e.target.closest('a[href]');
      if (!a || !locked(a)) return;
      e.preventDefault(); e.stopImmediatePropagation(); window.mmJoinPop();
    }, true);
    pop.addEventListener('click', function (e) { if (e.target === pop || e.target.closest('[data-close]')) pop.close(); });
  })();

  // Carousel arrows: move along the row by most of its width.
  document.querySelectorAll('.carousel').forEach(function (c) {
    var track = c.querySelector('[data-carousel]'); if (!track) return;
    var btns = c.querySelectorAll('[data-car]');
    function sync() {
      var max = track.scrollWidth - track.clientWidth - 2;
      c.classList.toggle('fits', track.scrollWidth <= track.clientWidth + 2 + 170);
      btns.forEach(function (b) { b.disabled = b.getAttribute('data-car') === '-1' ? track.scrollLeft <= 2 : track.scrollLeft >= max; });
    }
    btns.forEach(function (b) { b.addEventListener('click', function () { track.scrollBy({ left: +b.getAttribute('data-car') * track.clientWidth * 0.8, behavior: 'smooth' }); }); });
    track.addEventListener('scroll', sync, { passive: true }); track.addEventListener('transitionend', sync); window.addEventListener('resize', sync); sync();

    // Hover opens a book to its cover. If that cover would be cut off at the edge of the row, the row glides
    // just far enough to show all of it. While the row is moving, covers stay as they are (no flicker).
    if (!(window.matchMedia && window.matchMedia('(hover: hover)').matches)) return;
    track.setAttribute('data-ready', '');
    var current = null, moving = false, stopT = 0, lastX = -1, lastY = -1;
    function open(bk) {
      if (bk === current) return;
      if (current) current.classList.remove('open');
      current = bk;
      if (!bk) return;
      bk.classList.add('open');
      if (bk.classList.contains('lying')) return; // lies flat: it lifts a little where it is, no cover to show
      var wide = parseFloat(getComputedStyle(bk.querySelector('.cover')).width) || 220, pad = 16;
      var left = bk.offsetLeft - track.offsetLeft, right = left + wide;
      var view0 = track.scrollLeft, view1 = view0 + track.clientWidth, to = null;
      if (right > view1 - pad) to = right - track.clientWidth + pad;
      else if (left < view0 + pad) to = left - pad;
      if (to !== null) track.scrollTo({ left: Math.max(0, to), behavior: reduceMotion ? 'auto' : 'smooth' });
    }
    function under() { var el = lastX < 0 ? null : document.elementFromPoint(lastX, lastY); return el && track.contains(el) ? el.closest('.bk') : null; }
    track.addEventListener('pointermove', function (e) { lastX = e.clientX; lastY = e.clientY; if (!moving) open(e.target.closest('.bk')); });
    track.addEventListener('pointerleave', function () { lastX = -1; if (!moving) open(null); });
    track.addEventListener('scroll', function () {
      moving = true; clearTimeout(stopT);
      stopT = setTimeout(function () { moving = false; open(under()); }, 140);
    }, { passive: true });
  });

  // Grimoire carousel: lift the book out from its cover, open it, then go in.
  function el(tag, cls, html) { var e = document.createElement(tag); if (cls) e.className = cls; if (html) e.innerHTML = html; return e; }
  function esc(t) { var d = document.createElement('div'); d.textContent = t || ''; return d.innerHTML; }
  document.addEventListener('click', function (e) {
    var book = e.target.closest('.carousel .bk, .book-pile .bk');
    if (!book || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0 || reduceMotion || !document.body.animate) return;
    e.preventDefault();
    visibleArea(function (v) { openBook(book, v); });
  });
  // The part of the page actually on screen. Usually the window, but when the page sits in a tall frame
  // (as in a preview), only part of it is visible, and the book should open in the middle of that part.
  function visibleArea(done) {
    var all = { x: window.innerWidth / 2, y: window.innerHeight / 2, h: window.innerHeight };
    if (!window.IntersectionObserver) { done(all); return; }
    var called = false, finish = function (v) { if (!called) { called = true; done(v); } };
    var io = new IntersectionObserver(function (entries) {
      io.disconnect();
      var r = entries[0] && entries[0].intersectionRect;
      finish(r && r.height > 40 ? { x: r.left + r.width / 2, y: r.top + r.height / 2, h: r.height } : all);
    });
    io.observe(document.documentElement);
    setTimeout(function () { io.disconnect(); finish(all); }, 120);
  }
  // Four-pointed gold stars that twinkle around a box (centre cx, cy; size w × h).
  function sparkle(cx, cy, w, h, delay, burst) {
    if (reduceMotion) return;
    var n = burst ? 22 : 12;
    for (var i = 0; i < n; i++) {
      var s = el('i', 'sparkle' + (i % 4 === 0 ? ' big' : i % 3 === 0 ? ' small' : ''));
      var a = (i / n) * Math.PI * 2 + Math.random() * 0.6;
      var rx = w / 2 + 14 + Math.random() * 46, ry = h / 2 + 10 + Math.random() * 40;
      var x = cx + Math.cos(a) * rx * (burst ? 0.98 + Math.random() * 0.3 : 1), y = cy + Math.sin(a) * ry * (burst ? 0.98 + Math.random() * 0.3 : 1);
      s.style.left = x + 'px'; s.style.top = y + 'px';
      document.body.appendChild(s);
      var d = 700 + Math.random() * 450, start = (delay || 0) + Math.random() * (burst ? 300 : 420);
      var dx = burst ? Math.cos(a) * 34 : 0, dy = burst ? Math.sin(a) * 34 : -14;
      s.animate([
        { opacity: 0, transform: 'translate(0,0) scale(.2) rotate(0deg)' },
        { opacity: 1, transform: 'translate(' + dx / 2 + 'px,' + dy / 2 + 'px) scale(1) rotate(45deg)', offset: .4 },
        { opacity: 0, transform: 'translate(' + dx + 'px,' + dy + 'px) scale(.3) rotate(90deg)' }
      ], { duration: d, delay: start, easing: 'ease-out', fill: 'both' });
    }
  }
  function openBook(book, v) {
    var href = book.href;
    var r = book.getBoundingClientRect();
    var W = Math.min(300, window.innerWidth * 0.44, v.h * 0.6), H = W * 1.42, T = Math.max(26, Math.round(W * 0.15));
    var label = book.getAttribute('data-label') || '';
    var color = getComputedStyle(book).getPropertyValue('--bc'), inkOn = getComputedStyle(book).getPropertyValue('--ink-on');

    var veil = el('div', 'book-veil'), stage = el('div', 'book-stage'), wrap = el('div', 'b3d-wrap'), box = el('div', 'b3d');
    box.style.width = W + 'px'; box.style.height = H + 'px'; box.style.setProperty('--bc', color); box.style.setProperty('--ink-on', inkOn);
    box.style.setProperty('--w', W + 'px'); box.style.setProperty('--t', T + 'px'); box.style.setProperty('--n', String(Math.max(4, label.length)));
    var spine = el('div', 'b3d-spine', '<span class="t">' + esc(label) + '</span>');
    spine.style.width = T + 'px'; spine.style.transform = 'translateZ(' + (-T / 2) + 'px) rotateY(-90deg)';
    var pages = el('div', 'b3d-pages', '<p class="p"><em>The Magick Manuscript</em>' + esc(book.getAttribute('data-title')) + '</p>');
    pages.style.transform = 'translateZ(' + (T / 2 - 2) + 'px)';
    var front = el('div', 'b3d-front');
    front.style.transform = 'translateZ(' + (T / 2) + 'px)';
    var photo = book.getAttribute('data-photo');
    front.appendChild(el('div', 'face', (photo ? '<img src="' + esc(photo) + '" alt="">' : '') + '<span class="t">' + esc(label) + '</span>'));
    front.appendChild(el('div', 'inside'));
    // Hovered, the book already shows its cover: start from that. Tapped (no hover), start side-on from the spine.
    // A book lying flat starts from its spine too, turned on its side; it stands up as it comes to the middle.
    var lying = book.classList.contains('lying');
    var fromCover = !lying && r.width > 120, turn0 = fromCover ? 'rotateY(0deg)' : lying ? 'rotateZ(-90deg) rotateY(90deg)' : 'rotateY(90deg)';
    if (lying) box.classList.add('lying');
    if (!fromCover) pages.style.opacity = '0';
    box.appendChild(pages); box.appendChild(spine); box.appendChild(front);
    wrap.appendChild(box); stage.appendChild(wrap);
    document.body.appendChild(veil); document.body.appendChild(stage);

    // Start exactly over the book in the row.
    var s0 = fromCover ? r.width / W : (lying ? r.width : r.height) / H, cx0 = r.left + r.width / 2, cy0 = r.top + r.height / 2;
    // Open in the middle of the visible screen; the open book (cover + pages, 2W wide) is centred, so the spine sits at the middle.
    var cx1 = v.x, cy1 = v.y;
    stage.style.perspectiveOrigin = v.x + 'px ' + v.y + 'px';
    wrap.style.width = W + 'px'; wrap.style.height = H + 'px'; wrap.style.transformOrigin = '50% 50%';
    var at = function (cx, cy, sc, dz) { return 'translate(' + (cx - W / 2) + 'px,' + (cy - H / 2) + 'px) translateZ(' + (dz || 0) + 'px) scale(' + sc + ')'; };
    box.style.transform = turn0;
    wrap.style.transform = at(cx0, cy0, s0);
    book.style.visibility = 'hidden';
    var opts = function (d, delay) { return { duration: d, delay: delay || 0, easing: 'cubic-bezier(.45,.05,.2,1)', fill: 'forwards' }; };

    veil.animate([{ opacity: 0 }, { opacity: 1 }], opts(380));
    var travel = fromCover ? 460 : 560, openT = 560;
    // 1. lift it out of the row
    wrap.animate([{ transform: at(cx0, cy0, s0) }, { transform: at(cx0, cy0 - 24, s0 * 1.06, 50) }], opts(200)).finished
      .then(function () {
        // 2. bring it to the middle (turning it to the cover if it started side-on); stars start to gather
        sparkle(cx1, cy1, W, H, travel - 120);
        wrap.animate([{ transform: at(cx0, cy0 - 24, s0 * 1.06, 50) }, { transform: at(cx1, cy1, 1, 0) }], opts(travel));
        if (fromCover) return box.animate([{ transform: 'rotateY(0deg)' }, { transform: 'rotateY(-8deg)', offset: .5 }, { transform: 'rotateY(0deg)' }], opts(travel)).finished;
        pages.animate([{ opacity: 0 }, { opacity: 0, offset: .4 }, { opacity: 1 }], opts(travel));
        return box.animate(lying ? [{ transform: turn0 }, { transform: 'rotateZ(0deg) rotateY(0deg)' }]
          : [{ transform: 'rotateY(90deg)' }, { transform: 'rotateY(0deg)' }], opts(travel)).finished;
      })
      .then(function () {
        // 3. open the cover, shifting the open book to stay centred, in a burst of stars
        sparkle(cx1, cy1, W * 2, H, 0, true);
        wrap.animate([{ transform: at(cx1, cy1, 1, 0) }, { transform: at(cx1 + W / 2, cy1, 1.05, 0) }], opts(openT));
        return front.animate([{ transform: 'translateZ(' + (T / 2) + 'px) rotateY(0deg)' }, { transform: 'translateZ(' + (T / 2) + 'px) rotateY(-170deg)' }], opts(openT)).finished;
      })
      .then(function () { setTimeout(function () { location.href = href; }, 220); })
      .catch(function () { location.href = href; });
  }
  // Put the row back as it was once a book has been opened: when coming back with the browser's Back button,
  // and when the page changes without reloading (an address ending in #…, as in a preview).
  function restoreShelf() {
    document.querySelectorAll('.book-stage, .book-veil, .sparkle').forEach(function (n) { n.remove(); });
    document.querySelectorAll('.carousel .bk, .book-pile .bk').forEach(function (b) { b.style.visibility = ''; b.classList.remove('open'); });
  }
  window.addEventListener('pageshow', restoreShelf);
  window.addEventListener('hashchange', restoreShelf);
})();
