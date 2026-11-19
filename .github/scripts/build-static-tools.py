#!/usr/bin/env python3
"""
The members' tools on the GitHub Pages copy of the Manuscript, which has no server (the PHP site builds them in
manuscript/lib/tools.php; assets/tools-static.js does the same in the browser here):

  1. manuscript/tools/data.json: every page (grimoire, address, title, icon, which part of a spell it can be,
     its kinds and date range) and every connection between pages, both ways, plus each calendar day's festivals.
  2. The tools' own pages: manuscript/today/, spell-builder/, wheel/ and notes/ (shells that tools-static.js fills in).

Run after the Manuscript's pages are regenerated:  python3 .github/scripts/build-static-tools.py
"""
import html
import json
import os
import re

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', 'manuscript')
GRIMOIRES = ['animal', 'astrology', 'begin-here', 'card', 'correspondence', 'dream', 'entity', 'material', 'mineral',
             'oil', 'plant', 'recipe', 'ritual', 'rune', 'symbol', 'timing']
ROW = re.compile(r'<div class="prop-row( rel-row)?"[^>]*><div class="prop-name"><span class="prop-glyph[^"]*" aria-hidden="true">(?:◇)?</span>'
                 r'([^<]+)(?:<small>\d+</small>)?</div><div class="prop-val">(.*?)</div></div>(?=<div class="prop-row|</section>|<div class="gallery)', re.S)
LINK = re.compile(r'href="(?:\.\./)+manuscript/([a-z-]+)/([^/"#?]+)/"')
PILL = re.compile(r'<(?:span|a) class="pill"[^>]*>(.*?)</(?:span|a)>', re.S)
# Kinds of page the tools look for (an entry's Type)
KINDS = {'Planets', 'Moon Phases', 'Western Zodiac', 'Days of the Week', 'Days of the Year', 'Holidays', 'Seasons', 'Time of Day', 'Color'}
MONTHS = 'january|february|march|april|may|june|july|august|september|october|november|december'


def text(s):
    return html.unescape(re.sub(r'<[^>]+>', '', s)).strip()


def spell_part(g, types, props):
    """Which part of a working a page can be (the same rules as spell_part() in lib/tools.php)."""
    if g == 'astrology':
        return 'when' if types & {'Planets', 'Moon Phases'} else ''
    if g == 'timing':
        return 'when' if types & {'Days of the Week', 'Holidays', 'Seasons', 'Time of Day'} else ''
    if g == 'symbol':
        return 'colours' if 'Color' in types else ''
    if g == 'card':
        return 'tarot' if ('Suit' in props or 'Upright Correspondences' in props) else ''
    return {'plant': 'plants', 'mineral': 'stones', 'oil': 'oils', 'entity': 'deities', 'rune': 'runes',
            'recipe': 'recipes', 'ritual': 'rituals'}.get(g, '')


def main():
    pages, index, rels = [], {}, []
    for g in GRIMOIRES:
        gdir = os.path.join(ROOT, g)
        if not os.path.isdir(gdir):
            continue
        for slug in sorted(os.listdir(gdir)):
            f = os.path.join(gdir, slug, 'index.html')
            if not os.path.isfile(f):
                continue
            s = open(f, encoding='utf-8').read()
            h1 = re.search(r'<h1>(.*?)</h1>', s, re.S)
            if not h1 or 'props-panel' not in s:
                continue
            icon = ''
            m = re.search(r'<span class="pg-icon big" aria-hidden="true">([^<]*)</span>', h1.group(1))
            if m:
                icon = html.unescape(m.group(1))
            m = re.search(r'<img class="pg-icon big" src="[^"]*/media/([^"]+)"', h1.group(1))
            if m:
                icon = 'm:' + m.group(1)
            if g == 'entity':
                icon = ''  # the Entity Grimoire shows no icons
            title = text(re.sub(r'<span class="pg-icon[^"]*"[^>]*>[^<]*</span>', '', h1.group(1)))
            props, types, rng = {}, set(), ''
            for r in ROW.finditer(s):
                name = html.unescape(r.group(2)).strip()
                props[name] = True
                if r.group(1):
                    for i, l in enumerate(LINK.finditer(r.group(3))):
                        rels.append((len(pages), name, l.group(1), l.group(2)))
                elif name in ('Type', 'Category'):
                    types |= {text(v) for v in PILL.findall(r.group(3))}
                elif name == 'Date Range':
                    vals = [text(v) for v in PILL.findall(r.group(3))] or [text(r.group(3))]
                    rng = ' '.join(vals)
            index[(g, slug)] = len(pages)
            pages.append([GRIMOIRES.index(g), slug, title, icon, spell_part(g, types, props),
                          '|'.join(sorted(types & KINDS)), rng])
    links = [set() for _ in pages]
    festivals = {}
    for a, prop, g, slug in rels:
        b = index.get((g, slug))
        if b is None or b == a:
            continue
        links[a].add(b)
        links[b].add(a)
        if prop == 'Related Timing' and re.match(rf'^({MONTHS})-\d+$', pages[a][1]) and GRIMOIRES[pages[a][0]] == 'timing':
            festivals.setdefault(pages[a][1], []).append(b)
    out = {'g': GRIMOIRES, 'p': pages, 'l': [sorted(x) for x in links], 'f': festivals}
    os.makedirs(os.path.join(ROOT, 'tools'), exist_ok=True)
    with open(os.path.join(ROOT, 'tools', 'data.json'), 'w', encoding='utf-8') as fh:
        json.dump(out, fh, ensure_ascii=False, separators=(',', ':'))
    print(f'tools/data.json: {len(pages)} pages, {sum(len(x) for x in links) // 2} connections')

    # The tools' pages: the Saved page's frame (header, footer, styles) around a shell tools-static.js fills in.
    frame = open(os.path.join(ROOT, 'saved', 'index.html'), encoding='utf-8').read()
    shells = {
        'today': ('Today', 'today'),
        'spell-builder': ('Spell Builder', 'spell'),
        'wheel': ('Correspondence Wheel', 'wheel'),
        'notes': ('Your notes', 'notes'),
    }
    for folder, (title, tool) in shells.items():
        page = re.sub(r'<title>[^<]*</title>', f'<title>{title} | The Magick Manuscript</title>', frame, count=1)
        page = re.sub(r'<main id="main" class="wrap mm-main">.*?</main>',
                      f'<main id="main" class="wrap mm-main">\n<article class="pg pg-tool" data-static-tool="{tool}"><p class="lede">Opening the {title}…</p></article>\n</main>',
                      page, count=1, flags=re.S)
        os.makedirs(os.path.join(ROOT, folder), exist_ok=True)
        with open(os.path.join(ROOT, folder, 'index.html'), 'w', encoding='utf-8') as fh:
            fh.write(page)
    print('tool pages:', ', '.join(shells))


if __name__ == '__main__':
    main()
