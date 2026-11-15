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
})();
