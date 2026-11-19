#!/usr/bin/env python3
"""
The members' tools on the GitHub Pages copy of the Manuscript, which has no server (assets/tools.js runs them in the
browser, here and on the live site, where lib/tools.php serves the same data from the database):

  1. manuscript/tools/data.json: every page (grimoire, address, title, icon, its kinds, date range and how many
     words it has), every connection, each calendar day's festivals, and the tarot cards' pictures and keywords and every connection between pages, both ways, plus each calendar day's festivals.
     The same shape lib/tools.php serves on the live site (tools_data()); the tools themselves are only in tools.js.
  2. The tools' own pages: manuscript/today/, spell-builder/, wheel/ and notes/ (shells that tools.js fills in).

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
KINDS = {'Planets', 'Moon Phases', 'Western Zodiac', 'Days of the Week', 'Days of the Year', 'Holidays', 'Seasons', 'Time of Day', 'Color', 'Elder Futhark Runes'}
SAFETY_FIELDS = ['Safety & Uses', 'Pregnancy', 'Drug Interactions', 'Notes/Warnings', 'Water Safe?', 'Sun Safe?']
SAFETY_GRIMOIRES = ['plant', 'oil', 'mineral', 'material']
MONTHS = 'january|february|march|april|may|june|july|august|september|october|november|december'


def text(s):
    return html.unescape(re.sub(r'<[^>]+>', '', s)).strip()


def main():
    pages, index, rels, covers, safety = [], {}, [], {}, {}
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
            m = re.search(r'<img class="pg-icon big" src="[^"]*/(media|assets)/([^"]+)"', h1.group(1))
            if m:
                icon = ('m:' if m.group(1) == 'media' else 'a:') + m.group(2)
            if g == 'entity':
                icon = ''  # the Entity Grimoire shows no icons
            title = text(re.sub(r'<span class="pg-icon[^"]*"[^>]*>[^<]*</span>', '', h1.group(1)))
            props, types, rng, fields = {}, set(), '', []
            for r in ROW.finditer(s):
                name = html.unescape(r.group(2)).strip()
                props[name] = True
                if r.group(1):
                    for i, l in enumerate(LINK.finditer(r.group(3))):
                        rels.append((len(pages), name, l.group(1), l.group(2)))
                elif name in ('Type', 'Category'):
                    types |= {text(v) for v in PILL.findall(r.group(3))}
                if not r.group(1) and name in SAFETY_FIELDS and g in SAFETY_GRIMOIRES:  # word for word, for the safety notes
                    fields.append([name, [text(v) for v in PILL.findall(r.group(3))] or [text(r.group(3).replace('<br>', '\n'))]])
                if not r.group(1) and name == 'Date Range':
                    vals = [text(v) for v in PILL.findall(r.group(3))] or [text(r.group(3))]
                    rng = ' '.join(vals)
            if fields:
                safety[len(pages)] = fields
            index[(g, slug)] = len(pages)
            kinds = sorted(types & KINDS) + (['Tarot'] if 'Upright Correspondences' in props else [])
            if 'Upright Correspondences' in props:  # a tarot card: its picture, for Draw a Card
                cv = re.search(r'class="pg-cover[^"]*">.*?<img src="[^"]*/(media|assets)/([^"]+)"', s, re.S)
                covers[len(pages)] = (('m:' if cv.group(1) == 'media' else 'a:') + cv.group(2)) if cv else ''
            body = re.search(r'<div class="mm-prose">(.*?)</div></article>', s, re.S)
            words = len(text(body.group(1).replace('<', ' <')).split()) if body else 0  # how much the page has to read
            pages.append([GRIMOIRES.index(g), slug, title, icon, '|'.join(kinds), rng, words])
    links = [set() for _ in pages]
    festivals = {}
    tarot = {i: [i, c, [], []] for i, c in covers.items()}
    for a, prop, g, slug in rels:
        b = index.get((g, slug))
        if b is None or b == a:
            continue
        links[a].add(b)
        links[b].add(a)
        if a in tarot and prop in ('Upright Correspondences', 'Reversed Correspondences'):
            tarot[a][2 if prop.startswith('Upright') else 3].append(b)
        if prop == 'Related Timing' and re.match(rf'^({MONTHS})-\d+$', pages[a][1]) and GRIMOIRES[pages[a][0]] == 'timing':
            festivals.setdefault(pages[a][1], []).append(b)
    out = {'g': GRIMOIRES, 'p': pages, 'l': [sorted(x) for x in links], 'f': festivals, 't': list(tarot.values()), 'w': safety}
    os.makedirs(os.path.join(ROOT, 'tools'), exist_ok=True)
    with open(os.path.join(ROOT, 'tools', 'data.json'), 'w', encoding='utf-8') as fh:
        json.dump(out, fh, ensure_ascii=False, separators=(',', ':'))
    print(f'tools/data.json: {len(pages)} pages, {sum(len(x) for x in links) // 2} connections')

    # The tools' pages: the Saved page's frame (header, footer, styles) around a shell tools.js fills in.
    frame = open(os.path.join(ROOT, 'saved', 'index.html'), encoding='utf-8').read()
    shells = {
        'today': ('Today', 'today'),
        'spell-builder': ('Spell Builder', 'spell'),
        'wheel': ('Correspondence Wheel', 'wheel'),
        'draw-a-card': ('Draw a Card', 'tarot'),
        'pull-a-rune': ('Pull a Rune', 'rune'),
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
