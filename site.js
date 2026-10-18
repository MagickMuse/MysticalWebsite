(function () {
  var SEEN_KEY = 'tmm_popup_seen';

  // Gumroad clicks: analytics event, and never show the exit offer afterwards
  document.addEventListener('click', function (e) {
    var link = e.target.closest('a[href*="themagickmuse.gumroad.com"]');
    if (!link) return;
    try { localStorage.setItem(SEEN_KEY, '1'); } catch (err) {}
    if (typeof gtag !== 'function') return;
    var href = link.href;
    var type = 'store';
    if (href.indexOf('/l/manuscript') !== -1) type = 'manuscript';
    gtag('event', 'click_gumroad', {
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
  // once per visitor, and never after a Gumroad click.
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
  var hero = document.querySelector('.hero');
  if (buybar && hero && 'IntersectionObserver' in window) {
    new IntersectionObserver(function (entries) {
      buybar.classList.toggle('shown', !entries[0].isIntersecting);
    }).observe(hero);
  }

  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

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
    mark('.about .frame.arch, .course .frame.arch, .library .frame.arch, .feature-art', 'arch');
    mark('main .intro, .cards > *, .posts > .post, .library > li, .course, .chapter, .stats > li, .faq, .carousel, .about-text, .feature-text, .cta-inner, .link-list > li', 'rise');

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

  // Blog search and topic filter
  var search = document.getElementById('post-search');
  var filter = document.getElementById('post-topic');
  if (search && filter) {
    var cards = Array.prototype.slice.call(document.querySelectorAll('#post-grid .post'));
    var empty = document.getElementById('post-empty');
    var topics = [];
    // A card can sit under several topics (data-topics, primary first); its eyebrow shows the primary one
    var topicsOf = function (card) {
      return card.dataset.topics ? card.dataset.topics.split('|') : [card.querySelector('.eyebrow').textContent.trim()];
    };
    cards.forEach(function (card) {
      topicsOf(card).forEach(function (topic) {
        if (topics.indexOf(topic) === -1) topics.push(topic);
      });
    });
    topics.sort().forEach(function (topic) {
      var option = document.createElement('option');
      option.value = option.textContent = topic;
      filter.appendChild(option);
    });
    var apply = function () {
      var q = search.value.trim().toLowerCase();
      var visible = 0;
      cards.forEach(function (card) {
        var text = (card.textContent + ' ' + (card.dataset.keywords || '') + ' ' + (card.dataset.topics || '')).toLowerCase();
        var show = (!q || text.indexOf(q) !== -1) && (!filter.value || topicsOf(card).indexOf(filter.value) !== -1);
        card.hidden = !show;
        if (show) visible++;
      });
      empty.hidden = visible > 0;
    };
    search.addEventListener('input', apply);
    filter.addEventListener('change', apply);
  }
})();
