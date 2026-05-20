#!/usr/bin/env python3
"""
reescribir-enlaces-internos.py

Reescribe los enlaces de una copia estática generada por el plugin
"Copia Estática Local" para que, en vez de apuntar a las URLs públicas
del blog (lanzatu.blog, blogpocket.com), apunten a los archivos HTML
locales correspondientes.

Para enlaces internos que NO se correspondan con un HTML local
(categorías, tags, archivos por fecha, etc.) deja la URL intacta y lo
reporta al final.

Uso:
    python3 reescribir-enlaces-internos.py /ruta/a/copia-estatica-html
    python3 reescribir-enlaces-internos.py /ruta/a/copia-estatica-html dominio1.com dominio2.com

Si no se pasan dominios, usa los predeterminados.
"""

import os
import re
import sys
from pathlib import Path
from urllib.parse import urlparse

# Dominios considerados "internos" (formato sin www, minúsculas)
DEFAULT_DOMAINS = [
    'lanzatu.blog',
    'blogpocket.com',
]

# Segmentos de URL que indican "esto no es contenido propio del blog"
# (paths internos de WordPress que no se exportan a HTML estático)
SKIP_PATH_SEGMENTS = {
    'wp-content', 'wp-admin', 'wp-includes', 'wp-json',
    'feed', 'comments', 'trackback', 'embed',
}


def normalize_host(host):
    """Pasa a minúsculas y elimina el prefijo 'www.' si lo tiene."""
    host = host.lower()
    if host.startswith('www.'):
        host = host[4:]
    return host


def build_slug_index(root):
    """Indexa todos los .html (excepto los index.html) como slug -> Path relativo.

    Si hay conflicto (mismo slug entre post y página), prefiere el post
    (que vive en YYYY/MM/) sobre la página (que vive en pages/).
    """
    index = {}
    for html_file in root.rglob('*.html'):
        if html_file.name == 'index.html':
            continue
        rel = html_file.relative_to(root)
        slug = html_file.stem
        if slug not in index:
            index[slug] = rel
        else:
            existing = index[slug]
            existing_is_page = existing.parts[0] == 'pages'
            new_is_page = rel.parts[0] == 'pages'
            # Si el existente es una página y el nuevo es un post, prefiere post
            if existing_is_page and not new_is_page:
                index[slug] = rel
    return index


def compute_relative_path(from_relpath, to_relpath):
    """Ruta relativa desde un HTML a otro (ambos relativos a la raíz)."""
    from_dir = from_relpath.parent
    return os.path.relpath(str(to_relpath), str(from_dir)).replace(os.sep, '/')


def find_target(url, slug_index):
    """Para una URL, devuelve (ruta_destino, fragmento) o (None, None)."""
    parsed = urlparse(url)
    path = parsed.path.strip('/')
    if not path:
        return None, None  # URL a la raíz del dominio

    segments = path.split('/')

    # Saltar URLs claramente "no contenido" (categoría, tag, feed, wp-*, etc.)
    for seg in segments:
        if seg.lower() in SKIP_PATH_SEGMENTS:
            return None, None

    # 1) Intento principal: el último segmento es el slug
    slug = segments[-1]
    if slug in slug_index:
        return slug_index[slug], parsed.fragment

    # 2) Fallback: el último segmento no es un slug conocido. Probar con el
    # penúltimo. Cubre URLs de attachment tipo /post-slug/attachment-slug/
    # típicas de WordPress.
    if len(segments) >= 2 and segments[-2] in slug_index:
        return slug_index[segments[-2]], parsed.fragment

    return None, None


def process_html(html_path, root, slug_index, internal_hosts, stats):
    """Reescribe los enlaces internos en un fichero HTML."""
    try:
        with open(html_path, 'r', encoding='utf-8') as f:
            content = f.read()
    except UnicodeDecodeError:
        # Si por algún motivo no es UTF-8, leerlo con tolerancia
        with open(html_path, 'r', encoding='utf-8', errors='replace') as f:
            content = f.read()

    rel_html = html_path.relative_to(root)

    # href="..." o href='...' con URL absoluta http/https
    pattern = re.compile(r'''(href\s*=\s*["'])(https?://[^"']+)(["'])''', re.IGNORECASE)

    def replace_match(m):
        prefix, url, suffix = m.group(1), m.group(2), m.group(3)
        parsed = urlparse(url)
        host = normalize_host(parsed.netloc)

        if host not in internal_hosts:
            return m.group(0)  # No es un dominio interno

        target, fragment = find_target(url, slug_index)
        if target is None:
            stats['not_found'] += 1
            stats['not_found_urls'].add(url)
            return m.group(0)

        rel = compute_relative_path(rel_html, target)
        if fragment:
            rel = f"{rel}#{fragment}"

        stats['rewritten'] += 1
        return f"{prefix}{rel}{suffix}"

    new_content = pattern.sub(replace_match, content)

    if new_content != content:
        with open(html_path, 'w', encoding='utf-8') as f:
            f.write(new_content)
        stats['modified_files'] += 1


def main():
    args = sys.argv[1:]
    if not args:
        print(__doc__)
        sys.exit(1)

    root = Path(args[0]).resolve()
    if not root.is_dir():
        print(f"ERROR: no es un directorio: {root}")
        sys.exit(1)

    domains = args[1:] if len(args) > 1 else DEFAULT_DOMAINS
    internal_hosts = {normalize_host(d) for d in domains}

    print(f"Carpeta:           {root}")
    print(f"Dominios internos: {sorted(internal_hosts)}")
    print()

    print("Indexando posts y páginas...")
    slug_index = build_slug_index(root)
    print(f"  {len(slug_index)} archivos indexados (posts + páginas)")
    print()

    stats = {
        'rewritten': 0,
        'modified_files': 0,
        'not_found': 0,
        'not_found_urls': set(),
    }

    print("Procesando HTMLs...")
    total = 0
    for html_file in root.rglob('*.html'):
        total += 1
        process_html(html_file, root, slug_index, internal_hosts, stats)
        if total % 100 == 0:
            print(f"  [{total} archivos procesados]")

    print()
    print("=" * 55)
    print("  REESCRITURA TERMINADA")
    print("=" * 55)
    print(f"Archivos HTML revisados:       {total}")
    print(f"Archivos HTML modificados:     {stats['modified_files']}")
    print(f"Enlaces internos reescritos:   {stats['rewritten']}")
    print(f"Enlaces internos NO resueltos: {stats['not_found']}")
    print()

    if stats['not_found'] > 0:
        print("Algunos enlaces no se reescribieron porque apuntan a contenido")
        print("que no existe como HTML en la copia (categorías, tags, archivos")
        print("por fecha, etc.). Quedan apuntando al blog original.")
        print()
        print("Ejemplos de las primeras 15 URLs no resueltas:")
        for url in sorted(stats['not_found_urls'])[:15]:
            print(f"  {url}")


if __name__ == '__main__':
    main()
