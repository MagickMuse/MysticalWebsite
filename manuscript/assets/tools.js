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
      d.part = d.p.map(function (p) { return partOf(d.g[p[0]], p[4] ? p[4].split('|') : [], p[1]); });
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
    var name = { ritual: 'Ritual Grimoire', astrology: 'Astrology Grimoire', timing: 'Timing Grimoire', card: 'Card Grimoire', rune: 'Rune Grimoire', dream: 'Dream Grimoire' }[g] || '';
    var h = '<nav class="crumbs" aria-label="Breadcrumb"><ol><li><a href="' + esc(ROOT) + '">Manuscript</a></li>' + (name ? '<li><a href="' + esc(url(g + '/')) + '">' + name + '</a></li>' : '');
    (more || []).forEach(function (m) { h += '<li><a href="' + esc(m[1]) + '">' + esc(m[0]) + '</a></li>'; });
    return h + '</ol></nav>';
  }

  /** Which part of a working a page can be: timing, a colour, a herb… (none: it isn't one). */
  /** One of the 78 tarot cards (the "Suit of Cups"-style overview pages have meanings too, but aren't cards). */
  function isTarotCard(slug, kinds) { return kinds.indexOf('Tarot') !== -1 && !/^suit-of-/.test(slug); }
  function partOf(g, kinds, slug) {
    var has = function (k) { return kinds.indexOf(k) !== -1; };
    if (has('Guide')) return ''; // guide pages are reading, not ingredients
    if (g === 'astrology') return has('Planets') || has('Moon Phases') ? 'when' : '';
    if (g === 'timing') return has('Days of the Week') || has('Holidays') || has('Seasons') || has('Time of Day') ? 'when' : '';
    if (g === 'symbol') return has('Color') ? 'colours' : '';
    if (g === 'card') return isTarotCard(slug, kinds) ? 'tarot' : '';
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
      + (R.lede ? '<p class="lede">' + esc(R.lede) + '</p>' : '') + sabbatHtml(day, south)
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
      + (R.lede ? '<p class="lede">' + esc(R.lede) + '</p>' : '') + sabbatHtml(day, false) + '</header>'
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

  // ------------------------------------------------------------------ share a pull
  // A story-sized picture (1080 × 1920) of what was pulled, in the Muse's colours, with the logo in the corner; no link.
  // Phones: the share menu (TikTok, Instagram, Messages…) opens with the picture. Elsewhere: the picture downloads.
  // The picture is made as soon as the card or stone turns over, so the Share tap opens the menu straight away.
  var SITE_ROOT = new URL('../', ROOT).toString();
  function loadImage(src) {
    return new Promise(function (ok, fail) { var im = new Image(); im.onload = function () { ok(im); }; im.onerror = fail; im.src = src; });
  }
  function spaced(ctx, text, x, y, gap) { // letter-spaced capitals, centred on x
    var w = 0, chars = text.split('');
    chars.forEach(function (c) { w += ctx.measureText(c).width + gap; }); w -= gap;
    var cx = x - w / 2;
    chars.forEach(function (c) { ctx.fillText(c, cx + ctx.measureText(c).width / 2, y); cx += ctx.measureText(c).width + gap; });
  }
  function wrapLines(ctx, words, maxW) {
    var lines = [], line = '';
    words.forEach(function (w) { var t = line ? line + '  ✦  ' + w : w; if (ctx.measureText(t).width > maxW && line) { lines.push(line); line = w; } else line = t; });
    if (line) lines.push(line);
    return lines;
  }
  /** The picture: o = {eyebrow, title, state ('Upright', 'Reversed' or ''), words: [...], card: Image | null, reversed, glyph, tone}. */
  function shareCanvas(o) {
    var W = 1080, H = 1920, c = document.createElement('canvas'); c.width = W; c.height = H;
    var x = c.getContext('2d');
    x.fillStyle = '#f6efe8'; x.fillRect(0, 0, W, H);
    var glow = x.createRadialGradient(W / 2, 820, 60, W / 2, 820, 760); glow.addColorStop(0, 'rgba(201,154,146,.32)'); glow.addColorStop(1, 'rgba(201,154,146,0)');
    x.fillStyle = glow; x.fillRect(0, 0, W, H);
    x.strokeStyle = '#c9a356'; x.lineWidth = 3; x.strokeRect(44, 44, W - 88, H - 88);
    x.globalAlpha = .45; x.lineWidth = 1.5; x.strokeRect(60, 60, W - 120, H - 120); x.globalAlpha = 1;
    x.textAlign = 'center'; x.textBaseline = 'alphabetic';
    x.fillStyle = '#7a1f2b'; x.font = '500 34px Jost, sans-serif'; spaced(x, o.eyebrow.toUpperCase(), W / 2, 200, 9);
    x.fillStyle = '#c9a356'; x.font = '40px serif'; x.fillText('✦', W / 2 - 250, 202); x.fillText('✦', W / 2 + 250, 202);
    var top = 270, artH = 1000;
    if (o.card) { // the card, upside-down when reversed
      var ar = o.card.naturalWidth / o.card.naturalHeight, h = artH, w = h * ar;
      if (w > 640) { w = 640; h = w / ar; }
      var cx = W / 2, cy = top + artH / 2;
      x.save(); x.translate(cx, cy); if (o.reversed) x.rotate(Math.PI);
      x.shadowColor = 'rgba(46,31,32,.35)'; x.shadowBlur = 50; x.shadowOffsetY = 24;
      x.beginPath(); if (x.roundRect) x.roundRect(-w / 2, -h / 2, w, h, 22); else x.rect(-w / 2, -h / 2, w, h); x.fillStyle = '#efe5da'; x.fill();
      x.shadowColor = 'transparent'; x.clip(); x.drawImage(o.card, -w / 2, -h / 2, w, h); x.restore();
    } else { // the rune stone: an uneven pebble, the rune gilded on it
      var sx = W / 2, sy = top + artH / 2, R = 360, k = [1, .93, 1.05, .9, 1.02, .95, .98, .91];
      x.save(); x.shadowColor = 'rgba(46,31,32,.4)'; x.shadowBlur = 60; x.shadowOffsetY = 30;
      // eight uneven points round the stone, joined by a smooth curve through their midpoints
      var pts = k.map(function (f, i) { var a = i / 8 * Math.PI * 2 - Math.PI / 2; return [sx + R * f * Math.cos(a), sy + R * 1.12 * f * Math.sin(a)]; });
      var midOf = function (a, b) { return [(a[0] + b[0]) / 2, (a[1] + b[1]) / 2]; };
      x.beginPath();
      var m0 = midOf(pts[7], pts[0]); x.moveTo(m0[0], m0[1]);
      pts.forEach(function (p, i) { var m = midOf(p, pts[(i + 1) % 8]); x.quadraticCurveTo(p[0], p[1], m[0], m[1]); });
      x.closePath(); x.fillStyle = o.tone || '#77706b'; x.fill(); x.restore();
      x.save(); x.clip();
      var hl = x.createRadialGradient(sx - 120, sy - 160, 20, sx - 120, sy - 160, 420); hl.addColorStop(0, 'rgba(255,255,255,.3)'); hl.addColorStop(1, 'rgba(255,255,255,0)');
      x.fillStyle = hl; x.fillRect(0, 0, W, H);
      var sh = x.createRadialGradient(sx + 140, sy + 260, 20, sx + 140, sy + 260, 480); sh.addColorStop(0, 'rgba(0,0,0,.32)'); sh.addColorStop(1, 'rgba(0,0,0,0)');
      x.fillStyle = sh; x.fillRect(0, 0, W, H); x.restore();
      if (o.glyph) {
        x.font = '440px "Segoe UI Historic", "Noto Sans Runic", "Junicode", serif'; x.textBaseline = 'middle';
        x.fillStyle = 'rgba(0,0,0,.45)'; x.fillText(o.glyph, sx, sy + 6);
        x.fillStyle = '#e3c27a'; x.fillText(o.glyph, sx, sy); x.textBaseline = 'alphabetic';
      }
    }
    var y = top + artH + 150;
    x.fillStyle = '#2e1f20'; x.font = '400 112px Cormorant, Georgia, serif'; x.fillText(o.title, W / 2, y);
    if (o.state) { y += 74; x.fillStyle = '#7a1f2b'; x.font = 'italic 400 56px Cormorant, Georgia, serif'; x.fillText(o.state, W / 2, y); }
    x.fillStyle = '#5c4a4b'; x.font = '300 40px Jost, sans-serif';
    wrapLines(x, o.words.slice(0, 6), W - 260).forEach(function (l) { y += 64; x.fillText(l, W / 2, y); });
    return c;
  }
  /** Makes the picture now; returns what the Share button does with it. */
  function prepareShare(o, name) {
    var fonts = document.fonts ? Promise.all(['400 112px Cormorant', 'italic 400 56px Cormorant', '300 40px Jost', '500 34px Jost'].map(function (f) { return document.fonts.load(f); })) : Promise.resolve();
    var logo = loadImage(SITE_ROOT + 'assets/logo.svg').catch(function () { return null; });
    return Promise.all([fonts, logo]).then(function (r) {
      var c = shareCanvas(o), lg = r[1];
      if (lg) { var x = c.getContext('2d'), w = 230, h = w * 879 / 1585; x.globalAlpha = .92; x.drawImage(lg, 1080 - 100 - w, 1920 - 100 - h, w, h); x.globalAlpha = 1; }
      return new Promise(function (ok) { c.toBlob(function (b) { ok(new File([b], name + '.png', { type: 'image/png' })); }, 'image/png'); });
    });
  }
  /** The Share button under a reading: share menu on phones, download elsewhere. */
  function shareButton(holder, filePromise, text) {
    var b = document.createElement('button');
    b.type = 'button'; b.className = 'btn ghost share-btn'; b.disabled = true; b.textContent = 'Share';
    holder.appendChild(b);
    var file = null;
    filePromise.then(function (f) {
      file = f; b.disabled = false;
      if (!(navigator.canShare && navigator.canShare({ files: [f] }))) b.textContent = 'Save picture';
    }).catch(function () { b.remove(); });
    b.addEventListener('click', function () {
      if (!file) return;
      if (navigator.canShare && navigator.canShare({ files: [file] })) { navigator.share({ files: [file], text: text }).catch(function () {}); return; }
      var a = document.createElement('a'); a.href = URL.createObjectURL(file); a.download = file.name;
      document.body.appendChild(a); a.click(); setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
    });
  }

  // ------------------------------------------------------------------ save to notes (pulls, spreads, dreams)
  function noteDate() { var d = new Date(); return MONTHS[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear(); }
  function moonNow() { var d = new Date(); d.setHours(12, 0, 0, 0); return 'a ' + moonPhase(moonAge(d))[1]; }
  /** A "Save to notes" button: saves what make() returns ({title, body}) as a new note on this page, then offers to open it. */
  function saveToNotes(holder, make) {
    var b = document.createElement('button');
    b.type = 'button'; b.className = 'btn ghost save-note-btn'; b.textContent = 'Save to notes';
    holder.appendChild(b);
    b.addEventListener('click', function () {
      if (b.disabled) return;
      b.disabled = true;
      var n = make(), item = { title: n.title, body: n.body, page: location.pathname, pageTitle: document.title.split(' | ')[0] };
      Store.save('notes', item).then(function (id) {
        item.id = id; b.textContent = 'Saved ✓';
        var open = document.createElement('a'); open.href = url('notes/'); open.className = 'link'; open.textContent = 'Open in notes';
        open.addEventListener('click', function (e) { e.preventDefault(); (pop = pop || noteDialog()).openNote(item); });
        b.after(open);
      }).catch(function () { b.disabled = false; b.textContent = 'Not saved. Try again'; });
    });
  }

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
    var deck = D.t.filter(function (c) { return isTarotCard(D.p[c[0]][1], D.p[c[0]][4].split('|')); }), hand = shuffled(deck).slice(0, 7);
    tool.className = 'pg pg-tool pg-tarot';
    render(tool, '<header class="pg-head tool-head">' + crumbs('card') + '<h1>Draw <em>a card</em></h1></header>'
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
        var front = drawn.querySelector('.front img');
        shareButton(drawn.querySelector('.reading .actions'), (front ? loadImage(front.src) : Promise.resolve(null)).then(function (im) {
          return prepareShare({ eyebrow: 'My card', title: card.title, state: rev ? 'Reversed' : 'Upright', reversed: rev, card: im, words: words.map(function (p) { return p.title; }) }, 'my-card-' + card.slug);
        }), 'I drew ' + card.title + (rev ? ', reversed' : '') + ' ✦');
        saveToNotes(drawn.querySelector('.reading .actions'), function () {
          return { title: card.title + (rev ? ' (reversed)' : '') + ' · ' + noteDate(), body: 'Drawn on ' + noteDate() + ', ' + moonNow() + '.\n'
            + (rev ? 'Reversed' : 'Upright') + (words.length ? ': ' + words.map(function (p) { return p.title; }).join(', ') : '') + '\n\n' };
        });
      }, quiet ? 0 : 450);
    });
  }

  // ------------------------------------------------------------------ Pull a Rune
  // Seven river stones, face down, from the Elder Futhark (24 runes and the blank Wyrd). The one picked turns over to
  // show its rune, carved, with the rune's correspondences and a way into its page.
  var RUNE_GLYPHS = { fehu: 'ᚠ', uruz: 'ᚢ', thurisaz: 'ᚦ', ansuz: 'ᚨ', raidho: 'ᚱ', kenaz: 'ᚲ', gebo: 'ᚷ', wunjo: 'ᚹ', hagaiaz: 'ᚺ', nauthiz: 'ᚾ',
    isaz: 'ᛁ', jera: 'ᛃ', eiwaz: 'ᛇ', pertho: 'ᛈ', algiz: 'ᛉ', sowilo: 'ᛊ', tiwaz: 'ᛏ', berkanan: 'ᛒ', ehwaz: 'ᛖ', mannaz: 'ᛗ', laguz: 'ᛚ',
    ingwaz: 'ᛜ', othala: 'ᛟ', dagaz: 'ᛞ', wyrd: '' };
  function runeWords(i) {
    return D.l[i].filter(function (j) { return D.g[D.p[j][0]] === 'correspondence'; })
      .sort(function (a, b) { return D.p[a][2].localeCompare(D.p[b][2]); }).map(pg);
  }
  function runes(tool) {
    var bag = []; D.p.forEach(function (p, i) { if (p[4] && p[4].split('|').indexOf('Elder Futhark Runes') !== -1) bag.push(i); });
    var hand = shuffled(bag).slice(0, 7);
    tool.className = 'pg pg-tool pg-runes';
    render(tool, '<header class="pg-head tool-head">' + crumbs('rune') + '<h1>Pull <em>a rune</em></h1></header>'
      + '<div class="stones" role="group" aria-label="Seven face-down rune stones">' + hand.map(function (i, k) {
        return '<button class="stone st-' + k + '" type="button" data-pick="' + k + '" aria-label="Stone ' + (k + 1) + ' of 7"><span class="stone-face"></span></button>';
      }).join('') + '</div><div class="drawn drawn-rune" aria-live="polite"></div>', 'Pull a Rune');
    var field = tool.querySelector('.stones'), drawn = tool.querySelector('.drawn');
    field.addEventListener('click', function (e) {
      var b = e.target.closest('[data-pick]');
      if (!b || field.classList.contains('picked')) return;
      var k = +b.getAttribute('data-pick'), rune = pg(hand[k]), words = runeWords(hand[k]), glyph = RUNE_GLYPHS[rune.slug];
      var quiet = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      field.classList.add('picked'); b.classList.add('chosen');
      setTimeout(function () {
        field.hidden = true;
        drawn.innerHTML = '<div class="drawn-card drawn-stone"><div class="flip"><span class="face stone st-' + k + '"><span class="stone-face"></span></span>'
          + '<span class="face front stone st-' + k + '"><span class="stone-face"></span><span class="rune-glyph' + (glyph ? '' : ' blank') + '" aria-hidden="true">' + (glyph || '') + '</span></span></div></div>'
          + '<div class="reading"><p class="eyebrow">Elder Futhark</p><h2>' + esc(rune.title) + '</h2>'
          + (words.length ? '<div class="rels keywords">' + words.map(chip).join('') + '</div>' : '')
          + '<div class="actions"><a class="btn" href="' + esc(rune.url) + '">View more</a><button class="btn ghost" type="button" data-again>Pull again</button></div></div>';
        var flip = drawn.querySelector('.drawn-card');
        if (quiet) flip.classList.add('turned'); else requestAnimationFrame(function () { requestAnimationFrame(function () { flip.classList.add('turned'); }); });
        drawn.querySelector('[data-again]').addEventListener('click', function () { runes(tool); window.scrollTo({ top: 0, behavior: quiet ? 'auto' : 'smooth' }); });
        var tone = getComputedStyle(drawn.querySelector('.front.stone')).getPropertyValue('--tone').trim();
        shareButton(drawn.querySelector('.reading .actions'), prepareShare({ eyebrow: 'My rune', title: rune.title, state: 'Elder Futhark', card: null, glyph: glyph, tone: tone,
          words: words.map(function (p) { return p.title; }) }, 'my-rune-' + rune.slug), 'I pulled ' + rune.title + ' ✦');
        saveToNotes(drawn.querySelector('.reading .actions'), function () {
          return { title: 'Rune: ' + rune.title + ' · ' + noteDate(), body: 'Pulled on ' + noteDate() + ', ' + moonNow() + '.\n'
            + (words.length ? words.map(function (p) { return p.title; }).join(', ') : '') + '\n\n' };
        });
      }, quiet ? 0 : 450);
    });
  }

  // ------------------------------------------------------------------ rituals and recipes: substitutions, the why, safety
  // Added after a ritual's or recipe's Quantities, all from the Manuscript's own pages, nothing made up:
  //   Substitutions        substitutions written on an ingredient's own page ("Substitution: Tobacco"), then other herbs,
  //                        stones and oils that share every correspondence the ingredient brings to this working and
  //                        carry no warnings of their own
  //   Why these ingredients each ingredient's correspondences that this working also has
  //   Before you begin     standard fire safety when the working uses fire, and each ingredient's own warnings, word for word
  var INGREDIENT_GRIMOIRES = ['plant', 'mineral', 'oil', 'material'];
  var SWAP_GRIMOIRES = ['plant', 'mineral', 'oil'];
  var FIRE_WORDS = /\b(candles?|tealights?|incense|charcoal|matches|lighters?|flames?|bonfires?|burn(?:s|ed|ing|t)?|ignite[sd]?)\b/i;
  var FIRE_SAFETY = ['Never leave a candle, incense or charcoal burning unattended.',
    'Use a fire-safe holder on a heat-proof, stable surface.',
    'Keep flames away from curtains, paper, hair and loose sleeves.',
    'Keep children and pets away, and have water or a fire extinguisher close by.',
    'Burn incense, herbs and charcoal in a well-ventilated room.',
    'Make sure everything is completely out before you leave it.'];
  var WARNING_WORDS = /\b(poison(?:s|ous|ing)?|toxic|toxins?|not (?:recommended|safe)|avoid|do not|don.t|never (?:use|be|ingest|take|apply)|irritant|irritat\w*|pregnan\w*|allerg\w*|caution|harmful|dangerous(?:ly)?|fatal(?:ly)?|lethal|abortifacient|phototoxic|unsafe|miscarriage|contraindicated|overdose)\b/i;
  /** An ingredient page's warnings, as written on it: [label, text]… (general notes and "safe" entries are left out). */
  function warnings(i) {
    var out = [];
    (D.w[i] || []).forEach(function (f) {
      var name = f[0], vals = f[1];
      if (name === 'Safety & Uses') vals.forEach(function (v) { if (/^(Toxic to Humans|Toxic to Pets|Skin Irritant)$/.test(v)) out.push(['', v]); });
      else if (name === 'Pregnancy' && /^(Avoid|Consult)$/.test(vals[0])) out.push(['Pregnancy', vals[0]]);
      else if (name === 'Drug Interactions' && vals[0] && !/^None known/i.test(vals[0])) out.push(['Drug interactions', vals.join(' ')]);
      else if ((name === 'Water Safe?' || name === 'Sun Safe?') && vals[0] === 'No') out.push(['', name === 'Water Safe?' ? 'Not water safe' : 'Not sun safe']);
      else if (name === 'Notes/Warnings') {
        // Only the sentences that are warnings, each exactly as written; descriptions and history are left out.
        var t = vals.join('\n').replace(/^[ \t]*Substitution:.*$/gim, '');
        var sentences = t.split(/\n+|(?<=[.!?])\s+(?=[A-Z0-9⚠])/).map(function (x) { return x.trim(); }).filter(function (x) {
          return x && !/^(non-toxic|generally safe)/i.test(x) && !/poison arrows?/i.test(x) && WARNING_WORDS.test(x);
        });
        if (sentences.length) out.push(['', sentences.join('\n')]);
      }
    });
    return out;
  }
  /** Substitutions written on an ingredient's own page, as page links where the name matches a page in its grimoire. */
  function ownSubstitutions(i) {
    var out = [];
    (D.w[i] || []).forEach(function (f) {
      if (f[0] !== 'Notes/Warnings') return;
      // Exactly as written: only semicolons separate suggestions ("Mace; Nutmeg"); "or", "and" and commas are left alone.
      f[1].join('\n').replace(/^[ \t]*Substitution:[ \t]*(.+)$/gim, function (m, names) { names.split(/\s*;\s*/).forEach(function (n) { if (n.trim()) out.push(n.trim()); }); });
    });
    var g = D.p[i][0];
    out = out.filter(function (n, k) { return out.map(function (x) { return x.toLowerCase(); }).indexOf(n.toLowerCase()) === k; });
    return out.map(function (n) {
      var hit = null; D.p.forEach(function (p, j) { if (!hit && p[0] === g && p[2].toLowerCase() === n.toLowerCase()) hit = j; });
      return hit == null ? '<span>' + esc(n) + '</span>' : '<a href="' + esc(pg(hit).url) + '">' + esc(D.p[hit][2]) + '</a>';
    });
  }
  /**
   * Is this working eaten or drunk? Read from the page's own Type (recipes) or Form (rituals). Then "Before you begin"
   * starts open, so its warnings are seen first.
   */
  var SWALLOWED = { Type: ['Drink', 'Food', 'Potion', 'Infusion', 'Elixir', 'Syrup', 'Tincture'], Form: ['Food/Edible', 'Potion', 'Elixir'] };
  function swallowed() {
    return [].some.call(document.querySelectorAll('.props-panel .prop-row'), function (row) {
      var name = row.querySelector('.prop-name'), list = name && SWALLOWED[name.textContent.replace(/\d+$/, '').trim()];
      return !!list && [].some.call(row.querySelectorAll('.pill'), function (p) { return list.indexOf(p.textContent.trim()) !== -1; });
    });
  }
  function workingExtras() {
    var m = /\/manuscript\/(ritual|recipe)\/([^/?#]+)\/?$/.exec(location.pathname), prose = document.querySelector('.pg-entry .mm-prose');
    if (!m || !prose || prose.querySelector('[data-extras]')) return;
    var me = find(m[1], m[2]);
    if (!me) return;
    var corr = {}, ingredients = [];
    D.l[me.i].forEach(function (j) {
      var g = D.g[D.p[j][0]];
      if (g === 'correspondence') corr[j] = true;
      else if (INGREDIENT_GRIMOIRES.indexOf(g) !== -1) ingredients.push(j);
    });
    ingredients.sort(function (a, b) { return D.p[a][2].localeCompare(D.p[b][2]); });
    var inWorking = {}; ingredients.forEach(function (j) { inWorking[j] = true; });
    var corrOf = function (j) { return D.l[j].filter(function (k) { return D.g[D.p[k][0]] === 'correspondence'; }); };
    var names = function (list) { return list.map(function (k) { return '<a href="' + esc(pg(k).url) + '">' + esc(D.p[k][2]) + '</a>'; }).join(', '); };

    var why = [], subs = [], warn = [];
    ingredients.forEach(function (j) {
      var shared = corrOf(j).filter(function (k) { return corr[k]; }).sort(function (a, b) { return D.p[a][2].localeCompare(D.p[b][2]); });
      if (shared.length) why.push('<li>' + chip(pg(j)) + '<span class="why-for">' + names(shared) + '</span></li>');
      var own = ownSubstitutions(j), swaps = [];
      if (shared.length && SWAP_GRIMOIRES.indexOf(D.g[D.p[j][0]]) !== -1) {
        // A swap must carry every correspondence the ingredient brings here (at least three of them, if it brings more).
        var need = Math.min(shared.length, 3), cands = {};
        shared.forEach(function (k) { D.l[k].forEach(function (c) { if (D.p[c][0] === D.p[j][0] && c !== j && !inWorking[c]) cands[c] = (cands[c] || 0) + 1; }); });
        // Never suggest a swap that carries any warning on its own page (toxic, irritant, pregnancy…): it may be eaten or worn.
        swaps = Object.keys(cands).map(Number).filter(function (c) { return cands[c] >= need && !warnings(c).length; })
          .sort(function (a, b) { return D.l[b].length - D.l[a].length; }).slice(0, 4).map(function (c) { return '<a href="' + esc(pg(c).url) + '">' + esc(D.p[c][2]) + '</a>'; });
      }
      if (own.length || swaps.length) {
        subs.push('<li>' + chip(pg(j)) + '<span class="sub-for">'
          + (own.length ? '<span class="sub-line"><span class="sub-label">On its page</span>' + own.join('; ') + '</span>' : '')
          + (swaps.length ? '<span class="sub-line"><span class="sub-label">' + (shared.length <= 3 ? 'Also ' + names(shared) : 'Shares 3+ of its correspondences') + '</span>' + swaps.join(', ') + '</span>' : '') + '</span></li>');
      }
      var w = warnings(j);
      if (w.length) warn.push('<li>' + chip(pg(j)) + '<span class="warn-for">' + w.map(function (x) {
        return '<span>' + (x[0] ? '<strong>' + esc(x[0]) + ':</strong> ' : '') + esc(x[1]).replace(/\n/g, '<br>') + '</span>';
      }).join('') + '</span></li>');
    });
    // Fire: in what the working uses or does (its Quantities and Directions; the whole text if it has neither).
    var parts = prose.querySelectorAll('ul.ingredients, ol.steps'), text = parts.length ? [].map.call(parts, function (n) { return n.textContent; }).join(' ') : prose.textContent, fire = FIRE_WORDS.test(text) || ingredients.some(function (j) { return FIRE_WORDS.test(D.p[j][2]); });

    // Each section folds away (closed to start), so the Directions stay right under Quantities; the closed heading says what's inside.
    var fold = function (id, title, sub, body, open) {
      return '<details class="toggle extras-fold"' + (open ? ' open' : '') + '><summary><h2 id="' + id + '">' + title + '</h2><span class="fold-sub">' + sub + '</span></summary>' + body + '</details>';
    };
    var plural = function (n, w) { return n + ' ' + w + (n === 1 ? '' : 's'); };
    var html = '';
    if (subs.length) html += fold('substitutions', 'Substitutions', 'for ' + plural(subs.length, 'ingredient'),
      '<p class="small extras-note">Out of something? These carry the same correspondences in this working. They match in meaning, not in physical effect: check a swap’s own page before it goes on skin or into food.</p><ul class="extras-list subs">' + subs.join('') + '</ul>');
    if (why.length) html += fold('why', 'Why these ingredients', plural(why.length, 'ingredient'),
      '<p class="small extras-note">What each one brings to this working, from its correspondences.</p><ul class="extras-list why">' + why.join('') + '</ul>');
    if (fire || warn.length) {
      html += fold('before-you-begin', 'Before you begin', [warn.length ? plural(warn.length, 'ingredient') + ' with warnings' : '', fire ? 'fire safety' : ''].filter(Boolean).join(' · '),
        '<aside class="safety-box">'
        + (fire ? '<p class="label">Fire safety</p><ul class="fire-list">' + FIRE_SAFETY.map(function (t) { return '<li>' + esc(t) + '</li>'; }).join('') + '</ul>' : '')
        + (warn.length ? '<p class="label">From the ingredients’ pages</p><ul class="extras-list warn">' + warn.join('') + '</ul>' : '') + '</aside>', swallowed());
    }
    if (!html) return;
    var box = document.createElement('div');
    box.setAttribute('data-extras', '');
    box.innerHTML = html;
    // Right after Quantities; else before Directions; else at the end of the page's text.
    var q = prose.querySelector('#quantities'), dir = prose.querySelector('#directions');
    if (q) { var after = q; while (after.nextElementSibling && !/^H2$/.test(after.nextElementSibling.tagName)) after = after.nextElementSibling; after.after(box); }
    else if (dir) dir.before(box);
    else prose.appendChild(box);
  }

  // ------------------------------------------------------------------ Do this spread (on every tarot spread page)
  // Deals the spread's own number of cards (its "Number of Cards"), one per position as written in its "Card Details",
  // shuffled from the 78 with a one-in-three chance of each being reversed. The spread's own layout picture is shown as
  // the guide: the cards are numbered to match it.
  function spreadTool() {
    var crumbs = document.querySelector('.pg-head .crumbs'), title = document.querySelector('.pg-head .pg-title');
    if (!crumbs || !title || !/Tarot Spreads/.test(crumbs.textContent) || document.querySelector('[data-do-spread]')) return;
    var prop = function (name) {
      var row = [].filter.call(document.querySelectorAll('.props-panel .prop-row'), function (r) { var n = r.querySelector('.prop-name'); return n && n.textContent.replace(/\d+$/, '').trim() === name; })[0];
      return row ? row.querySelector('.prop-val').innerText.trim() : '';
    };
    var n = parseInt(prop('Number of Cards'), 10);
    if (!(n >= 1 && n <= 15)) return;
    var details = {};
    prop('Card Details').split(/\n+/).forEach(function (line) { var m = /^\s*Card\s*(\d+)\s*[:.\u2002\s-]\s*(.+)$/i.exec(line); if (m) details[+m[1]] = m[2].trim(); });
    var layout = document.querySelector('.gallery img');
    title.insertAdjacentHTML('afterend', '<p class="tool-links small"><button class="btn do-spread" type="button" data-do-spread>✦ Do this spread</button></p>');
    var spreadName = document.querySelector('.pg-head h1').textContent.trim();
    document.querySelector('[data-do-spread]').addEventListener('click', function () {
      var old = document.querySelector('.spread-reading'); if (old) old.remove();
      var deck = D.t.filter(function (c) { return isTarotCard(D.p[c[0]][1], D.p[c[0]][4].split('|')); });
      var hand = shuffled(deck).slice(0, n).map(function (c) { return { c: c, rev: Math.random() < REVERSED_CHANCE }; });
      var box = document.createElement('section');
      box.className = 'spread-reading';
      box.innerHTML = '<header class="spread-head"><h2>Your <em>reading</em></h2><p class="small">Turn the cards over one at a time, in order.'
        + (layout ? ' The numbers match the layout.' : '') + '</p></header>'
        + (layout ? '<figure class="spread-layout"><img src="' + esc(layout.src) + '" alt="' + esc(spreadName) + ' layout"></figure>' : '')
        + '<ol class="spread-cards">' + hand.map(function (h, k) {
          return '<li class="spread-slot"><button class="spread-card" type="button" data-turn="' + k + '" aria-label="Card ' + (k + 1) + ', face down"><span class="slot-n">' + (k + 1) + '</span><span class="tarot-back"><span class="back-art"></span></span></button>'
            + '<div class="slot-pos">' + (details[k + 1] ? esc(details[k + 1]) : 'Card ' + (k + 1)) + '</div><div class="slot-read" hidden></div></li>';
        }).join('') + '</ol><div class="actions spread-actions"><button class="btn ghost" type="button" data-turn-all>Turn them all</button><button class="btn ghost" type="button" data-redeal>Deal again</button></div>';
      document.querySelector('.pg-head').after(box);
      var turn = function (k) {
        var slot = box.querySelectorAll('.spread-slot')[k], btn = slot.querySelector('.spread-card');
        if (btn.classList.contains('turned')) return;
        var h = hand[k], card = pg(h.c[0]), words = (h.rev ? h.c[3] : h.c[2]).map(pg).slice(0, 6);
        btn.classList.add('turned'); btn.setAttribute('aria-label', 'Card ' + (k + 1) + ': ' + card.title + (h.rev ? ', reversed' : ''));
        btn.querySelector('.tarot-back').outerHTML = '<span class="slot-front' + (h.rev ? ' reversed' : '') + '">' + (h.c[1] ? '<img src="' + esc(url((h.c[1].indexOf('a:') === 0 ? 'assets/' : 'media/') + h.c[1].slice(2))) + '" alt="">' : '<span>' + esc(card.title) + '</span>') + '</span>';
        var read = slot.querySelector('.slot-read');
        read.hidden = false;
        read.innerHTML = '<a class="slot-name" href="' + esc(card.url) + '">' + esc(card.title) + '</a><span class="slot-state">' + (h.rev ? 'Reversed' : 'Upright') + '</span>'
          + (words.length ? '<span class="slot-words">' + words.map(function (w) { return esc(w.title); }).join(' · ') + '</span>' : '');
        if (box.querySelectorAll('.spread-card.turned').length === n && !box.querySelector('.save-note-btn')) {
          saveToNotes(box.querySelector('.spread-actions'), function () {
            return { title: spreadName + ' · ' + noteDate(), body: 'Laid out on ' + noteDate() + ', ' + moonNow() + '.\n\n' + hand.map(function (x, j) {
              var c = pg(x.c[0]), w = (x.rev ? x.c[3] : x.c[2]).map(pg).slice(0, 6).map(function (p) { return p.title; });
              return (j + 1) + '. ' + (details[j + 1] || 'Card ' + (j + 1)) + '\n   ' + c.title + (x.rev ? ' (reversed)' : '') + (w.length ? ': ' + w.join(', ') : '');
            }).join('\n') + '\n\n' };
          });
        }
      };
      box.addEventListener('click', function (e) {
        var t = e.target.closest('[data-turn]'); if (t) { turn(+t.getAttribute('data-turn')); return; }
        if (e.target.closest('[data-turn-all]')) { hand.forEach(function (h, k) { turn(k); }); return; }
        if (e.target.closest('[data-redeal]')) document.querySelector('[data-do-spread]').click();
      });
      box.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  }

  // ------------------------------------------------------------------ Dream Decoder
  // Finds the Dream Grimoire's symbols in a dream as typed ("Dreaming of Water" → water, waters), shows them in the
  // dream, and each one's Interpretations from its own page, word for word. Nothing typed leaves the browser unless saved.
  var DREAM_SKIP = ['about', 'above', 'after', 'again', 'all', 'also', 'always', 'and', 'another', 'any', 'around', 'away', 'back', 'because', 'before', 'being', 'below',
    'between', 'both', 'but', 'can', 'could', 'did', 'down', 'each', 'even', 'every', 'everything', 'few', 'for', 'from', 'getting', 'going', 'had', 'has', 'have', 'here',
    'how', 'into', 'just', 'like', 'made', 'many', 'more', 'most', 'much', 'never', 'new', 'nothing', 'now', 'off', 'old', 'once', 'one', 'only', 'other', 'out', 'over',
    'own', 'really', 'same', 'saw', 'see', 'seeing', 'some', 'something', 'still', 'such', 'than', 'that', 'the', 'then', 'there', 'they', 'thing', 'things', 'this',
    'through', 'too', 'under', 'very', 'was', 'were', 'what', 'when', 'where', 'which', 'while', 'who', 'why', 'with', 'would', 'you', 'your'];
  // Words are compared by their root, so tenses, plurals and word forms meet: falling, falls, fell, fall → "fall";
  // flying, flies, flew → "fly"; houses → "hous"; baking, baked, bake → "bak". Irregular forms are listed.
  var IRREGULAR = { fell: 'fall', fallen: 'fall', flew: 'fly', flown: 'fly', ran: 'run', swam: 'swim', swum: 'swim', drove: 'drive', driven: 'drive',
    ate: 'eat', eaten: 'eat', drank: 'drink', drunk: 'drink', fought: 'fight', bit: 'bite', bitten: 'bite', broke: 'break', broken: 'break', caught: 'catch',
    chose: 'choose', chosen: 'choose', came: 'come', drew: 'draw', drawn: 'draw', fled: 'flee', froze: 'freeze', frozen: 'freeze', gave: 'give', given: 'give',
    grew: 'grow', grown: 'grow', hid: 'hide', hidden: 'hide', held: 'hold', hung: 'hang', kept: 'keep', knelt: 'kneel', knew: 'know', known: 'know', lost: 'lose',
    met: 'meet', paid: 'pay', rode: 'ride', ridden: 'ride', rang: 'ring', rung: 'ring', rose: 'rise', risen: 'rise', sang: 'sing', sung: 'sing', sank: 'sink',
    sunk: 'sink', sat: 'sit', shook: 'shake', shaken: 'shake', shot: 'shoot', slept: 'sleep', slid: 'slide', spoke: 'speak', spoken: 'speak', spun: 'spin',
    stole: 'steal', stolen: 'steal', stung: 'sting', struck: 'strike', swore: 'swear', sworn: 'swear', swept: 'sweep', took: 'take', taken: 'take', taught: 'teach',
    tore: 'tear', torn: 'tear', threw: 'throw', thrown: 'throw', woke: 'wake', woken: 'wake', wore: 'wear', worn: 'wear', wept: 'weep', won: 'win', wrote: 'write',
    written: 'write', bought: 'buy', brought: 'bring', built: 'build', burnt: 'burn', dug: 'dig', dreamt: 'dream', felt: 'feel', found: 'find', heard: 'hear',
    made: 'make', sold: 'sell', sent: 'send', sought: 'seek', stood: 'stand', told: 'tell', thought: 'think', died: 'die', dying: 'die', lying: 'lie', tying: 'tie',
    teeth: 'tooth', mice: 'mouse', geese: 'goose', children: 'child', men: 'man', women: 'woman', feet: 'foot', knives: 'knife', wives: 'wife',
    wolves: 'wolf', leaves: 'leaf', loaves: 'loaf', halves: 'half', lives: 'life', thieves: 'thief', shelves: 'shelf', calves: 'calf', elves: 'elf', people: 'person' };
  function root(w) {
    w = IRREGULAR[w] || w;
    var verb = false;
    if (w.length > 4 && /ies$/.test(w)) w = w.slice(0, -3) + 'y';
    else if (w.length > 5 && /ing$/.test(w)) { w = w.slice(0, -3); verb = true; }
    else if (w.length > 4 && /ied$/.test(w)) w = w.slice(0, -3) + 'y';
    else if (w.length > 4 && /ed$/.test(w)) { w = w.slice(0, -2); verb = true; }
    else if (w.length > 4 && /(s|x|z|ch|sh)es$/.test(w)) w = w.slice(0, -2);
    else if (w.length > 3 && /s$/.test(w) && !/(ss|us|is)$/.test(w)) w = w.slice(0, -1);
    if (verb && /([bdfgklmnprtvz])\1$/.test(w) && !/(ll|ss|zz|ff)$/.test(w)) w = w.slice(0, -1); // running → runn → run
    if (w.length > 3 && /e$/.test(w)) w = w.slice(0, -1); // bake → bak, to meet baking → bak
    return w;
  }
  var dreamIndex = null, dreamSingles = null, dreamExact = null;
  /** Did the dreamer use a verb form? (-ing, -ed, or an irregular past like flew, fell, ran) */
  function verbal(w) { return /(ing|ed)$/.test(w) || (w in IRREGULAR && !/^(teeth|mice|geese|children|men|women|feet|people)$/.test(w) && !/(ves)$/.test(w)); }
  function buildDreamIndex() {
    if (dreamIndex) return dreamIndex;
    dreamIndex = {}; dreamSingles = []; dreamExact = {};
    D.p.forEach(function (p, i) {
      if (D.g[p[0]] !== 'dream') return;
      var m = /^Dreaming of (?:an? |the )?(.+)$/i.exec(p[2]); if (!m) return;
      // "Bar/Bartending" and "Belching and Burping" are each two symbols.
      m[1].split(/\s*\/\s*|\s+and\s+|\s*&\s*/i).forEach(function (part) {
        var words = part.toLowerCase().replace(/[^a-z' -]/g, ' ').split(/[\s-]+/).filter(Boolean).map(function (w) { return w.replace(/'s$/, ''); });
        if (!words.length || words.length > 3) return;
        if (words.length === 1 && (DREAM_SKIP.indexOf(words[0]) !== -1 || words[0].length < 3)) return;
        var key = words.map(root).join(' '), exact = words.join(' ');
        if (!(exact in dreamExact)) dreamExact[exact] = i;
        if (!(key in dreamIndex)) { dreamIndex[key] = []; if (words.length === 1 && key.length >= 5) dreamSingles.push(key); }
        dreamIndex[key].push({ i: i, ing: /ing$/.test(words[words.length - 1]) });
      });
    });
    return dreamIndex;
  }
  /** Within one letter (a typo): one added, missing or wrong letter, or two letters swapped. */
  function oneOff(a, b) {
    if (a === b || Math.abs(a.length - b.length) > 1) return a === b;
    var i = 0; while (i < a.length && i < b.length && a[i] === b[i]) i++;
    if (a.length === b.length) return a.slice(i + 1) === b.slice(i + 1) || (a[i] === b[i + 1] && a[i + 1] === b[i] && a.slice(i + 2) === b.slice(i + 2));
    return a.length > b.length ? a.slice(i + 1) === b.slice(i) : a.slice(i) === b.slice(i + 1);
  }
  /** The symbols in a dream: [{start, end, i, first, near}] in order; longest phrases first (three words, then two, then one), then typos. */
  function findSymbols(text) {
    var idx = buildDreamIndex(), words = [], re = /[A-Za-z']+/g, m, out = [], used = {};
    while ((m = re.exec(text))) { var w = m[0].toLowerCase().replace(/'s$/, ''); words.push({ w: w, r: root(w), s: m.index, e: m.index + m[0].length }); }
    for (var k = 0; k < words.length; k++) {
      var hit = null;
      for (var len = 3; len >= 1 && !hit; len--) {
        if (k + len > words.length) continue;
        if (len === 1 && (DREAM_SKIP.indexOf(words[k].w) !== -1 || words[k].w.length < 3)) continue;
        var part = words.slice(k, k + len), exact = part.map(function (x) { return x.w; }).join(' '), phrase = part.map(function (x) { return x.r; }).join(' ');
        var pick = null;
        if (exact in dreamExact) pick = dreamExact[exact]; // the very word ("flying" → Flying)
        else if (phrase in idx) { // the same root: a verb form prefers the -ing symbol (flew → Flying), anything else the rest (fly → Flies)
          var c = idx[phrase], v = verbal(part[len - 1].w), best = c.filter(function (x) { return x.ing === v; })[0] || c[0];
          pick = best.i;
        }
        if (pick != null) { hit = { start: part[0].s, end: part[len - 1].e, i: pick }; k += len - 1; }
      }
      // A typo in a longer word: the one symbol within one letter of it (none if two are equally close).
      if (!hit && words[k].w.length >= 6 && DREAM_SKIP.indexOf(words[k].w) === -1) {
        var near = dreamSingles.filter(function (key) { return oneOff(words[k].r, key); });
        if (near.length === 1) hit = { start: words[k].s, end: words[k].e, i: idx[near[0]][0].i, near: true };
      }
      if (hit) { hit.first = !used[hit.i]; used[hit.i] = true; out.push(hit); }
    }
    return out;
  }
  /** A dream page's Interpretations, as written on it. */
  function interpretationOf(p) {
    return fetch(p.url, { credentials: 'same-origin' }).then(function (r) { if (!r.ok) throw new Error(); return r.text(); }).then(function (html) {
      var doc = new DOMParser().parseFromString(html, 'text/html'), out = '';
      doc.querySelectorAll('.props-panel .prop-row').forEach(function (row) {
        var n = row.querySelector('.prop-name');
        if (!n || !/^Interpretations?/.test(n.textContent.trim())) return;
        // Keep its line breaks and separate entries (a parsed page has no layout, so innerText can't).
        var html = row.querySelector('.prop-val').innerHTML.replace(/<br\s*\/?>/gi, '\n').replace(/<\/(p|li|div|span)>/gi, '\n');
        var tmp = document.createElement('div'); tmp.innerHTML = html;
        out = tmp.textContent.split('\n').map(function (l) { return l.trim(); }).filter(Boolean).join('\n');
      });
      return out;
    });
  }
  function dreamDecoder(tool) {
    tool.className = 'pg pg-tool pg-dream';
    render(tool, '<header class="pg-head tool-head">' + crumbs('dream') + '<h1>Dream <em>decoder</em></h1>'
      + '<p class="lede">Write down your dream. The symbols the Dream Grimoire knows will light up, with what each one means.</p></header>'
      + '<form class="dream-form"><label class="sr-only" for="dream-text">Your dream</label><textarea id="dream-text" rows="7" placeholder="I was walking by the ocean at night, and a black cat followed me…"></textarea>'
      + '<div class="actions"><button class="btn" type="submit">Decode</button></div></form><div class="dream-result" aria-live="polite"></div>', 'Dream Decoder');
    var form = tool.querySelector('.dream-form'), box = tool.querySelector('#dream-text'), out = tool.querySelector('.dream-result');
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var text = box.value.trim(); if (!text) return;
      var found = findSymbols(text), marked = '', last = 0;
      found.forEach(function (f) { marked += esc(text.slice(last, f.start)) + '<mark' + (f.near ? ' title="Read as ' + esc(D.p[f.i][2].replace(/^Dreaming of /i, '')) + '"' : '') + '>' + esc(text.slice(f.start, f.end)) + '</mark>'; last = f.end; });
      marked += esc(text.slice(last));
      var symbols = found.filter(function (f) { return f.first; }).map(function (f) { return pg(f.i); });
      out.innerHTML = '<section class="tool-section"><h2>Your <em>dream</em></h2><p class="dream-marked">' + marked.replace(/\n/g, '<br>') + '</p></section>'
        + (symbols.length ? '<section class="tool-section"><h2>The <em>symbols</em> <small>' + symbols.length + '</small></h2><ul class="dream-symbols">'
          + symbols.map(function (p, k) { return '<li><details class="toggle" data-sym="' + k + '"><summary><span class="sym-name">' + esc(p.title.replace(/^Dreaming of /i, '')) + '</span></summary><div class="sym-read"><p class="small">Opening…</p></div></details></li>'; }).join('')
          + '</ul></section>' : '<p class="lede">None of the Dream Grimoire’s symbols came up. Try naming the things, places, animals and people in it.</p>')
        + '<div class="actions dream-actions"></div>';
      if (symbols.length) saveToNotes(out.querySelector('.dream-actions'), function () {
        return { title: 'Dream · ' + noteDate(), body: text + '\n\nSymbols: ' + symbols.map(function (p) { return p.title.replace(/^Dreaming of /i, ''); }).join(', ') + '\n\n' };
      });
      out.querySelectorAll('details[data-sym]').forEach(function (d) {
        d.addEventListener('toggle', function () {
          if (!d.open || d.getAttribute('data-done')) return;
          d.setAttribute('data-done', '1');
          var p = symbols[+d.getAttribute('data-sym')], read = d.querySelector('.sym-read');
          interpretationOf(p).then(function (t) {
            read.innerHTML = (t ? '<p>' + esc(t).replace(/\n/g, '<br>') + '</p>' : '') + '<p><a class="link" href="' + esc(p.url) + '">' + esc(p.title) + '</a></p>';
          }).catch(function () { read.innerHTML = '<p><a class="link" href="' + esc(p.url) + '">' + esc(p.title) + '</a></p>'; });
        });
      });
      out.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  }

  // ------------------------------------------------------------------ the sky: Astronomy Engine (assets/astronomy.min.js)
  var astroP = null;
  function astro() {
    if (!astroP) astroP = new Promise(function (ok, fail) {
      if (window.Astronomy) return ok(window.Astronomy);
      var sc = document.createElement('script'); sc.src = url('assets/astronomy.min.js?v=1');
      sc.onload = function () { ok(window.Astronomy); }; sc.onerror = fail; document.head.appendChild(sc);
    });
    return astroP;
  }
  var SIGNS = ['Aries', 'Taurus', 'Gemini', 'Cancer', 'Leo', 'Virgo', 'Libra', 'Scorpio', 'Sagittarius', 'Capricorn', 'Aquarius', 'Pisces'];
  var SIGN_GLYPH = ['♈', '♉', '♊', '♋', '♌', '♍', '♎', '♏', '♐', '♑', '♒', '♓'];
  function clock(d) { return d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' }); }

  // ------------------------------------------------------------------ Planetary Hours (Timing Grimoire)
  // The day from sunrise to sunset and the night from sunset to the next sunrise are each split into twelve hours. The
  // first hour of the day belongs to the day's planet (Sunday the Sun, Monday the Moon…); each hour after it goes to the
  // next planet in the Chaldean order: Saturn, Jupiter, Mars, Sun, Venus, Mercury, Moon.
  var CHALDEAN = ['saturn', 'jupiter', 'mars', 'sun', 'venus', 'mercury', 'moon'];
  var DAY_RULER = ['sun', 'moon', 'mars', 'mercury', 'jupiter', 'venus', 'saturn'];
  var PLANET_GLYPH = { sun: '☉', moon: '☽', mercury: '☿', venus: '♀', mars: '♂', jupiter: '♃', saturn: '♄' };
  var ORDINAL = ['1st', '2nd', '3rd', '4th', '5th', '6th', '7th', '8th', '9th', '10th', '11th', '12th'];
  function planetaryHours(tool) {
    tool.className = 'pg pg-tool pg-hours';
    var saved = store0('mm-place'), q = params(), m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(q.get('d') || '');
    var day = m ? new Date(+m[1], +m[2] - 1, +m[3]) : new Date(); day.setHours(0, 0, 0, 0);
    var head = '<header class="pg-head tool-head">' + crumbs('timing') + '<h1>Planetary <em>hours</em></h1></header>';
    var placeForm = '<form class="mm-form inline place-form"><p class="lede">Planetary hours follow your sunrise and sunset, so they need to know roughly where you are. It stays in this browser.</p>'
      + '<div class="actions"><button class="btn" type="button" data-locate>Use my location</button></div>'
      + '<details class="toggle"><summary>Or enter it yourself</summary><div class="place-row"><label>Latitude <input name="lat" inputmode="decimal" placeholder="40.71"></label><label>Longitude <input name="lon" inputmode="decimal" placeholder="-74.01"></label><button class="btn ghost" type="submit">Use these</button></div>'
      + '<p class="small">Find yours by searching your town and “latitude longitude”. West and south are negative.</p></details><p class="small place-error" hidden></p></form>';
    if (!saved) {
      render(tool, head + placeForm, 'Planetary Hours');
      var form = tool.querySelector('.place-form'), err = tool.querySelector('.place-error');
      var use = function (lat, lon) {
        if (!(Math.abs(lat) <= 90 && Math.abs(lon) <= 180)) { err.hidden = false; err.textContent = 'Those don’t look like a latitude and longitude.'; return; }
        try { localStorage.setItem('mm-place', JSON.stringify({ lat: Math.round(lat * 100) / 100, lon: Math.round(lon * 100) / 100 })); } catch (e) {}
        planetaryHours(tool);
      };
      tool.querySelector('[data-locate]').addEventListener('click', function () {
        if (!navigator.geolocation) { err.hidden = false; err.textContent = 'This browser can’t share its location. Enter it below instead.'; return; }
        navigator.geolocation.getCurrentPosition(function (pos) { use(pos.coords.latitude, pos.coords.longitude); },
          function () { err.hidden = false; err.textContent = 'Location wasn’t shared. Enter it below instead.'; }, { timeout: 15000, maximumAge: 86400000 });
      });
      form.addEventListener('submit', function (e) { e.preventDefault(); use(parseFloat(form.lat.value), parseFloat(form.lon.value)); });
      return;
    }
    render(tool, head + '<p class="lede">Working out your hours…</p>', 'Planetary Hours');
    astro().then(function (A) {
      var obs = new A.Observer(saved.lat, saved.lon, 0);
      var from = new Date(day); from.setHours(0, 0, 0, 0);
      var rise = A.SearchRiseSet(A.Body.Sun, obs, +1, from, 1.5), set = rise && A.SearchRiseSet(A.Body.Sun, obs, -1, rise.date, 1), next = set && A.SearchRiseSet(A.Body.Sun, obs, +1, set.date, 1.5);
      if (!rise || !set || !next) { render(tool, head + '<p class="lede">The sun doesn’t rise and set here today, so there are no planetary hours to count.</p>', 'Planetary Hours'); return; }
      var r = rise.date, s = set.date, n2 = next.date, dayLen = (s - r) / 12, nightLen = (n2 - s) / 12;
      // Before today's sunrise it's still last night's hours: show yesterday's planetary day.
      if (!m && new Date() < r) { var y = new Date(day); y.setDate(day.getDate() - 1); history.replaceState(null, '', '?d=' + iso(y)); planetaryHours(tool); return; }
      var start = CHALDEAN.indexOf(DAY_RULER[day.getDay()]), rows = '', now = new Date(), current = null;
      for (var h = 0; h < 24; h++) {
        var night = h >= 12, a = new Date(night ? s.getTime() + (h - 12) * nightLen : r.getTime() + h * dayLen), b = new Date(a.getTime() + (night ? nightLen : dayLen));
        var planet = CHALDEAN[(start + h) % 7], pp = find('astrology', planet), hp = find('timing', ORDINAL[h % 12].toLowerCase() + '-hour-of-the-' + (night ? 'night' : 'day'));
        var on = now >= a && now < b; if (on) current = { planet: planet, pp: pp, b: b };
        rows += '<tr' + (on ? ' class="now"' : '') + '><td>' + (hp ? '<a href="' + esc(hp.url) + '">' + esc(hp.title) + '</a>' : ORDINAL[h % 12] + ' hour of the ' + (night ? 'night' : 'day')) + '</td>'
          + '<td class="when">' + clock(a) + ' – ' + clock(b) + '</td><td class="planet"><span aria-hidden="true">' + PLANET_GLYPH[planet] + '\uFE0E</span> '
          + (pp ? '<a href="' + esc(pp.url) + '">' + esc(pp.title) + '</a>' : planet) + '</td></tr>';
      }
      var ruler = find('astrology', DAY_RULER[day.getDay()]), prev = new Date(day), nxt = new Date(day); prev.setDate(day.getDate() - 1); nxt.setDate(day.getDate() + 1);
      render(tool, head
        + (current ? '<div class="hour-now"><p class="eyebrow">Right now</p><p class="hour-planet"><span aria-hidden="true">' + PLANET_GLYPH[current.planet] + '\uFE0E</span> '
          + (current.pp ? '<a href="' + esc(current.pp.url) + '">' + esc(current.pp.title) + '</a>' : current.planet) + '</p><p class="small">until ' + clock(current.b) + '</p></div>' : '')
        + '<p class="lede">' + DAYS[day.getDay()] + ', ' + MONTHS[day.getMonth()] + ' ' + day.getDate() + (ruler ? ': a <a href="' + esc(ruler.url) + '">' + esc(ruler.title) + '</a> day' : '')
        + '. Sunrise ' + clock(r) + ', sunset ' + clock(s) + '.</p>'
        + '<p class="small day-nav"><a class="link" href="?d=' + iso(prev) + '">‹ ' + short(prev) + '</a><span aria-hidden="true">·</span><a class="link" href="?d=' + iso(nxt) + '">' + short(nxt) + ' ›</a>'
        + '<span aria-hidden="true">·</span><button class="link as-button" type="button" data-forget>Change location</button></p>'
        + '<div class="table-wrap"><table class="hours"><thead><tr><th>Hour</th><th>Time</th><th>Planet</th></tr></thead><tbody>' + rows + '</tbody></table></div>', 'Planetary Hours');
      tool.querySelector('[data-forget]').addEventListener('click', function () { try { localStorage.removeItem('mm-place'); } catch (e) {} planetaryHours(tool); });
    }).catch(function () { render(tool, head + '<p class="lede">The hours couldn’t be worked out. Refresh the page to try again.</p>', 'Planetary Hours'); });
  }
  function store0(key) { try { return JSON.parse(localStorage.getItem(key)); } catch (e) { return null; } }

  // ------------------------------------------------------------------ the Calendar: the moon's sign and phases each day
  // Each day shows the sign the moon is in at noon (the Moon in Aries… pages), with its moves into a new sign and the
  // month's new, quarter and full moons listed under the calendar, at your local times. From Astronomy Engine.
  function calendarMoon() {
    var cal = document.querySelector('[data-calendar]'), title = cal && cal.querySelector('.cal-title');
    if (!cal || !title) return;
    var signOf = function (A, d) { return Math.floor(A.EclipticGeoMoon(d).lon / 30) % 12; };
    var draw = function (A) {
      var m = /^([A-Za-z]+) (\d{4})$/.exec(title.textContent.trim()); if (!m) return;
      var month = MONTHS.indexOf(m[1]), year = +m[2], sec = cal.querySelector('.cal-m[data-month="' + (month + 1) + '"]');
      if (!sec || month < 0) return;
      var events = [];
      sec.querySelectorAll('.cal-days li').forEach(function (li) {
        var d = +li.getAttribute('data-day'), a = li.firstElementChild, old = li.querySelector('.cal-moon'); if (old) old.remove();
        if (li.hidden) return;
        var start = new Date(year, month, d, 0, 0), noon = new Date(year, month, d, 12, 0), end = new Date(year, month, d + 1, 0, 0);
        var s0 = signOf(A, start), s1 = signOf(A, end), sn = signOf(A, noon);
        var tag = document.createElement('span'); tag.className = 'cal-moon';
        tag.textContent = '☽ ' + SIGN_GLYPH[sn] + '\uFE0E';
        tag.title = 'Moon in ' + SIGNS[sn];
        if (s0 !== s1) { // the moon changes sign today: find when
          var lo = start.getTime(), hi = end.getTime();
          while (hi - lo > 60000) { var mid = (lo + hi) / 2; if (signOf(A, new Date(mid)) === s0) lo = mid; else hi = mid; }
          tag.textContent = '☽ → ' + SIGN_GLYPH[s1] + '\uFE0E'; tag.title = 'Moon enters ' + SIGNS[s1] + ' at ' + clock(new Date(hi));
          events.push([new Date(hi), 'Moon enters ' + SIGNS[s1], find('astrology', 'moon-in-' + SIGNS[s1].toLowerCase())]);
        }
        a.appendChild(tag);
      });
      var names = ['New Moon', 'First Quarter Moon', 'Full Moon', 'Third Quarter Moon'], slugs = ['new-moon', 'first-quarter-moon', 'full-moon', 'third-quarter-moon'];
      var q = A.SearchMoonQuarter(new Date(year, month, 1)), endM = new Date(year, month + 1, 1);
      while (q && q.time.date < endM) { events.push([q.time.date, names[q.quarter], find('astrology', slugs[q.quarter])]); q = A.NextMoonQuarter(q); }
      events.sort(function (x, y) { return x[0] - y[0]; });
      var list = cal.querySelector('.cal-moon-list'); if (!list) { list = document.createElement('section'); list.className = 'cal-moon-list'; cal.appendChild(list); }
      list.innerHTML = '<h2>The moon in <em>' + MONTHS[month] + '</em></h2><ul>' + events.map(function (e) {
        return '<li><time datetime="' + e[0].toISOString() + '">' + MONTHS[e[0].getMonth()].slice(0, 3) + ' ' + e[0].getDate() + ', ' + clock(e[0]) + '</time>'
          + (e[2] ? '<a href="' + esc(e[2].url) + '">' + esc(e[1]) + '</a>' : '<span>' + esc(e[1]) + '</span>') + '</li>';
      }).join('') + '</ul><p class="small">Times are your local time. The sign shown on each day is the moon’s sign at noon.</p>';
    };
    Promise.all([astro(), data()]).then(function (r) {
      var A = r[0];
      draw(A);
      new MutationObserver(function () { draw(A); }).observe(title, { childList: true, characterData: true, subtree: true });
    }).catch(function () {});
  }

  // ------------------------------------------------------------------ Continue reading (Manuscript home)
  // The last pages a member opened, kept in this browser, shown under the Manuscript's welcome.
  var RECENT = 'mm-recent-v1';
  function rememberPage() {
    var art = document.querySelector('article.pg-entry, article.pg-guide'), h1 = art && art.querySelector('.pg-head h1');
    if (!h1) return;
    var c = h1.cloneNode(true), icon = c.querySelector('.pg-icon'), iconSrc = icon && icon.tagName === 'IMG' ? icon.getAttribute('src') : '', iconText = icon && icon.tagName !== 'IMG' ? icon.textContent : '';
    c.querySelectorAll('.pg-icon').forEach(function (x) { x.remove(); });
    var list = store0(RECENT) || [], item = { u: location.pathname, t: c.textContent.trim(), i: iconSrc ? new URL(iconSrc, location.href).pathname : '', e: iconText };
    list = [item].concat(list.filter(function (x) { return x.u !== item.u; })).slice(0, 8);
    try { localStorage.setItem(RECENT, JSON.stringify(list)); } catch (e) {}
  }
  function continueReading() {
    var hero = document.querySelector('.mm-hero'), list = store0(RECENT) || [];
    if (!hero || !document.querySelector('.shelves, .book-pile') || !list.length || document.querySelector('.continue-reading')) return;
    hero.insertAdjacentHTML('beforeend', '<div class="continue-reading"><p class="eyebrow">Continue reading</p><div class="rels">' + list.slice(0, 5).map(function (x) {
      return '<a class="rel" href="' + esc(x.u) + '">' + (x.i ? '<img class="rel-icon" src="' + esc(x.i) + '" alt="" width="24" height="24" loading="lazy">' : x.e ? '<span class="rel-icon" aria-hidden="true">' + esc(x.e) + '</span>' : '') + '<span>' + esc(x.t) + '</span></a>';
    }).join('') + '</div></div>');
  }

  // ------------------------------------------------------------------ the shelf's Guide Pages and Enchanted Tools books
  // Every guide page and every tool on one page each, grouped by grimoire (they're in each grimoire's intro too).
  function grimoireOrder(keys) {
    return keys.sort(function (a, b) { return a === 'begin-here' ? -1 : b === 'begin-here' ? 1 : (D.gt[a] || a).localeCompare(D.gt[b] || b); });
  }
  function guideIndex(tool) {
    var by = {};
    Object.keys(D.gp || {}).forEach(function (g) { if (D.gp[g].length) by[g] = D.gp[g]; });
    D.p.forEach(function (p, i) { // a grimoire without a Guide Pages list (Begin Here): its guide pages as they are
      var g = D.g[p[0]];
      if (!(g in (D.gp || {})) && p[4] && p[4].split('|').indexOf('Guide') !== -1) (by[g] = by[g] || []).push(i);
    });
    var total = 0;
    var html = grimoireOrder(Object.keys(by)).map(function (g) {
      total += by[g].length;
      return '<section class="index-group"><h2><a href="' + esc(url(g + '/')) + '">' + esc(D.gt[g] || g) + '</a> <small>' + by[g].length + '</small></h2><ul class="index-list">'
        + by[g].map(function (i) { var p = pg(i); return '<li><a href="' + esc(p.url) + '">' + esc(p.title) + '</a></li>'; }).join('') + '</ul></section>';
    }).join('');
    tool.className = 'pg pg-tool pg-index';
    render(tool, '<header class="pg-head tool-head"><nav class="crumbs" aria-label="Breadcrumb"><ol><li><a href="' + esc(ROOT) + '">Manuscript</a></li></ol></nav><h1>Guide <em>pages</em></h1>'
      + '<p class="lede">All ' + total + ' guide pages, by grimoire. Each is also in its grimoire’s introduction.</p></header><div class="index-groups">' + html + '</div>', 'Guide Pages');
  }
  function toolIndex(tool) {
    var html = grimoireOrder(Object.keys(GRIMOIRE_TOOLS)).map(function (g) {
      return '<section class="index-group"><h2><a href="' + esc(url(g + '/')) + '">' + esc(D.gt[g] || g) + '</a></h2><ul class="index-list">'
        + GRIMOIRE_TOOLS[g].map(function (t) { return '<li><a href="' + esc(url(t[0])) + '">' + esc(t[1]) + '</a></li>'; }).join('') + '</ul></section>';
    }).join('');
    tool.className = 'pg pg-tool pg-index';
    render(tool, '<header class="pg-head tool-head"><nav class="crumbs" aria-label="Breadcrumb"><ol><li><a href="' + esc(ROOT) + '">Manuscript</a></li></ol></nav><h1>Enchanted <em>tools</em></h1>'
      + '<p class="lede">Every tool in the Manuscript, by grimoire. Each is also in its grimoire’s introduction.</p></header><div class="index-groups">' + html
      + '<section class="index-group"><h2>Everywhere</h2><ul class="index-list"><li><a href="' + esc(url('notes/')) + '">Your notes</a></li><li><a href="' + esc(url('')) + '">Surprise me (on the Manuscript home)</a></li></ul></section></div>', 'Enchanted Tools');
  }

  // ------------------------------------------------------------------ the next sabbat
  // The Wheel of the Year (northern hemisphere dates; the southern hemisphere keeps each one six months later).
  var SABBATS = [['Imbolc', 'imbolc', 2, 1], ['Ostara', 'ostara', 3, 20], ['Beltane', 'beltane', 5, 1], ['Litha', 'summer-solstice', 6, 21],
    ['Lughnasadh', 'lughnasadh', 8, 1], ['Mabon', 'mabon', 9, 22], ['Samhain', 'samhain', 10, 31], ['Yule', 'yule', 12, 21]];
  /** The next sabbat from a day (today included): {name, page, date, days}. */
  function nextSabbat(day, south) {
    var best = null;
    SABBATS.forEach(function (s) {
      var d = new Date(day.getFullYear(), (s[2] - 1 + (south ? 6 : 0)) % 12, s[3]);
      if (d < day) d.setFullYear(d.getFullYear() + 1);
      var days = Math.round((d - day) / 86400000);
      if (!best || days < best.days) best = { name: s[0], page: find('timing', s[1]), date: d, days: days };
    });
    return best;
  }
  function sabbatHtml(day, south) {
    var s = nextSabbat(day, south);
    if (!s) return '';
    var when = s.days === 0 ? 'is today' : s.days === 1 ? 'is tomorrow' : 'in <strong>' + s.days + '</strong> days';
    var name = s.page ? '<a href="' + esc(s.page.url) + '">' + esc(s.name) + '</a>' : esc(s.name);
    return '<p class="sabbat-count"><span aria-hidden="true">✦</span> ' + name + ' ' + when
      + ' <span class="sabbat-date">' + DAYS[s.date.getDay()].slice(0, 3) + ', ' + MONTHS[s.date.getMonth()] + ' ' + s.date.getDate() + '</span></p>';
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
    timing: [['today/', 'Today'], ['calendar/', 'Calendar'], ['planetary-hours/', 'Planetary Hours']],
    dream: [['dream-decoder/', 'Dream Decoder']],
    card: [['draw-a-card/', 'Draw a Card']],
    rune: [['pull-a-rune/', 'Pull a Rune']]
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
  // The beginner path: the member's time zone goes with "Start", so its emails come at 11:11 their time (lib/path.php).
  document.querySelectorAll('input[data-tz]').forEach(function (i) { try { i.value = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) {} });
  grimoireTools();
  toolLinks();
  rememberPage();
  continueReading();
  calendarMoon();
  if (document.querySelector('.pg-head .crumbs') && /Tarot Spreads/.test(document.querySelector('.pg-head .crumbs').textContent)) data().then(spreadTool).catch(function () {});
  if (/\/manuscript\/(ritual|recipe)\/[^/]+\/?$/.test(location.pathname)) data().then(workingExtras).catch(function () {});
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
    else if (kind === 'rune') runes(tool);
    else if (kind === 'dream') dreamDecoder(tool);
    else if (kind === 'hours') planetaryHours(tool);
    else if (kind === 'guides') guideIndex(tool);
    else if (kind === 'tools') toolIndex(tool);
    else if (kind === 'wheel') { wheel(tool); window.addEventListener('popstate', function () { wheel(tool); }); }
  }).catch(function () { tool.innerHTML = '<p class="lede">The tools couldn’t load. Refresh the page to try again.</p>'; });
})();
