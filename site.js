(function () {
  // Gumroad click tracking for analytics
  document.addEventListener('click', function (e) {
    var link = e.target.closest('a[href*="themagickmuse.gumroad.com"]');
    if (!link || typeof gtag !== 'function') return;
    var href = link.href;
    var type = 'store';
    if (href.indexOf('/lifetimemanuscript') !== -1) type = 'lifetime';
    else if (href.indexOf('/monthlymanuscript') !== -1) type = 'monthly';
    gtag('event', 'click_gumroad', {
      link_type: type,
      link_text: link.textContent.trim() || 'icon',
      page_path: location.pathname
    });
  });

  // Exit-intent offer: once per visitor, on mouse leaving the top edge or after 90s
  var offer = document.getElementById('offer');
  if (offer && typeof offer.showModal === 'function') {
    var SEEN_KEY = 'tmm_popup_seen';
    var seen = false;
    try { seen = !!(sessionStorage.getItem(SEEN_KEY) || localStorage.getItem(SEEN_KEY)); } catch (e) {}
    if (!seen) {
      var shown = false;
      var show = function () {
        if (shown) return;
        shown = true;
        try { localStorage.setItem(SEEN_KEY, '1'); } catch (e) {}
        offer.showModal();
      };
      document.addEventListener('mouseleave', function (e) { if (e.clientY < 10) show(); });
      setTimeout(show, 90000);
    }
    offer.addEventListener('click', function (e) {
      if (e.target === offer || e.target.closest('[data-close]')) offer.close();
    });
  }

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
