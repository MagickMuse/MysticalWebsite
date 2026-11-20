(function () {
  var SEEN_KEY = 'tmm_popup_seen';

  // Shop / join clicks: analytics event, and never show the exit offer afterwards
  document.addEventListener('click', function (e) {
    var link = e.target.closest('a[href^="/market"], a[href^="/manuscript/join"]');
    if (!link) return;
    try { localStorage.setItem(SEEN_KEY, '1'); } catch (err) {}
    if (typeof gtag !== 'function') return;
    var href = link.getAttribute('href');
    var type = 'store';
    if (href.indexOf('/manuscript/join') === 0) type = 'manuscript';
    else if (href.indexOf('/market/') === 0) type = href.split('/')[2] || 'store';
    gtag('event', 'click_shop', {
      link_type: type,
      link_text: link.textContent.trim() || 'icon',
      page_path: location.pathname
    });
  });

  // Mobile menu
  var menuButton = document.querySelector('.menu');
  var menu = document.getElementById('site-menu');
  if (menuButton && menu) {
    var setMenu = function (open) {
      menuButton.setAttribute('aria-expanded', String(open));
      menu.classList.toggle('open', open);
    };
    menuButton.addEventListener('click', function () {
      setMenu(menuButton.getAttribute('aria-expanded') !== 'true');
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && menuButton.getAttribute('aria-expanded') === 'true') {
        setMenu(false);
        menuButton.focus();
      }
    });
  }

  // Exit-intent offer: desktop pointers only, top-edge exit only, not in the first 20s,
  // once per visitor, and never after a shop click.
  var offer = document.getElementById('offer');
  var desktop = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
  if (offer && desktop && typeof offer.showModal === 'function') {
    var seen = function () {
      try { return !!(sessionStorage.getItem(SEEN_KEY) || localStorage.getItem(SEEN_KEY)); } catch (e) { return true; }
    };
    var armedAt = Date.now() + 20000;
    document.addEventListener('mouseleave', function (e) {
      if (e.clientY >= 10 || Date.now() < armedAt || offer.open || seen()) return;
      try { localStorage.setItem(SEEN_KEY, '1'); } catch (err) {}
      offer.showModal();
    });
    offer.addEventListener('click', function (e) {
      if (e.target === offer || e.target.closest('[data-close]')) offer.close();
    });
  }

  // Mobile buy bar: appears once the hero has scrolled out of view
  var buybar = document.getElementById('buybar');
  var hero = document.querySelector('.hero, .opening');
  if (buybar && hero && 'IntersectionObserver' in window) {
    new IntersectionObserver(function (entries) {
      buybar.classList.toggle('shown', !entries[0].isIntersecting);
    }).observe(hero);
  }

  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches || document.documentElement.getAttribute('data-motion') === 'reduce';

  // Scroll reveals, arch "draw open" reveals and count-up numbers.
  // Only content that starts below the first screen is animated, so nothing visible on load is hidden.
  if (!reduceMotion && 'IntersectionObserver' in window) {
    var belowFold = function (el) { return el.getBoundingClientRect().top > window.innerHeight; };
    // Fully clipped arches don't register as visible, so each element is watched through an
    // unclipped stand-in (its parent for arches, itself otherwise).
    var waiting = new Map();
    var revealIn = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        (waiting.get(entry.target) || []).forEach(function (el) { el.classList.add('in'); });
        waiting.delete(entry.target);
        revealIn.unobserve(entry.target);
      });
    }, { rootMargin: '0px 0px -8% 0px' });
    var mark = function (selector, kind) {
      Array.prototype.forEach.call(document.querySelectorAll(selector), function (el) {
        if (el.hasAttribute('data-reveal') || !belowFold(el)) return;
        var siblings = Array.prototype.filter.call(el.parentNode.children, function (c) { return c.matches(selector); });
        el.setAttribute('data-reveal', kind);
        el.style.setProperty('--delay', Math.min(siblings.indexOf(el), 5) * 90 + 'ms');
        var watch = kind === 'arch' ? el.parentNode : el;
        if (!waiting.has(watch)) { waiting.set(watch, []); revealIn.observe(watch); }
        waiting.get(watch).push(el);
      });
    };
    // Scroll reveals are retired: the page has one orchestrated moment (the opening on the home page, in CSS),
    // and sections simply sit on the page like printed ones. mark() stays for any page that opts back in.
    void mark;

    // Re-stagger library grimoire items by column so each row fades in one at a time
    (function () {
      var items = document.querySelectorAll('.grimoire-grid > li[data-reveal]');
      if (!items.length) return;
      var colCount = window.matchMedia('(max-width: 640px)').matches ? 2
                   : window.matchMedia('(max-width: 1000px)').matches ? 3 : 5;
      Array.prototype.forEach.call(items, function (el, i) {
        el.style.setProperty('--delay', (i % colCount) * 90 + 'ms');
      });
    }());

    var counters = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        counters.unobserve(entry.target);
        var el = entry.target;
        var match = el.textContent.match(/^(\s*)([\d,]+)(.*)$/);
        if (!match) return;
        var target = parseInt(match[2].replace(/,/g, ''), 10);
        var comma = match[2].indexOf(',') !== -1;
        el.style.minWidth = el.getBoundingClientRect().width + 'px';
        var start = performance.now();
        var step = function (now) {
          var t = Math.min((now - start) / 1400, 1);
          var value = Math.round(target * (1 - Math.pow(1 - t, 3)));
          el.textContent = match[1] + (comma ? value.toLocaleString('en-US') : value) + match[3];
          if (t < 1) requestAnimationFrame(step);
        };
        requestAnimationFrame(step);
      });
    }, { threshold: 0.6 });
    Array.prototype.forEach.call(document.querySelectorAll('.stats strong, .intro .meta li'), function (el) {
      if (belowFold(el) && /^\s*\d/.test(el.textContent)) counters.observe(el);
    });
  }

  // Screenshot carousels: swipe/scroll-snap works without JS; this adds arrows and a counter
  Array.prototype.forEach.call(document.querySelectorAll('.carousel'), function (carousel) {
    var track = carousel.querySelector('.slides');
    var slides = track.querySelectorAll('.slide');
    var nav = carousel.querySelector('.carousel-nav');
    var count = carousel.querySelector('[data-count]');
    if (slides.length < 2) return;
    nav.hidden = false;
    var pitch = function () { return slides[1].offsetLeft - slides[0].offsetLeft; };
    var index = function () { return Math.round(track.scrollLeft / pitch()); };
    nav.addEventListener('click', function (e) {
      var button = e.target.closest('[data-step]');
      if (!button) return;
      var next = (index() + Number(button.getAttribute('data-step')) + slides.length) % slides.length;
      track.scrollTo({ left: next * pitch(), behavior: reduceMotion ? 'auto' : 'smooth' });
    });
    var ticking = false;
    track.addEventListener('scroll', function () {
      if (ticking) return;
      ticking = true;
      requestAnimationFrame(function () {
        count.textContent = index() + 1;
        ticking = false;
      });
    });
  });

  // Home: today's moon and Sun sign, worked out here as the Manuscript's Today page does (its new-moon epoch and phase
  // boundaries, and the sign dates on its sign pages), leading to the full Today page.
  var teaser = document.getElementById('today-teaser');
  if (teaser) {
    var now = new Date(), SYNODIC = 29.530588853;
    var age = ((Date.UTC(now.getFullYear(), now.getMonth(), now.getDate(), 12) / 1000 - 947182440) / 86400) % SYNODIC;
    if (age < 0) age += SYNODIC;
    var phases = [[1.84566, 'new-moon', 'New Moon'], [5.53699, 'waxing-crescent-moon', 'Waxing Crescent Moon'], [9.22831, 'first-quarter-moon', 'First Quarter Moon'],
      [12.91963, 'waxing-gibbous-moon', 'Waxing Gibbous Moon'], [16.61096, 'full-moon', 'Full Moon'], [20.30228, 'waning-gibbous-moon', 'Waning Gibbous Moon'],
      [23.99361, 'third-quarter-moon', 'Third Quarter Moon'], [27.68493, 'waning-crescent-moon', 'Waning Crescent Moon'], [99, 'new-moon', 'New Moon']];
    var moon = phases.filter(function (ph) { return age < ph[0]; })[0];
    // [last day of the sign, as month * 100 + day, its page]; Capricorn wraps the new year
    var signs = [[119, 'capricorn'], [218, 'aquarius'], [320, 'pisces'], [419, 'aries'], [520, 'taurus'], [620, 'gemini'], [722, 'cancer'],
      [822, 'leo'], [922, 'virgo'], [1022, 'libra'], [1121, 'scorpio'], [1221, 'sagittarius'], [1231, 'capricorn']];
    var md = (now.getMonth() + 1) * 100 + now.getDate(), sign = signs.filter(function (x) { return md <= x[0]; })[0][1];
    var signName = sign.charAt(0).toUpperCase() + sign.slice(1), pic = function (slug) { return 'manuscript/assets/signs/' + slug + '.png'; };
    var date = now.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' });
    teaser.innerHTML = '<p class="teaser-date">' + date + '</p>'
      + '<p class="teaser-sky"><span><img src="' + pic(moon[1]) + '" alt="" width="40" height="40">' + moon[2] + '</span>'
      + '<span><img src="' + pic(sign) + '" alt="" width="40" height="40">The Sun in ' + signName + '</span></p>'
      + '<a class="link" href="manuscript/today/">See Today in the Manuscript</a>';
    teaser.hidden = false;
  }

  // Blog search: filters the posts as you type (title, summary, topics and keywords)
  var search = document.getElementById('post-search');
  if (search) {
    var cards = Array.prototype.slice.call(document.querySelectorAll('#post-grid .post'));
    var empty = document.getElementById('post-empty');
    var apply = function () {
      var q = search.value.trim().toLowerCase();
      var visible = 0;
      cards.forEach(function (card) {
        var text = (card.textContent + ' ' + (card.dataset.keywords || '') + ' ' + (card.dataset.topics || '')).toLowerCase();
        card.hidden = !!q && text.indexOf(q) === -1;
        if (!card.hidden) visible++;
      });
      empty.hidden = visible > 0;
    };
    search.addEventListener('input', apply);
    // Results filter as you type, so Enter shouldn't submit the form and reload the page
    search.form.addEventListener('submit', function (e) { e.preventDefault(); });
  }
  // The Manuscript's shelf on the homepage: drawn at full width inside its frame, shrunk to fit the space.
  var peek = document.querySelector('.shelf-peek iframe');
  if (peek) {
    var box = peek.parentNode, W = 1200;
    var fit = function () {
      var doc = null; try { doc = peek.contentDocument; } catch (e) {}
      var shelf = doc && doc.querySelector('.shelves');
      var h = shelf ? Math.ceil(shelf.getBoundingClientRect().height) + 28 : 720, s = box.clientWidth / W;
      peek.style.height = h + 'px';
      peek.style.transform = 'scale(' + s + ')';
      box.style.aspectRatio = W + ' / ' + h;
      if (shelf) peek.classList.add('ready');
    };
    peek.addEventListener('load', function () { fit(); setTimeout(fit, 600); });
    if ('ResizeObserver' in window) new ResizeObserver(fit).observe(box); else window.addEventListener('resize', fit);
  }
})();
