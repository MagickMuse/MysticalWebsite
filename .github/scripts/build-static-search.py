#!/usr/bin/env python3
"""
Builds manuscript/search/index.json: the search index for the GitHub Pages copy of the Manuscript,
which has no server to search with. One row per page: title, other names, address, grimoire,
the list it sits in, and its emoji. assets/wiki.js searches it in the browser.

Run it after the Manuscript's pages are regenerated:
    python3 .github/scripts/build-static-search.py
"""
import html
import json
import os
import re

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', 'manuscript')
NOT_GRIMOIRES = {'account', 'assets', 'cli', 'join', 'lib', 'login', 'media', 'saved', 'search', 'sneak-peek'}


def text(fragment):
    return html.unescape(re.sub(r'<[^>]+>', '', fragment or '')).strip()


def first(pattern, s):
    m = re.search(pattern, s, re.S)
    return m.group(1) if m else ''


def page(path):
    s = open(path, encoding='utf-8').read()
    article = first(r'(<article class="pg[^"]*".*?</article>)', s)
    if not article:
        return None
    h1 = first(r'<h1>(.*?)</h1>', article)
    icon = text(first(r'<span class="pg-icon[^"]*" aria-hidden="true">(.*?)</span>', h1))
    title = text(re.sub(r'<span class="pg-icon.*?</span>|<img[^>]*>', '', h1))
    aka = text(first(r'Also called</div><div class="prop-val">(.*?)</div></div>', article))
    crumbs = re.findall(r'<li><a [^>]*>(.*?)</a></li>', first(r'(<nav class="crumbs".*?</nav>)', article))
    where = text(crumbs[-1]) if len(crumbs) > 1 else ''
    return title, aka, icon, where


def main():
    grimoires, rows = {}, []
    for g in sorted(os.listdir(ROOT)):
        gdir = os.path.join(ROOT, g)
        if g in NOT_GRIMOIRES or not os.path.isfile(os.path.join(gdir, 'index.html')):
            continue
        info = page(os.path.join(gdir, 'index.html'))
        if not info:
            continue
        grimoires[g] = info[0]
        rows.append([info[0], '', f'{g}/', g, '', info[2]])
        for slug in sorted(os.listdir(gdir)):
            f = os.path.join(gdir, slug, 'index.html')
            if os.path.isfile(f) and (info := page(f)):
                title, aka, icon, where = info
                rows.append([title, aka, f'{g}/{slug}/', g, where, icon])
    out = os.path.join(ROOT, 'search', 'index.json')
    with open(out, 'w', encoding='utf-8') as fh:
        json.dump({'g': grimoires, 'p': rows}, fh, ensure_ascii=False, separators=(',', ':'))
    print(f'{len(rows)} pages from {len(grimoires)} grimoires -> {os.path.relpath(out)} ({os.path.getsize(out) // 1024} KB)')


if __name__ == '__main__':
    main()
