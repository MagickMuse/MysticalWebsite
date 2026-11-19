/*
 * The members' tools on the GitHub Pages copy of the Manuscript, which has no server. On the live site PHP does all of
 * this (lib/tools.php); here the browser does it, from tools/data.json (built by .github/scripts/build-static-tools.py):
 * Today, the Spell Builder, the Planet / Zodiac / Moon Phase Wheels, the Enchanted Tools on grimoire pages, and notes.
 * Notes and saved workings are kept in this browser (localStorage), since there's nowhere else to keep them.
 * wiki.js loads this file only on the GitHub Pages copy.
 */
(function () {
  'use strict';
  var searchForm = document.querySelector('.mm-search'), action = searchForm && searchForm.getAttribute('action');
  if (!action || !/search\/$/.test(action)) return; // the live site: PHP does all of this
  var ROOT = new URL(action + '../', location.href).toString(); // …/manuscript/

  // ------------------------------------------------------------------ small helpers
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function url(path) { return ROOT + path; }
  function params() { return new URLSearchParams(location.search); }
  function store(key, fallback) { try { return JSON.parse(localStorage.getItem(key)) || fallback; } catch (e) { return fallback; } }
  function keep(key, value) { try { localStorage.setItem(key, JSON.stringify(value)); } catch (e) {} }
  var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
  var DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

  // ------------------------------------------------------------------ the data
  var dataP = null, D = null, bySlug = {};
  function data() {
    if (!dataP) dataP = fetch(url('tools/data.json')).then(function (r) { if (!r.ok) throw new Error(); return r.json(); }).then(function (d) {
      D = d;
      d.p.forEach(function (p, i) { bySlug[d.g[p[0]] + '/' + p[1]] = i; });
      return d;
    });
    return dataP;
  }
  function pg(i) {
    var p = D.p[i];
    return { i: i, g: D.g[p[0]], slug: p[1], title: p[2], icon: p[3], part: p[4], kinds: p[5] ? p[5].split('|') : [], range: p[6], url: url(D.g[p[0]] + '/' + p[1] + '/') };
  }
  function find(g, slug) { var i = bySlug[g + '/' + slug]; return i == null ? null : pg(i); }
  function ofKind(g, kind) {
    var out = {};
    D.p.forEach(function (p, i) { if (D.g[p[0]] === g && p[5] && p[5].split('|').indexOf(kind) !== -1) out[p[1]] = pg(i); });
    return out;
  }
  function iconHtml(icon, cls) {
    if (!icon) return '';
    if (icon.indexOf('m:') === 0) return '<img class="' + cls + '" src="' + esc(url('media/' + icon.slice(2))) + '" alt="" width="24" height="24" loading="lazy">';
    return '<span class="' + cls + '" aria-hidden="true">' + esc(icon) + '</span>';
  }
  function chip(p) { return '<a class="rel" href="' + esc(p.url) + '">' + iconHtml(p.icon, 'rel-icon') + '<span>' + esc(p.title) + '</span></a>'; }
  function crumbs(g, more) {
    var name = { ritual: 'Ritual Grimoire', astrology: 'Astrology Grimoire', timing: 'Timing Grimoire' }[g] || '';
    var h = '<nav class="crumbs" aria-label="Breadcrumb"><ol><li><a href="' + esc(ROOT) + '">Manuscript</a></li>' + (name ? '<li><a href="' + esc(url(g + '/')) + '">' + name + '</a></li>' : '');
    (more || []).forEach(function (m) { h += '<li><a href="' + esc(m[1]) + '">' + esc(m[0]) + '</a></li>'; });
    return h + '</ol></nav>';
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
      var part = D.p[i][4];
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
  function today(tool) {
    var q = params(), m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(q.get('d') || ''), south = q.get('h') === 's';
    var day = m ? new Date(+m[1], +m[2] - 1, +m[3]) : new Date();
    day.setHours(0, 0, 0, 0);
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

    var cards = infl.map(function (x) {
      var corr = D.l[x[1].i].filter(function (i) { return D.g[D.p[i][0]] === 'correspondence'; })
        .sort(function (a, b) { return D.l[b].length - D.l[a].length; }).slice(0, 6).map(pg);
      var pic = x[0] === 'The moon' ? moonSvg(age) : '<span class="today-icon">' + iconHtml(x[1].icon, 'pg-icon big') + '</span>';
      return '<section class="today-card"><p class="eyebrow">' + esc(x[0]) + '</p>' + pic + '<h2><a href="' + esc(x[1].url) + '">' + esc(x[1].title) + '</a></h2>'
        + (x[2] ? '<p class="small">' + esc(x[2]) + (x[3] ? ' <a class="link" href="' + esc(x[3].url) + '">Read about ' + esc(x[3].title) + '</a>' : '') + '</p>' : '')
        + (corr.length ? '<div class="rels">' + corr.map(chip).join('') + '</div>' : '') + '</section>';
    }).join('');

    var counts = linkedCounts(src), intents = [];
    counts.forEach(function (n, i) { if (n >= 2 && D.g[D.p[i][0]] === 'correspondence') intents.push([pg(i), n]); });
    intents.sort(function (a, b) { return b[1] - a[1] || a[0].title.localeCompare(b[0].title); });
    var favours = intents.slice(0, 12).map(function (x) {
      return '<a class="fav" href="' + esc(url('spell-builder/?i=' + encodeURIComponent(x[0].slug))) + '">' + iconHtml(x[0].icon, 'rel-icon') + esc(x[0].title) + '<small>' + x[1] + ' of today’s influences</small></a>';
    }).join('');
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
    var lede = [moon ? 'a ' + ph[1] : '', ruler ? 'a ' + ruler.title + ' day' : '', sign ? 'the Sun in ' + sign.title : '', seas ? seas.title.toLowerCase() : ''].filter(Boolean).join(', ');
    tool.className = 'pg pg-tool pg-today';
    render(tool, '<header class="pg-head tool-head">' + crumbs('timing') + '<p class="eyebrow labelled">Today in the Manuscript</p>'
      + '<h1>' + DAYS[day.getDay()] + ', <em>' + MONTHS[day.getMonth()] + ' ' + day.getDate() + '</em></h1>'
      + (lede ? '<p class="lede">' + esc(lede.charAt(0).toUpperCase() + lede.slice(1)) + '.</p>' : '')
      + '<p class="small day-nav"><a class="link" href="?d=' + iso(prev) + h + '">‹ ' + short(prev) + '</a><span aria-hidden="true">·</span>'
      + '<a class="link" href="?d=' + iso(day) + (south ? '' : '&h=s') + '">' + (south ? 'Use the northern hemisphere' : 'Use the southern hemisphere') + '</a><span aria-hidden="true">·</span>'
      + '<a class="link" href="?d=' + iso(next) + h + '">' + short(next) + ' ›</a></p></header>'
      + '<div class="today-cards">' + cards + '</div>'
      + (favours ? '<section class="tool-section"><h2>Today <em>favours</em></h2><p class="small">Intentions tied to more than one of today’s influences. Choose one to build a spell for it.</p><div class="favs">' + favours + '</div></section>' : '')
      + (work ? '<section class="tool-section"><h2>Work <em>with today</em></h2><p class="small">Tied to the most of today’s moon, day, sign and season.</p><div class="work-parts">' + work + '</div></section>' : '')
      + '<section class="tool-section two-col">' + (onDay ? '<div><h2>On <em>this day</em></h2>' + onDay + '</div>' : '')
      + (soon.length ? '<div><h2>Coming <em>up</em></h2><ul class="soon">' + soon.join('') + '</ul><p class="small"><a class="link" href="' + esc(url('calendar/')) + '">The full calendar</a></p></div>' : '') + '</section>', 'Today');
  }

  // ------------------------------------------------------------------ Spell Builder
  var WORKINGS = 'mm-workings-v1';
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
          + '<button class="btn" type="submit">Save the working</button><p class="small">On this preview, workings are kept in this browser, and listed below.</p></div></form>'
        : '<p class="lede">Nothing in the Manuscript is tied to this yet. Try a broader intention.</p>';
    }
    tool.className = 'pg pg-tool pg-spell';
    render(tool, '<header class="pg-head tool-head">' + crumbs('ritual') + '<p class="eyebrow labelled">Spell Builder</p><h1>Build <em>a working</em></h1>'
      + '<p class="lede">Choose what the spell is for. The Manuscript gathers the days, colours, herbs, stones, oils, deities and rituals tied to it.</p></header>'
      + (chips ? '<div class="intents">' + chips + '</div>' : '') + box + starters + result + workingsHtml(), 'Spell Builder');
    var save = tool.querySelector('[data-spell-save]');
    if (save) save.addEventListener('submit', function (e) {
      e.preventDefault();
      var picks = [].map.call(save.querySelectorAll('input[name="pick"]:checked'), function (c) { var p = pg(+c.value); return [p.g, p.slug]; });
      var list = store(WORKINGS, []);
      list.unshift({ id: Date.now(), title: save.title.value.trim() || 'My working', intents: slugs, items: picks, notes: save.notes.value.trim(), created: iso(new Date()) });
      keep(WORKINGS, list.slice(0, 300));
      spell(tool);
      var w = tool.querySelector('.workings'); if (w) { w.querySelector('details').open = true; w.scrollIntoView({ behavior: 'smooth' }); }
    });
  }
  function workingsHtml() {
    var list = store(WORKINGS, []);
    if (!list.length) return '';
    return '<section class="tool-section workings"><h2>Your <em>workings</em></h2><p class="small">Kept in this browser (on the live site they’re under ★ Saved).</p>' + list.map(function (w) {
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
    keep(WORKINGS, store(WORKINGS, []).filter(function (w) { return String(w.id) !== b.getAttribute('data-del-working'); }));
    var t = document.querySelector('[data-static-tool]'); if (t) spell(t);
  });

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

  // ------------------------------------------------------------------ notes (kept in this browser)
  var NOTES = 'mm-notes-v1';
  function notes() { return store(NOTES, []); }
  function pageTitle() {
    var h1 = document.querySelector('.mm-main h1'), t = document.title.split(' | ')[0];
    if (h1) { var c = h1.cloneNode(true); c.querySelectorAll('.pg-icon').forEach(function (x) { x.remove(); }); t = c.textContent.trim() || t; }
    return t.replace(/\s+/g, ' ') || 'A note';
  }
  function noteDialog() {
    var path = location.pathname, mine = notes().filter(function (n) { return n.page === path; })[0], def = pageTitle();
    var d = document.createElement('dialog');
    d.className = 'note-pop'; d.id = 'note-pop'; d.setAttribute('aria-labelledby', 'note-pop-h');
    d.innerHTML = '<form class="note-form"><div class="note-top"><p class="eyebrow" id="note-pop-h"><span aria-hidden="true">✎</span> Your note</p>'
      + '<button class="note-x" type="button" data-note-close aria-label="Close">&times;</button></div>'
      + '<label class="sr-only" for="note-title">Note title</label><input class="note-title" id="note-title" maxlength="160" placeholder="Name this note">'
      + '<label class="sr-only" for="note-body">Note</label><textarea class="note-body" id="note-body" rows="9" maxlength="20000" placeholder="Write in the margin… only you can see this."></textarea>'
      + '<div class="note-foot"><button class="btn" type="submit">Save</button><span class="notes-status" role="status"></span>'
      + '<span class="note-links"><button class="link as-button" type="button" data-note-new>New note</button><a class="link" href="' + esc(url('notes/')) + '">All notes</a></span></div>'
      + '<p class="small note-local">On this preview, notes are kept in this browser only.</p></form>';
    document.body.appendChild(d);
    var title = d.querySelector('.note-title'), body = d.querySelector('.note-body'), status = d.querySelector('.notes-status'), cur = null, timer;
    function load(n) { cur = n; title.value = n ? n.title : def; body.value = n ? n.body : ''; status.textContent = ''; }
    function save() {
      clearTimeout(timer);
      if (!cur && !body.value.trim()) return;
      var list = notes(), now = new Date().toISOString();
      if (!cur) { cur = { id: Date.now(), page: path, pageTitle: def, created: now }; list.unshift(cur); }
      cur.title = title.value.trim() || 'Untitled note'; cur.body = body.value; cur.updated = now;
      list = list.map(function (n) { return n.id === cur.id ? cur : n; });
      if (!list.some(function (n) { return n.id === cur.id; })) list.unshift(cur);
      keep(NOTES, list); status.textContent = 'Saved';
    }
    function later() { status.textContent = ''; clearTimeout(timer); timer = setTimeout(save, 700); }
    function close() { save(); d.close(); if (document.querySelector('[data-static-tool="notes"]')) notesPage(document.querySelector('[data-static-tool="notes"]')); }
    title.addEventListener('input', later); body.addEventListener('input', later);
    d.querySelector('form').addEventListener('submit', function (e) { e.preventDefault(); save(); });
    d.querySelector('[data-note-close]').addEventListener('click', close);
    d.addEventListener('cancel', function (e) { e.preventDefault(); close(); });
    d.addEventListener('click', function (e) { if (e.target === d) close(); });
    d.querySelector('[data-note-new]').addEventListener('click', function () { save(); load(null); title.select(); title.focus(); });
    window.addEventListener('pagehide', save);
    load(mine);
    return { open: function (n) { if (n !== undefined) load(n); if (!d.open) d.showModal(); body.focus(); } };
  }
  function notesPage(tool) {
    var list = notes().sort(function (a, b) { return (b.updated || '').localeCompare(a.updated || ''); });
    tool.className = 'saved notes-page';
    render(tool, '<p class="eyebrow labelled">Your Manuscript</p><h1>Your <em>notes</em></h1>'
      + '<p class="small">Only you can see these. Each one opens to read, rename or change it. On this preview they’re kept in this browser.</p>'
      + (list.length ? '<ul class="note-cards">' + list.map(function (n) {
        var snip = (n.body || '').replace(/\s+/g, ' ').trim();
        return '<li class="note-card"><a class="note-open" href="#note-' + n.id + '" data-local-note="' + n.id + '"><span class="note-card-title">' + esc(n.title) + '</span>'
          + '<span class="note-card-snip">' + esc(snip.length > 190 ? snip.slice(0, 189) + '…' : snip) + '</span></a>'
          + '<p class="note-card-meta">' + esc(new Date(n.updated || n.created).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }))
          + (n.page && n.page !== location.pathname ? ' · from <a href="' + esc(n.page) + '">' + esc(n.pageTitle || 'this page') + '</a>' : '')
          + ' · <button class="link as-button" type="button" data-del-note="' + n.id + '">Delete</button></p></li>';
      }).join('') + '</ul>' : '<p class="lede">No notes yet. Tap <strong>✎ Notes</strong> at the top of any page to write one.</p>'), 'Your notes');
  }

  // ------------------------------------------------------------------ on every page of the copy
  var GRIMOIRE_TOOLS = {
    ritual: [['spell-builder/', 'Spell Builder']],
    astrology: [['wheel/?of=planets', 'Planet Wheel'], ['wheel/?of=signs', 'Zodiac Wheel'], ['wheel/?of=moon', 'Moon Phase Wheel']],
    timing: [['today/', 'Today'], ['calendar/', 'Calendar']]
  };
  function header() {
    var bar = document.querySelector('.mm-bar'), acct = bar && bar.querySelector('.mm-acct');
    if (!bar || bar.querySelector('.mm-mine')) return;
    if (acct) {
      var saved = acct.querySelector('a[href$="saved/"]');
      if (saved) { var dot = saved.nextElementSibling; saved.remove(); if (dot && dot.classList.contains('dot')) dot.remove(); }
    }
    var mine = document.createElement('p');
    mine.className = 'mm-mine';
    mine.innerHTML = '<a href="' + esc(url('saved/')) + '"><span aria-hidden="true">★</span> Saved</a><span class="dot" aria-hidden="true">·</span>'
      + '<a href="' + esc(url('notes/')) + '" data-note-open aria-haspopup="dialog"><span aria-hidden="true">✎</span> Notes</a>';
    bar.insertBefore(mine, bar.firstChild);
  }
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

  header();
  grimoireTools();
  toolLinks();
  var pop = null;
  document.addEventListener('click', function (e) {
    var open = e.target.closest('[data-note-open]');
    if (open) { e.preventDefault(); pop = pop || noteDialog(); pop.open(); return; }
    var card = e.target.closest('[data-local-note]');
    if (card) {
      e.preventDefault();
      var n = notes().filter(function (x) { return String(x.id) === card.getAttribute('data-local-note'); })[0];
      if (n) { pop = pop || noteDialog(); pop.open(n); }
      return;
    }
    var del = e.target.closest('[data-del-note]');
    if (del && confirm('Delete this note? This can’t be undone.')) {
      keep(NOTES, notes().filter(function (x) { return String(x.id) !== del.getAttribute('data-del-note'); }));
      notesPage(document.querySelector('[data-static-tool="notes"]'));
    }
  });
  var tool = document.querySelector('[data-static-tool]');
  if (!tool) return;
  var kind = tool.getAttribute('data-static-tool');
  if (kind === 'notes') { notesPage(tool); return; }
  data().then(function () {
    if (kind === 'today') today(tool);
    else if (kind === 'spell') spell(tool);
    else if (kind === 'wheel') { wheel(tool); window.addEventListener('popstate', function () { wheel(tool); }); }
  }).catch(function () { tool.innerHTML = '<p class="lede">The tools couldn’t load. Refresh the page to try again.</p>'; });
})();
