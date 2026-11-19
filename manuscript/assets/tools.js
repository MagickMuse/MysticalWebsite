/*
 * The members' tools: Today, the Spell Builder, the Planet / Zodiac / Moon Phase Wheels, the "Enchanted Tools" list on
 * grimoire pages, the spell and wheel links on entries, and notes. This is the only implementation of them: it runs in
 * the browser on the live site and on the GitHub Pages preview alike, from tools/data.json (on the live site served to
 * members by lib/tools.php from the database; on the preview a copy built by .github/scripts/build-static-tools.py).
 * Notes and saved workings are kept in the member's account on the live site, and in this browser on the preview,
 * which has no server. wiki.js loads this file on every Manuscript page.
 */
(function () {
  'use strict';
  var me = document.currentScript;
  var ROOT = new URL('../', me ? me.src : location.href).toString(); // …/manuscript/
  // The preview has no server: its search form points at search/ (with a slash). See wiki.js.
  var searchForm = document.querySelector('.mm-search'), action = searchForm && searchForm.getAttribute('action');
  var PREVIEW = !!action && /search\/$/.test(action);
  var CSRF = document.body.getAttribute('data-csrf') || '';
  var MEMBER = PREVIEW || !!CSRF; // the live site gives members (only) a form token

  // ------------------------------------------------------------------ small helpers
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function url(path) { return ROOT + path; }
  function params() { return new URLSearchParams(location.search); }

  // Notes and workings: the member's account on the live site (lib/tools.php), this browser on the preview.
  var Store = {
    local: function (kind) { try { return JSON.parse(localStorage.getItem('mm-' + kind + '-v1')) || []; } catch (e) { return []; } },
    keepLocal: function (kind, list) { try { localStorage.setItem('mm-' + kind + '-v1', JSON.stringify(list)); } catch (e) {} },
    list: function (kind) {
      if (PREVIEW) return Promise.resolve(Store.local(kind));
      return fetch(url(kind + '?json=1'), { credentials: 'same-origin' }).then(function (r) { if (!r.ok) throw new Error(); return r.json(); });
    },
    post: function (kind, fields) {
      var body = new FormData(); body.append('csrf', CSRF);
      Object.keys(fields).forEach(function (k) { body.append(k, typeof fields[k] === 'object' ? JSON.stringify(fields[k]) : fields[k] == null ? '' : fields[k]); });
      return fetch(url(kind), { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
        .then(function (r) { if (!r.ok) throw new Error(); return r.json(); });
    },
    /** Saves one (new when it has no id); resolves to its id (0: an empty new note, not kept). */
    save: function (kind, item) {
      if (!PREVIEW) return Store.post(kind, item).then(function (d) { return d.id; });
      var list = Store.local(kind), now = new Date().toISOString();
      if (kind === 'notes' && !item.id && !(item.body || '').trim()) return Promise.resolve(0);
      if (!item.id) { item.id = Date.now(); item.created = kind === 'notes' ? now : now.slice(0, 10); list.unshift(item); }
      item.updated = now;
      Store.keepLocal(kind, list.map(function (x) { return x.id === item.id ? item : x; }).slice(0, 2000));
      return Promise.resolve(item.id);
    },
    remove: function (kind, id) {
      if (!PREVIEW) return Store.post(kind, { do: 'delete', id: id });
      Store.keepLocal(kind, Store.local(kind).filter(function (x) { return String(x.id) !== String(id); }));
      return Promise.resolve();
    }
  };
  var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
  var DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

  // ------------------------------------------------------------------ the data
  var dataP = null, D = null, bySlug = {};
  function data() {
    if (!dataP) dataP = fetch(url('tools/data.json')).then(function (r) { if (!r.ok) throw new Error(); return r.json(); }).then(function (d) {
      D = d;
      d.part = d.p.map(function (p) { return partOf(d.g[p[0]], p[4] ? p[4].split('|') : []); });
      d.p.forEach(function (p, i) { bySlug[d.g[p[0]] + '/' + p[1]] = i; });
      return d;
    });
    return dataP;
  }
  function pg(i) {
    var p = D.p[i];
    return { i: i, g: D.g[p[0]], slug: p[1], title: p[2], icon: p[3], part: D.part[i], kinds: p[4] ? p[4].split('|') : [], range: p[5], url: url(D.g[p[0]] + '/' + p[1] + '/') };
  }
  function find(g, slug) { var i = bySlug[g + '/' + slug]; return i == null ? null : pg(i); }
  function ofKind(g, kind) {
    var out = {};
    D.p.forEach(function (p, i) { if (D.g[p[0]] === g && p[4] && p[4].split('|').indexOf(kind) !== -1) out[p[1]] = pg(i); });
    return out;
  }
  function iconHtml(icon, cls) {
    if (!icon) return '';
    if (icon.indexOf('a:') === 0) return '<img class="' + cls + '" src="' + esc(url('assets/' + icon.slice(2))) + '" alt="" width="24" height="24" loading="lazy">';
    if (icon.indexOf('m:') === 0) return '<img class="' + cls + '" src="' + esc(url('media/' + icon.slice(2))) + '" alt="" width="24" height="24" loading="lazy">';
    return '<span class="' + cls + '" aria-hidden="true">' + esc(icon) + '</span>';
  }
  function chip(p) { return '<a class="rel" href="' + esc(p.url) + '">' + iconHtml(p.icon, 'rel-icon') + '<span>' + esc(p.title) + '</span></a>'; }
  function crumbs(g, more) {
    var name = { ritual: 'Ritual Grimoire', astrology: 'Astrology Grimoire', timing: 'Timing Grimoire', card: 'Card Grimoire' }[g] || '';
    var h = '<nav class="crumbs" aria-label="Breadcrumb"><ol><li><a href="' + esc(ROOT) + '">Manuscript</a></li>' + (name ? '<li><a href="' + esc(url(g + '/')) + '">' + name + '</a></li>' : '');
    (more || []).forEach(function (m) { h += '<li><a href="' + esc(m[1]) + '">' + esc(m[0]) + '</a></li>'; });
    return h + '</ol></nav>';
  }

  /** Which part of a working a page can be: timing, a colour, a herb… (none: it isn't one). */
  function partOf(g, kinds) {
    var has = function (k) { return kinds.indexOf(k) !== -1; };
    if (g === 'astrology') return has('Planets') || has('Moon Phases') ? 'when' : '';
    if (g === 'timing') return has('Days of the Week') || has('Holidays') || has('Seasons') || has('Time of Day') ? 'when' : '';
    if (g === 'symbol') return has('Color') ? 'colours' : '';
    if (g === 'card') return has('Tarot') ? 'tarot' : '';
    return { plant: 'plants', mineral: 'stones', oil: 'oils', entity: 'deities', rune: 'runes', recipe: 'recipes', ritual: 'rituals' }[g] || '';
  }
  /** Pages linked to any of ids: Map(index → how many of ids). */
  function linkedCounts(ids) {
    var out = new Map(), set = new Set(ids);
    ids.forEach(function (a) { D.l[a].forEach(function (b) { if (!set.has(b)) out.set(b, (out.get(b) || 0) + 1); }); });
    return out;
  }
  var PARTS = [
    ['when', 'When to work', 8, 3], ['colours', 'Colours', 6, 2], ['plants', 'Herbs & plants', 10, 3], ['stones', 'Crystals & stones', 8, 2],
    ['oils', 'Oils', 6, 2], ['deities', 'Deities & spirits', 8, 0], ['runes', 'Runes', 4, 0], ['tarot', 'Tarot', 4, 0], ['recipes', 'Recipes', 6, 0], ['rituals', 'Rituals to adapt', 8, 1]
  ];
  var PART = {}; PARTS.forEach(function (p) { PART[p[0]] = p; });
  /** Everything tied to ids, by part of a working, best first: [[part, [[page, hits]…]]…]. */
  function rankedParts(ids, only) {
    var counts = linkedCounts(ids), parts = {};
    counts.forEach(function (n, i) {
      var part = D.part[i];
      if (!part || (only && only.indexOf(part) === -1)) return;
      (parts[part] = parts[part] || []).push([i, n, D.l[i].length]);
    });
    var out = [];
    PARTS.forEach(function (pt) {
      var list = parts[pt[0]];
      if (!list) return;
      list.sort(function (a, b) { return b[1] - a[1] || b[2] - a[2] || D.p[a[0]][2].localeCompare(D.p[b[0]][2]); });
      out.push([pt[0], list.map(function (x) { return [pg(x[0]), x[1]]; })]);
    });
    return out;
  }
  function render(tool, html, title) {
    tool.innerHTML = html;
    if (title) document.title = title + ' | The Magick Manuscript';
  }

  // ------------------------------------------------------------------ the moon (as moon_age() and friends in lib/tools.php)
  var SYNODIC = 29.530588853;
  function moonAge(day) {
    var t = Date.UTC(day.getFullYear(), day.getMonth(), day.getDate(), 12) / 1000;
    var age = ((t - 947182440) / 86400) % SYNODIC;
    return age < 0 ? age + SYNODIC : age;
  }
  function moonPhase(age) {
    var ph = [[1.84566, 'new-moon', 'New Moon'], [5.53699, 'waxing-crescent-moon', 'Waxing Crescent Moon'], [9.22831, 'first-quarter-moon', 'First Quarter Moon'],
      [12.91963, 'waxing-gibbous-moon', 'Waxing Gibbous Moon'], [16.61096, 'full-moon', 'Full Moon'], [20.30228, 'waning-gibbous-moon', 'Waning Gibbous Moon'],
      [23.99361, 'third-quarter-moon', 'Third Quarter Moon'], [27.68493, 'waning-crescent-moon', 'Waning Crescent Moon']];
    for (var i = 0; i < ph.length; i++) if (age < ph[i][0]) return [ph[i][1], ph[i][2], age < 14.765];
    return ['new-moon', 'New Moon', true];
  }
  function moonSvg(age) {
    var size = 96, r = size / 2 - 2, c = size / 2, k = Math.cos(2 * Math.PI * age / SYNODIC), rx = Math.round(Math.abs(k) * r * 100) / 100;
    var waxing = age < 14.765, limb = waxing ? 1 : 0, term = waxing ? (k > 0 ? 0 : 1) : (k > 0 ? 1 : 0);
    var lit = (age < 0.5 || age > 29.03) ? '' : '<path class="moon-lit" d="M' + c + ' ' + (c - r) + ' A' + r + ' ' + r + ' 0 0 ' + limb + ' ' + c + ' ' + (c + r) + ' A' + rx + ' ' + r + ' 0 0 ' + term + ' ' + c + ' ' + (c - r) + 'Z"/>';
    return '<svg class="moon-pic" viewBox="0 0 96 96" width="96" height="96" aria-hidden="true"><circle class="moon-dark" cx="48" cy="48" r="' + r + '"/>' + lit + '</svg>';
  }
  function md(day) { return (day.getMonth() + 1) * 100 + day.getDate(); }
  function sunSign(day, signs) {
    var x = md(day), found = null;
    Object.keys(signs).forEach(function (k) {
      var m = /^([A-Za-z]+)\s+(\d+)\s*[-–]\s*([A-Za-z]+)\s+(\d+)/.exec(signs[k].range || '');
      if (!m) return;
      var from = (MONTHS.indexOf(m[1]) + 1) * 100 + +m[2], to = (MONTHS.indexOf(m[3]) + 1) * 100 + +m[4];
      if (from <= to ? (x >= from && x <= to) : (x >= from || x <= to)) found = signs[k];
    });
    return found;
  }
  function season(day, south) {
    var x = md(day), n = x >= 1221 || x < 320 ? 'winter' : x < 621 ? 'spring' : x < 922 ? 'summer' : 'autumn';
    return south ? { winter: 'summer', spring: 'autumn', summer: 'winter', autumn: 'spring' }[n] : n;
  }
  function daySlug(day) { return MONTHS[day.getMonth()].toLowerCase() + '-' + day.getDate(); }
  function iso(day) { return day.getFullYear() + '-' + String(day.getMonth() + 1).padStart(2, '0') + '-' + String(day.getDate()).padStart(2, '0'); }
  function short(day) { return MONTHS[day.getMonth()].slice(0, 3) + ' ' + day.getDate(); }
  function addDays(day, n) { var d = new Date(day); d.setDate(d.getDate() + n); return d; }

  // ------------------------------------------------------------------ Today
  /** What a day holds: its moon, weekday and ruling planet, the Sun's sign, the season, and the intentions they favour. */
  function dayReading(day, south) {
    var age = moonAge(day), ph = moonPhase(age), phases = ofKind('astrology', 'Moon Phases'), planets = ofKind('astrology', 'Planets'), signs = ofKind('astrology', 'Western Zodiac');
    var weekday = find('timing', DAYS[day.getDay()].toLowerCase()), dayPage = find('timing', daySlug(day)), seas = find('timing', season(day, south));
    var moon = phases[ph[0]] || null, sign = sunSign(day, signs), fullName = null, ruler = null;
    if (ph[0].indexOf('full') === 0) Object.keys(phases).forEach(function (k) {
      if (k.indexOf('full-') === 0 && k !== 'full-moon' && new RegExp(MONTHS[day.getMonth()] + ' \\(' + (south ? 'SH' : 'NH') + '\\)').test(phases[k].range)) fullName = phases[k];
    });
    if (weekday) Object.keys(planets).forEach(function (k) { if (!ruler && D.l[weekday.i].indexOf(planets[k].i) !== -1) ruler = planets[k]; });
    var infl = [];
    if (moon) infl.push(['The moon', moon, fullName ? 'Tonight’s full moon is the ' + fullName.title + '.' : (ph[2] ? 'Waxing: a time to draw things in and build.' : 'Waning: a time to release, banish and clear.'), fullName]);
    if (weekday) infl.push(['The day', weekday, ruler ? weekday.title + ' belongs to ' + ruler.title + '.' : '', ruler]);
    if (sign) infl.push(['The sun', sign, 'The Sun is in ' + sign.title + '.', null]);
    if (seas) infl.push(['The season', seas, south ? 'Southern hemisphere.' : 'Northern hemisphere.', null]);
    var src = [];
    infl.forEach(function (x) { src.push(x[1].i); if (x[3]) src.push(x[3].i); });
    if (dayPage) src.push(dayPage.i);
    var counts = linkedCounts(src), intents = [];
    counts.forEach(function (n, i) { if (n >= 2 && D.g[D.p[i][0]] === 'correspondence') intents.push([pg(i), n]); });
    intents.sort(function (a, b) { return b[1] - a[1] || a[0].title.localeCompare(b[0].title); });
    var lede = [moon ? 'a ' + ph[1] : '', ruler ? 'a ' + ruler.title + ' day' : '', sign ? 'the Sun in ' + sign.title : '', seas ? seas.title.toLowerCase() : ''].filter(Boolean).join(', ');
    return { age: age, ph: ph, moon: moon, weekday: weekday, ruler: ruler, sign: sign, seas: seas, dayPage: dayPage, infl: infl, src: src, intents: intents,
      lede: lede ? lede.charAt(0).toUpperCase() + lede.slice(1) + '.' : '' };
  }
  /** The day's four influences as cards: the moon, the day, the Sun's sign, the season (Today page and Manuscript home). */
  function influenceCards(R) {
    return '<div class="today-cards">' + R.infl.map(function (x) {
      var pic = x[0] === 'The moon' ? moonSvg(R.age) : '<span class="today-icon">' + iconHtml(x[1].icon, 'pg-icon big') + '</span>';
      var sub = { 'The moon': x[3] ? 'The ' + x[3].title : (R.ph[2] ? 'Waxing: draw in, build' : 'Waning: release, clear'),
        'The day': x[3] ? 'Ruled by ' + x[3].title : '', 'The sun': 'The Sun’s sign', 'The season': x[2].replace(/\.$/, '') }[x[0]];
      return '<a class="today-card" href="' + esc(x[1].url) + '"><span class="eyebrow">' + esc(x[0]) + '</span>' + pic
        + '<span class="today-name">' + esc(x[1].title) + '</span>' + (sub ? '<span class="today-sub">' + esc(sub) + '</span>' : '') + '</a>';
    }).join('') + '</div>';
  }
  function favourLink(x, plain) {
    return '<a class="fav" href="' + esc(url('spell-builder/?i=' + encodeURIComponent(x[0].slug))) + '">' + iconHtml(x[0].icon, 'rel-icon') + esc(x[0].title) + (plain ? '' : '<small>' + x[1] + ' of today’s influences</small>') + '</a>';
  }

  function today(tool) {
    var q = params(), m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(q.get('d') || ''), south = q.get('h') === 's';
    var day = m ? new Date(+m[1], +m[2] - 1, +m[3]) : new Date();
    day.setHours(0, 0, 0, 0);
    var R = dayReading(day, south), ph = R.ph, moon = R.moon, ruler = R.ruler, sign = R.sign, seas = R.seas, dayPage = R.dayPage, src = R.src;

    var cards = influenceCards(R);

    var favours = R.intents.slice(0, 12).map(function (x) { return favourLink(x, false); }).join('');
    var work = rankedParts(src, ['colours', 'plants', 'stones', 'oils', 'rituals']).map(function (pt) {
      var top = pt[1].filter(function (x) { return x[1] >= 2; }).slice(0, 6);
      if (!top.length) top = pt[1].slice(0, 3);
      return '<div class="work-part"><h3>' + esc(PART[pt[0]][1]) + '</h3><div class="rels">' + top.map(function (x) { return chip(x[0]); }).join('') + '</div></div>';
    }).join('');

    var onDay = '';
    if (dayPage) {
      var fest = (D.f[dayPage.slug] || []).map(pg), festIds = fest.map(function (f) { return f.i; });
      var tied = D.l[dayPage.i].filter(function (i) { return festIds.indexOf(i) === -1; }).map(pg)
        .sort(function (a, b) { return a.g.localeCompare(b.g) || a.title.localeCompare(b.title); }).slice(0, 16);
      onDay = (fest.length ? '<div class="rels">' + fest.map(chip).join('') + '</div>' : '')
        + (tied.length ? '<p class="small">Tied to this day in the Manuscript:</p><div class="rels">' + tied.map(chip).join('') + '</div>' : '')
        + '<p><a class="link" href="' + esc(dayPage.url) + '">' + esc(dayPage.title) + ' in the Timing Grimoire</a></p>';
    }
    var soon = [];
    for (var n = 1; n <= 21 && soon.length < 8; n++) {
      var d2 = addDays(day, n);
      (D.f[daySlug(d2)] || []).forEach(function (i) { if (soon.length < 8) soon.push('<li><time datetime="' + iso(d2) + '">' + short(d2) + '</time>' + chip(pg(i)) + '</li>'); });
    }
    var h = south ? '&h=s' : '', prev = addDays(day, -1), next = addDays(day, 1);
    tool.className = 'pg pg-tool pg-today';
    render(tool, '<header class="pg-head tool-head">' + crumbs('timing') + '<p class="eyebrow labelled">Today in the Manuscript</p>'
      + '<h1>' + DAYS[day.getDay()] + ', <em>' + MONTHS[day.getMonth()] + ' ' + day.getDate() + '</em></h1>'
      + (R.lede ? '<p class="lede">' + esc(R.lede) + '</p>' : '')
      + '<p class="small day-nav"><a class="link" href="?d=' + iso(prev) + h + '">‹ ' + short(prev) + '</a><span aria-hidden="true">·</span>'
      + '<a class="link" href="?d=' + iso(day) + (south ? '' : '&h=s') + '">' + (south ? 'Use the northern hemisphere' : 'Use the southern hemisphere') + '</a><span aria-hidden="true">·</span>'
      + '<a class="link" href="?d=' + iso(next) + h + '">' + short(next) + ' ›</a></p></header>'
      + cards
      + (favours ? '<section class="tool-section"><h2>Today <em>favours</em></h2><p class="small">Intentions tied to more than one of today’s influences. Choose one to build a spell for it.</p><div class="favs">' + favours + '</div></section>' : '')
      + (work ? '<section class="tool-section"><h2>Work <em>with today</em></h2><p class="small">Tied to the most of today’s moon, day, sign and season.</p><div class="work-parts">' + work + '</div></section>' : '')
      + '<section class="tool-section two-col">' + (onDay ? '<div><h2>On <em>this day</em></h2>' + onDay + '</div>' : '')
      + (soon.length ? '<div><h2>Coming <em>up</em></h2><ul class="soon">' + soon.join('') + '</ul><p class="small"><a class="link" href="' + esc(url('calendar/')) + '">The full calendar</a></p></div>' : '') + '</section>', 'Today');
  }

  /**
   * A page worth landing on: one with plenty to read (250 words or more) and plenty of connections (8 or more).
   * A grimoire is chosen first, so the small ones come up as often as the big ones, then a page inside it.
   */
  var RICH_WORDS = 250, RICH_LINKS = 8;
  function surprise() {
    var by = {};
    D.p.forEach(function (p, i) { if ((p[6] || 0) >= RICH_WORDS && D.l[i].length >= RICH_LINKS) (by[p[0]] = by[p[0]] || []).push(i); });
    var gs = Object.keys(by).filter(function (g) { return by[g].length >= 5; }), list = by[gs[Math.floor(Math.random() * gs.length)]];
    return pg(list[Math.floor(Math.random() * list.length)]);
  }
  /** The Manuscript home: today at a glance, under the shelves, leading to the full Today page. */
  function homeToday(after) {
    var day = new Date(); day.setHours(0, 0, 0, 0);
    var R = dayReading(day, false);

    var box = document.createElement('section');
    box.className = 'today-glance';
    box.setAttribute('aria-labelledby', 'glance-h');
    box.innerHTML = '<header class="glance-head"><p class="eyebrow labelled">Today in the Manuscript</p>'
      + '<h2 id="glance-h">' + DAYS[day.getDay()] + ', <em>' + MONTHS[day.getMonth()] + ' ' + day.getDate() + '</em></h2>'
      + (R.lede ? '<p class="lede">' + esc(R.lede) + '</p>' : '') + '</header>'
      + influenceCards(R)
      + (R.intents.length ? '<div class="glance-favs"><p class="eyebrow">Today favours</p><div class="favs">' + R.intents.slice(0, 6).map(function (x) { return favourLink(x, true); }).join('') + '</div></div>' : '')
      + '<p class="glance-more"><a class="btn ghost" href="' + esc(url('today/')) + '">Open Today</a></p>';
    after.after(box);
  }

  // ------------------------------------------------------------------ Spell Builder
  function spell(tool) {
    var q = params(), corr = [];
    D.p.forEach(function (p, i) { if (D.g[p[0]] === 'correspondence') corr.push(i); });
    var chosen = (q.get('i') || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean)
      .filter(function (s, k, a) { return a.indexOf(s) === k; }).slice(0, 3).map(function (s) { return find('correspondence', s); }).filter(Boolean);
    var slugs = chosen.map(function (p) { return p.slug; });
    // "Add" from the box: the title as typed, then one that starts with it, then one that contains it.
    if (q.has('add')) {
      var want = q.get('add').trim().toLowerCase(), hit = null;
      [function (t) { return t === want; }, function (t) { return t.indexOf(want) === 0; }, function (t) { return t.indexOf(want) !== -1; }].some(function (test) {
        corr.some(function (i) { if (test(D.p[i][2].toLowerCase())) { hit = D.p[i][1]; return true; } return false; });
        return !!hit;
      });
      if (hit && slugs.indexOf(hit) === -1) slugs.push(hit);
      location.replace('?' + (slugs.length ? 'i=' + encodeURIComponent(slugs.slice(0, 3).join(',')) : '') + (hit ? '' : (slugs.length ? '&' : '') + 'missing=' + encodeURIComponent(q.get('add'))));
      return;
    }
    var withSlugs = function (s) { return '?' + (s.length ? 'i=' + encodeURIComponent(s.join(',')) : ''); };
    var chips = chosen.map(function (p) {
      return '<span class="intent">' + iconHtml(p.icon, 'rel-icon') + '<a href="' + esc(p.url) + '">' + esc(p.title) + '</a>'
        + '<a class="intent-x" href="' + esc(withSlugs(slugs.filter(function (s) { return s !== p.slug; }))) + '" aria-label="Remove ' + esc(p.title) + '">×</a></span>';
    }).join('');
    var missing = (q.get('missing') || '').trim();
    var box = chosen.length < 3
      ? '<form class="mm-form spell-add" method="get" role="search">' + (slugs.length ? '<input type="hidden" name="i" value="' + esc(slugs.join(',')) + '">' : '')
        + '<label for="spell-q">' + (chosen.length ? 'Add another intention <small>(up to three)</small>' : 'What is the spell for?') + '</label>'
        + '<div class="spell-add-row"><input id="spell-q" type="search" name="add" list="intentions" autocomplete="off" placeholder="Protection, love, money, a new home…" required>'
        + '<button class="btn" type="submit">' + (chosen.length ? 'Add' : 'Begin') + '</button></div><datalist id="intentions">'
        + corr.map(function (i) { return D.p[i][2]; }).sort().map(function (t) { return '<option value="' + esc(t) + '">'; }).join('') + '</datalist></form>'
      : '<p class="small">Three intentions is the most a working can hold. Remove one to change it.</p>';
    if (missing) box = '<p class="notice">Nothing called “' + esc(missing) + '” in the Correspondence Grimoire. Try another word for it.</p>' + box;
    var starters = '';
    if (!chosen.length) {
      starters = ['protection', 'love', 'money', 'healing', 'banishing', 'luck', 'cleansing', 'divination', 'courage', 'peace', 'success', 'wisdom']
        .map(function (s) { return find('correspondence', s); }).filter(Boolean)
        .map(function (p) { return '<a class="fav" href="' + esc(withSlugs([p.slug])) + '">' + iconHtml(p.icon, 'rel-icon') + esc(p.title) + '</a>'; }).join('');
      if (starters) starters = '<section class="tool-section"><h2>Or start <em>with one of these</em></h2><div class="favs">' + starters + '</div></section>';
    }
    var result = '';
    if (chosen.length) {
      var n = chosen.length, rows = rankedParts(chosen.map(function (p) { return p.i; })).map(function (pt) {
        var meta = PART[pt[0]], items = pt[1].slice(0, meta[2]).map(function (x, k) {
          return '<label class="pick"><input type="checkbox" name="pick" value="' + x[0].i + '"' + (k < meta[3] ? ' checked' : '') + '><span class="pick-box" aria-hidden="true"></span>'
            + iconHtml(x[0].icon, 'rel-icon') + '<a href="' + esc(x[0].url) + '">' + esc(x[0].title) + '</a>'
            + (n > 1 ? '<small class="hits' + (x[1] === n ? ' all' : '') + '">' + (x[1] === n ? 'all ' + n : x[1] + ' of ' + n) + '</small>' : '') + '</label>';
        }).join('');
        var more = pt[1].length > meta[2] ? '<p class="small more-count">' + (pt[1].length - meta[2]) + ' more in the Manuscript</p>' : '';
        return '<fieldset class="spell-part part-' + pt[0] + '"><legend>' + esc(meta[1]) + '</legend><div class="picks">' + items + '</div>' + more + '</fieldset>';
      }).join('');
      var title = 'A working for ' + chosen.map(function (p) { return p.title; }).join(' & ');
      result = rows
        ? '<form class="spell-form" data-spell-save><p class="small">The best-connected matches come first' + (n > 1 ? ', and those tied to all ' + n + ' intentions before the rest' : '') + '. Tick what you’ll use, then save it as a working.</p>'
          + '<div class="spell-parts">' + rows + '</div><div class="spell-save"><h2>Keep <em>this working</em></h2>'
          + '<label for="w-title">Name</label><input id="w-title" name="title" maxlength="120" value="' + esc(title) + '">'
          + '<label for="w-notes">Your notes <small>(optional)</small></label><textarea id="w-notes" name="notes" rows="4" maxlength="5000" placeholder="What you’ll do, when, and anything you want to remember."></textarea>'
          + '<button class="btn" type="submit">Save the working</button><p class="small">Saved workings are listed at the end of this page.</p></div></form>'
        : '<p class="lede">Nothing in the Manuscript is tied to this yet. Try a broader intention.</p>';
    }
    tool.className = 'pg pg-tool pg-spell';
    render(tool, '<header class="pg-head tool-head">' + crumbs('ritual') + '<p class="eyebrow labelled">Spell Builder</p><h1>Build <em>a working</em></h1>'
      + '<p class="lede">Choose what the spell is for. The Manuscript gathers the days, colours, herbs, stones, oils, deities and rituals tied to it.</p></header>'
      + (chips ? '<div class="intents">' + chips + '</div>' : '') + box + starters + result, 'Spell Builder');
    var save = tool.querySelector('[data-spell-save]');
    if (save) save.addEventListener('submit', function (e) {
      e.preventDefault();
      var picks = [].map.call(save.querySelectorAll('input[name="pick"]:checked'), function (c) { var p = pg(+c.value); return [p.g, p.slug]; });
      Store.save('workings', { title: save.title.value.trim() || 'My working', intents: slugs, items: picks, notes: save.notes.value.trim() })
        .then(function () { return showWorkings(tool, true); })
        .catch(function () { alert('The working couldn’t be saved. Check your connection and try again.'); });
    });
    showWorkings(tool, false);
  }
  /** "Your workings" under the Spell Builder ($open: open the newest and scroll to it, just after saving). */
  function showWorkings(tool, open) {
    return Store.list('workings').then(function (list) {
      var old = tool.querySelector('.workings'); if (old) old.remove();
      if (!list.length) return;
      tool.insertAdjacentHTML('beforeend', workingsHtml(list));
      var w = tool.querySelector('.workings');
      if (open) { w.querySelector('details').open = true; w.scrollIntoView({ behavior: 'smooth' }); }
      else if (location.hash === '#workings') w.scrollIntoView();
    }).catch(function () {});
  }
  function workingsHtml(list) {
    return '<section class="tool-section workings" id="workings"><h2>Your <em>workings</em></h2>'
      + (PREVIEW ? '<p class="small">On this preview, workings are kept in this browser.</p>' : '') + list.map(function (w) {
      var groups = {};
      w.items.forEach(function (it) { var p = find(it[0], it[1]); if (p) (groups[p.part || 'other'] = groups[p.part || 'other'] || []).push(p); });
      var parts = PARTS.filter(function (pt) { return groups[pt[0]]; }).map(function (pt) {
        return '<div class="work-part"><h3>' + esc(pt[1]) + '</h3><div class="rels">' + groups[pt[0]].map(chip).join('') + '</div></div>';
      }).join('');
      return '<details class="toggle working"><summary>' + esc(w.title) + ' <small>' + esc(w.created) + '</small></summary>'
        + '<div class="work-parts">' + (parts || '<p class="small">Nothing was ticked.</p>') + '</div>'
        + (w.notes ? '<p class="note-card-snip working-notes">' + esc(w.notes) + '</p>' : '')
        + '<p class="small"><a class="link" href="?i=' + encodeURIComponent(w.intents.join(',')) + '">Open in the spell builder</a> · '
        + '<button class="link as-button" type="button" data-del-working="' + w.id + '">Delete</button></p></details>';
    }).join('') + '</section>';
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-del-working]');
    if (!b || !confirm('Delete this working? This can’t be undone.')) return;
    Store.remove('workings', b.getAttribute('data-del-working')).then(function () { showWorkings(document.querySelector('[data-static-tool]'), false); });
  });

  // ------------------------------------------------------------------ Draw a Card
  // Seven face-down cards, shuffled from the tarot deck, fanned out. The one picked turns over: upright, or (one time
  // in three) reversed, with that side's keywords and a way into the card's page.
  var REVERSED_CHANCE = 1 / 3;
  function shuffled(list) {
    var a = list.slice();
    for (var i = a.length - 1; i > 0; i--) { var j = Math.floor(Math.random() * (i + 1)), t = a[i]; a[i] = a[j]; a[j] = t; }
    return a;
  }
  function tarot(tool) {
    // The deck: the tarot cards, without the "Suit of Cups"-style overview pages (they have meanings too, but aren't cards).
    var deck = D.t.filter(function (c) { return !/^suit-of-/.test(D.p[c[0]][1]); }), hand = shuffled(deck).slice(0, 7);
    tool.className = 'pg pg-tool pg-tarot';
    render(tool, '<header class="pg-head tool-head">' + crumbs('card') + '<p class="eyebrow labelled">Draw a Card</p><h1>Draw <em>a card</em></h1>'
      + '<p class="lede">Seven cards from the deck of ' + deck.length + ', face down. Hold your question in mind, and choose the one that calls to you.</p></header>'
      + '<div class="spread" role="group" aria-label="Seven face-down cards">' + hand.map(function (c, k) {
        return '<button class="tarot-back fan-' + k + '" type="button" data-pick="' + k + '" aria-label="Card ' + (k + 1) + ' of 7"><span class="back-art" aria-hidden="true"></span></button>';
      }).join('') + '</div><div class="drawn" aria-live="polite"></div>', 'Draw a Card');
    var spread = tool.querySelector('.spread'), drawn = tool.querySelector('.drawn');
    spread.addEventListener('click', function (e) {
      var b = e.target.closest('[data-pick]');
      if (!b || spread.classList.contains('picked')) return;
      var c = hand[+b.getAttribute('data-pick')], card = pg(c[0]), rev = Math.random() < REVERSED_CHANCE;
      var words = (rev ? c[3] : c[2]).map(pg), quiet = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      spread.classList.add('picked'); b.classList.add('chosen');
      setTimeout(function () {
        spread.hidden = true;
        drawn.innerHTML = '<div class="drawn-card' + (rev ? ' reversed' : '') + '"><div class="flip"><span class="face tarot-back"><span class="back-art"></span></span>'
          + '<span class="face front">' + (c[1] ? '<img src="' + esc(url((c[1].indexOf('a:') === 0 ? 'assets/' : 'media/') + c[1].slice(2))) + '" alt="' + esc(card.title + (rev ? ', reversed' : '')) + '">' : '<span class="front-name">' + esc(card.title) + '</span>') + '</span></div></div>'
          + '<div class="reading"><p class="eyebrow">' + (rev ? 'Reversed' : 'Upright') + '</p><h2>' + esc(card.title) + '</h2>'
          + (words.length ? '<p class="small">' + (rev ? 'Reversed, it speaks of' : 'It speaks of') + '</p><div class="rels keywords">' + words.map(chip).join('') + '</div>' : '')
          + '<div class="actions"><a class="btn" href="' + esc(card.url) + '">View more</a><button class="btn ghost" type="button" data-again>Draw again</button></div></div>';
        var flip = drawn.querySelector('.drawn-card');
        if (quiet) flip.classList.add('turned'); else requestAnimationFrame(function () { requestAnimationFrame(function () { flip.classList.add('turned'); }); });
        drawn.querySelector('[data-again]').addEventListener('click', function () { tarot(tool); window.scrollTo({ top: 0, behavior: quiet ? 'auto' : 'smooth' }); });
      }, quiet ? 0 : 450);
    });
  }

  // ------------------------------------------------------------------ the wheels
  var WHEELS = {
    planets: ['Planet Wheel', 'Planets', 'planet', ['sun', 'moon', 'mercury', 'venus', 'mars', 'jupiter', 'saturn', 'uranus', 'neptune', 'pluto'],
      { sun: '☉', moon: '☽', mercury: '☿', venus: '♀', mars: '♂', jupiter: '♃', saturn: '♄', uranus: '♅', neptune: '♆', pluto: '♇' }, 'Planet'],
    signs: ['Zodiac Wheel', 'Western Zodiac', 'zodiac sign', ['aries', 'taurus', 'gemini', 'cancer', 'leo', 'virgo', 'libra', 'scorpio', 'sagittarius', 'capricorn', 'aquarius', 'pisces'],
      { aries: '♈', taurus: '♉', gemini: '♊', cancer: '♋', leo: '♌', virgo: '♍', libra: '♎', scorpio: '♏', sagittarius: '♐', capricorn: '♑', aquarius: '♒', pisces: '♓' }, 'Zodiac sign'],
    moon: ['Moon Phase Wheel', 'Moon Phases', 'moon phase', ['new-moon', 'waxing-crescent-moon', 'first-quarter-moon', 'waxing-gibbous-moon', 'full-moon', 'waning-gibbous-moon', 'third-quarter-moon', 'waning-crescent-moon'],
      { 'new-moon': '●', 'waxing-crescent-moon': '☽', 'first-quarter-moon': '◑', 'waxing-gibbous-moon': '◖', 'full-moon': '○', 'waning-gibbous-moon': '◗', 'third-quarter-moon': '◐', 'waning-crescent-moon': '☾' }, 'Moon phase']
  };
  var GROUPS = [['correspondence', 'Intentions'], ['plant', 'Herbs & plants'], ['mineral', 'Crystals & stones'], ['oil', 'Oils'], ['entity', 'Deities & spirits'],
    ['timing', 'Timing'], ['astrology', 'Astrology'], ['animal', 'Animals'], ['symbol', 'Symbols'], ['rune', 'Runes'], ['card', 'Cards'], ['material', 'Materials'],
    ['recipe', 'Recipes'], ['ritual', 'Rituals'], ['dream', 'Dreams']];
  function wheel(tool) {
    var q = params(), of = WHEELS[q.get('of')] ? q.get('of') : 'planets', w = WHEELS[of], all = ofKind('astrology', w[1]);
    var points = w[3].map(function (s) { return all[s]; }).filter(Boolean), sel = null;
    points.forEach(function (p) { if (p.slug === q.get('on')) sel = p; });
    sel = sel || points[0] || null;
    var tabs = Object.keys(WHEELS).map(function (k) { return '<a class="fchip' + (k === of ? ' on' : '') + '" href="?of=' + k + '"' + (k === of ? ' aria-current="true"' : '') + '>' + WHEELS[k][0] + '</a>'; }).join('');
    tool.className = 'pg pg-tool pg-wheel';
    render(tool, '<header class="pg-head tool-head">' + crumbs('astrology') + '<p class="eyebrow labelled">Correspondence wheel</p><h1>The ' + w[0].replace(' Wheel', '') + ' <em>Wheel</em></h1>'
      + '<p class="lede">Choose a ' + w[2] + ' to light up everything tied to it: the intentions it rules, its herbs, stones, oils, deities and days.</p>'
      + '<nav class="fchips wheel-tabs" aria-label="Wheels">' + tabs + '</nav></header><div data-static-wheel>' + wheelBody(of, points, sel) + '</div>', w[0]);
  }
  // Choosing a point on the wheel: swap in its connections, no reload.
  document.addEventListener('click', function (e) {
    var seg = e.target.closest('[data-static-wheel] a.seg');
    if (!seg || e.metaKey || e.ctrlKey) return;
    e.preventDefault();
    history.pushState(null, '', seg.getAttribute('href'));
    wheel(document.querySelector('[data-static-tool]'));
  });
  function wheelBody(of, points, sel) {
    var w = WHEELS[of], n = points.length, W = 520, c = W / 2, R = 248, r = 112, sizes = points.map(function (p) { return D.l[p.i].length; });
    var max = Math.max.apply(null, sizes.concat([1]));
    var pt = function (a, rad) { return [Math.round((c + rad * Math.cos(a)) * 100) / 100, Math.round((c + rad * Math.sin(a)) * 100) / 100]; };
    var segs = points.map(function (p, i) {
      var a0 = -Math.PI / 2 + (i - 0.5) * 2 * Math.PI / n, a1 = a0 + 2 * Math.PI / n, mid = (a0 + a1) / 2;
      var A = pt(a0, r), B = pt(a0, R), C = pt(a1, R), E = pt(a1, r), g = pt(mid, (R + r) / 2 + 8), dot = pt(mid, r + 16), on = sel && p.slug === sel.slug;
      return '<a class="seg' + (on ? ' on' : '') + '" href="?of=' + of + '&on=' + p.slug + '" data-on="' + p.slug + '"' + (on ? ' aria-current="true"' : '') + ' aria-label="' + esc(p.title) + '">'
        + '<path class="seg-bg" d="M' + A.join(' ') + ' L' + B.join(' ') + ' A' + R + ' ' + R + ' 0 0 1 ' + C.join(' ') + ' L' + E.join(' ') + ' A' + r + ' ' + r + ' 0 0 0 ' + A.join(' ') + 'Z"/>'
        + '<text class="seg-glyph" x="' + g[0] + '" y="' + (g[1] - 6) + '" text-anchor="middle" dominant-baseline="central">' + (w[4][p.slug] || '✦') + '︎</text>'
        + '<text class="seg-name" x="' + g[0] + '" y="' + (g[1] + 20) + '" text-anchor="middle" dominant-baseline="central">' + esc(p.title.replace(/ Moon$/, '')) + '</text>'
        + '<circle class="seg-dot" cx="' + dot[0] + '" cy="' + dot[1] + '" r="' + Math.round((2 + 5 * Math.sqrt(sizes[i] / max)) * 10) / 10 + '"/></a>';
    }).join('');
    var svg = '<svg class="wheel" viewBox="0 0 520 520" role="group" aria-label="Correspondence wheel"><circle class="wheel-rim" cx="260" cy="260" r="254"/>' + segs
      + '<circle class="wheel-hub" cx="260" cy="260" r="102"/><text class="wheel-hub-star" x="260" y="260" text-anchor="middle" dominant-baseline="central">✦</text></svg>';
    var detail = '<p class="lede">These pages haven’t been moved over from Notion yet.</p>';
    if (sel) {
      var by = {}, total = D.l[sel.i].length;
      D.l[sel.i].forEach(function (i) {
        var p = pg(i);
        if (p.kinds.indexOf('Days of the Year') !== -1) return; // single calendar days are on the Calendar
        (by[p.g] = by[p.g] || []).push(p);
      });
      detail = '<header class="wheel-head"><p class="eyebrow">' + (w[4][sel.slug] ? w[4][sel.slug] + '\uFE0E' : '') + ' ' + w[5] + '</p><h2><a href="' + esc(sel.url) + '">' + esc(sel.title) + '</a></h2>'
        + '<p class="small">Tied to ' + total.toLocaleString() + ' pages across the grimoires.</p></header>'
        + GROUPS.filter(function (g) { return by[g[0]]; }).map(function (g) {
          var list = by[g[0]].sort(function (a, b) { return a.title.localeCompare(b.title, undefined, { numeric: true }); });
          var rest = list.slice(24);
          return '<section class="wheel-group"><h3>' + g[1] + ' <small>' + list.length + '</small></h3><div class="rels">' + list.slice(0, 24).map(chip).join('')
            + (rest.length ? '<details class="rels-more"><summary>Show all ' + list.length + '</summary><div class="rels">' + rest.map(chip).join('') + '</div></details>' : '') + '</div></section>';
        }).join('');
    }
    return '<div class="wheel-layout"><div class="wheel-wrap">' + svg + '</div><div class="wheel-detail" aria-live="polite">' + detail + '</div></div>';
  }

  // ------------------------------------------------------------------ notes
  // "✎ Notes" at the top opens the note pop-up: the note already written on this page if there is one, else a new one
  // named after the page (it can be renamed). It saves a moment after typing stops, and on closing. "New note" starts
  // another. The notes page (/notes) lists every note with its first lines; each opens in the pop-up.
  function pageTitle() {
    var h1 = document.querySelector('.mm-main h1'), t = document.title.split(' | ')[0];
    if (h1) { var c = h1.cloneNode(true); c.querySelectorAll('.pg-icon').forEach(function (x) { x.remove(); }); t = c.textContent.trim() || t; }
    return t.replace(/\s+/g, ' ') || 'A note';
  }
  function noteDialog() {
    var path = location.pathname, def = pageTitle(), onNotesPage = !!document.querySelector('[data-static-tool="notes"]');
    var d = document.createElement('dialog');
    d.className = 'note-pop'; d.id = 'note-pop'; d.setAttribute('aria-labelledby', 'note-pop-h');
    d.innerHTML = '<form class="note-form"><div class="note-top"><p class="eyebrow" id="note-pop-h"><span aria-hidden="true">✎</span> Your note</p>'
      + '<button class="note-x" type="button" data-note-close aria-label="Close">&times;</button></div>'
      + '<label class="sr-only" for="note-title">Note title</label><input class="note-title" id="note-title" maxlength="160" placeholder="Name this note">'
      + '<label class="sr-only" for="note-body">Note</label><textarea class="note-body" id="note-body" rows="9" maxlength="20000" placeholder="Write in the margin… only you can see this."></textarea>'
      + '<div class="note-foot"><button class="btn" type="submit">Save</button><span class="notes-status" role="status"></span>'
      + '<span class="note-links"><button class="link as-button" type="button" data-note-new>New note</button><a class="link" href="' + esc(url('notes/')) + '">All notes</a></span></div>'
      + (PREVIEW ? '<p class="small">On this preview, notes are kept in this browser only.</p>' : '') + '</form>';
    document.body.appendChild(d);
    var title = d.querySelector('.note-title'), body = d.querySelector('.note-body'), status = d.querySelector('.notes-status');
    var cur = null, last = '', timer, busy = Promise.resolve(), changed = false;
    function snap() { return title.value + '\n' + body.value; }
    function load(n) { cur = n || null; title.value = n ? n.title : def; body.value = n ? n.body : ''; last = snap(); status.textContent = ''; }
    function save() {
      clearTimeout(timer);
      busy = busy.then(function () {
        if (snap() === last || (!cur && !body.value.trim())) return;
        var sent = snap(), item = cur || { page: path, pageTitle: def };
        item.title = title.value.trim() || 'Untitled note'; item.body = body.value;
        status.textContent = 'Saving…';
        return Store.save('notes', item).then(function (id) {
          if (id) { item.id = id; cur = item; }
          last = sent; changed = true; status.textContent = 'Saved';
        }).catch(function () { status.textContent = 'Not saved. Check your connection.'; });
      });
      return busy;
    }
    function later() { status.textContent = ''; clearTimeout(timer); timer = setTimeout(save, 900); }
    function close() { save().then(function () { d.close(); if (changed && onNotesPage) notesPage(document.querySelector('[data-static-tool="notes"]')); changed = false; }); }
    title.addEventListener('input', later); body.addEventListener('input', later);
    d.querySelector('form').addEventListener('submit', function (e) { e.preventDefault(); save(); });
    d.querySelector('[data-note-close]').addEventListener('click', close);
    d.addEventListener('cancel', function (e) { e.preventDefault(); close(); });
    d.addEventListener('click', function (e) { if (e.target === d) close(); });
    d.querySelector('[data-note-new]').addEventListener('click', function () { save().then(function () { load(null); title.select(); title.focus(); }); });
    window.addEventListener('pagehide', function () {
      if (snap() === last || (!cur && !body.value.trim())) return;
      if (PREVIEW) { save(); return; }
      var f = new FormData(); f.append('csrf', CSRF); f.append('id', cur ? cur.id : ''); f.append('title', title.value); f.append('body', body.value);
      f.append('page', path); f.append('pageTitle', def);
      if (navigator.sendBeacon) navigator.sendBeacon(url('notes'), f);
    });
    function show() { if (!d.open) d.showModal(); body.focus(); }
    return {
      // The note written on this page, if any; else a new one.
      openHere: function () {
        if (d.open) return;
        Store.list('notes').then(function (list) { load(onNotesPage ? null : list.filter(function (n) { return n.page === path; })[0]); show(); })
          .catch(function () { load(null); show(); });
      },
      openNote: function (n) { load(n); show(); }
    };
  }
  var notesCache = [];
  function notesPage(tool) {
    return Store.list('notes').then(function (list) {
      notesCache = list.sort(function (a, b) { return String(b.updated || '').localeCompare(String(a.updated || '')); });
      tool.className = 'saved notes-page';
      render(tool, '<p class="eyebrow labelled">Your Manuscript</p><h1>Your <em>notes</em></h1>'
        + '<p class="small">Only you can see these. Each one opens to read, rename or change it.' + (PREVIEW ? ' On this preview they’re kept in this browser.' : '') + '</p>'
        + (list.length ? '<ul class="note-cards">' + list.map(function (n) {
          var snip = (n.body || '').replace(/\s+/g, ' ').trim(), when = new Date(String(n.updated || n.created).replace(' ', 'T'));
          return '<li class="note-card"><a class="note-open" href="#note-' + n.id + '" data-note-id="' + n.id + '"><span class="note-card-title">' + esc(n.title) + '</span>'
            + '<span class="note-card-snip">' + esc(snip.length > 190 ? snip.slice(0, 189) + '…' : snip) + '</span></a>'
            + '<p class="note-card-meta">' + esc(isNaN(when) ? '' : when.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }))
            + (n.page ? ' · from <a href="' + esc(n.page) + '">' + esc(n.pageTitle || 'this page') + '</a>' : '')
            + ' · <button class="link as-button" type="button" data-del-note="' + n.id + '">Delete</button></p></li>';
        }).join('') + '</ul>' : '<p class="lede">No notes yet. Tap <strong>✎ Notes</strong> at the top of any page to write one.</p>'), 'Your notes');
    }).catch(function () { tool.innerHTML = '<p class="lede">Your notes couldn’t load. Refresh the page to try again.</p>'; });
  }

  // ------------------------------------------------------------------ on every Manuscript page
  var GRIMOIRE_TOOLS = {
    ritual: [['spell-builder/', 'Spell Builder']],
    astrology: [['wheel/?of=planets', 'Planet Wheel'], ['wheel/?of=signs', 'Zodiac Wheel'], ['wheel/?of=moon', 'Moon Phase Wheel']],
    timing: [['today/', 'Today'], ['calendar/', 'Calendar']],
    card: [['draw-a-card/', 'Draw a Card']]
  };
  function grimoireTools() {
    var art = document.querySelector('article.pg-grimoire'), m = /\/manuscript\/([a-z-]+)\/?$/.exec(location.pathname);
    if (!art || !m || !GRIMOIRE_TOOLS[m[1]] || art.querySelector('.tools-toggle')) return;
    var box = document.createElement('details');
    box.className = 'toggle tools-toggle';
    box.innerHTML = '<summary>Enchanted Tools</summary><div class="link-flow">' + GRIMOIRE_TOOLS[m[1]].map(function (t) {
      return '<p class="page-link tool-page"><a href="' + esc(url(t[0])) + '">' + t[1] + '</a></p>';
    }).join('') + '</div>';
    var guide = [].filter.call(art.querySelectorAll('.mm-callout details.toggle'), function (d) { return /Guide Pages/.test(d.querySelector('summary').textContent); })[0];
    if (guide) guide.after(box);
    else { var c = art.querySelector('.mm-callout > div'); if (c) c.appendChild(box); }
  }
  function toolLinks() {
    var m = /\/manuscript\/(correspondence|astrology)\/([^/]+)\/?$/.exec(location.pathname), head = document.querySelector('.pg-head .pg-title');
    if (!m || !head || document.querySelector('.tool-links')) return;
    var h1 = document.querySelector('.pg-head h1'), links = [], title = '';
    if (h1) { var c = h1.cloneNode(true); c.querySelectorAll('.pg-icon').forEach(function (x) { x.remove(); }); title = c.textContent.trim(); }
    if (m[1] === 'correspondence') links.push('<a class="link" href="' + esc(url('spell-builder/?i=' + encodeURIComponent(m[2]))) + '">✦ Build a spell for ' + esc(title) + '</a>');
    Object.keys(WHEELS).forEach(function (k) { if (m[1] === 'astrology' && WHEELS[k][3].indexOf(m[2]) !== -1) links.push('<a class="link" href="' + esc(url('wheel/?of=' + k + '&on=' + m[2])) + '">✺ See ' + esc(title) + ' on the wheel</a>'); });
    if (links.length) head.insertAdjacentHTML('afterend', '<p class="tool-links small">' + links.join('') + '</p>');
  }

  if (!MEMBER) return; // visitors: nothing here is theirs to use
  grimoireTools();
  toolLinks();
  var pop = null;
  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-note-open]')) { e.preventDefault(); (pop = pop || noteDialog()).openHere(); return; }
    var card = e.target.closest('a[data-note-id]');
    if (card) {
      e.preventDefault();
      var n = notesCache.filter(function (x) { return String(x.id) === card.getAttribute('data-note-id'); })[0];
      if (n) (pop = pop || noteDialog()).openNote(n);
      return;
    }
    var del = e.target.closest('[data-del-note]');
    if (del && confirm('Delete this note? This can’t be undone.')) {
      Store.remove('notes', del.getAttribute('data-del-note')).then(function () { notesPage(document.querySelector('[data-static-tool="notes"]')); });
    }
  });
  // The Manuscript home: "Surprise me" beside "Begin here" opens a random well-filled page from a random grimoire.
  var begin = document.querySelector('.mm-hero .actions a.btn[href*="begin-here"]');
  if (begin && !document.querySelector('[data-surprise]')) {
    begin.insertAdjacentHTML('afterend', '<button class="btn ghost" type="button" data-surprise>Surprise me</button>');
    document.querySelector('[data-surprise]').addEventListener('click', function () {
      var btn = this; btn.disabled = true;
      data().then(function () { location.href = surprise().url; }).catch(function () { btn.disabled = false; });
    });
  }
  // The Manuscript home: today at a glance under the shelves (the data loads once the shelves are showing).
  var shelves = document.querySelector('.book-pile') || document.querySelector('.shelves');
  if (shelves && !document.querySelector('[data-static-tool]')) {
    var later = window.requestIdleCallback || function (f) { setTimeout(f, 300); };
    later(function () { data().then(function () { homeToday(shelves); }).catch(function (e) { ; }); });
  }
  var tool = document.querySelector('[data-static-tool]');
  if (!tool) return;
  var kind = tool.getAttribute('data-static-tool');
  if (kind === 'notes') { notesPage(tool); return; }
  data().then(function () {
    if (kind === 'today') today(tool);
    else if (kind === 'spell') spell(tool);
    else if (kind === 'tarot') tarot(tool);
    else if (kind === 'wheel') { wheel(tool); window.addEventListener('popstate', function () { wheel(tool); }); }
  }).catch(function () { tool.innerHTML = '<p class="lede">The tools couldn’t load. Refresh the page to try again.</p>'; });
})();
