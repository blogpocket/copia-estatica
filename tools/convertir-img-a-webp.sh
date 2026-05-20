#!/usr/bin/env bash
#
# convertir-img-a-webp.sh
#
# Convierte todas las imágenes JPG/PNG/GIF/BMP de la carpeta /img/ de una
# copia estática generada por el plugin "Copia Estática Local" a formato WebP,
# y actualiza todas las referencias en los .html de la copia.
#
# Uso:
#   ./convertir-img-a-webp.sh /ruta/a/copia-estatica-html
#   ./convertir-img-a-webp.sh /ruta/a/copia-estatica-html --quality 80
#
# Por defecto la calidad es 82 (buen equilibrio tamaño/calidad).
# Para fotos típicas el ahorro es del 40-70% sobre JPG/PNG.
#
# Requisitos: cwebp (preferido) o ImageMagick.
#   macOS:   brew install webp
#   Ubuntu:  sudo apt install webp
#   (ImageMagick suele venir preinstalado en Mac y Linux)

set -euo pipefail

# ---------- Parámetros ----------
ROOT="${1:-}"
QUALITY=82

# Permitir --quality N
while [[ $# -gt 0 ]]; do
    case "$1" in
        --quality)
            QUALITY="$2"; shift 2 ;;
        -h|--help)
            sed -n '3,20p' "$0"; exit 0 ;;
        *)
            if [[ -z "${ROOT_SET:-}" ]]; then ROOT="$1"; ROOT_SET=1; fi
            shift ;;
    esac
done

if [[ -z "${ROOT}" ]]; then
    echo "Uso: $0 /ruta/a/copia-estatica-html [--quality 82]"
    exit 1
fi

if [[ ! -d "$ROOT/img" ]]; then
    echo "ERROR: no encuentro $ROOT/img"
    exit 1
fi

# ---------- Detectar herramienta disponible ----------
TOOL=""
if command -v cwebp >/dev/null 2>&1; then
    TOOL="cwebp"
elif command -v convert >/dev/null 2>&1; then
    TOOL="convert"
elif command -v magick >/dev/null 2>&1; then
    TOOL="magick"
else
    echo "ERROR: necesito cwebp o ImageMagick instalado."
    echo "  macOS:  brew install webp"
    echo "  Ubuntu: sudo apt install webp"
    exit 1
fi

# gif2webp para GIFs animados (opcional)
HAS_GIF2WEBP=0
if command -v gif2webp >/dev/null 2>&1; then
    HAS_GIF2WEBP=1
fi

echo "Herramienta de conversión: $TOOL (calidad $QUALITY)"
[[ $HAS_GIF2WEBP -eq 1 ]] && echo "gif2webp disponible para GIFs animados"
echo ""

# ---------- Stat portable (Linux y macOS) ----------
filesize() {
    if stat -c%s "$1" >/dev/null 2>&1; then
        stat -c%s "$1"
    else
        stat -f%z "$1"
    fi
}

# ---------- Conversión ----------
IMG_DIR="$ROOT/img"
TOTAL_BEFORE=0
TOTAL_AFTER=0
COUNT_OK=0
COUNT_FAIL=0
COUNT_SKIP=0

# Recopilar lista de imágenes a convertir (compatible con Bash 3.2 de macOS)
FILES=()
while IFS= read -r f; do
    FILES+=("$f")
done < <(find "$IMG_DIR" -maxdepth 1 -type f \
    \( -iname "*.jpg" -o -iname "*.jpeg" -o -iname "*.png" \
       -o -iname "*.gif" -o -iname "*.bmp" \) | sort)

TOTAL=${#FILES[@]}
if [[ $TOTAL -eq 0 ]]; then
    echo "No hay imágenes JPG/PNG/GIF/BMP que convertir en $IMG_DIR"
    exit 0
fi

echo "Imágenes a convertir: $TOTAL"
echo "Esto puede tardar varios minutos. Progreso cada 50 archivos:"
echo ""

i=0
for src in "${FILES[@]}"; do
    i=$((i + 1))
    base="${src%.*}"
    dst="${base}.webp"

    # Si ya existe un .webp con el mismo nombre, saltar
    if [[ -f "$dst" ]]; then
        COUNT_SKIP=$((COUNT_SKIP + 1))
        continue
    fi

    size_before=$(filesize "$src")
    ok=0

    # GIF: usar gif2webp si está disponible, para preservar animación
    ext_lower=$(printf '%s' "${src##*.}" | tr 'A-Z' 'a-z')
    if [ "$ext_lower" = "gif" ] && [ $HAS_GIF2WEBP -eq 1 ]; then
        if gif2webp -q "$QUALITY" "$src" -o "$dst" >/dev/null 2>&1; then
            ok=1
        fi
    else
        case "$TOOL" in
            cwebp)
                if cwebp -quiet -q "$QUALITY" "$src" -o "$dst" 2>/dev/null; then
                    ok=1
                fi
                ;;
            convert|magick)
                if "$TOOL" "$src" -quality "$QUALITY" "$dst" 2>/dev/null; then
                    ok=1
                fi
                ;;
        esac
    fi

    if [[ $ok -eq 1 && -f "$dst" && $(filesize "$dst") -gt 0 ]]; then
        size_after=$(filesize "$dst")
        TOTAL_BEFORE=$((TOTAL_BEFORE + size_before))
        TOTAL_AFTER=$((TOTAL_AFTER + size_after))
        rm -f "$src"
        COUNT_OK=$((COUNT_OK + 1))
    else
        rm -f "$dst" 2>/dev/null || true
        echo "  ⚠ Falló: $(basename "$src")"
        COUNT_FAIL=$((COUNT_FAIL + 1))
    fi

    if (( i % 50 == 0 )); then
        pct=$(( i * 100 / TOTAL ))
        echo "  [$i/$TOTAL] ${pct}%"
    fi
done

# ---------- Función para mostrar tamaños humanos ----------
human() {
    local b=$1
    if (( b > 1073741824 )); then
        awk -v b=$b 'BEGIN{printf "%.2f GB", b/1073741824}'
    elif (( b > 1048576 )); then
        awk -v b=$b 'BEGIN{printf "%.1f MB", b/1048576}'
    elif (( b > 1024 )); then
        awk -v b=$b 'BEGIN{printf "%.1f KB", b/1024}'
    else
        echo "$b B"
    fi
}

echo ""
echo "==========================================="
echo "  CONVERSIÓN DE IMÁGENES TERMINADA"
echo "==========================================="
echo "Convertidas: $COUNT_OK"
echo "Saltadas (ya existían): $COUNT_SKIP"
echo "Fallidas: $COUNT_FAIL"
if (( TOTAL_BEFORE > 0 )); then
    saved=$(( TOTAL_BEFORE - TOTAL_AFTER ))
    pct_saved=$(( saved * 100 / TOTAL_BEFORE ))
    echo "Tamaño antes:  $(human $TOTAL_BEFORE)"
    echo "Tamaño después: $(human $TOTAL_AFTER)"
    echo "Ahorrado: $(human $saved) (${pct_saved}%)"
fi
echo ""

# ---------- Actualizar referencias en HTML ----------
echo "Actualizando referencias en archivos .html..."
HTML_COUNT=0
HTML_CHANGED=0

# Usar sed portable con backup, y luego borrar los .bak
# Patrón: rutas con hash MD5 (32 hex) seguidas de extensión convertible
SED_EXPR='s#(img/[a-f0-9]{32})\.(jpe?g|png|gif|bmp)#\1.webp#gi'

while IFS= read -r -d '' html; do
    HTML_COUNT=$((HTML_COUNT + 1))
    # sed -i.bak funciona igual en GNU sed (Linux) y BSD sed (macOS)
    sed -i.bak -E "$SED_EXPR" "$html"
    # Detectar si cambió comparando con el .bak
    if ! cmp -s "$html" "$html.bak"; then
        HTML_CHANGED=$((HTML_CHANGED + 1))
    fi
    rm -f "$html.bak"
done < <(find "$ROOT" -type f -name "*.html" -print0)

echo "Archivos HTML revisados: $HTML_COUNT"
echo "Archivos HTML modificados: $HTML_CHANGED"
echo ""
echo "✓ Listo. Comprueba el tamaño total con:"
echo "    du -sh \"$ROOT\""
