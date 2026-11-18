#!/usr/bin/env python3
"""
One-time move (kept for reference): blog posts into blog/, and addresses without ".html".

  what-is-shadow-work-witchcraft.html  ->  blog/what-is-shadow-work-witchcraft   (file blog/<post>.html)
  grimoires.html                        ->  grimoires                             (file stays grimoires.html)
  index.html                            ->  ./  (the site's root)

Every link in the site's HTML, sitemap.xml, llms.txt and the Manuscript's PHP is rewritten; the old post
files stay behind as small forwarding pages for GitHub Pages (Hostinger answers with 301s from .htaccess).
"""
import base64
import glob
import hashlib
import html
import os
import re
import subprocess

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..')
os.chdir(ROOT)
SITE = 'https://magickmuse.com/'
PAGES = ['index', '404', 'grimoires', 'link', 'links', 'manuscript', 'mastery', 'meetup', 'privacy-policy', 'story']
POSTS = sorted(p[:-5] for p in glob.glob('*.html') if p[:-5] not in PAGES)
NAMES = '|'.join(map(re.escape, PAGES + POSTS))


def new_path(name):
    """Where a root .html file lives now, relative to the site's root ('' for the home page)."""
    if name == 'index':
        return ''
    return ('blog/' + name) if name in POSTS else name


def rewrite_links(text, depth):
    """Links to the site's root .html files, from a file `depth` folders down, without .html (posts under blog/)."""
    up = '../' * depth
    # absolute: https://magickmuse.com/x.html
    text = re.sub(r'https://magickmuse\.com/(' + NAMES + r')\.html', lambda m: SITE + new_path(m.group(1)), text)
    # site-absolute: href="/x.html"
    text = re.sub(r'(["\'(=])/(' + NAMES + r')\.html(?=[#?"\'\s)])', lambda m: m.group(1) + '/' + new_path(m.group(2)), text)
    # relative, resolving to the site's root: ../../x.html from two folders down
    def rel(m):
        target = new_path(m.group(2))
        return m.group(1) + (up + target if (up + target) else './')
    return re.sub(r'(href="|src="|url=|content="0; url=|location\.replace\(\')' + re.escape(up) + r'(' + NAMES + r')\.html(?=[#?"\'])', rel, text)


def prefix_relative(text):
    """A file moved one folder down (into blog/): its relative links get ../ in front."""
    def one(url):
        if re.match(r'^(?:[a-z][a-z0-9+.-]*:|//|/|#|\?)', url) or url == '':
            return url
        url = '../' + url
        url = re.sub(r'^\.\./blog/', '', url)            # another post: same folder
        return '../' if url == '.././' else url
    text = re.sub(r'(\s(?:href|src)=")([^"]*)(")', lambda m: m.group(1) + one(m.group(2)) + m.group(3), text)
    text = re.sub(r'(\ssrcset=")([^"]*)(")', lambda m: m.group(1) + ', '.join(
        ' '.join([one(part.split()[0])] + part.split()[1:]) for part in m.group(2).split(',') if part.strip()) + m.group(3), text)
    return text


def fix_csp(text):
    """Keep each page's Content-Security-Policy hashes in step with its inline scripts."""
    m = re.search(r'(<meta http-equiv="Content-Security-Policy" content=")([^"]+)(")', text)
    if not m:
        return text
    hashes = ["'sha256-" + base64.b64encode(hashlib.sha256(b.encode()).digest()).decode() + "'"
              for a, b in re.findall(r'<script(?![^>]*\bsrc=)([^>]*)>(.*?)</script>', text, re.S) if 'ld+json' not in a]
    csp = re.sub(r"\s*'sha256-[^']+'", '', m.group(2))
    csp = re.sub(r"script-src 'self'", "script-src 'self'" + ''.join(' ' + h for h in hashes), csp, count=1)
    return text[:m.start(2)] + csp + text[m.end(2):]


def main():
    files = [f for f in glob.glob('**/*.html', recursive=True) if not f.startswith('.')]
    for f in files:
        depth = f.count('/')
        s = open(f, encoding='utf-8').read()
        t = rewrite_links(s, depth)
        if depth == 0:
            t = fix_csp(t)
        if t != s:
            open(f, 'w', encoding='utf-8').write(t)
    for f in ['sitemap.xml', 'llms.txt']:
        s = open(f, encoding='utf-8').read()
        open(f, 'w', encoding='utf-8').write(rewrite_links(s, 0))
    # PHP: the Manuscript's links to the website's pages
    for f in ['manuscript/lib/render.php', 'manuscript/lib/shop.php']:
        s = open(f, encoding='utf-8').read()
        s = re.sub(r"site_url\('(" + NAMES + r")\.html'\)", lambda m: "site_url('" + new_path(m.group(1)) + "')", s)
        open(f, 'w', encoding='utf-8').write(s)
    # move the posts, then leave a forwarding page where each was
    os.makedirs('blog', exist_ok=True)
    for p in POSTS:
        subprocess.run(['git', 'mv', f'{p}.html', f'blog/{p}.html'], check=True)
        s = open(f'blog/{p}.html', encoding='utf-8').read()
        open(f'blog/{p}.html', 'w', encoding='utf-8').write(prefix_relative(s))
        title = html.escape(html.unescape(re.search(r'<title>(.*?)</title>', s).group(1)))
        open(f'{p}.html', 'w', encoding='utf-8').write(
            '<!doctype html>\n<html lang="en">\n<head>\n<meta charset="utf-8">\n'
            f'<title>{title}</title>\n<meta name="robots" content="noindex">\n'
            f'<link rel="canonical" href="{SITE}blog/{p}">\n'
            f'<meta http-equiv="refresh" content="0; url=blog/{p}">\n</head>\n'
            f'<body><p>This post has moved: <a href="blog/{p}">{title}</a></p></body>\n</html>\n')
    print(f'{len(POSTS)} posts moved to blog/, links rewritten in {len(files)} HTML files')


if __name__ == '__main__':
    main()
