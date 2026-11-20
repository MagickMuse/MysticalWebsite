#!/usr/bin/env python3
"""
Keeps the website's hand-written pages in step (the top-level pages and blog/):

  1. The header and footer come from partials/header.html and partials/footer.html. Each page keeps
     which menu item it marks as the current page (aria-current); links get "../" in blog/.
  2. Each page's styles.css, prefs.js, site.js and cookie-consent.js get ?v= and a fingerprint of the
     file's contents, so browsers fetch a new copy exactly when a file changes.
  3. llms.txt (what the site is, for AI assistants such as ChatGPT, Claude and Perplexity): partials/llms.txt
     followed by every blog post's title, address and description.

Run after editing a partial, a page's header or footer, or a stylesheet or script:
    python3 .github/scripts/build-pages.py
Check without changing anything (fails if a page is out of date; the CI runs this):
    python3 .github/scripts/build-pages.py --check

The Manuscript's preview pages (manuscript/, market/) are not touched: they come from the Manuscript's own template.
"""
import hashlib
import html as htmllib
import os
import re
import sys

ROOT = os.path.normpath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..'))
ASSETS = ['styles.css', 'prefs.js', 'site.js', 'cookie-consent.js']
HEADER = re.compile(r'<header class="site">.*?</header>', re.S)
FOOTER = re.compile(r'<footer class="site">.*?</footer>', re.S)
CURRENT = re.compile(r'<a href="(?:\.\./|/)?([a-z/-]*)"[^>]*aria-current="page"')


def partial(name):
    with open(os.path.join(ROOT, 'partials', name), encoding='utf-8') as f:
        return re.sub(r'^<!--.*?-->\n', '', f.read(), flags=re.S).rstrip('\n')


def pages():
    for folder in ('', 'blog'):
        for name in sorted(os.listdir(os.path.join(ROOT, folder) if folder else ROOT)):
            if name.endswith('.html'):
                yield os.path.join(folder, name) if folder else name


def fingerprint(asset):
    with open(os.path.join(ROOT, asset), 'rb') as f:
        return hashlib.sha256(f.read()).hexdigest()[:10]


def build(page, html, header, footer, versions):
    # 404.html is shown at whatever address was missing (e.g. /blog/old/post), so its links start at the site root.
    prefix = '/' if page == '404.html' else '../' if page.startswith('blog/') else ''
    m = HEADER.search(html)
    if m:
        current = CURRENT.search(m.group(0))
        block = header.replace('{{home}}', prefix or './').replace('{{root}}', prefix)
        if current:
            target = 'href="' + prefix + current.group(1) + '"'
            block = block.replace(target, target + ' aria-current="page"', 1)
        html = html[:m.start()] + block + html[m.end():]
    m = FOOTER.search(html)
    if m:
        html = html[:m.start()] + footer.replace('{{home}}', prefix or './').replace('{{root}}', prefix) + html[m.end():]
    for asset, v in versions.items():
        html = re.sub(r'((?:src|href)="(?:\.\./|/)?' + re.escape(asset) + r')(?:\?v=[^"]*)?"', r'\1?v=' + v + '"', html)
    return html


def llms_txt():
    posts = []
    for page in pages():
        if not page.startswith('blog/') or page == 'blog/index.html':
            continue
        with open(os.path.join(ROOT, page), encoding='utf-8') as f:
            src = f.read()
        title = re.search(r'<title>([^<|]*)', src)
        desc = re.search(r'<meta name="description" content="([^"]*)"', src)
        if title:
            line = '- [' + htmllib.unescape(title.group(1)).strip() + '](https://magickmuse.com/' + page[:-5] + ')'
            posts.append(line + (': ' + htmllib.unescape(desc.group(1)) if desc else ''))
    return partial('llms.txt') + '\n\n## Blog\n\n' + '\n'.join(posts) + '\n'


def main():
    check = '--check' in sys.argv
    header, footer = partial('header.html'), partial('footer.html')
    versions = {a: fingerprint(a) for a in ASSETS}
    stale = []
    for page in pages():
        path = os.path.join(ROOT, page)
        with open(path, encoding='utf-8') as f:
            html = f.read()
        new = build(page, html, header, footer, versions)
        if new != html:
            stale.append(page)
            if not check:
                with open(path, 'w', encoding='utf-8') as f:
                    f.write(new)
    text = llms_txt()
    path = os.path.join(ROOT, 'llms.txt')
    if not os.path.exists(path) or open(path, encoding='utf-8').read() != text:
        stale.append('llms.txt')
        if not check:
            with open(path, 'w', encoding='utf-8') as f:
                f.write(text)
    if check and stale:
        print('Out of date (run python3 .github/scripts/build-pages.py):\n  ' + '\n  '.join(stale))
        sys.exit(1)
    print(('Up to date' if check else 'Updated %d file(s)' % len(stale)) if not check or not stale else '')


if __name__ == '__main__':
    main()
