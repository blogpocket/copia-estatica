#!/usr/bin/env python3
"""
descargar-pdfs-y-reescribir.py

Encuentra todos los enlaces a PDFs hospedados en dominios internos (lanzatu.blog,
blogpocket.com) dentro de una copia estática, los descarga a una carpeta /pdf
dentro de la copia, y reescribe las referencias en los HTML para que apunten a
la carpeta local con rutas relativas.

Diseñado para ser idempotente: si re-ejecutas el script tras descargar
manualmente los PDFs que fallaron, completará la reescritura sin descargar
de nuevo los que ya están.

Uso:
    python3 descargar-pdfs-y-reescribir.py /ruta/a/copia-estatica-html
    python3 descargar-pdfs-y-reescribir.py /ruta/a/copia-estatica-html --yes
    python3 descargar-pdfs-y-reescribir.py /ruta/a/copia-estatica-html --dry-run

Flags:
    --yes / -y    Saltar la confirmación interactiva.
    --dry-run     Solo análisis: listar los PDFs detectados sin descargar ni
                  modificar nada.
"""

import os
import re
import ssl
import sys
import time
from pathlib import Path
from urllib.parse import urlparse, unquote
from urllib.request import urlopen, Request
from urllib.error import URLError, HTTPError

# Dominios considerados "internos" (sin www, en minúsculas)
INTERNAL_DOMAINS = [
    'lanzatu.blog',
    'blogpocket.com',
]

# Prefijos de path a EXCLUIR aunque el host sea interno.
# Útil para PDFs que ya están resueltos manualmente en otra ubicación
# y no deben tocarse. Ejemplo: PDFs servidos por Download Monitor.
EXCLUDED_PATH_PREFIXES = [
    '/wp-content/uploads/dlm-uploads/',
]

USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) descargar-pdfs/1.0'
DOWNLOAD_TIMEOUT = 60  # segundos
INTER_REQUEST_DELAY = 0.2  # pausa entre descargas para no martillear

# Patrón para detectar href="...pdf" con query (?...) y/o fragmento (#...) opcionales.
# Soporta cualquier combinación: .pdf, .pdf?q, .pdf#f, .pdf?q#f
PDF_HREF_PATTERN = re.compile(
    r'''(href\s*=\s*["'])(https?://[^"']+?\.pdf(?:[?#][^"']*)?)(["'])''',
    re.IGNORECASE
)


def normalize_host(host):
    h = host.lower()
    if h.startswith('www.'):
        h = h[4:]
    return h


def find_pdf_links(root, internal_hosts):
    """Recorre todos los HTML buscando enlaces a PDFs en hosts internos.

    Devuelve dict {url_canonica: info} donde url_canonica es la URL sin query/fragment
    y info incluye 'url_full' (con query original, para descargar) y 'used_in'
    (lista de HTMLs que la referencian).
    """
    pdfs = {}
    for html_file in root.rglob('*.html'):
        try:
            with open(html_file, 'r', encoding='utf-8') as f:
                content = f.read()
        except UnicodeDecodeError:
            with open(html_file, 'r', encoding='utf-8', errors='replace') as f:
                content = f.read()

        for match in PDF_HREF_PATTERN.finditer(content):
            url = match.group(2)
            parsed = urlparse(url)
            host = normalize_host(parsed.netloc)
            if host not in internal_hosts:
                continue

            # Saltar paths excluidos (PDFs ya resueltos por otra vía)
            if any(parsed.path.startswith(p) for p in EXCLUDED_PATH_PREFIXES):
                continue

            url_canonical = url.split('?')[0].split('#')[0]
            if url_canonical not in pdfs:
                pdfs[url_canonical] = {
                    'url_full': url,
                    'used_in': [],
                }
            pdfs[url_canonical]['used_in'].append(html_file.relative_to(root))

    return pdfs


def assign_filenames(pdfs):
    """Asigna nombre destino a cada PDF; resuelve colisiones con sufijo numérico."""
    used = {}  # basename -> contador para sufijos

    for url_canonical in sorted(pdfs.keys()):
        parsed = urlparse(url_canonical)
        basename = os.path.basename(unquote(parsed.path)) or 'documento.pdf'
        if not basename.lower().endswith('.pdf'):
            basename = 'documento.pdf'

        if basename not in used:
            used[basename] = 1
            pdfs[url_canonical]['filename'] = basename
        else:
            used[basename] += 1
            stem, ext = os.path.splitext(basename)
            pdfs[url_canonical]['filename'] = f"{stem}-{used[basename]}{ext}"

    return pdfs


# Estado del módulo: si SSL falla por falta de certificados raíz, se activa este
# contexto sin verificación y se reutiliza para todas las siguientes descargas.
# Frecuente en macOS con Python instalado sin ejecutar Install Certificates.command.
_ssl_context_override = None
_ssl_fallback_announced = False


def _announce_ssl_fallback():
    """Imprime una sola vez el aviso de que pasamos a modo sin verificación SSL."""
    global _ssl_fallback_announced
    if _ssl_fallback_announced:
        return
    _ssl_fallback_announced = True
    print()
    print("  AVISO: este Python no tiene los certificados raíz cargados.")
    print("         Las descargas continúan sin verificación SSL.")
    print("         Para arreglarlo de raíz en macOS, ejecuta una vez:")
    print("           /Applications/Python\\ 3.X/Install\\ Certificates.command")
    print("         (sustituye 3.X por tu versión: 3.10, 3.11, 3.12, 3.13...)")
    print()


def download_pdf(url, dest_path):
    """Descarga un PDF y verifica que es válido. Devuelve (ok, mensaje).

    Si la verificación SSL falla por certificados raíz ausentes (típico en
    macOS sin Install Certificates.command), reintenta automáticamente sin
    verificación. Solo avisa una vez.
    """
    global _ssl_context_override
    try:
        req = Request(url, headers={'User-Agent': USER_AGENT})

        # Si ya tenemos override por fallo previo, usarlo directamente
        if _ssl_context_override is not None:
            resp = urlopen(req, timeout=DOWNLOAD_TIMEOUT, context=_ssl_context_override)
        else:
            try:
                resp = urlopen(req, timeout=DOWNLOAD_TIMEOUT)
            except URLError as e:
                # Si es error de certificado, activar fallback y reintentar
                reason_str = str(e.reason) if e.reason else ''
                if isinstance(e.reason, ssl.SSLError) or 'CERTIFICATE_VERIFY_FAILED' in reason_str:
                    ctx = ssl.create_default_context()
                    ctx.check_hostname = False
                    ctx.verify_mode = ssl.CERT_NONE
                    _ssl_context_override = ctx
                    _announce_ssl_fallback()
                    resp = urlopen(req, timeout=DOWNLOAD_TIMEOUT, context=ctx)
                else:
                    raise

        with resp:
            if resp.status != 200:
                return False, f'HTTP {resp.status}'
            data = resp.read()
            if not data:
                return False, 'respuesta vacía'
            if not data.startswith(b'%PDF-'):
                return False, 'no parece un PDF (firma %PDF- ausente)'
            with open(dest_path, 'wb') as f:
                f.write(data)
            return True, f'{len(data):,} bytes'
    except HTTPError as e:
        return False, f'HTTP {e.code}'
    except URLError as e:
        return False, f'URLError: {e.reason}'
    except Exception as e:
        return False, f'Error: {e}'


def human_size(b):
    for unit in ('B', 'KB', 'MB', 'GB'):
        if b < 1024:
            return f"{b:.1f} {unit}"
        b /= 1024
    return f"{b:.1f} TB"


def main():
    args = sys.argv[1:]
    yes_flag = '--yes' in args or '-y' in args
    dry_run = '--dry-run' in args
    positional = [a for a in args if not a.startswith('-')]

    if not positional:
        print(__doc__)
        sys.exit(1)

    root = Path(positional[0]).resolve()
    if not root.is_dir():
        print(f"ERROR: no es un directorio: {root}")
        sys.exit(1)

    internal_hosts = {normalize_host(d) for d in INTERNAL_DOMAINS}

    print(f"Carpeta:           {root}")
    print(f"Dominios internos: {sorted(internal_hosts)}")
    print()

    # --- FASE 1: análisis ---
    print("Fase 1: rastreando HTMLs en busca de enlaces a PDFs internos...")
    pdfs = find_pdf_links(root, internal_hosts)

    if not pdfs:
        print("  No se encontraron enlaces a PDFs internos. Nada que hacer.")
        return

    pdfs = assign_filenames(pdfs)
    print(f"  {len(pdfs)} PDFs únicos detectados.\n")

    print("Lista de PDFs a procesar:")
    print("-" * 78)
    for url_canonical, info in sorted(pdfs.items()):
        refs = len(info['used_in'])
        ref_str = f"(usado en {refs} HTML{'s' if refs != 1 else ''})"
        print(f"  {info['filename']:50}  {ref_str}")
        print(f"    <- {url_canonical}")
    print("-" * 78)
    print()

    if dry_run:
        print("Modo --dry-run: solo análisis. No se descarga ni modifica nada.")
        return

    if not yes_flag:
        try:
            resp = input("¿Continuar con la descarga y reescritura? [Y/n] ").strip().lower()
        except EOFError:
            resp = ''
        if resp and resp not in ('y', 'yes', 's', 'si', 'sí'):
            print("Abortado por el usuario.")
            return
        print()

    # --- FASE 2: descarga ---
    print("Fase 2: descargando PDFs...")
    pdf_dir = root / 'pdf'
    pdf_dir.mkdir(exist_ok=True)

    ok_count = 0
    fail_count = 0
    skipped_count = 0
    failed_urls = []
    total_bytes = 0

    for i, (url_canonical, info) in enumerate(sorted(pdfs.items()), 1):
        dest = pdf_dir / info['filename']

        # Idempotencia: si ya existe y no está vacío, asumir OK
        if dest.exists() and dest.stat().st_size > 0:
            print(f"  [{i:3}/{len(pdfs)}] (ya en disco) {info['filename']}")
            info['downloaded'] = True
            skipped_count += 1
            continue

        print(f"  [{i:3}/{len(pdfs)}] {info['filename']:55}", end=' ', flush=True)
        ok, msg = download_pdf(info['url_full'], dest)
        if ok:
            print(f'OK ({msg})')
            info['downloaded'] = True
            ok_count += 1
            total_bytes += dest.stat().st_size
        else:
            print(f'FALLO ({msg})')
            info['downloaded'] = False
            fail_count += 1
            failed_urls.append((url_canonical, info['filename'], msg))

        time.sleep(INTER_REQUEST_DELAY)

    print()
    print("Resumen de descargas:")
    print(f"  Descargados OK:    {ok_count}")
    print(f"  Ya estaban:        {skipped_count}")
    print(f"  Fallidos:          {fail_count}")
    if total_bytes:
        print(f"  Total descargado:  {human_size(total_bytes)}")
    print(f"  Carpeta destino:   {pdf_dir}")
    print()

    if failed_urls:
        print("PDFs que no se pudieron descargar automáticamente:")
        print("-" * 78)
        for url, fname, reason in failed_urls:
            print(f"  {fname}")
            print(f"    URL:    {url}")
            print(f"    Motivo: {reason}")
        print("-" * 78)
        print(f"Descárgalos manualmente a {pdf_dir}/ usando los nombres indicados,")
        print("y vuelve a ejecutar este script: detectará los que ya están y completará")
        print("la reescritura de los HTML para todos.")
        print()

    # --- FASE 3: reescritura ---
    print("Fase 3: reescribiendo referencias en los HTML...")

    # Mapa url_canonica -> filename solo para los descargados (o ya en disco)
    url_to_filename = {
        url_canonical: info['filename']
        for url_canonical, info in pdfs.items()
        if info.get('downloaded')
    }

    if not url_to_filename:
        print("  Sin PDFs descargados, no hay enlaces que reescribir.")
        return

    files_modified = 0
    links_rewritten = 0

    for html_file in root.rglob('*.html'):
        try:
            with open(html_file, 'r', encoding='utf-8') as f:
                content = f.read()
        except UnicodeDecodeError:
            with open(html_file, 'r', encoding='utf-8', errors='replace') as f:
                content = f.read()

        rel_html = html_file.relative_to(root)
        depth = len(rel_html.parent.parts)
        prefix = '../' * depth if depth > 0 else ''

        def replace(m):
            nonlocal links_rewritten
            url = m.group(2)
            parsed = urlparse(url)
            host = normalize_host(parsed.netloc)
            if host not in internal_hosts:
                return m.group(0)
            # Respetar exclusiones por path (PDFs ya resueltos por otra vía)
            if any(parsed.path.startswith(p) for p in EXCLUDED_PATH_PREFIXES):
                return m.group(0)
            url_canonical = url.split('?')[0].split('#')[0]
            if url_canonical not in url_to_filename:
                return m.group(0)
            new_url = prefix + 'pdf/' + url_to_filename[url_canonical]
            if parsed.fragment:
                new_url += '#' + parsed.fragment
            links_rewritten += 1
            return m.group(1) + new_url + m.group(3)

        new_content = PDF_HREF_PATTERN.sub(replace, content)
        if new_content != content:
            with open(html_file, 'w', encoding='utf-8') as f:
                f.write(new_content)
            files_modified += 1

    print(f"  HTMLs modificados:   {files_modified}")
    print(f"  Enlaces reescritos:  {links_rewritten}")
    print()
    print("✓ Listo.")


if __name__ == '__main__':
    main()
