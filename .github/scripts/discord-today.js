#!/usr/bin/env node
/*
 * Posts Today's Correspondences to the Discord server, through a channel webhook (the DISCORD_WEBHOOK_URL secret).
 * Run every day by .github/workflows/discord-today.yml. Works out the day exactly as the Today page does
 * (dayReading() in manuscript/assets/tools.js), from the public Today data (tools/today.json): names only, and one
 * link, to the Today page, which anyone can open.
 *
 *   node .github/scripts/discord-today.js            post today's (needs DISCORD_WEBHOOK_URL)
 *   node .github/scripts/discord-today.js --dry-run  print the post instead
 *   DAY=2026-10-31 node .github/scripts/discord-today.js --dry-run   any day
 */
'use strict';
const fs = require('fs');
const path = require('path');

const SITE = 'https://magickmuse.com/manuscript/';
const TODAY_PAGE = SITE + 'today';
const ZONE = 'America/Los_Angeles'; // whose "today" it is
const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const SYNODIC = 29.530588853;

/** The data: the live site's copy, else the one in this repository (until the site is on Hostinger). */
async function loadData() {
  try {
    const res = await fetch(SITE + 'tools/today.json', { signal: AbortSignal.timeout(20000) });
    if (res.ok) return await res.json();
  } catch (e) { /* fall back below */ }
  return JSON.parse(fs.readFileSync(path.join(__dirname, '..', '..', 'manuscript', 'tools', 'today.json'), 'utf8'));
}

/** Today's date where ZONE is, as a plain calendar day. */
function localDay() {
  if (process.env.DAY) { const [y, m, d] = process.env.DAY.split('-').map(Number); return { y, m, d }; }
  const parts = Object.fromEntries(new Intl.DateTimeFormat('en-US', { timeZone: ZONE, year: 'numeric', month: 'numeric', day: 'numeric' })
    .formatToParts(new Date()).map((p) => [p.type, p.value]));
  return { y: +parts.year, m: +parts.month, d: +parts.day };
}

function reading(D, day) {
  const kinds = (p) => (p[4] ? p[4].split('|') : []);
  const bySlug = {};
  D.p.forEach((p, i) => { bySlug[D.g[p[0]] + '/' + p[1]] = i; });
  const pg = (i) => ({ i, g: D.g[D.p[i][0]], slug: D.p[i][1], title: D.p[i][2], range: D.p[i][5] });
  const find = (g, slug) => (bySlug[g + '/' + slug] == null ? null : pg(bySlug[g + '/' + slug]));
  const ofKind = (g, kind) => {
    const out = {};
    D.p.forEach((p, i) => { if (D.g[p[0]] === g && kinds(p).includes(kind)) out[p[1]] = pg(i); });
    return out;
  };
  const isTarot = (slug, k) => k.includes('Tarot') && !/^suit-of-/.test(slug);
  const partOf = (g, k, slug) => {
    const has = (x) => k.includes(x);
    if (has('Guide')) return '';
    if (g === 'astrology') return has('Planets') || has('Moon Phases') ? 'when' : '';
    if (g === 'timing') return has('Days of the Week') || has('Holidays') || has('Seasons') || has('Time of Day') ? 'when' : '';
    if (g === 'symbol') return has('Color') ? 'colours' : '';
    if (g === 'card') return isTarot(slug, k) ? 'tarot' : '';
    return { plant: 'plants', mineral: 'stones', oil: 'oils', entity: 'deities', rune: 'runes', recipe: 'recipes', ritual: 'rituals' }[g] || '';
  };
  const part = D.p.map((p) => partOf(D.g[p[0]], kinds(p), p[1]));

  // The moon (moonAge/moonPhase in tools.js)
  const t = Date.UTC(day.y, day.m - 1, day.d, 12) / 1000;
  let age = ((t - 947182440) / 86400) % SYNODIC; if (age < 0) age += SYNODIC;
  const PH = [[1.84566, 'new-moon', 'New Moon'], [5.53699, 'waxing-crescent-moon', 'Waxing Crescent Moon'], [9.22831, 'first-quarter-moon', 'First Quarter Moon'],
    [12.91963, 'waxing-gibbous-moon', 'Waxing Gibbous Moon'], [16.61096, 'full-moon', 'Full Moon'], [20.30228, 'waning-gibbous-moon', 'Waning Gibbous Moon'],
    [23.99361, 'third-quarter-moon', 'Third Quarter Moon'], [27.68493, 'waning-crescent-moon', 'Waning Crescent Moon']];
  const ph = PH.find((x) => age < x[0]) || PH[0];
  const waxing = age < 14.765;

  const md = day.m * 100 + day.d;
  const weekdayIdx = new Date(Date.UTC(day.y, day.m - 1, day.d)).getUTCDay();
  const phases = ofKind('astrology', 'Moon Phases'), planets = ofKind('astrology', 'Planets'), signs = ofKind('astrology', 'Western Zodiac');
  const weekday = find('timing', DAYS[weekdayIdx].toLowerCase());
  const daySlug = MONTHS[day.m - 1].toLowerCase() + '-' + day.d;
  const dayPage = find('timing', daySlug);
  const seasonName = md >= 1221 || md < 320 ? 'winter' : md < 621 ? 'spring' : md < 922 ? 'summer' : 'autumn';
  const seas = find('timing', seasonName);
  const moon = phases[ph[1]] || null;
  let sign = null;
  Object.values(signs).forEach((s) => {
    const m = /^([A-Za-z]+)\s+(\d+)\s*[-–]\s*([A-Za-z]+)\s+(\d+)/.exec(s.range || '');
    if (!m) return;
    const from = (MONTHS.indexOf(m[1]) + 1) * 100 + +m[2], to = (MONTHS.indexOf(m[3]) + 1) * 100 + +m[4];
    if (from <= to ? md >= from && md <= to : md >= from || md <= to) sign = s;
  });
  let fullName = null, ruler = null;
  if (ph[1].startsWith('full')) Object.keys(phases).forEach((k) => {
    if (k.startsWith('full-') && k !== 'full-moon' && new RegExp(MONTHS[day.m - 1] + ' \\(NH\\)').test(phases[k].range || '')) fullName = phases[k];
  });
  if (weekday) Object.values(planets).forEach((p) => { if (!ruler && D.l[weekday.i].includes(p.i)) ruler = p; });

  const src = [moon, fullName, weekday, ruler, sign, seas, dayPage].filter(Boolean).map((p) => p.i);
  const counts = new Map(), set = new Set(src);
  src.forEach((a) => D.l[a].forEach((b) => { if (!set.has(b)) counts.set(b, (counts.get(b) || 0) + 1); }));
  const weight = (i) => (D.n ? D.n[i] : D.l[i].length);
  const best = (want, min, max) => {
    const list = [];
    counts.forEach((n, i) => { if (want(i)) list.push([i, n]); });
    list.sort((a, b) => b[1] - a[1] || weight(b[0]) - weight(a[0]) || D.p[a[0]][2].localeCompare(D.p[b[0]][2]));
    const top = list.filter((x) => x[1] >= min);
    return (top.length ? top : list).slice(0, max).map((x) => D.p[x[0]][2]);
  };
  // "Today favors": intentions tied to two or more of today's influences, most first, then A to Z (as on the Today page).
  const favors = (max) => {
    const list = [];
    counts.forEach((n, i) => { if (n >= 2 && D.g[D.p[i][0]] === 'correspondence') list.push([D.p[i][2], n]); });
    return list.sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0])).slice(0, max).map((x) => x[0]);
  };
  return {
    phase: ph[2], waxing, fullName: fullName && fullName.title, weekday: DAYS[weekdayIdx], ruler: ruler && ruler.title,
    sign: sign && sign.title, season: seas && seas.title,
    favors: favors(4),
    colours: best((i) => part[i] === 'colours', 2, 3), plants: best((i) => part[i] === 'plants', 2, 3),
    stones: best((i) => part[i] === 'stones', 2, 3), oils: best((i) => part[i] === 'oils', 2, 3),
    festivals: (dayPage && D.f[daySlug] || []).map((i) => D.p[i][2]),
  };
}

function message(R, day) {
  const date = DAYS[new Date(Date.UTC(day.y, day.m - 1, day.d)).getUTCDay()] + ', ' + MONTHS[day.m - 1] + ' ' + day.d;
  const lines = [];
  if (R.phase) lines.push('🌙 **' + R.phase + '**' + (R.fullName ? ', the ' + R.fullName : '') + (R.waxing ? ': draw in, build' : ': release, clear'));
  if (R.weekday) lines.push('🪐 **' + R.weekday + '**' + (R.ruler ? ', ruled by ' + R.ruler : ''));
  if (R.sign) lines.push('☀️ The Sun in **' + R.sign + '**');
  if (R.season) lines.push('🍂 **' + R.season + '**');
  if (R.festivals.length) lines.push('✨ Today: **' + R.festivals.join(', ') + '**');
  const work = [['Colors', R.colours], ['Herbs', R.plants], ['Crystals', R.stones], ['Oils', R.oils]].filter((w) => w[1].length);
  return {
    username: 'The Magick Manuscript',
    embeds: [{
      title: 'Today’s Correspondences · ' + date,
      url: TODAY_PAGE,
      color: 0x7a1f2b,
      description: lines.join('\n') + (R.favors.length ? '\n\n**Today favors:** ' + R.favors.join(', ') : ''),
      fields: work.map((w) => ({ name: w[0], value: w[1].join(', '), inline: true })),
      footer: { text: 'The full day, with spells to match: magickmuse.com/manuscript/today' },
    }],
  };
}

/**
 * GitHub runs schedules in UTC, so the workflow is scheduled at both 18:11 and 19:11 UTC and only the one that is
 * 11:11 in ZONE today posts (summer and winter time). Judged by the scheduled time, not the clock, as GitHub
 * sometimes starts a run late.
 */
function scheduledNow() {
  const cron = process.env.SCHEDULE || '';
  if (!cron) return true; // run by hand
  const hour = +cron.split(' ')[1];
  const offset = new Intl.DateTimeFormat('en-US', { timeZone: ZONE, timeZoneName: 'shortOffset' }).formatToParts(new Date())
    .find((p) => p.type === 'timeZoneName').value; // e.g. "GMT-8"
  const hoursBehind = -Number((/GMT([+-]\d+)/.exec(offset) || [0, 0])[1]);
  return (11 + hoursBehind) % 24 === hour;
}

(async () => {
  if (!scheduledNow()) { console.log('Not 11:11 in ' + ZONE + ' at this run; the other daily run posts.'); return; }
  const day = localDay();
  const payload = message(reading(await loadData(), day), day);
  if (process.argv.includes('--dry-run')) { console.log(JSON.stringify(payload, null, 2)); return; }
  const hook = process.env.DISCORD_WEBHOOK_URL;
  if (!hook) { console.error('::error::Add the DISCORD_WEBHOOK_URL repository secret (see the workflow file).'); process.exit(1); }
  const res = await fetch(hook, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
  if (!res.ok) { console.error('::error::Discord answered ' + res.status + ': ' + (await res.text()).slice(0, 300)); process.exit(1); }
  console.log('Posted ' + payload.embeds[0].title);
})();
