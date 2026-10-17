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

  // Screenshot carousels: swipe/scroll-snap works without JS; this adds arrows and a counter
  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
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
    cards.forEach(function (card) {
      var topic = card.querySelector('.eyebrow').textContent.trim();
      if (topics.indexOf(topic) === -1) topics.push(topic);
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
        var text = (card.textContent + ' ' + (card.dataset.keywords || '')).toLowerCase();
        var topic = card.querySelector('.eyebrow').textContent.trim();
        var show = (!q || text.indexOf(q) !== -1) && (!filter.value || topic === filter.value);
        card.hidden = !show;
        if (show) visible++;
      });
      empty.hidden = visible > 0;
    };
    search.addEventListener('input', apply);
    filter.addEventListener('change', apply);
  }
})();
