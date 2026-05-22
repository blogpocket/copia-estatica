#!/usr/bin/env python3
"""
backup-pdfs-libreria.py

Descarga todos los PDFs de la Biblioteca Multimedia de un sitio WordPress
mediante su API REST. Útil para respaldar PDFs que están en la biblioteca
pero NO están enlazados desde posts o páginas (y que por tanto no aparecen
en la copia estática generada por el plugin Copia Estática Local).

Modos:
    Anónimo:        WordPress publica los attachments en /wp-json/wp/v2/media
                    sin autenticación por defecto. Pruébalo primero.
    Autenticado:    Si el anónimo no devuelve resultados o devuelve 401/403,
                    usa un Application Password (NO tu contraseña principal).
                    Se crea desde: Usuarios -> Perfil -> Application Passwords.

Uso básico:
    python3 backup-pdfs-libreria.py https://lanzatu.blog ~/Downloads/backup-pdfs

Saltar PDFs que ya tienes localmente (carpeta /pdf de la copia estática):
    python3 backup-pdfs-libreria.py https://lanzatu.blog ~/Downloads/backup-pdfs \\
        --existing ~/Downloads/blog-estatico/copia-estatica-html/pdf

Autenticado:
    python3 backup-pdfs-libreria.py https://lanzatu.blog ~/Downloads/backup-pdfs \\
        --user antonio --password 'abcd 1234 efgh 5678'

Solo listar, sin descargar:
    python3 backup-pdfs-libreria.py https://lanzatu.blog ~/Downloads/backup-pdfs --dry-run
"""

import os
import sys
import ssl
import json
import time
import base64
from pathlib import Path
from urllib.parse import urlparse, unquote
from urllib.request import urlopen, Request
from urllib.error import URLError, HTTPError

USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) backup-pdfs-libreria/1.0'
DOWNLOAD_TIMEOUT = 60
INTER_REQUEST_DELAY = 0.2
PAGE_SIZE = 100  # máximo permitido por la REST API de WP

# Auto-fallback de SSL para entornos macOS sin Install Certificates.command
_ssl_context_override = None
_ssl_announced = False


def _announce_ssl():
    global _ssl_announced
    if _ssl_announced:
        return
    _ssl_announced = True
    print()
    print("  AVISO: este Python no tiene los certificados raíz disponibles.")
    print("         Las descargas continúan sin verificación SSL.")
    print("         Para arreglarlo de raíz en macOS, ejecuta una vez:")
    print("           /Applications/Python\\ 3.X/Install\\ Certificates.command")
    print()


def http_get(url, auth_header=None, timeout=DOWNLOAD_TIMEOUT, accept_json=False):
    """GET genérico con fallback SSL y auth opcional. Devuelve bytes."""
    global _ssl_context_override
    headers = {'User-Agent': USER_AGENT}
    if auth_header:
        headers['Authorization'] = auth_header
    if accept_json:
        headers['Accept'] = 'application/json'

    req = Request(url, headers=headers)

    def _open(ctx=None):
        if ctx is None:
            return urlopen(req, timeout=timeout)
        return urlopen(req, timeout=timeout, context=ctx)

    try:
        if _ssl_context_override is not None:
            resp = _open(_ssl_context_override)
        else:
            try:
                resp = _open()
            except URLError as e:
                reason_str = str(e.reason) if e.reason else ''
                if isinstance(e.reason, ssl.SSLError) or 'CERTIFICATE_VERIFY_FAILED' in reason_str:
                    ctx = ssl.create_default_context()
                    ctx.check_hostname = False
                    ctx.verify_mode = ssl.CERT_NONE
                    _ssl_context_override = ctx
                    _announce_ssl()
                    resp = _open(ctx)
                else:
                    raise

        with resp:
            return resp.read(), dict(resp.headers)
    except HTTPError as e:
        raise
    except URLError as e:
        raise


def list_pdfs_from_api(site_url, auth_header=None):
    """Recorre /wp-json/wp/v2/media paginando, devuelve solo los PDFs."""
    api_base = site_url.rstrip('/') + '/wp-json/wp/v2/media'
    pdfs = []
    page = 1
    total_pages = None

    while True:
        url = f"{api_base}?media_type=application&per_page={PAGE_SIZE}&page={page}"
        try:
            body, headers = http_get(url, auth_header=auth_header, accept_json=True)
        except HTTPError as e:
            if e.code == 400 and total_pages is not None and page > total_pages:
                # WP devuelve 400 cuando pides una página más allá de las disponibles
                break
            if e.code in (401, 403):
                print(f"  ERROR de autenticación (HTTP {e.code}).")
                print("  Prueba con --user y --password (Application Password).")
                return None
            raise

        try:
            items = json.loads(body)
        except json.JSONDecodeError:
            print(f"  ERROR: la respuesta no es JSON válido en página {page}")
            return None

        if not isinstance(items, list) or not items:
            break

        if total_pages is None:
            tp = headers.get('X-WP-TotalPages') or headers.get('x-wp-totalpages')
            if tp:
                try:
                    total_pages = int(tp)
                    print(f"  Páginas totales: {total_pages}")
                except ValueError:
                    pass

        for item in items:
            mime = item.get('mime_type', '')
            if mime != 'application/pdf':
                continue
            source_url = item.get('source_url')
            if not source_url:
                continue
            title = (item.get('title', {}) or {}).get('rendered', '')
            pdfs.append({
                'id': item.get('id'),
                'source_url': source_url,
                'title': title,
                'date': item.get('date', ''),
            })

        print(f"  Página {page}: {len(items)} ítems, {len(pdfs)} PDFs acumulados")
        page += 1

        if total_pages and page > total_pages:
            break

        time.sleep(INTER_REQUEST_DELAY)

    return pdfs


def filename_from_url(url):
    parsed = urlparse(url)
    return os.path.basename(unquote(parsed.path)) or 'documento.pdf'


def download_file(url, dest_path, auth_header=None):
    try:
        data, _ = http_get(url, auth_header=auth_header)
        if not data or not data.startswith(b'%PDF-'):
            return False, 'no parece un PDF válido'
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


def parse_args(argv):
    """Parser minimalista sin dependencias."""
    if len(argv) < 3:
        print(__doc__)
        sys.exit(1)

    out = {
        'site': argv[1].rstrip('/'),
        'dest': Path(argv[2]).expanduser().resolve(),
        'existing': None,
        'user': None,
        'password': None,
        'dry_run': False,
        'yes': False,
    }
    i = 3
    while i < len(argv):
        a = argv[i]
        if a in ('--existing',):
            i += 1
            out['existing'] = Path(argv[i]).expanduser().resolve()
        elif a == '--user':
            i += 1
            out['user'] = argv[i]
        elif a == '--password':
            i += 1
            out['password'] = argv[i]
        elif a == '--dry-run':
            out['dry_run'] = True
        elif a in ('--yes', '-y'):
            out['yes'] = True
        else:
            print(f"Argumento desconocido: {a}")
            sys.exit(1)
        i += 1

    return out


def main():
    args = parse_args(sys.argv)

    auth_header = None
    if args['user'] and args['password']:
        # Application Passwords se pasan como HTTP Basic
        token = base64.b64encode(f"{args['user']}:{args['password']}".encode()).decode()
        auth_header = f'Basic {token}'
        print(f"Modo: autenticado como {args['user']}")
    else:
        print("Modo: anónimo (intentando sin autenticación)")

    print(f"Sitio:    {args['site']}")
    print(f"Destino:  {args['dest']}")
    if args['existing']:
        print(f"Existing: {args['existing']}")
    print()

    print("Fase 1: listando PDFs en la librería multimedia...")
    pdfs = list_pdfs_from_api(args['site'], auth_header=auth_header)

    if pdfs is None:
        sys.exit(2)

    if not pdfs:
        print("  No se encontraron PDFs en la biblioteca. Si esperabas resultados,")
        print("  prueba con --user y --password (Application Password).")
        return

    print(f"  Total de PDFs en la biblioteca: {len(pdfs)}")
    print()

    # Filtrar por los que ya tienes localmente
    existing_names = set()
    if args['existing'] and args['existing'].is_dir():
        existing_names = {p.name for p in args['existing'].glob('*.pdf')}
        print(f"Filtrando contra {len(existing_names)} PDFs ya presentes en {args['existing']}")

    to_download = []
    skipped = 0
    for p in pdfs:
        fname = filename_from_url(p['source_url'])
        if fname in existing_names:
            skipped += 1
            continue
        p['filename'] = fname
        to_download.append(p)

    if existing_names:
        print(f"  Saltados (ya en existing): {skipped}")
    print(f"  Pendientes a descargar:    {len(to_download)}")
    print()

    if not to_download:
        print("Nada que descargar. Todos los PDFs de la biblioteca están ya en local.")
        return

    print("Lista de PDFs a descargar:")
    print("-" * 78)
    for p in to_download[:20]:
        print(f"  {p['filename']}")
    if len(to_download) > 20:
        print(f"  ...y {len(to_download) - 20} más")
    print("-" * 78)
    print()

    if args['dry_run']:
        print("Modo --dry-run: no se descarga nada.")
        return

    if not args['yes']:
        try:
            resp = input(f"¿Descargar {len(to_download)} PDFs a {args['dest']}? [Y/n] ").strip().lower()
        except EOFError:
            resp = ''
        if resp and resp not in ('y', 'yes', 's', 'si', 'sí'):
            print("Abortado.")
            return
        print()

    args['dest'].mkdir(parents=True, exist_ok=True)

    ok = 0
    fail = 0
    total_bytes = 0
    fails = []

    for i, p in enumerate(to_download, 1):
        dest = args['dest'] / p['filename']
        if dest.exists() and dest.stat().st_size > 0:
            print(f"  [{i:3}/{len(to_download)}] (ya existe) {p['filename']}")
            ok += 1
            continue

        print(f"  [{i:3}/{len(to_download)}] {p['filename']:60}", end=' ', flush=True)
        success, msg = download_file(p['source_url'], dest, auth_header=auth_header)
        if success:
            print(f"OK ({msg})")
            ok += 1
            total_bytes += dest.stat().st_size
        else:
            print(f"FALLO ({msg})")
            fail += 1
            fails.append((p['filename'], p['source_url'], msg))

        time.sleep(INTER_REQUEST_DELAY)

    print()
    print("Resumen:")
    print(f"  Descargados OK:    {ok}")
    print(f"  Fallidos:          {fail}")
    if total_bytes:
        print(f"  Total descargado:  {human_size(total_bytes)}")
    print(f"  Carpeta destino:   {args['dest']}")

    if fails:
        print()
        print("PDFs fallidos:")
        for fname, url, reason in fails:
            print(f"  {fname}")
            print(f"    {url}")
            print(f"    {reason}")


if __name__ == '__main__':
    main()
