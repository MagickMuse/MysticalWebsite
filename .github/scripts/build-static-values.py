#!/usr/bin/env python3
"""
For the GitHub Pages copy of the Manuscript (no server to filter with):
  1. manuscript/<grimoire>/values.json: for each list on the grimoire page, which entries have each
     property value ({"lists": {"plants": {"slugs": [...], "props": {"type": {"Flower": [0, 5, ...]}}}}}).
  2. On every entry page, short property values (Type: Flower) become links to the grimoire page
     filtered to that value (?f_type=Flower#list-plants), as on the PHP site.
assets/wiki.js reads values.json and filters the list in the browser.

Run after the Manuscript's pages are regenerated:  python3 .github/scripts/build-static-values.py
"""
import html
import json
import os
import re
import urllib.parse

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', 'manuscript')
ROW = re.compile(r'(<div class="prop-row"><div class="prop-name"><span class="prop-glyph[^"]*" aria-hidden="true">(?:◇)?</span>)([^<]+)(</div><div class="prop-val">)(.*?)(</div></div>)', re.S)
PILL = re.compile(r'<(span|a) class="pill"[^>]*>(.*?)</\1>', re.S)


def slugify(s):
    """Same as the PHP slugify(): letters and digits, everything else a dash (property names are plain ASCII)."""
    s = re.sub(r'[^A-Za-z0-9]+', '-', s.strip()).strip('-').lower()
    return s[:80] or 'page'


def main():
    grimoires = sorted(g for g in os.listdir(ROOT) if os.path.isfile(os.path.join(ROOT, g, 'index.html')))
    pages_linked = 0
    for g in grimoires:
        gpage = open(os.path.join(ROOT, g, 'index.html'), encoding='utf-8').read()
        lists = re.findall(r'<section class="collection" id="list-([^"]+)">', gpage)
        if not lists:
            continue
        several = len(lists) > 1
        out = {'lists': {}}
        for i, key in enumerate(lists):
            start = gpage.index(f'<section class="collection" id="list-{key}">')
            end = gpage.find('<section class="collection" id="list-', start + 10)
            section = gpage[start:end if end > 0 else len(gpage)]
            slugs = list(dict.fromkeys(re.findall(rf'href="[^"]*/manuscript/{re.escape(g)}/([^/"]+)/"', section)))
            props = {}
            for n, slug in enumerate(slugs):
                path = os.path.join(ROOT, g, slug, 'index.html')
                if not os.path.isfile(path):
                    continue
                s = open(path, encoding='utf-8').read()

                def link(row):
                    name = html.unescape(row.group(2)).strip()
                    pslug = slugify(name)

                    def one(p):
                        value = html.unescape(re.sub(r'<[^>]+>', '', p.group(2))).strip()
                        props.setdefault(pslug, {}).setdefault(value, [])
                        if not props[pslug][value] or props[pslug][value][-1] != n:
                            props[pslug][value].append(n)
                        query = urllib.parse.urlencode(({'list': key} if several else {}) | {f'f_{pslug}': value})
                        return (f'<a class="pill" href="../?{html.escape(query)}#list-{key}" '
                                f'title="All {html.escape(name)}: {html.escape(value)}">{p.group(2)}</a>')
                    return row.group(1) + row.group(2) + row.group(3) + PILL.sub(one, row.group(4)) + row.group(5)

                s2 = ROW.sub(link, s)
                if s2 != s:
                    open(path, 'w', encoding='utf-8').write(s2)
                    pages_linked += 1
            out['lists'][key] = {'slugs': slugs, 'props': props}
        with open(os.path.join(ROOT, g, 'values.json'), 'w', encoding='utf-8') as fh:
            json.dump(out, fh, ensure_ascii=False, separators=(',', ':'))
        print(f'{g}: {len(lists)} list(s), values.json {os.path.getsize(os.path.join(ROOT, g, "values.json")) // 1024} KB')
    print(f'entry pages with linked values: {pages_linked}')


if __name__ == '__main__':
    main()
