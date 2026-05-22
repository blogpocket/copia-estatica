# Herramientas auxiliares

Estos scripts cubren tareas que rodean al plugin y se ejecutan **fuera de WordPress**, sobre la carpeta de salida descargada a tu máquina local. Son opcionales: ninguno es necesario para que el plugin funcione, pero cada uno cubre un caso que aparece tarde o temprano cuando se archiva un blog grande.

| Script | Para qué sirve |
|--------|----------------|
| [`convertir-img-a-webp.sh`](#convertir-img-a-webpsh) | Reducir el peso de las imágenes en un 60–80% sin pérdida visible |
| [`reescribir-enlaces-internos.py`](#reescribir-enlaces-internospy) | Convertir enlaces internos a rutas locales después de haber generado la copia |
| [`descargar-pdfs-y-reescribir.py`](#descargar-pdfs-y-reescribirpy) | Descargar PDFs enlazados desde posts y redirigir las referencias |
| [`backup-pdfs-libreria.py`](#backup-pdfs-libreriapy) | Respaldar todos los PDFs de la biblioteca multimedia, incluidos los no enlazados |

---

## `convertir-img-a-webp.sh`

Convierte todas las imágenes JPG/PNG/GIF/BMP de la carpeta `/img/` de la copia estática a formato WebP, y actualiza las referencias en los archivos `.html` para que apunten a los nuevos `.webp`.

### Por qué

WebP comprime entre un 40% y un 70% mejor que JPEG/PNG manteniendo calidad visual equivalente. En blogs con muchas imágenes esto puede ser la diferencia entre una copia que cabe en GitHub Pages (límite duro de 1 GB) y una que no.

### Requisitos

- macOS o Linux con bash.
- `cwebp` (preferido) o ImageMagick (`convert`).

Instalación:

```bash
# macOS (requiere Homebrew)
brew install webp

# Ubuntu/Debian
sudo apt install webp
```

### Uso

```bash
chmod +x convertir-img-a-webp.sh
./convertir-img-a-webp.sh /ruta/a/copia-estatica-html
```

La calidad por defecto es 82. Puedes ajustarla:

```bash
./convertir-img-a-webp.sh /ruta/a/copia-estatica-html --quality 75
```

El script va imprimiendo progreso cada 50 archivos, borra el original solo si la conversión fue correcta, y al final muestra un resumen con el ahorro total.

### Limitaciones

JPGs en espacio CMYK no se convierten directamente. Si ves errores tipo `libjpeg error: Unsupported color conversion request`, conviértelas primero a sRGB con `sips` (macOS) o ImageMagick:

```bash
sips -s format jpeg \
     --matchTo "/System/Library/ColorSync/Profiles/sRGB Profile.icc" \
     imagen.jpg --out /tmp/fixed.jpg
cwebp -q 82 /tmp/fixed.jpg -o imagen.webp
```

---

## `reescribir-enlaces-internos.py`

Reescribe los enlaces internos de una copia estática ya generada, sin tener que volver a ejecutar el plugin en WordPress.

### Por qué

A partir de la versión 1.7 del plugin, los enlaces internos se resuelven correctamente durante la generación (incluyendo dominios históricos vía `CEL_EXTRA_INTERNAL_HOSTS`). Pero si ya tienes una copia estática generada con una versión anterior, o si descubres dominios históricos que olvidaste declarar, regenerar todo desde WordPress es costoso. Este script reescribe los enlaces directamente sobre los HTML existentes, sin tocar las imágenes ni la estructura.

### Requisitos

Python 3.6 o superior. Viene preinstalado en macOS y en la mayoría de distribuciones Linux.

### Uso

```bash
python3 reescribir-enlaces-internos.py /ruta/a/copia-estatica-html
```

Por defecto reescribe enlaces a `lanzatu.blog` y `blogpocket.com`. Para usar otros dominios, edita la constante `DEFAULT_DOMAINS` al principio del script.

### Cómo funciona

1. Indexa todos los `.html` de la copia construyendo un mapa `slug → ruta_local`.
2. Recorre cada HTML buscando enlaces `href="..."` a los dominios declarados.
3. Para cada uno, intenta resolver el último segmento del path (el slug) en el índice.
4. Si no resuelve, prueba con el penúltimo segmento (cubre URLs de attachment de WordPress tipo `/post/imagen/`).
5. Calcula la ruta relativa correcta y reescribe el enlace.
6. Preserva fragmentos `#anchor` al reescribir.
7. Salta URLs no-contenido (`/category/`, `/tag/`, `/feed/`, paths `wp-*`, etc.).

### Idempotencia

El script es seguro de re-ejecutar. Los enlaces ya reescritos en pasadas anteriores son rutas relativas, no URLs absolutas, así que no entran en el patrón y no se tocan.

---

## `descargar-pdfs-y-reescribir.py`

Encuentra todos los enlaces a PDFs alojados en dominios internos (`lanzatu.blog`, `blogpocket.com`) dentro de una copia estática, los descarga a una carpeta `/pdf` y reescribe las referencias en los HTML para que apunten localmente.

### Por qué

El plugin descarga imágenes pero **no descarga PDFs ni otros documentos**. Si tu blog enlaza ebooks, manuales o cualquier otro PDF desde el contenido de los posts, esos enlaces apuntan a la URL original. Cuando esa URL deja de existir (porque migras de dominio, borras directorios, etc.) los enlaces de la copia estática quedan rotos. Este script soluciona el problema.

### Requisitos

Python 3.6 o superior.

### Uso básico

```bash
# Modo análisis: ver qué PDFs detecta sin tocar nada
python3 descargar-pdfs-y-reescribir.py /ruta/a/copia-estatica-html --dry-run

# Ejecución real con confirmación interactiva
python3 descargar-pdfs-y-reescribir.py /ruta/a/copia-estatica-html

# Sin confirmación (modo no interactivo)
python3 descargar-pdfs-y-reescribir.py /ruta/a/copia-estatica-html --yes
```

### Cómo funciona

El script trabaja en tres fases:

1. **Análisis**: rastrea todos los `.html`, encuentra todos los `<a href="...pdf">` que apunten a dominios internos, deduplica por URL canónica (ignorando query strings y fragmentos) y construye una lista única.
2. **Descarga**: bajada uno a uno con rate-limiting suave (0.2s entre peticiones) para no martillear el servidor. Cada archivo se verifica leyendo su cabecera (`%PDF-`) para detectar 404 disfrazados de 200. Los fallidos se reportan al final con su URL para descarga manual.
3. **Reescritura**: para cada HTML, calcula la profundidad relativa hasta `pdf/` y reescribe el enlace. Soporta los cuatro casos de URL: `.pdf`, `.pdf?query`, `.pdf#fragmento`, `.pdf?query#fragmento`, preservando el fragmento al reescribir.

### Idempotencia

Si la primera pasada deja fallidos, descárgalos manualmente con el nombre que el script indica y re-ejecuta. Los archivos ya en `pdf/` se detectan como `(ya en disco)`, no se vuelven a descargar, y solo se completa la reescritura de los enlaces huérfanos.

### Configuración

Dos constantes al principio del script:

```python
INTERNAL_DOMAINS = [
    'lanzatu.blog',
    'blogpocket.com',
]

EXCLUDED_PATH_PREFIXES = [
    '/wp-content/uploads/dlm-uploads/',
]
```

`INTERNAL_DOMAINS` define qué dominios se consideran "internos" (los únicos cuyos PDFs se descargan). `EXCLUDED_PATH_PREFIXES` permite ignorar rutas específicas dentro de esos dominios, útil cuando tienes PDFs resueltos por otra vía (un plugin de descargas tipo Download Monitor, por ejemplo).

### Limitaciones

- Solo detecta `<a href="...pdf">`. No procesa imágenes ni iframes.
- No procesa URLs sin extensión `.pdf` explícita (por ejemplo, `/?attachment_id=NNN` no se detecta).
- Si una URL tiene su query string, esta se descarta en la reescritura (queda `.pdf` solo, sin parámetros).

### Compatibilidad con macOS

Si te aparece este error:

```
URLError: [SSL: CERTIFICATE_VERIFY_FAILED] certificate verify failed
```

es porque el Python de macOS no trae los certificados raíz cargados por defecto. El script lo detecta automáticamente y pasa a descargar sin verificación SSL avisándote una vez. Es seguro para descargar de servidores que tú controlas. Para arreglarlo de raíz, ejecuta una vez:

```bash
/Applications/Python\ 3.X/Install\ Certificates.command
```

(Sustituye `3.X` por tu versión de Python.)

---

## `backup-pdfs-libreria.py`

Descarga **todos** los PDFs de la biblioteca multimedia de un WordPress vía API REST, opcionalmente filtrando contra una carpeta local para no repetir descargas.

### Por qué

El script anterior (`descargar-pdfs-y-reescribir.py`) descarga solo los PDFs **enlazados** desde el contenido de posts y páginas. Pero la biblioteca multimedia de WordPress puede tener muchos más: archivos subidos que nunca se insertaron en un post, versiones antiguas de un mismo documento, PDFs adjuntados a borradores nunca publicados, etc. Si quieres un backup completo de los PDFs de tu blog (no solo de los que aparecen en la copia estática), este script lo hace.

### Requisitos

Python 3.6 o superior. Acceso público o autenticado a la API REST del WordPress de origen.

### Uso

```bash
# Modo análisis
python3 backup-pdfs-libreria.py https://miblog.com ~/Downloads/backup-pdfs --dry-run

# Modo anónimo (funciona en la mayoría de instalaciones)
python3 backup-pdfs-libreria.py https://miblog.com ~/Downloads/backup-pdfs

# Filtrar contra los PDFs que ya tienes en otra carpeta
python3 backup-pdfs-libreria.py https://miblog.com ~/Downloads/backup-pdfs \
    --existing /ruta/a/copia-estatica-html/pdf

# Modo autenticado (si la API REST tiene restricciones)
python3 backup-pdfs-libreria.py https://miblog.com ~/Downloads/backup-pdfs \
    --user MI_USUARIO \
    --password 'abcd 1234 efgh 5678 ijkl 9012'
```

### Cómo funciona

1. Paginación de `/wp-json/wp/v2/media?media_type=application&per_page=100`.
2. Filtrado por `mime_type === 'application/pdf'`.
3. Comparación opcional contra `--existing` por nombre de archivo: salta los que ya tienes.
4. Descarga al directorio destino con el nombre original.

### Application Passwords

Si el modo anónimo no devuelve resultados o devuelve un 401/403, la API REST está restringida. Crea un **Application Password** desde el admin de WordPress: **Usuarios → Tu perfil → Application Passwords** (al final del formulario). Dale un nombre identificativo y obtendrás un código tipo `abcd 1234 efgh 5678` (incluidos los espacios). Úsalo con `--user` y `--password` (entre comillas simples para conservar los espacios).

Las Application Passwords son específicas para uso programático: no comprometen tu contraseña principal y pueden revocarse individualmente cuando ya no las necesites.

### Compatibilidad con macOS

El mismo fallback SSL automático que en `descargar-pdfs-y-reescribir.py`.
