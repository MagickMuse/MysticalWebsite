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

  // Sort & filter drop-down: close on a click outside it or on Escape
  document.addEventListener('click', function (e) {
    document.querySelectorAll('.col-filter[open]').forEach(function (d) { if (!d.contains(e.target)) d.open = false; });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') document.querySelectorAll('.col-filter[open]').forEach(function (d) { d.open = false; d.querySelector('summary').focus(); });
  });

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
    var book = e.target.closest('.carousel .bk');
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
  function openBook(book, v) {
    var href = book.href;
    var r = book.getBoundingClientRect();
    var W = Math.min(300, window.innerWidth * 0.42, v.h * 0.6), H = W * 1.42, T = 46;
    var color = getComputedStyle(book).getPropertyValue('--bc'), inkOn = getComputedStyle(book).getPropertyValue('--ink-on');

    var veil = el('div', 'book-veil'), stage = el('div', 'book-stage'), wrap = el('div', 'b3d-wrap'), box = el('div', 'b3d');
    box.style.width = W + 'px'; box.style.height = H + 'px'; box.style.setProperty('--bc', color); box.style.setProperty('--ink-on', inkOn);
    var spine = el('div', 'b3d-spine', '<span class="t">' + esc(book.getAttribute('data-label')) + '</span>');
    spine.style.width = T + 'px'; spine.style.transform = 'translateZ(' + (-T / 2) + 'px) rotateY(-90deg)';
    var pages = el('div', 'b3d-pages', '<p class="p"><em>The Magick Manuscript</em>' + esc(book.getAttribute('data-title')) + '</p>');
    pages.style.transform = 'translateZ(' + (T / 2 - 2) + 'px)';
    var front = el('div', 'b3d-front');
    front.style.transform = 'translateZ(' + (T / 2) + 'px)';
    var photo = book.getAttribute('data-photo');
    front.appendChild(el('div', 'face', (photo ? '<img src="' + esc(photo) + '" alt="">' : '') + '<span class="t">' + esc(book.getAttribute('data-label')) + '</span>'));
    front.appendChild(el('div', 'inside'));
    // Hovered, the book already shows its cover: start from that. Tapped (no hover), start side-on from the spine.
    var fromCover = r.width > 120, turn0 = fromCover ? 'rotateY(0deg)' : 'rotateY(90deg)';
    if (!fromCover) pages.style.opacity = '0';
    box.appendChild(pages); box.appendChild(spine); box.appendChild(front);
    wrap.appendChild(box); stage.appendChild(wrap);
    document.body.appendChild(veil); document.body.appendChild(stage);

    // Start exactly over the book in the row.
    var s0 = fromCover ? r.width / W : r.height / H, cx0 = r.left + r.width / 2, cy0 = r.top + r.height / 2;
    // Open in the middle of the visible screen; the open book (cover + pages, 2W wide) is centred, so the spine sits at the middle.
    var cx1 = v.x, cy1 = v.y;
    stage.style.perspectiveOrigin = v.x + 'px ' + v.y + 'px';
    wrap.style.width = W + 'px'; wrap.style.height = H + 'px'; wrap.style.transformOrigin = '50% 50%';
    var at = function (cx, cy, sc, dz) { return 'translate(' + (cx - W / 2) + 'px,' + (cy - H / 2) + 'px) translateZ(' + (dz || 0) + 'px) scale(' + sc + ')'; };
    box.style.transform = turn0;
    wrap.style.transform = at(cx0, cy0, s0);
    book.style.visibility = 'hidden';
    var opts = function (d, delay) { return { duration: d, delay: delay || 0, easing: 'cubic-bezier(.45,.05,.2,1)', fill: 'forwards' }; };

    veil.animate([{ opacity: 0 }, { opacity: 1 }], opts(500));
    // 1. lift it out of the row
    wrap.animate([{ transform: at(cx0, cy0, s0) }, { transform: at(cx0, cy0 - 30, s0 * 1.08, 60) }], opts(300)).finished
      .then(function () {
        // 2. bring it to the middle (turning it to the cover if it started side-on)
        wrap.animate([{ transform: at(cx0, cy0 - 30, s0 * 1.08, 60) }, { transform: at(cx1, cy1, 1, 0) }], opts(fromCover ? 600 : 750));
        if (fromCover) return box.animate([{ transform: 'rotateY(0deg)' }, { transform: 'rotateY(-8deg)', offset: .5 }, { transform: 'rotateY(0deg)' }], opts(600)).finished;
        pages.animate([{ opacity: 0 }, { opacity: 0, offset: .4 }, { opacity: 1 }], opts(750));
        return box.animate([{ transform: 'rotateY(90deg)' }, { transform: 'rotateY(0deg)' }], opts(750)).finished;
      })
      .then(function () {
        // 3. open the cover, shifting the open book to stay centred
        wrap.animate([{ transform: at(cx1, cy1, 1, 0) }, { transform: at(cx1 + W / 2, cy1, 1.05, 0) }], opts(700));
        return front.animate([{ transform: 'translateZ(' + (T / 2) + 'px) rotateY(0deg)' }, { transform: 'translateZ(' + (T / 2) + 'px) rotateY(-170deg)' }], opts(700)).finished;
      })
      .then(function () { setTimeout(function () { location.href = href; }, 180); })
      .catch(function () { location.href = href; });
  }
  // Coming back with the browser's Back button: put the row back as it was.
  window.addEventListener('pageshow', function () {
    document.querySelectorAll('.book-stage, .book-veil').forEach(function (n) { n.remove(); });
    document.querySelectorAll('.carousel .bk').forEach(function (b) { b.style.visibility = ''; });
  });
})();
